<?php
/**
 * Права и роли панели.
 *
 * Код везде проверяет ПРАВА (capabilities), а не названия ролей.
 * Роли — это наборы прав:
 *   administrator — все права панели + все права WordPress;
 *   pcb_manager   — вход в панель и только просмотр.
 */

defined( 'ABSPATH' ) || exit;

/** Все права панели. */
function pcb_panel_caps() {
	return array(
		'pcb_panel_access'   => 'Вход в панель',
		'pcb_crm_view'       => 'CRM: просмотр заявок и файлов',
		'pcb_crm_edit'       => 'CRM: статусы, ответственный, заметки',
		'pcb_crm_delete'     => 'CRM: удаление и выгрузка',
		'pcb_analytics_view' => 'Аналитика: просмотр',
		'pcb_seo_view'       => 'SEO: просмотр',
		'pcb_seo_edit'       => 'SEO: редактирование',
		'pcb_panel_log_view' => 'Журнал действий',
	);
}

/** Права роли «Менеджер»: только просмотр. */
function pcb_panel_manager_caps() {
	return array( 'read', 'pcb_panel_access', 'pcb_crm_view', 'pcb_analytics_view', 'pcb_seo_view' );
}

function pcb_panel_install_roles() {
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array_keys( pcb_panel_caps() ) as $cap ) {
			$admin->add_cap( $cap );
		}
	}
	// Пересоздаём роль, чтобы набор прав всегда совпадал с кодом.
	remove_role( 'pcb_manager' );
	add_role( 'pcb_manager', 'Менеджер (просмотр)', array_fill_keys( pcb_panel_manager_caps(), true ) );
}

// При обновлении плагина роли обновятся сами, без повторной активации.
add_action( 'init', function () {
	if ( get_option( 'pcb_panel_roles_version' ) !== PCB_PANEL_VERSION ) {
		pcb_panel_install_roles();
		update_option( 'pcb_panel_roles_version', PCB_PANEL_VERSION, false );
	}
}, 5 );

/** Подпись роли текущего пользователя для шапки. */
function pcb_panel_role_label() {
	return current_user_can( 'manage_options' ) ? 'Администратор' : 'Только просмотр';
}
