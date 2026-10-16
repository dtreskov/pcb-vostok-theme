<?php
/**
 * Приведение контактов к единому виду (0.5.0): проверка ввода и ключи для поиска связанных заявок.
 *
 * Ключи сравнения хранятся в заявке отдельными метаполями и обновляются при поступлении и правке:
 *   _pcb_k_email   почта в нижнем регистре
 *   _pcb_k_phone   только цифры, российские номера — с 7 в начале (8 912… и 912… → 7912…)
 *   _pcb_k_company название без формы собственности, кавычек и регистра («ООО «Электрон-М»» → «электрон м»)
 * Эти же правила — основа будущей базы клиентов (README, §6).
 */

defined( 'ABSPATH' ) || exit;

/** Почта: true/WP_Error для проверки, значение — в нижнем регистре. */
function pcb_crm_norm_email( $raw ) {
	$v = strtolower( trim( (string) $raw ) );
	if ( '' === $v ) {
		return '';
	}
	if ( ! is_email( $v ) || ! preg_match( '/@[^@.\s]+(\.[^@.\s]+)*\.[a-zа-яё]{2,}$/u', $v ) ) {
		return new WP_Error( 'pcb_crm', 'Проверьте почту — нужен формат имя@компания.ru.', array( 'status' => 400 ) );
	}
	return $v;
}

/** Цифры телефона для сравнения; '' если цифр нет. */
function pcb_crm_phone_digits( $raw ) {
	$d = preg_replace( '/\D+/', '', (string) $raw );
	if ( 11 === strlen( $d ) && '8' === $d[0] ) {
		$d = '7' . substr( $d, 1 ); // 8 912 … → 7 912 …
	} elseif ( 10 === strlen( $d ) && '9' === $d[0] ) {
		$d = '7' . $d;              // 912 … без кода страны
	}
	return $d;
}

/**
 * Телефон: проверка и единый вид. Российский номер → «+7 912 345-67-89»,
 * иностранный (7–15 цифр с «+») → «+<цифры>». Пустой — допустим.
 */
function pcb_crm_norm_phone( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	if ( preg_match( '/[^\d\s()+\-.]/', $raw ) ) {
		return new WP_Error( 'pcb_crm', 'В телефоне допустимы только цифры, пробелы, скобки, дефисы и «+».', array( 'status' => 400 ) );
	}
	$d = pcb_crm_phone_digits( $raw );
	if ( 11 === strlen( $d ) && '7' === $d[0] ) {
		return sprintf( '+7 %s %s-%s-%s', substr( $d, 1, 3 ), substr( $d, 4, 3 ), substr( $d, 7, 2 ), substr( $d, 9, 2 ) );
	}
	if ( strlen( $d ) >= 7 && strlen( $d ) <= 15 && 0 === strpos( ltrim( $raw ), '+' ) ) {
		return '+' . $d;
	}
	return new WP_Error( 'pcb_crm', 'Проверьте телефон: российский номер — 10 цифр после +7 или 8, иностранный — с «+» и кодом страны.', array( 'status' => 400 ) );
}

/** Ключ компании: без формы собственности, кавычек, знаков и регистра. */
function pcb_crm_company_key( $raw ) {
	$v = mb_strtolower( trim( (string) $raw ) );
	$v = str_replace( array( '«', '»', '"', '“', '”', '„', "'", '`', 'ё' ), array( ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', 'е' ), $v );
	$v = preg_replace( '/(^|\s)(ооо|оао|зао|пао|ао|нао|ип|фгуп|фгбу|фгбоу|гуп|муп|ано|нко|ltd|llc|inc|gmbh|co)(\.|\s|$)/u', ' ', $v );
	$v = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $v );
	return trim( preg_replace( '/\s+/u', ' ', $v ) );
}

/** Пересчитать ключи сравнения заявки — вызывать после любой смены контактов. */
function pcb_crm_reindex( $id ) {
	update_post_meta( $id, '_pcb_k_email', strtolower( trim( (string) get_post_meta( $id, '_pcb_email', true ) ) ) );
	update_post_meta( $id, '_pcb_k_phone', pcb_crm_phone_digits( get_post_meta( $id, '_pcb_phone', true ) ) );
	update_post_meta( $id, '_pcb_k_company', pcb_crm_company_key( get_post_meta( $id, '_pcb_company', true ) ) );
}

/**
 * Связанные заявки: совпадает почта, телефон или компания (по ключам).
 * Возвращает array( id => array( 'почта', 'телефон', … ) ) — по каким признакам связаны.
 */
function pcb_crm_related( $id, $limit = 20 ) {
	$keys = array(
		'_pcb_k_email'   => 'почта',
		'_pcb_k_phone'   => 'телефон',
		'_pcb_k_company' => 'компания',
	);
	$out = array();
	foreach ( $keys as $key => $label ) {
		$v = (string) get_post_meta( $id, $key, true );
		if ( '' === $v || ( '_pcb_k_phone' === $key && strlen( $v ) < 7 ) ) {
			continue;
		}
		$ids = get_posts( array(
			'post_type'      => 'pcb_request',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => array( $id ),
			'meta_key'       => $key,
			'meta_value'     => $v,
		) );
		foreach ( $ids as $rid ) {
			$out[ $rid ][] = $label;
		}
	}
	krsort( $out );
	return array_slice( $out, 0, $limit, true );
}
