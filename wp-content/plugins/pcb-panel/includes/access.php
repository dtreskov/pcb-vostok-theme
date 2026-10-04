<?php
/**
 * Проверка доступа и вывод панели.
 * Всё решается на сервере ДО вывода страницы: скрытие вкладок в меню — не защита.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'pcb_panel_handle_request', 0 );

function pcb_panel_handle_request() {
	if ( ! pcb_panel_is_request() ) {
		return;
	}

	pcb_panel_private_headers();

	// Не вошёл — на форму входа WordPress, после входа вернёт на этот же адрес.
	if ( ! is_user_logged_in() ) {
		auth_redirect(); // завершает выполнение
	}

	if ( ! current_user_can( 'pcb_panel_access' ) ) {
		pcb_panel_render( 'forbidden', 403 );
	}

	$all     = pcb_panel_tabs();
	$allowed = pcb_panel_allowed_tabs();
	$slug    = sanitize_key( (string) get_query_var( 'pcb_panel_tab' ) );

	// /panel без вкладки — первая разрешённая.
	if ( '' === $slug ) {
		if ( ! $allowed ) {
			pcb_panel_render( 'forbidden', 403 );
		}
		wp_safe_redirect( pcb_panel_url( array_key_first( $allowed ) ), 302 );
		exit;
	}

	if ( ! isset( $all[ $slug ] ) ) {
		pcb_panel_render( 'notfound', 404 );
	}
	if ( ! isset( $allowed[ $slug ] ) ) {
		pcb_panel_render( 'forbidden', 403 );
	}

	pcb_panel_render( $slug, 200 );
}

/** Запрет кэширования и индексации — панель не должна попасть в кэш или поиск. */
function pcb_panel_private_headers() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // для плагинов кэша
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: same-origin', true );
	header( 'X-Frame-Options: SAMEORIGIN', true );
}

function pcb_panel_render( $view, $status = 200 ) {
	status_header( $status );
	$GLOBALS['pcb_panel_view'] = $view;
	include PCB_PANEL_DIR . 'templates/shell.php';
	exit;
}

/* ---------- Пользователи без прав администратора ---------- */

// Без чёрной панели WordPress на сайте.
add_filter( 'show_admin_bar', function ( $show ) {
	return current_user_can( 'manage_options' ) ? $show : false;
} );

// /wp-admin → /panel. Профиль оставлен открытым: там настраивается двухфакторный вход.
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || current_user_can( 'manage_options' ) || ! current_user_can( 'pcb_panel_access' ) ) {
		return;
	}
	global $pagenow;
	if ( in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
		return;
	}
	wp_safe_redirect( pcb_panel_url() );
	exit;
} );

// После входа менеджер попадает в панель, а не в консоль WordPress.
add_filter( 'login_redirect', function ( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User
		&& $user->has_cap( 'pcb_panel_access' )
		&& ! $user->has_cap( 'manage_options' )
		&& ( '' === $requested || admin_url() === $requested ) ) {
		return pcb_panel_url();
	}
	return $redirect_to;
}, 10, 3 );
