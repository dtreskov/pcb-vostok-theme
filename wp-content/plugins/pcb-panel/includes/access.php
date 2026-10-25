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

	// «Мой профиль» (подпись в письмах) — не вкладка, доступен всем пользователям панели
	if ( 'profile' === $slug ) {
		pcb_panel_render( 'profile', 200, '' );
	}

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

	$item = (string) get_query_var( 'pcb_panel_item' );
	if ( '' !== $item ) {
		pcb_panel_render_item( $slug, $item );
	}

	pcb_panel_render( $slug, 200 );
}

/** Вложенные адреса вкладок. Сейчас — только у CRM: карточка заявки и общий журнал. */
function pcb_panel_render_item( $slug, $item ) {
	// SEO: /panel/seo/<id страницы>/, /panel/seo/site|markup|semantic|cards|robots|maintenance/
	if ( 'seo' === $slug ) {
		if ( '' !== (string) get_query_var( 'pcb_panel_sub' ) ) {
			pcb_panel_render( 'notfound', 404, 'seo' );
		}
		if ( 'maintenance' === $item && isset( $_GET['pcb_maint_view'] ) ) { // превью заглушки для iframe
			include PCB_PANEL_DIR . 'templates/maintenance.php';
			exit;
		}
		if ( in_array( $item, array( 'site', 'markup', 'semantic', 'cards', 'robots', 'maintenance' ), true ) ) {
			pcb_panel_render( 'seo-' . $item, 200, 'seo' );
		}
		if ( ctype_digit( $item ) && pcb_seo_is_page( (int) $item ) ) {
			$GLOBALS['pcb_panel_seo_page'] = (int) $item;
			pcb_panel_render( 'seo-page', 200, 'seo' );
		}
		pcb_panel_render( 'notfound', 404, 'seo' );
	}
	if ( 'crm' !== $slug ) {
		pcb_panel_render( 'notfound', 404 );
	}
	// база клиентов: /panel/crm/clients/[queue/], /panel/crm/company/<id>/, /panel/crm/contact/<id>/
	if ( in_array( $item, array( 'clients', 'company', 'contact' ), true ) ) {
		$sub = (string) get_query_var( 'pcb_panel_sub' );
		if ( 'clients' === $item ) {
			pcb_panel_render( 'queue' === $sub ? 'crm-queue' : ( '' === $sub ? 'crm-clients' : 'notfound' ), '' === $sub || 'queue' === $sub ? 200 : 404, 'crm' );
		}
		if ( 'new' === $sub ) {
			if ( ! current_user_can( 'pcb_crm_edit' ) ) {
				pcb_panel_render( 'forbidden', 403 );
			}
			$GLOBALS['pcb_panel_client'] = null;
			pcb_panel_render( 'company' === $item ? 'crm-company' : 'crm-contact-new', 200, 'crm' );
		}
		$row = ctype_digit( $sub ) ? pcb_cl_get( $item, (int) $sub ) : null;
		if ( ! $row ) {
			pcb_panel_render( 'notfound', 404, 'crm' );
		}
		if ( (int) $row['id'] !== (int) $sub ) { // запись влита в другую
			wp_safe_redirect( pcb_cl_url( $item, $row['id'] ), 302 );
			exit;
		}
		$GLOBALS['pcb_panel_client'] = $row;
		pcb_panel_render( 'crm-' . $item, 200, 'crm' );
	}
	// «Реакция на заявку»: смотрят все с CRM, правят — с pcb_crm_edit (проверка — в REST)
	if ( 'settings' === $item ) {
		pcb_panel_render( '' === (string) get_query_var( 'pcb_panel_sub' ) ? 'crm-settings' : 'notfound', '' === (string) get_query_var( 'pcb_panel_sub' ) ? 200 : 404, 'crm' );
	}
	if ( 'journal' === $item ) {
		if ( ! current_user_can( 'pcb_panel_log_view' ) ) {
			pcb_panel_render( 'forbidden', 403 );
		}
		pcb_panel_render( 'crm-journal', 200, 'crm' );
	}
	$id = pcb_rq_find( $item );
	if ( ! $id ) {
		pcb_panel_render( 'notfound', 404, 'crm' );
	}
	// по коду клиента открываем канонический адрес — с внутренним номером
	$number = (string) get_post_meta( $id, '_pcb_number', true );
	if ( strtoupper( $item ) !== $number ) {
		$sub = sanitize_key( (string) get_query_var( 'pcb_panel_sub' ) );
		wp_safe_redirect( pcb_rq_panel_url( $id ) . ( '' !== $sub ? $sub . '/' : '' ), 302 );
		exit;
	}
	$GLOBALS['pcb_panel_request_id'] = $id;
	$sub = sanitize_key( (string) get_query_var( 'pcb_panel_sub' ) );
	if ( 'reply' === $sub ) {
		if ( ! current_user_can( 'pcb_crm_edit' ) ) {
			pcb_panel_render( 'forbidden', 403 );
		}
		$GLOBALS['pcb_panel_bare'] = true; // отдельное окно: без вкладок
		pcb_panel_render( 'crm-reply', 200, 'crm' );
	}
	if ( '' !== $sub ) {
		pcb_panel_render( 'notfound', 404, 'crm' );
	}
	pcb_crm_mark_seen( $id );
	pcb_panel_render( 'crm-card', 200, 'crm' );
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

/** $tab — какая вкладка подсвечена в шапке, если вид не совпадает с вкладкой (карточка заявки → crm). */
function pcb_panel_render( $view, $status = 200, $tab = '' ) {
	status_header( $status );
	$GLOBALS['pcb_panel_view'] = $view;
	$GLOBALS['pcb_panel_tab']  = '' !== $tab ? $tab : $view;
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
