<?php
/**
 * Компании и контактные лица: чтение, создание, правка, идентификаторы, привязка заявок, объединение.
 * Все изменения пишутся в общий журнал (pcb_cl_log, entity = company:<id> | contact:<id>).
 * Права здесь не проверяются — это делает REST (includes/clients/rest.php).
 */

defined( 'ABSPATH' ) || exit;

/** Публичные почтовые домены: по ним компанию не определяем. */
function pcb_cl_public_domains() {
	return array(
		'gmail.com', 'googlemail.com', 'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'internet.ru', 'yandex.ru', 'ya.ru',
		'yandex.com', 'yandex.by', 'yandex.kz', 'rambler.ru', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com',
		'me.com', 'yahoo.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'zoho.com', 'aol.com', 'qq.com', '163.com',
	);
}

/** Корпоративный домен почты или '' для публичных. */
function pcb_cl_email_domain( $email ) {
	$email = strtolower( trim( (string) $email ) );
	$at    = strrpos( $email, '@' );
	if ( false === $at ) {
		return '';
	}
	$d = substr( $email, $at + 1 );
	return in_array( $d, pcb_cl_public_domains(), true ) ? '' : $d;
}

/** Ключ ФИО: регистр, ё/е и порядок слов не важны («Петров Иван» = «иван петров»). */
function pcb_cl_person_key( $name ) {
	$v     = str_replace( 'ё', 'е', mb_strtolower( trim( (string) $name ) ) );
	$words = array_filter( preg_split( '/[^\p{L}]+/u', $v ) );
	sort( $words );
	return implode( ' ', $words );
}

/* ---------- чтение ---------- */

function pcb_cl_get( $type, $id ) {
	global $wpdb;
	$t   = pcb_cl_table( 'company' === $type ? 'companies' : 'contacts' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
	// влитая запись — ведём на ту, куда влили
	for ( $i = 0; $row && $row['merged_into'] && $i < 5; $i++ ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $row['merged_into'] ), ARRAY_A );
	}
	return $row ?: null;
}
function pcb_cl_company( $id ) { return pcb_cl_get( 'company', $id ); }
function pcb_cl_contact( $id ) { return pcb_cl_get( 'contact', $id ); }

/** Идентификаторы записи: array( kind => array( value, … ) ). */
function pcb_cl_ids_of( $type, $id ) {
	global $wpdb;
	$col  = 'company' === $type ? 'company_id' : 'contact_id';
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, value FROM ' . pcb_cl_table( 'client_ids' ) . " WHERE {$col} = %d ORDER BY id", $id ), ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['kind'] ][] = $r['value'];
	}
	return $out;
}

/** Кто владеет идентификатором: array( contact_id, company_id ) первой подходящей живой записи или null. */
function pcb_cl_find( $kind, $value, $target = 'contact' ) {
	global $wpdb;
	if ( '' === (string) $value ) {
		return 0;
	}
	$col = 'company' === $target ? 'company_id' : 'contact_id';
	$id  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ' . $col . ' FROM ' . pcb_cl_table( 'client_ids' ) . " WHERE kind = %s AND value = %s AND {$col} > 0 ORDER BY id LIMIT 1", $kind, $value ) );
	if ( ! $id ) {
		return 0;
	}
	$row = pcb_cl_get( $target, $id );
	return $row ? (int) $row['id'] : 0;
}

/** Заявки клиента (ID записей). */
function pcb_cl_requests( $type, $id ) {
	return get_posts( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => 'company' === $type ? '_pcb_company_id' : '_pcb_contact_id',
		'meta_value'     => (int) $id,
	) );
}

/** Ссылка на карточку клиента в панели. */
function pcb_cl_url( $type, $id ) {
	return pcb_panel_url( 'crm' ) . ( 'company' === $type ? 'company' : 'contact' ) . '/' . (int) $id . '/';
}

/* ---------- идентификаторы ---------- */

