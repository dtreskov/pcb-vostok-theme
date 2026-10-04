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
