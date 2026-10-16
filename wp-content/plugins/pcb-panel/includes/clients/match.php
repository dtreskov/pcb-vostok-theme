<?php
/**
 * Сопоставление заявок с клиентами и очередь «Проверить».
 *
 * Новая заявка (и при первом запуске — все старые, от ранних к поздним):
 *   1. Контакт по точному совпадению: почта, затем мобильный телефон (+7 9…). Нашёлся — заявка к нему и к его компании.
 *      Городской номер может быть общим (приёмная, отдел), поэтому по нему не связываем сами — только предложение в очереди.
 *   2. Не нашёлся — создаётся новый контакт с почтой и телефоном заявки.
 *   3. Компания нового контакта: если по корпоративному домену почты или по названию (без формы собственности)
 *      уже есть кандидат — НЕ привязываем сами, а показываем в очереди «Проверить». Кандидатов нет, а название
 *      указано — создаётся новая компания. Публичные домены (gmail, mail.ru…) компанию не определяют.
 *
 * Очередь «Проверить» не хранится, а вычисляется из данных; отклонённые пары («это разные») — в pcb_match_skip.
 *   link:<контакт>:<компания>   контакт без компании, есть вероятная компания
 *   cmerge:<a>:<b>              две компании с одним доменом или одинаковым названием
 *   pmerge:<a>:<b>              два контакта с одинаковым ФИО в одной компании (или один без компании)
 */

defined( 'ABSPATH' ) || exit;

function pcb_cl_match_request( $rid ) {
	global $wpdb;
	if ( (int) get_post_meta( $rid, '_pcb_contact_id', true ) ) {
		return;
	}
	$email   = strtolower( trim( (string) get_post_meta( $rid, '_pcb_email', true ) ) );
	$phone   = pcb_crm_phone_digits( get_post_meta( $rid, '_pcb_phone', true ) );
	$name    = (string) get_post_meta( $rid, '_pcb_name', true );
	$company = (string) get_post_meta( $rid, '_pcb_company', true );

	$contact = pcb_cl_find( 'email', $email );
	if ( ! $contact && pcb_cl_is_mobile( $phone ) ) {
		$contact = pcb_cl_find( 'phone', $phone );
	}
	if ( $contact ) {
		pcb_cl_contact_add_channels( $contact, $email, $phone ); // вторая почта/телефон того же человека
		$c = pcb_cl_contact( $contact );
		pcb_cl_link_request( $rid, $contact, (int) $c['company_id'], 'auto' );
		return;
	}

	$contact = pcb_cl_contact_create( $name, 0, $email, $phone, $rid );
	$company_id = 0;
	if ( ! pcb_cl_company_candidates( $email, $company ) && '' !== pcb_crm_company_key( $company ) ) {
		$company_id = pcb_cl_company_create( array( 'name' => $company ), $rid );
		$wpdb->update( pcb_cl_table( 'contacts' ), array( 'company_id' => $company_id ), array( 'id' => $contact ) );
		pcb_cl_contact_add_channels( $contact, $email, '' ); // домен почты — новой компании
	}
	pcb_cl_link_request( $rid, $contact, $company_id, 'auto' );
}

/** Вероятные компании: array( company_id => причина ). */
function pcb_cl_company_candidates( $email, $company_name, $exclude = 0 ) {
	global $wpdb;
	$out = array();
	$d   = pcb_cl_email_domain( $email );
	if ( '' !== $d && ( $c = pcb_cl_find( 'domain', $d, 'company' ) ) && $c !== (int) $exclude ) {
		$out[ $c ] = 'домен почты ' . $d;
	}
	$key = pcb_crm_company_key( $company_name );
	if ( '' !== $key ) {
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . pcb_cl_table( 'companies' ) . ' WHERE merged_into = 0 AND name_key = %s AND id <> %d', $key, (int) $exclude ) );
		foreach ( $ids as $c ) {
			$out[ (int) $c ] = isset( $out[ (int) $c ] ) ? $out[ (int) $c ] . ' и название' : 'название «' . $company_name . '»';
		}
	}
	return $out;
}

/** Российский мобильный: 7 9XX XXX-XX-XX. */
function pcb_cl_is_mobile( $digits ) {
	return 11 === strlen( (string) $digits ) && 0 === strpos( (string) $digits, '79' );
}

function pcb_cl_skipped( $pair ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . pcb_cl_table( 'match_skip' ) . ' WHERE pair = %s', $pair ) );
}

function pcb_cl_skip( $pair ) {
	global $wpdb;
	if ( ! preg_match( '/^(link|cmerge|pmerge):\d+:\d+$/', $pair ) ) {
		return new WP_Error( 'pcb_cl', 'Неизвестное предложение.', array( 'status' => 400 ) );
	}
	$wpdb->replace( pcb_cl_table( 'match_skip' ), array( 'pair' => $pair, 'user_id' => get_current_user_id(), 'time' => current_time( 'mysql' ) ) );
	list( $type, $a, $b ) = explode( ':', $pair );
	pcb_cl_log( ( 'cmerge' === $type ? 'company:' : 'contact:' ) . $a, 'cl_skip', $pair, 'link' === $type ? 'не из компании #' . $b : 'не дубль #' . $b );
	return true;
}

