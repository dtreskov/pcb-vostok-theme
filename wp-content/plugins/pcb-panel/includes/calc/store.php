<?php
/**
 * Калькулятор: хранение формулы, черновика, версий, эталонов и курсов ЦБ.
 *
 * Опции (не автозагружаются, кроме опубликованной версии — она нужна каждому расчёту на сайте):
 *   pcb_calc_versions  — опубликованные версии [{v, params, note, user, time, rate, changes, deltas}], последняя — на сайте;
 *   pcb_calc_draft     — черновик {params, rev, user, time}; нет — черновик совпадает с сайтом;
 *   pcb_calc_etalons   — эталонные заказы [{id, n, in}];
 *   pcb_calc_rates     — курсы ЦБ {cny, usd, date, fetched, hist: [[дата, cny], …]}.
 * Пока версий нет, сайт считает по старой формуле темы (calculator.js) — см. pcb_calc_site_on().
 */

defined( 'ABSPATH' ) || exit;

const PCB_CALC_KEEP_VERSIONS = 60;

function pcb_calc_versions() {
	$v = get_option( 'pcb_calc_versions', array() );
	return is_array( $v ) ? $v : array();
}

/** Сайт считает на сервере только после первой публикации. */
function pcb_calc_site_on() {
	return (bool) pcb_calc_versions();
}

/** Опубликованная версия: {v, params, …}; до первой публикации — версия 0 со значениями по умолчанию. */
function pcb_calc_published() {
	$all = pcb_calc_versions();
	if ( ! $all ) {
		return array( 'v' => 0, 'params' => pcb_calc_defaults(), 'note' => '', 'user' => 0, 'time' => '', 'rate' => 0 );
	}
	$last           = end( $all );
	$last['params'] = pcb_calc_clean( $last['params'] ); // новые параметры из обновлений плагина — по умолчанию
	return $last;
}

/** Черновик: {params, rev, user, time}. */
function pcb_calc_draft() {
	$d = get_option( 'pcb_calc_draft' );
	if ( ! is_array( $d ) || ! isset( $d['params'] ) ) {
		return array( 'params' => pcb_calc_published()['params'], 'rev' => (int) get_option( 'pcb_calc_draft_rev', 0 ), 'user' => 0, 'time' => '' );
	}
	$d['params'] = pcb_calc_clean( $d['params'], pcb_calc_published()['params'] );
	return $d;
}

function pcb_calc_draft_save( array $params ) {
	$rev = (int) get_option( 'pcb_calc_draft_rev', 0 ) + 1;
	update_option( 'pcb_calc_draft_rev', $rev, false );
	update_option( 'pcb_calc_draft', array( 'params' => $params, 'rev' => $rev, 'user' => get_current_user_id(), 'time' => current_time( 'mysql' ) ), false );
	return $rev;
}

function pcb_calc_draft_drop() {
	delete_option( 'pcb_calc_draft' );
	$rev = (int) get_option( 'pcb_calc_draft_rev', 0 ) + 1;
	update_option( 'pcb_calc_draft_rev', $rev, false );
	return $rev;
}

/** Публикация черновика. Возвращает номер версии. */
function pcb_calc_publish( array $params, $note, array $deltas ) {
	$all     = pcb_calc_versions();
	$prev    = pcb_calc_published();
	$v       = (int) $prev['v'] + 1;
	$all[]   = array(
		'v'       => $v,
		'params'  => $params,
		'note'    => mb_substr( trim( wp_strip_all_tags( (string) $note ) ), 0, 200 ),
		'user'    => get_current_user_id(),
		'time'    => current_time( 'mysql' ),
		'rate'    => pcb_calc_fx( $params )['cny'],
		'changes' => $prev['v'] ? pcb_calc_diff( $prev['params'], $params ) : array( 'Первая публикация — сайт перешёл на расчёт по этой формуле' ),
		'deltas'  => $deltas,
	);
	$all = array_slice( $all, -PCB_CALC_KEEP_VERSIONS );
	update_option( 'pcb_calc_versions', $all, true );
	pcb_calc_draft_drop();
	pcb_cl_log( 'calc', 'calc_publish', (string) $prev['v'], wp_json_encode( array( 'v' => $v, 'note' => end( $all )['note'] ), JSON_UNESCAPED_UNICODE ) );
	return $v;
}

