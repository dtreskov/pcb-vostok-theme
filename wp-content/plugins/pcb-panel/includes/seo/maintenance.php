<?php
/**
 * Режим техработ (заменяет плагин Under Construction & Maintenance Mode).
 *
 * Включён — посетители видят заглушку с кодом 503 и Retry-After: поисковики понимают, что сайт закрыт
 * временно, и не выкидывают страницы из индекса из-за «пустой» заглушки.
 * Сайт видят как есть: пользователи панели (вошедшие) и те, кто открыл ссылку предпросмотра
 * (?pcb_preview=<ключ> → cookie на 7 дней; новый ключ отключает старые ссылки).
 * Не перехватываются: /panel, вход и консоль WordPress, REST (форма заявки), robots.txt, карта сайта.
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_MAINT_LEGACY', 'under-construction-maintenance-mode/under-construction-maintenance-mode.php' );

function pcb_maint_defaults() {
	return array(
		'on'    => false,
		'title' => 'Сайт обновляется',
		'text'  => "Готовим новую версию сайта PCB Восток.\nПока идут работы, присылайте задачи на почту или звоните — рассчитаем проект и ответим на вопросы.",
		'sign'  => null, // подпись под текстом; null — почта и телефон из «Данных сайта»
		'key'   => '',
		'since' => '',
		'by'    => 0,
	);
}

function pcb_maint_get( $key = null ) {
	$all = array_merge( pcb_maint_defaults(), (array) get_option( 'pcb_maintenance', array() ) );
	return null === $key ? $all : ( $all[ $key ] ?? '' );
}

/** Подпись под текстом заглушки: свои строки или (по умолчанию) почта и телефон из «Данных сайта». */
function pcb_maint_sign() {
	$sign = pcb_maint_get( 'sign' );
	if ( null === $sign ) {
		$sign = implode( "\n", array_filter( array( pcb_seo_get( 'email' ), pcb_seo_get( 'phone' ) ) ) );
	}
	return (string) $sign;
}

/**
 * Строки подписи для вывода: почта → mailto, телефон → tel, остальное — текст.
 * Возвращает [ [ 'text' => …, 'href' => … ], … ].
 */
function pcb_maint_sign_lines() {
	$out = array();
	foreach ( preg_split( '/\R/', pcb_maint_sign() ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$href = '';
		if ( is_email( $line ) ) {
			$href = 'mailto:' . $line;
		} elseif ( preg_match( '/^\+?[\d\s()\-]{7,}$/', $line ) && strlen( preg_replace( '/\D/', '', $line ) ) >= 7 ) {
			$href = 'tel:+' . pcb_crm_phone_digits( $line );
		}
		$out[] = array( 'text' => $line, 'href' => $href );
	}
	return $out;
}

function pcb_maint_on() {
	return (bool) pcb_maint_get( 'on' );
}

function pcb_maint_update( array $vals ) {
	$all = array_merge( pcb_maint_get(), $vals );
	update_option( 'pcb_maintenance', array_intersect_key( $all, pcb_maint_defaults() ), false );
}

/** Ключ ссылки предпросмотра (создаётся при первом обращении). */
function pcb_maint_key( $renew = false ) {
	$key = (string) pcb_maint_get( 'key' );
	if ( $renew || '' === $key ) {
		$key = wp_generate_password( 20, false, false );
		pcb_maint_update( array( 'key' => $key ) );
	}
	return $key;
}

function pcb_maint_preview_url() {
	return add_query_arg( 'pcb_preview', pcb_maint_key(), home_url( '/' ) );
}

/** Включён ли ещё старый плагин заглушки. */
function pcb_maint_legacy_active() {
	return in_array( PCB_MAINT_LEGACY, (array) get_option( 'active_plugins', array() ), true );
}

/**
 * Переход с плагина Under Construction: если он включён при обновлении панели — включаем свой режим,
 * чтобы после отключения плагина сайт не открылся раньше времени. Однократно.
 */
add_action( 'init', function () {
	if ( get_option( 'pcb_maint_migrated' ) ) {
		return;
	}
	update_option( 'pcb_maint_migrated', 1, false );
	if ( pcb_maint_legacy_active() && ! pcb_maint_on() ) {
		pcb_maint_update( array( 'on' => true, 'since' => current_time( 'mysql' ), 'by' => 0 ) );
		pcb_crm_log_seo( 'maint', 'Режим техработ', 'выключен', 'включён (перенос с плагина Under Construction)', 0 );
	}
}, 20 );

/** Включить/выключить: журнал и сброс кэша, чтобы закэшированные страницы не обошли заглушку. */
function pcb_maint_set( $on ) {
	$on  = (bool) $on;
	$was = pcb_maint_on();
	if ( $on === $was ) {
		return;
	}
	pcb_maint_update( array( 'on' => $on, 'since' => current_time( 'mysql' ), 'by' => get_current_user_id() ) );
	pcb_crm_log_seo( 'maint', 'Режим техработ', $was ? 'включён' : 'выключен', $on ? 'включён' : 'выключен' );
	pcb_seo_purge();
}

/** Пока режим включён, ни одна страница не кэшируется (иначе страница из предпросмотра ушла бы посетителям). */
add_action( 'init', function () {
	if ( pcb_maint_on() && ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
}, 1 );

function pcb_maint_cookie_ok() {
	$c = isset( $_COOKIE['pcb_preview'] ) ? (string) wp_unslash( $_COOKIE['pcb_preview'] ) : '';
	return '' !== $c && '' !== (string) pcb_maint_get( 'key' ) && hash_equals( wp_hash( pcb_maint_get( 'key' ), 'nonce' ), $c );
}

add_action( 'template_redirect', function () {
	if ( ! pcb_maint_on() ) {
		return;
	}
	// ссылка предпросмотра: ставим cookie и убираем ключ из адреса
	if ( isset( $_GET['pcb_preview'] ) ) {
		$given = (string) wp_unslash( $_GET['pcb_preview'] );
		if ( '' !== (string) pcb_maint_get( 'key' ) && hash_equals( (string) pcb_maint_get( 'key' ), $given ) ) {
			setcookie( 'pcb_preview', wp_hash( pcb_maint_get( 'key' ), 'nonce' ), array(
				'expires'  => time() + 7 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
			wp_safe_redirect( remove_query_arg( 'pcb_preview' ) );
			exit;
		}
	}
	if ( pcb_panel_is_request() || is_robots() || is_favicon() || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
		return;
	}
	if ( current_user_can( 'pcb_panel_access' ) || pcb_maint_cookie_ok() ) {
		return;
	}
	pcb_maint_render();
}, -10 );

function pcb_maint_render() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	status_header( 503 );
	header( 'Retry-After: 3600' );
	header( 'X-Robots-Tag: noindex', true );
	header( 'Content-Type: text/html; charset=utf-8' );
	include PCB_PANEL_DIR . 'templates/maintenance.php';
	exit;
}

/** Напоминание администратору в чёрной панели WordPress на сайте. */
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! pcb_maint_on() || ! current_user_can( 'pcb_panel_access' ) ) {
		return;
	}
	$bar->add_node( array(
		'id'    => 'pcb-maint',
		'title' => 'Техработы: сайт закрыт для посетителей',
		'href'  => pcb_panel_url( 'seo' ) . 'maintenance/',
		'meta'  => array( 'class' => 'pcb-maint-flag' ),
	) );
}, 100 );

add_action( 'wp_head', function () {
	if ( pcb_maint_on() && is_admin_bar_showing() ) {
		echo '<style>#wpadminbar .pcb-maint-flag>.ab-item{background:#A85C28;color:#fff}</style>';
	}
} );
