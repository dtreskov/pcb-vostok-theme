<?php
/**
 * Автозаполнение реквизитов по ИНН через DaData — ТОЛЬКО для организаций (ИНН из 10 цифр).
 * Сведения об организациях — открытые данные ЕГРЮЛ. ИНН из 12 цифр (ИП) в DaData не отправляем:
 * это персональные данные, их реквизиты вводятся вручную.
 *
 * Ключ — константа PCB_DADATA_TOKEN в wp-config.php. Без ключа кнопка «Заполнить по ИНН» не показывается.
 * Запрос: POST https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party, тело {"query": ИНН, "branch_type": "MAIN"}.
 * Ответ кешируется на сутки. Данные не сохраняются сами — подставляются в форму, сохраняет человек.
 */

defined( 'ABSPATH' ) || exit;

function pcb_cl_dadata_enabled() {
	return '' !== pcb_rq_const( 'PCB_DADATA_TOKEN' );
}

/** Реквизиты по ИНН организации: array( поле => значение ) или WP_Error. */
function pcb_cl_dadata_party( $inn ) {
	$inn = pcb_cl_digits_only( $inn );
	if ( 10 !== strlen( $inn ) ) {
		return new WP_Error( 'pcb_cl', 'Автозаполнение — только для организаций (ИНН из 10 цифр). Реквизиты ИП введите вручную.', array( 'status' => 400 ) );
	}
	if ( ! pcb_cl_check_inn( $inn ) ) {
		return new WP_Error( 'pcb_cl', 'ИНН не проходит проверку — проверьте цифры.', array( 'status' => 400 ) );
	}
	if ( ! pcb_cl_dadata_enabled() ) {
		return new WP_Error( 'pcb_cl', 'Автозаполнение не настроено: нет PCB_DADATA_TOKEN в wp-config.php.', array( 'status' => 501 ) );
	}
	$cache = get_transient( 'pcb_dadata_' . $inn );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$res = wp_remote_post( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party', array(
		'timeout' => 8,
		'headers' => array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Token ' . PCB_DADATA_TOKEN,
		),
		'body'    => wp_json_encode( array( 'query' => $inn, 'branch_type' => 'MAIN' ) ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'pcb_cl', 'DaData не ответила: ' . $res->get_error_message(), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code ) {
		return new WP_Error( 'pcb_cl', 'DaData вернула ошибку ' . $code . ( 401 === $code || 403 === $code ? ' — проверьте ключ PCB_DADATA_TOKEN.' : '.' ), array( 'status' => 502 ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$d    = $body['suggestions'][0]['data'] ?? null;
	if ( ! is_array( $d ) ) {
		return new WP_Error( 'pcb_cl', 'По этому ИНН организация не найдена.', array( 'status' => 404 ) );
	}
	$g   = function ( $path ) use ( $d ) {
		$v = $d;
		foreach ( explode( '.', $path ) as $p ) {
			if ( ! is_array( $v ) || ! isset( $v[ $p ] ) ) {
				return '';
			}
			$v = $v[ $p ];
		}
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	};
	$out = array(
		'name'       => $g( 'name.short_with_opf' ) ?: ( $body['suggestions'][0]['value'] ?? '' ),
		'full_name'  => $g( 'name.full_with_opf' ),
		'opf'        => $g( 'opf.short' ),
		'inn'        => $inn,
		'kpp'        => $g( 'kpp' ),
		'ogrn'       => $g( 'ogrn' ),
		'addr_legal' => $g( 'address.unrestricted_value' ) ?: $g( 'address.value' ),
		'head_name'  => $g( 'management.name' ),
		'head_post'  => $g( 'management.post' ),
		'_status'    => $g( 'state.status' ), // ACTIVE | LIQUIDATING | LIQUIDATED | BANKRUPT | REORGANIZING
	);
	set_transient( 'pcb_dadata_' . $inn, $out, DAY_IN_SECONDS );
	return $out;
}
