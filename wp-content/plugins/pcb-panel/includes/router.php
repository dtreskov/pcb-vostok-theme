<?php
/**
 * Виртуальные адреса /panel и /panel/<вкладка>.
 * Это не страницы WordPress: их нет в списке «Страницы», в меню и в sitemap.
 */

defined( 'ABSPATH' ) || exit;

/** Вкладки панели и право, нужное для каждой. Порядок = порядок в меню. */
function pcb_panel_tabs() {
	return array(
		'crm'       => array( 'label' => 'CRM',       'title' => 'Заявки',    'cap' => 'pcb_crm_view' ),
		'analytics' => array( 'label' => 'Аналитика', 'title' => 'Аналитика', 'cap' => 'pcb_analytics_view' ),
		'seo'       => array( 'label' => 'SEO',       'title' => 'SEO',       'cap' => 'pcb_seo_view' ),
		'calc'      => array( 'label' => 'Калькулятор', 'title' => 'Калькулятор', 'cap' => 'pcb_calc_view' ),
	);
}

/** Вкладки, доступные текущему пользователю. */
function pcb_panel_allowed_tabs() {
	return array_filter( pcb_panel_tabs(), function ( $tab ) {
		return current_user_can( $tab['cap'] );
	} );
}

function pcb_panel_register_rewrites() {
	add_rewrite_rule( '^' . PCB_PANEL_SLUG . '/?$', 'index.php?pcb_panel=1', 'top' );
	add_rewrite_rule( '^' . PCB_PANEL_SLUG . '/([a-z0-9-]+)/?$', 'index.php?pcb_panel=1&pcb_panel_tab=$matches[1]', 'top' );
	// вложенный адрес вкладки: /panel/crm/2026-0012/, /panel/crm/journal/
	add_rewrite_rule( '^' . PCB_PANEL_SLUG . '/([a-z0-9-]+)/([A-Za-z0-9-]+)/?$', 'index.php?pcb_panel=1&pcb_panel_tab=$matches[1]&pcb_panel_item=$matches[2]', 'top' );
	// действие над элементом: /panel/crm/2026-0012/reply/
	add_rewrite_rule( '^' . PCB_PANEL_SLUG . '/([a-z0-9-]+)/([A-Za-z0-9-]+)/([A-Za-z0-9-]+)/?$', 'index.php?pcb_panel=1&pcb_panel_tab=$matches[1]&pcb_panel_item=$matches[2]&pcb_panel_sub=$matches[3]', 'top' );
}
add_action( 'init', 'pcb_panel_register_rewrites' );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'pcb_panel';
	$vars[] = 'pcb_panel_tab';
	$vars[] = 'pcb_panel_item';
	$vars[] = 'pcb_panel_sub';
	return $vars;
} );

function pcb_panel_url( $tab = '' ) {
	return home_url( '/' . PCB_PANEL_SLUG . '/' . ( $tab ? $tab . '/' : '' ) );
}

function pcb_panel_is_request() {
	return (bool) get_query_var( 'pcb_panel' );
}

// Новая версия плагина могла добавить адреса — правила пересобираются один раз после обновления.
add_action( 'init', function () {
	if ( get_option( 'pcb_panel_rewrite_version' ) !== PCB_PANEL_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'pcb_panel_rewrite_version', PCB_PANEL_VERSION, false );
	}
}, 99 );
