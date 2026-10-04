<?php
/**
 * REST-эндпоинты панели: /wp-json/pcb-panel/v1/...
 *
 * Правило: у КАЖДОГО эндпоинта своя проверка прав в permission_callback.
 * Чтение — право *_view, изменение — *_edit / *_delete.
 * Запросы из интерфейса панели передают nonce 'wp_rest' в заголовке X-WP-Nonce.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	// Образец: сводка о текущем пользователе для интерфейса панели.
	register_rest_route( 'pcb-panel/v1', '/me', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'pcb_panel_access' );
		},
		'callback'            => function () {
			$caps = array();
			foreach ( array_keys( pcb_panel_caps() ) as $cap ) {
				$caps[ $cap ] = current_user_can( $cap );
			}
			return array(
				'name' => wp_get_current_user()->display_name,
				'role' => pcb_panel_role_label(),
				'caps' => $caps,
				'tabs' => array_keys( pcb_panel_allowed_tabs() ),
			);
		},
	) );
} );