function pcb_cl_ids_add( $kind, $value, $contact_id = 0, $company_id = 0 ) {
	global $wpdb;
	$value = (string) $value;
	if ( '' === $value ) {
		return;
	}
	$t      = pcb_cl_table( 'client_ids' );
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE kind = %s AND value = %s AND contact_id = %d AND company_id = %d", $kind, $value, $contact_id, $company_id ) );
	if ( ! $exists ) {
		$wpdb->insert( $t, array( 'kind' => $kind, 'value' => $value, 'contact_id' => (int) $contact_id, 'company_id' => (int) $company_id ) );
	}
}

function pcb_cl_ids_remove( $kind, $value, $contact_id = 0, $company_id = 0 ) {
	global $wpdb;
	$wpdb->delete( pcb_cl_table( 'client_ids' ), array( 'kind' => $kind, 'value' => (string) $value, 'contact_id' => (int) $contact_id, 'company_id' => (int) $company_id ) );
}

/** Почта и телефон контакта (в виде для сравнения) + корпоративный домен — компании. */
function pcb_cl_contact_add_channels( $contact_id, $email, $phone ) {
	$email = strtolower( trim( (string) $email ) );
	$phone = pcb_crm_phone_digits( $phone );
	if ( '' !== $email ) {
		pcb_cl_ids_add( 'email', $email, $contact_id );
	}
	if ( strlen( $phone ) >= 7 ) {
		pcb_cl_ids_add( 'phone', $phone, $contact_id ); // городской может повторяться у разных контактов — это нормально
	}
	$c = pcb_cl_contact( $contact_id );
	if ( $c && $c['company_id'] && '' !== ( $d = pcb_cl_email_domain( $email ) ) ) {
		pcb_cl_ids_add( 'domain', $d, 0, (int) $c['company_id'] );
	}
}

/* ---------- компании ---------- */

function pcb_cl_company_create( array $vals, $request_id = 0, $manual = false ) {
	global $wpdb;
	$clean = pcb_cl_clean_company( $vals );
	$v     = $clean['values'];
	$now   = current_time( 'mysql' );
	$row   = array_merge(
		array_fill_keys( array_keys( pcb_cl_company_fields() ), '' ),
		$v,
		array( 'name_key' => pcb_crm_company_key( $v['name'] ?? '' ), 'created' => $now, 'updated' => $now, 'verified' => 0 )
	);
	$wpdb->insert( pcb_cl_table( 'companies' ), $row );
	$id = (int) $wpdb->insert_id;
	if ( '' !== $row['inn'] && empty( $clean['errors']['inn'] ) ) {
		pcb_cl_ids_add( 'inn', $row['inn'], 0, $id );
	}
	pcb_cl_log( 'company:' . $id, 'cl_created', $manual ? 'manual' : '', $row['name'], $request_id );
	return $id;
}