function pcb_calc_version( $v ) {
	foreach ( pcb_calc_versions() as $row ) {
		if ( (int) $row['v'] === (int) $v ) {
			return $row;
		}
	}
	return null;
}

/* ---------------- эталоны ---------------- */

function pcb_calc_etalons() {
	$e = get_option( 'pcb_calc_etalons' );
	return is_array( $e ) && $e ? $e : pcb_calc_default_etalons();
}

function pcb_calc_etalons_save( array $list ) {
	update_option( 'pcb_calc_etalons', array_values( $list ), false );
}

/* ---------------- курсы ЦБ ---------------- */

function pcb_calc_rates() {
	$r = get_option( 'pcb_calc_rates' );
	return is_array( $r ) ? $r : array();
}

/** Курсы для расчёта: закреплённые вручную или ЦБ; пока ЦБ не загружен — ручные значения. */
function pcb_calc_fx( array $p ) {
	$r = pcb_calc_rates();
	if ( empty( $p['rateFix'] ) && ! empty( $r['cny'] ) ) {
		return array( 'cny' => (float) $r['cny'], 'usd' => (float) ( $r['usd'] ?: $p['usd'] ), 'src' => 'cbr', 'date' => $r['date'] ?? '' );
	}
	return array( 'cny' => (float) $p['rate'], 'usd' => (float) $p['usd'], 'src' => empty( $p['rateFix'] ) ? 'none' : 'manual', 'date' => '' );
}

/**
 * Загрузка курсов с cbr.ru (XML_daily, кодировка windows-1251). Персональных данных в запросе нет.
 * Возвращает true или текст ошибки.
 */
function pcb_calc_rates_fetch() {
	$resp = wp_remote_get( 'https://www.cbr.ru/scripts/XML_daily.asp', array( 'timeout' => 15, 'user-agent' => 'Mozilla/5.0 (compatible; PCB-Vostok site)' ) );
	if ( is_wp_error( $resp ) ) {
		return 'ЦБ не ответил: ' . $resp->get_error_message();
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return 'ЦБ ответил кодом ' . (int) wp_remote_retrieve_response_code( $resp );
	}
	$body = (string) wp_remote_retrieve_body( $resp );
	if ( function_exists( 'mb_convert_encoding' ) && false !== stripos( $body, 'windows-1251' ) ) {
		$body = mb_convert_encoding( $body, 'UTF-8', 'Windows-1251' );
		$body = preg_replace( '/encoding="windows-1251"/i', 'encoding="UTF-8"', $body );
	}
	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( $body );
	if ( ! $xml ) {
		return 'Не удалось разобрать ответ ЦБ';
	}
	$get = function ( $code ) use ( $xml ) {
		foreach ( $xml->Valute as $v ) {
			if ( (string) $v->CharCode === $code ) {
				$val = (float) str_replace( ',', '.', (string) $v->Value );
				$nom = max( 1, (int) $v->Nominal );
				return $val / $nom;
			}
		}
		return 0;
	};
	$cny = $get( 'CNY' );
	$usd = $get( 'USD' );
	if ( $cny <= 0 ) {
		return 'В ответе ЦБ нет курса юаня';
	}
	$date = (string) $xml['Date']; // дд.мм.гггг
	$old  = pcb_calc_rates();
	$hist = isset( $old['hist'] ) && is_array( $old['hist'] ) ? $old['hist'] : array();
	if ( ! $hist || end( $hist )[0] !== $date ) {
		$hist[] = array( $date, round( $cny, 4 ) );
	}
	update_option( 'pcb_calc_rates', array(
		'cny'     => round( $cny, 4 ),
		'usd'     => round( $usd, 4 ),
		'date'    => $date,
		'fetched' => current_time( 'mysql' ),
		'hist'    => array_slice( $hist, -30 ),
	), false );
	return true;
}

// раз в сутки; первый раз — вскоре после обновления плагина
add_action( 'pcb_calc_rates', 'pcb_calc_rates_fetch' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'pcb_calc_rates' ) ) {
		wp_schedule_event( time() + 60, 'twicedaily', 'pcb_calc_rates' );
	}
} );
