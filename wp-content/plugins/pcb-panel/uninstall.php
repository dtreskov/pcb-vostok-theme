<?php
// Удаление плагина: убираем роль, права и настройки. Данные CRM здесь удалять не будем —
// когда появятся таблицы, решим отдельно, что делать с ними при удалении.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/roles.php';

remove_role( 'pcb_manager' );
$admin = get_role( 'administrator' );
if ( $admin ) {
	foreach ( array_keys( pcb_panel_caps() ) as $cap ) {
		$admin->remove_cap( $cap );
	}
}
delete_option( 'pcb_panel_roles_version' );

// Аналитика: таблица обезличенных событий, кеш и служебные настройки API Яндекса.
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pcb_events' );
foreach ( array_keys( (array) get_option( 'pcb_ya_cache_keys', array() ) ) as $k ) {
	delete_transient( $k );
}
foreach ( array( 'pcb_events_db_version', 'pcb_ya_cache_keys', 'pcb_ya_status', 'pcb_wm_user_id' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'pcb_ya_goal_map' );
delete_transient( 'pcb_wm_host' );
wp_clear_scheduled_hook( 'pcb_events_purge' );