/** Правка реквизитов: true или WP_Error со списком ошибок по полям (data.fields). */
function pcb_cl_company_update( $id, array $in ) {
	global $wpdb;
	$row = pcb_cl_company( $id );
	if ( ! $row ) {
		return new WP_Error( 'pcb_cl', 'Компания не найдена.', array( 'status' => 404 ) );
	}
	$id    = (int) $row['id'];
	$clean = pcb_cl_clean_company( $in );
	if ( $clean['errors'] ) {
		return new WP_Error( 'pcb_cl', implode( ' ', $clean['errors'] ), array( 'status' => 400, 'fields' => $clean['errors'] ) );
	}
	$fields = pcb_cl_company_fields();
	$set    = array();
	foreach ( $clean['values'] as $k => $v ) {
		if ( (string) $row[ $k ] !== $v ) {
			$set[ $k ] = $v;
			pcb_cl_log( 'company:' . $id, 'cl_field', $k, wp_json_encode( array( 'field' => $k, 'label' => $fields[ $k ][0], 'old' => $row[ $k ], 'new' => $v ), JSON_UNESCAPED_UNICODE ) );
		}
	}
	if ( array_key_exists( 'verified', $in ) && (int) (bool) $in['verified'] !== (int) $row['verified'] ) {
		$set['verified'] = (int) (bool) $in['verified'];
		pcb_cl_log( 'company:' . $id, 'cl_field', 'verified', wp_json_encode( array( 'field' => 'verified', 'label' => 'Реквизиты проверены', 'old' => $row['verified'] ? 'да' : 'нет', 'new' => $set['verified'] ? 'да' : 'нет' ), JSON_UNESCAPED_UNICODE ) );
	}
	if ( ! $set ) {
		return true;
	}
	if ( isset( $set['name'] ) ) {
		$set['name_key'] = pcb_crm_company_key( $set['name'] );
	}
	if ( isset( $set['inn'] ) ) {
		pcb_cl_ids_remove( 'inn', $row['inn'], 0, $id );
		if ( '' !== $set['inn'] ) {
			$other = pcb_cl_find( 'inn', $set['inn'], 'company' );
			if ( $other && $other !== $id ) {
				return new WP_Error( 'pcb_cl', 'Компания с таким ИНН уже есть — объедините их.', array( 'status' => 409, 'company' => $other ) );
			}
			pcb_cl_ids_add( 'inn', $set['inn'], 0, $id );
		}
	}
	$set['updated'] = current_time( 'mysql' );
	$wpdb->update( pcb_cl_table( 'companies' ), $set, array( 'id' => $id ) );
	return true;
}

/* ---------- контакты ---------- */

function pcb_cl_contact_create( $name, $company_id, $email, $phone, $request_id = 0, $manual = false, $post = '' ) {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert( pcb_cl_table( 'contacts' ), array(
		'company_id' => (int) $company_id,
		'name'       => (string) $name,
		'post'       => (string) $post,
		'note'       => '',
		'name_key'   => pcb_cl_person_key( $name ),
		'created'    => $now,
		'updated'    => $now,
	) );
	$id = (int) $wpdb->insert_id;
	pcb_cl_contact_add_channels( $id, $email, $phone );
	pcb_cl_log( 'contact:' . $id, 'cl_created', $manual ? 'manual' : '', '' !== (string) $name ? $name : (string) $email, $request_id );
	return $id;
}

/**
 * Новый контакт вручную (без заявки): ФИО обязательно, нужна почта или телефон.
 * Почта или мобильный уже у другого контакта — не создаём дубль, возвращаем ссылку на существующий.
 */
function pcb_cl_contact_create_manual( array $in ) {
	$name = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ) );
	$post = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $in['post'] ?? '' ) ) ) );
	$co   = (int) ( $in['company_id'] ?? 0 );
	$errs = array();
	if ( mb_strlen( $name ) < 2 ) {
		$errs['name'] = 'Укажите ФИО.';
	}
	$email = pcb_crm_norm_email( (string) ( $in['email'] ?? '' ) );
	if ( is_wp_error( $email ) ) {
		$errs['email'] = $email->get_error_message();
		$email         = '';
	}
	$phone = pcb_crm_norm_phone( (string) ( $in['phone'] ?? '' ) );
	if ( is_wp_error( $phone ) ) {
		$errs['phone'] = $phone->get_error_message();
		$phone         = '';
	}
	if ( ! $errs && '' === $email && '' === $phone ) {
		$errs['email'] = 'Нужна почта или телефон — по ним к контакту привязываются заявки.';
	}
	if ( $co && ! pcb_cl_company( $co ) ) {
		$errs['company_id'] = 'Компания не найдена.';
	}
	if ( $errs ) {
		return new WP_Error( 'pcb_cl', implode( ' ', $errs ), array( 'status' => 400, 'fields' => $errs ) );
	}
	$digits = pcb_crm_phone_digits( $phone );
	$dup    = '' !== $email ? pcb_cl_find( 'email', $email ) : 0;
	if ( ! $dup && pcb_cl_is_mobile( $digits ) ) {
		$dup = pcb_cl_find( 'phone', $digits );
	}
	if ( $dup ) {
		$what = '' !== $email && pcb_cl_find( 'email', $email ) ? 'с такой почтой' : 'с таким мобильным';
		return new WP_Error( 'pcb_cl', 'Контакт ' . $what . ' уже есть.', array( 'status' => 409, 'redirect' => pcb_cl_url( 'contact', $dup ) ) );
	}
	$co = $co ? (int) pcb_cl_company( $co )['id'] : 0;
	return pcb_cl_contact_create( $name, $co, $email, $phone, 0, true, $post );
}