/** Предложения очереди: array( array( type, pair, a, b, why ), … ). */
function pcb_cl_queue() {
	global $wpdb;
	$out = array();
	$tc  = pcb_cl_table( 'contacts' );
	$to  = pcb_cl_table( 'companies' );
	$ti  = pcb_cl_table( 'client_ids' );

	// контакты без компании
	foreach ( (array) $wpdb->get_results( "SELECT id FROM {$tc} WHERE merged_into = 0 AND company_id = 0", ARRAY_A ) as $r ) {
		$cid    = (int) $r['id'];
		$emails = pcb_cl_ids_of( 'contact', $cid )['email'] ?? array();
		$names  = array();
		foreach ( pcb_cl_requests( 'contact', $cid ) as $rid ) {
			$names[] = (string) get_post_meta( $rid, '_pcb_company', true );
		}
		$cands = array();
		foreach ( $emails ?: array( '' ) as $e ) {
			foreach ( array_unique( $names ?: array( '' ) ) as $n ) {
				$cands += pcb_cl_company_candidates( $e, $n );
			}
		}
		foreach ( $cands as $co => $why ) {
			$pair = 'link:' . $cid . ':' . $co;
			if ( ! pcb_cl_skipped( $pair ) ) {
				$out[] = array( 'type' => 'link', 'pair' => $pair, 'a' => $cid, 'b' => $co, 'why' => $why );
			}
		}
	}

	// компании-дубли: общий домен или одинаковое название
	$dups = $wpdb->get_results(
		"SELECT a.company_id AS a, b.company_id AS b, a.value AS v FROM {$ti} a JOIN {$ti} b ON a.kind = 'domain' AND b.kind = 'domain' AND a.value = b.value AND a.company_id < b.company_id
		 UNION SELECT a.id, b.id, a.name_key FROM {$to} a JOIN {$to} b ON a.name_key <> '' AND a.name_key = b.name_key AND a.id < b.id AND a.merged_into = 0 AND b.merged_into = 0",
		ARRAY_A
	);
	$seen = array();
	foreach ( (array) $dups as $d ) {
		$a = pcb_cl_company( $d['a'] );
		$b = pcb_cl_company( $d['b'] );
		if ( ! $a || ! $b || $a['id'] === $b['id'] ) {
			continue;
		}
		$pair = 'cmerge:' . min( $a['id'], $b['id'] ) . ':' . max( $a['id'], $b['id'] );
		if ( isset( $seen[ $pair ] ) || pcb_cl_skipped( $pair ) ) {
			continue;
		}
		$seen[ $pair ] = true;
		$out[]         = array( 'type' => 'cmerge', 'pair' => $pair, 'a' => (int) min( $a['id'], $b['id'] ), 'b' => (int) max( $a['id'], $b['id'] ), 'why' => false !== strpos( $d['v'], '.' ) ? 'общий домен почты ' . $d['v'] : 'одинаковое название' );
	}

	// контакты-дубли: одинаковое ФИО (от двух слов) в одной компании или у одного компании нет
	$pd = $wpdb->get_results(
		"SELECT a.id AS a, b.id AS b FROM {$tc} a JOIN {$tc} b ON a.name_key = b.name_key AND a.id < b.id
		 WHERE a.merged_into = 0 AND b.merged_into = 0 AND a.name_key LIKE '% %' AND (a.company_id = b.company_id OR a.company_id = 0 OR b.company_id = 0)",
		ARRAY_A
	);
	// контакты с одним городским номером: возможно, один человек, возможно — общий телефон отдела
	$pp = $wpdb->get_results(
		"SELECT MIN(a.contact_id) AS a, MIN(b.contact_id) AS b, a.value AS v FROM {$ti} a JOIN {$ti} b ON a.kind = 'phone' AND b.kind = 'phone' AND a.value = b.value AND a.contact_id < b.contact_id AND a.contact_id > 0
		 GROUP BY a.contact_id, b.contact_id, a.value",
		ARRAY_A
	);
	$pd = array_merge( array_map( function ( $d ) { $d['why'] = 'одинаковое ФИО'; return $d; }, (array) $pd ),
		array_map( function ( $d ) { $d['why'] = 'общий городской телефон ' . pcb_cl_show_id( 'phone', $d['v'] ); return $d; }, (array) $pp ) );
	$seen = array();
	foreach ( $pd as $d ) {
		$a = pcb_cl_contact( $d['a'] );
		$b = pcb_cl_contact( $d['b'] );
		if ( ! $a || ! $b || $a['id'] === $b['id'] ) {
			continue;
		}
		$pair = 'pmerge:' . min( $a['id'], $b['id'] ) . ':' . max( $a['id'], $b['id'] );
		if ( isset( $seen[ $pair ] ) || pcb_cl_skipped( $pair ) ) {
			continue;
		}
		$seen[ $pair ] = true;
		$out[]         = array( 'type' => 'pmerge', 'pair' => $pair, 'a' => (int) min( $a['id'], $b['id'] ), 'b' => (int) max( $a['id'], $b['id'] ), 'why' => $d['why'] );
	}
	return $out;
}

function pcb_cl_queue_count() {
	static $n = null;
	return null === $n ? ( $n = count( pcb_cl_queue() ) ) : $n;
}
