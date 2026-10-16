<?php
/**
 * Значок сайта (favicon) и иконки приложения — файлы в assets/icons/favicon/ (вариант A «Чип на тёмной плашке»).
 *
 * Значок задаётся темой, а не «Иконкой сайта» WordPress: так поддерживаются SVG и набор под браузеры,
 * Яндекс, Google, iOS и Android. Иконка сайта WordPress, если её когда-то задали, не выводится.
 * Тот же набор ссылок подключают панель /panel и заглушка техработ (плагин pcb-panel) — через pcb_theme_icon_links().
 */

defined( 'ABSPATH' ) || exit;

/** Адрес файла значка в теме или '' — если файла нет. */
function pcb_theme_icon_url( $file ) {
	$rel = 'assets/icons/favicon/' . $file;
	$path = get_theme_file_path( $rel );
	return file_exists( $path ) ? get_theme_file_uri( $rel ) . '?ver=' . filemtime( $path ) : '';
}

/** HTML ссылок на значки для <head>. */
function pcb_theme_icon_links() {
	$links = array(
		array( 'icon', 'favicon.ico', 'sizes="48x48"' ),
		array( 'icon', 'favicon.svg', 'type="image/svg+xml"' ),
		array( 'icon', 'favicon-96.png', 'type="image/png" sizes="96x96"' ),
		array( 'icon', 'icon-120.png', 'type="image/png" sizes="120x120"' ),
		array( 'apple-touch-icon', 'apple-touch-icon.png', 'sizes="180x180"' ),
		array( 'manifest', 'site.webmanifest', '' ),
	);
	$out = '';
	foreach ( $links as $l ) {
		$url = pcb_theme_icon_url( $l[1] );
		if ( '' !== $url ) {
			$out .= sprintf( '<link rel="%s" href="%s"%s>' . "\n", $l[0], esc_url( $url ), $l[2] ? ' ' . $l[2] : '' );
		}
	}
	return $out . '<meta name="theme-color" content="#0B2C22">' . "\n";
}

// вместо иконки сайта WordPress — свой набор
remove_action( 'wp_head', 'wp_site_icon', 99 );
add_action( 'wp_head', function () {
	echo pcb_theme_icon_links(); // phpcs:ignore -- адреса экранированы
}, 2 );
add_action( 'login_head', function () {
	echo pcb_theme_icon_links(); // phpcs:ignore
} );

// прямой запрос /favicon.ico (браузеры и поисковые роботы) — файл темы, а не значок WordPress
add_action( 'do_faviconico', function () {
	$path = get_theme_file_path( 'assets/icons/favicon/favicon.ico' );
	if ( ! file_exists( $path ) ) {
		return;
	}
	header( 'Content-Type: image/x-icon' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: public, max-age=604800' );
	readfile( $path );
	exit;
} );