/** Правка контакта: name, post, note, company_id. */
function pcb_cl_contact_update( $id, array $in ) {
	global $wpdb;
	$row = pcb_cl_contact( $id );
	if ( ! $row ) {
		return new WP_Error( 'pcb_cl', 'Контакт не найден.', array( 'status' => 404 ) );
	}
	$id     = (int) $row['id'];
	$labels = array( 'name' => 'ФИО', 'post' => 'Должность', 'note' => 'Комментарий', 'company_id' => 'Компания' );
	$set    = array();
	foreach ( $labels as $k => $label ) {
		if ( ! array_key_exists( $k, $in ) ) {
			continue;
		}
		$v = 'company_id' === $k ? (int) $in[ $k ] : ( 'note' === $k ? trim( sanitize_textarea_field( (string) $in[ $k ] ) ) : trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $in[ $k ] ) ) ) );
		if ( 'company_id' === $k && $v && ! pcb_cl_company( $v ) ) {
			return new WP_Error( 'pcb_cl', 'Компания не найдена.', array( 'status' => 404 ) );
		}
		if ( (string) $row[ $k ] !== (string) $v ) {
			$set[ $k ] = $v;
			$show      = function ( $x ) use ( $k ) { if ( 'company_id' !== $k ) { return $x; } $c = $x ? pcb_cl_company( $x ) : null; return $c ? $c['name'] : ''; };
			pcb_cl_log( 'contact:' . $id, 'cl_field', $k, wp_json_encode( array( 'field' => $k, 'label' => $label, 'old' => $show( $row[ $k ] ), 'new' => $show( $v ) ), JSON_UNESCAPED_UNICODE ) );
		}
	}
	if ( ! $set ) {
		return true;
	}
	if ( isset( $set['name'] ) ) {
		$set['name_key'] = pcb_cl_person_key( $set['name'] );
	}
	$set['updated'] = current_time( 'mysql' );
	$wpdb->update( pcb_cl_table( 'contacts' ), $set, array( 'id' => $id ) );
	if ( array_key_exists( 'company_id', $set ) ) {
		// заявки контакта переходят вместе с ним; корпоративный домен — новой компании
		foreach ( pcb_cl_requests( 'contact', $id ) as $rid ) {
			update_post_meta( $rid, '_pcb_company_id', (int) $set['company_id'] );
		}
		foreach ( pcb_cl_ids_of( 'contact', $id )['email'] ?? array() as $e ) {
			pcb_cl_contact_add_channels( $id, $e, '' );
		}
	}
	return true;
}

