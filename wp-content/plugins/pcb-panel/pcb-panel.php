<?php
/**
 * Plugin Name: PCB Восток — панель управления
 * Description: Закрытый раздел сайта /panel: CRM, аналитика, SEO. Вход — через учётные записи WordPress.
 * Version:     0.2.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: pcb-panel
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_PANEL_VERSION', '0.2.0' );
define( 'PCB_PANEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCB_PANEL_URL', plugin_dir_url( __FILE__ ) );
define( 'PCB_PANEL_SLUG', 'panel' ); // адрес раздела: /panel

require_once PCB_PANEL_DIR . 'includes/roles.php';
require_once PCB_PANEL_DIR . 'includes/router.php';
require_once PCB_PANEL_DIR . 'includes/access.php';
require_once PCB_PANEL_DIR . 'includes/rest.php';
require_once PCB_PANEL_DIR . 'includes/analytics/events.php';
require_once PCB_PANEL_DIR . 'includes/analytics/yandex.php';
require_once PCB_PANEL_DIR . 'includes/analytics/data.php';

register_activation_hook( __FILE__, 'pcb_panel_activate' );
register_deactivation_hook( __FILE__, 'pcb_panel_deactivate' );

function pcb_panel_activate() {
	pcb_panel_install_roles();
	pcb_events_install();
	pcb_panel_register_rewrites();
	flush_rewrite_rules();
}

function pcb_panel_deactivate() {
	// Правила пересоберутся при следующем запросе уже без /panel.
	delete_option( 'rewrite_rules' );
	wp_clear_scheduled_hook( 'pcb_events_purge' );
}
