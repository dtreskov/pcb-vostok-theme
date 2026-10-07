<?php
/**
 * Собственные обезличенные события сайта.
 *
 * Хранятся только агрегаты: день + событие + значение + количество.
 * Ни IP, ни cookie, ни идентификаторов посетителя — это не персональные данные.
 *
 * Источники:
 *   браузер — POST /wp-json/pcb-panel/v1/e (navigator.sendBeacon из темы, assets/js/site-goals.js);
 *   сервер  — do_action( 'pcb_event', $event, $value ) из темы (ошибки формы заявки).
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_EVENTS_DB_VERSION', '1' );
define( 'PCB_EVENTS_KEEP_DAYS', 730 ); // 2 года

function pcb_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcb_events';
}

/**
 * Разрешённые события и значения. Всё остальное отбрасывается.
 * Значение — либо из списка, либо проходит регулярку.
 */
function pcb_events_whitelist() {
	return array(
		'consent'  => array( 'accept', 'necessary' ),
		'calc'     => '/^(all|layers:(1|2|4|6|8-12|14\+)|qty:(1-10|11-50|51-200|201-1000|1000\+)|finish:(hasl|enig|iag|enepig|flash|hard|other)|mat:(fr4|fr4tg|alu|pi|hf)|size:(0-50|50-100|100-200|200\+))$/',
		'calc_err' => array( 'zip', 'rar', 'format', 'ipc', 'xml' ),
		'form_err' => array( 'captcha', 'files', 'empty', 'rate', 'save' ),
	);
}

function pcb_events_valid( $event, $value ) {
	$w = pcb_events_whitelist();
	if ( ! isset( $w[ $event ] ) || ! is_string( $value ) || strlen( $value ) > 40 ) {
		return false;
	}
	return is_array( $w[ $event ] ) ? in_array( $value, $w[ $event ], true ) : (bool) preg_match( $w[ $event ], $value );
}

/* ---------- таблица ---------- */

function pcb_events_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = pcb_events_table();
	dbDelta( "CREATE TABLE {$t} (
		day date NOT NULL,
		event varchar(24) NOT NULL,
		val varchar(40) NOT NULL DEFAULT '',
		n int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,event,val)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'pcb_events_db_version', PCB_EVENTS_DB_VERSION, false );
}

add_action( 'init', function () {
	if ( get_option( 'pcb_events_db_version' ) !== PCB_EVENTS_DB_VERSION ) {
		pcb_events_install();
	}
}, 6 );

/* ---------- запись ---------- */

function pcb_events_add( $event, $value, $count = 1 ) {
	if ( ! pcb_events_valid( $event, $value ) ) {
		return false;
	}
	global $wpdb;
	$t = pcb_events_table();
	return false !== $wpdb->query( $wpdb->prepare(
		"INSERT INTO {$t} (day, event, val, n) VALUES (%s, %s, %s, %d) ON DUPLICATE KEY UPDATE n = n + VALUES(n)",
		wp_date( 'Y-m-d' ), $event, $value, max( 1, (int) $count )
	) );
}

// Серверные события из темы: do_action( 'pcb_event', 'form_err', 'captcha' ).
add_action( 'pcb_event', function ( $event, $value = '' ) {
	pcb_events_add( (string) $event, (string) $value );
}, 10, 2 );

/* ---------- приём из браузера ---------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'pcb-panel/v1', '/e', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // публичный приём, данные — только из белого списка
		'callback'            => 'pcb_events_collect',
	) );
} );

/**
 * Тело — JSON (sendBeacon шлёт его как text/plain, чтобы не было предварительного запроса):
 *   {"e": [["calc","layers:4"], ["calc","finish:enig"], ...]}
 */
function pcb_events_collect( WP_REST_Request $req ) {
	$body  = json_decode( (string) $req->get_body(), true );
	$items = ( is_array( $body ) && isset( $body['e'] ) && is_array( $body['e'] ) ) ? array_slice( $body['e'], 0, 12 ) : array();
	$seen  = array();
	foreach ( $items as $it ) {
		if ( ! is_array( $it ) || count( $it ) !== 2 ) {
			continue;
		}
		$key = $it[0] . '|' . $it[1];
		if ( isset( $seen[ $key ] ) ) {
			continue; // одно и то же значение в одном пакете считаем один раз
		}
		$seen[ $key ] = true;
		pcb_events_add( (string) $it[0], (string) $it[1] );
	}
	$res = new WP_REST_Response( null, 204 );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/* ---------- чтение ---------- */

/** Суммы по значениям события за период: array( val => n ). */
function pcb_events_sum( $event, $from, $to ) {
	global $wpdb;
	$t    = pcb_events_table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT val, SUM(n) AS n FROM {$t} WHERE event = %s AND day BETWEEN %s AND %s GROUP BY val",
		$event, $from, $to
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['val'] ] = (int) $r['n'];
	}
	return $out;
}

/** Значения события по дням: array( 'Y-m-d' => array( val => n ) ). */
function pcb_events_daily( $event, $from, $to ) {
	global $wpdb;
	$t    = pcb_events_table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, val, n FROM {$t} WHERE event = %s AND day BETWEEN %s AND %s",
		$event, $from, $to
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['day'] ][ $r['val'] ] = (int) $r['n'];
	}
	return $out;
}

/* ---------- очистка: раз в сутки удаляем старше 2 лет ---------- */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'pcb_events_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pcb_events_purge' );
	}
} );

add_action( 'pcb_events_purge', function () {
	global $wpdb;
	$t = pcb_events_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", wp_date( 'Y-m-d', time() - PCB_EVENTS_KEEP_DAYS * DAY_IN_SECONDS ) ) );
} );