/** Добавить / убрать почту или телефон у контакта. */
function pcb_cl_contact_channel( $id, $kind, $raw, $remove = false ) {
	$row = pcb_cl_contact( $id );
	if ( ! $row ) {
		return new WP_Error( 'pcb_cl', 'Контакт не найден.', array( 'status' => 404 ) );
	}
	$id = (int) $row['id'];
	if ( 'email' === $kind ) {
		$v = pcb_crm_norm_email( $raw );
	} elseif ( 'phone' === $kind ) {
		$v = pcb_crm_norm_phone( $raw );
		$v = is_wp_error( $v ) ? $v : pcb_crm_phone_digits( $v );
	} else {
		return new WP_Error( 'pcb_cl', 'Неизвестный тип.', array( 'status' => 400 ) );
	}
	if ( is_wp_error( $v ) ) {
		return $v;
	}
	if ( '' === $v ) {
		return new WP_Error( 'pcb_cl', 'Пустое значение.', array( 'status' => 400 ) );
	}
	$label = 'email' === $kind ? 'Почта' : 'Телефон';
	if ( $remove ) {
		pcb_cl_ids_remove( $kind, $v, $id );
		pcb_cl_log( 'contact:' . $id, 'cl_field', $kind, wp_json_encode( array( 'field' => $kind, 'label' => $label, 'old' => pcb_cl_show_id( $kind, $v ), 'new' => '' ), JSON_UNESCAPED_UNICODE ) );
		return true;
	}
	$owner = pcb_cl_find( $kind, $v );
	if ( $owner && $owner !== $id && ( 'email' === $kind || pcb_cl_is_mobile( $v ) ) ) {
		return new WP_Error( 'pcb_cl', 'Этот ' . mb_strtolower( $label ) . ' уже у другого контакта — объедините их.', array( 'status' => 409, 'contact' => $owner ) );
	}
	if ( 'email' === $kind ) {
		pcb_cl_contact_add_channels( $id, $v, '' );
	} else {
		pcb_cl_ids_add( 'phone', $v, $id );
	}
	pcb_cl_log( 'contact:' . $id, 'cl_field', $kind, wp_json_encode( array( 'field' => $kind, 'label' => $label, 'old' => '', 'new' => pcb_cl_show_id( $kind, $v ) ), JSON_UNESCAPED_UNICODE ) );
	return true;
}

/** Идентификатор в читаемом виде: телефон из цифр → +7 912 345-67-89. */
function pcb_cl_show_id( $kind, $v ) {
	if ( 'phone' === $kind ) {
		$p = pcb_crm_norm_phone( ( 11 === strlen( $v ) && '7' === $v[0] ) ? '+' . $v : '+' . $v );
		return is_wp_error( $p ) ? $v : $p;
	}
	return $v;
}

/* ---------- заявки ---------- */

/** Привязать заявку к контакту и компании. $how — 'auto' | 'manual'. */
function pcb_cl_link_request( $rid, $contact_id, $company_id, $how = 'manual' ) {
	$old_c = (int) get_post_meta( $rid, '_pcb_contact_id', true );
	$old_o = (int) get_post_meta( $rid, '_pcb_company_id', true );
	update_post_meta( $rid, '_pcb_contact_id', (int) $contact_id );
	update_post_meta( $rid, '_pcb_company_id', (int) $company_id );
	update_post_meta( $rid, '_pcb_client_link', $how );
	if ( $old_c !== (int) $contact_id || $old_o !== (int) $company_id ) {
		$c = $contact_id ? pcb_cl_contact( $contact_id ) : null;
		$o = $company_id ? pcb_cl_company( $company_id ) : null;
		$t = trim( ( $c ? ( $c['name'] ?: 'контакт #' . $c['id'] ) : '' ) . ( $o ? ' · ' . $o['name'] : '' ), ' ·' );
		pcb_crm_log( $rid, 'client_link', 'auto' === $how ? 'auto' : '', $t, 'auto' === $how ? 0 : null );
	}
}

/* ---------- объединение ---------- */

/**
 * Влить компанию $src в $dst: пустые поля $dst заполняются из $src, контакты, идентификаторы и заявки
 * переходят к $dst; $src помечается merged_into.
 */
function pcb_cl_merge_company( $src, $dst ) {
	global $wpdb;
	$a = pcb_cl_company( $src );
	$b = pcb_cl_company( $dst );
	if ( ! $a || ! $b || (int) $a['id'] === (int) $b['id'] ) {
		return new WP_Error( 'pcb_cl', 'Нечего объединять.', array( 'status' => 400 ) );
	}
	$src  = (int) $a['id'];
	$dst  = (int) $b['id'];
	$fill = array();
	foreach ( array_keys( pcb_cl_company_fields() ) as $k ) {
		if ( '' === (string) $b[ $k ] && '' !== (string) $a[ $k ] ) {
			$fill[ $k ] = $a[ $k ];
		}
	}
	if ( $fill ) {
		$wpdb->update( pcb_cl_table( 'companies' ), $fill + array( 'updated' => current_time( 'mysql' ) ), array( 'id' => $dst ) );
	}
	$wpdb->update( pcb_cl_table( 'contacts' ), array( 'company_id' => $dst ), array( 'company_id' => $src ) );
	$wpdb->update( pcb_cl_table( 'client_ids' ), array( 'company_id' => $dst ), array( 'company_id' => $src ) );
	foreach ( pcb_cl_requests( 'company', $src ) as $rid ) {
		update_post_meta( $rid, '_pcb_company_id', $dst );
	}
	$wpdb->update( pcb_cl_table( 'companies' ), array( 'merged_into' => $dst ), array( 'id' => $src ) );
	pcb_cl_log( 'company:' . $dst, 'cl_merge', (string) $src, 'влита «' . $a['name'] . '»' . ( $fill ? ', дополнены поля: ' . implode( ', ', array_map( function ( $k ) { return pcb_cl_company_fields()[ $k ][0]; }, array_keys( $fill ) ) ) : '' ) );
	pcb_cl_log( 'company:' . $src, 'cl_merge', (string) $dst, 'влита в «' . $b['name'] . '»' );
	return $dst;
}

function pcb_cl_merge_contact( $src, $dst ) {
	global $wpdb;
	$a = pcb_cl_contact( $src );
	$b = pcb_cl_contact( $dst );
	if ( ! $a || ! $b || (int) $a['id'] === (int) $b['id'] ) {
		return new WP_Error( 'pcb_cl', 'Нечего объединять.', array( 'status' => 400 ) );
	}
	$src  = (int) $a['id'];
	$dst  = (int) $b['id'];
	$fill = array();
	foreach ( array( 'name', 'post', 'company_id' ) as $k ) {
		if ( ! $b[ $k ] && $a[ $k ] ) {
			$fill[ $k ] = $a[ $k ];
		}
	}
	if ( isset( $fill['name'] ) ) {
		$fill['name_key'] = pcb_cl_person_key( $fill['name'] );
	}
	if ( $fill ) {
		$wpdb->update( pcb_cl_table( 'contacts' ), $fill + array( 'updated' => current_time( 'mysql' ) ), array( 'id' => $dst ) );
	}
	$wpdb->update( pcb_cl_table( 'client_ids' ), array( 'contact_id' => $dst ), array( 'contact_id' => $src ) );
	$company = (int) ( $fill['company_id'] ?? $b['company_id'] );
	foreach ( pcb_cl_requests( 'contact', $src ) as $rid ) {
		update_post_meta( $rid, '_pcb_contact_id', $dst );
		update_post_meta( $rid, '_pcb_company_id', $company );
	}
	$wpdb->update( pcb_cl_table( 'contacts' ), array( 'merged_into' => $dst ), array( 'id' => $src ) );
	pcb_cl_log( 'contact:' . $dst, 'cl_merge', (string) $src, 'влит «' . ( $a['name'] ?: '#' . $src ) . '»' );
	pcb_cl_log( 'contact:' . $src, 'cl_merge', (string) $dst, 'влит в «' . ( $b['name'] ?: '#' . $dst ) . '»' );
	return $dst;
}

/* ---------- списки ---------- */

function pcb_cl_list( $type, $q = '', $limit = 100 ) {
	global $wpdb;
	$t     = pcb_cl_table( 'company' === $type ? 'companies' : 'contacts' );
	$where = 'merged_into = 0';
	$args  = array();
	if ( '' !== $q ) {
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		if ( 'company' === $type ) {
			$where .= ' AND (name LIKE %s OR full_name LIKE %s OR inn LIKE %s)';
			$args   = array( $like, $like, $like );
		} else {
			$where .= ' AND (name LIKE %s OR id IN (SELECT contact_id FROM ' . pcb_cl_table( 'client_ids' ) . ' WHERE value LIKE %s))';
			$args   = array( $like, '%' . $wpdb->esc_like( pcb_crm_phone_digits( $q ) ?: strtolower( $q ) ) . '%' );
		}
	}
	$sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY updated DESC LIMIT " . (int) $limit;
	return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
}
