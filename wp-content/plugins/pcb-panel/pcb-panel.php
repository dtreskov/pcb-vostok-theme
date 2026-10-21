<?php
/**
 * Plugin Name: PCB Восток — панель управления
 * Description: Закрытый раздел сайта /panel: CRM, аналитика, SEO, калькулятор. Вход — через учётные записи WordPress.
 * Version:     0.8.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: pcb-panel
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_PANEL_VERSION', '0.8.2' );
define( 'PCB_PANEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCB_PANEL_URL', plugin_dir_url( __FILE__ ) );
define( 'PCB_PANEL_SLUG', 'panel' ); // адрес раздела: /panel

require_once PCB_PANEL_DIR . 'includes/roles.php';
require_once PCB_PANEL_DIR . 'includes/router.php';
require_once PCB_PANEL_DIR . 'includes/access.php';
require_once PCB_PANEL_DIR . 'includes/rest.php';
// заявки: хранение (перенесено из темы в 0.3.0) и CRM — см. README.md, §3–6
require_once PCB_PANEL_DIR . 'includes/requests/handler.php';
require_once PCB_PANEL_DIR . 'includes/requests/storage.php';
require_once PCB_PANEL_DIR . 'includes/requests/admin.php';
require_once PCB_PANEL_DIR . 'includes/crm/log.php';
require_once PCB_PANEL_DIR . 'includes/crm/actions.php';
require_once PCB_PANEL_DIR . 'includes/crm/calc-labels.php';
require_once PCB_PANEL_DIR . 'includes/crm/normalize.php';
// база клиентов (0.6.0): компании, контакты, сопоставление заявок — см. README.md, §6.1
require_once PCB_PANEL_DIR . 'includes/clients/schema.php';
require_once PCB_PANEL_DIR . 'includes/clients/requisites.php';
require_once PCB_PANEL_DIR . 'includes/clients/store.php';
require_once PCB_PANEL_DIR . 'includes/clients/match.php';
require_once PCB_PANEL_DIR . 'includes/clients/dadata.php';
require_once PCB_PANEL_DIR . 'includes/clients/rest.php';
require_once PCB_PANEL_DIR . 'includes/crm/rest.php';
require_once PCB_PANEL_DIR . 'includes/requests/migrate.php';
require_once PCB_PANEL_DIR . 'includes/analytics/events.php';
require_once PCB_PANEL_DIR . 'includes/analytics/yandex.php';
require_once PCB_PANEL_DIR . 'includes/analytics/data.php';
// SEO и режим техработ (0.7.0) — см. README.md, §8
require_once PCB_PANEL_DIR . 'includes/seo/settings.php';
require_once PCB_PANEL_DIR . 'includes/seo/head.php';
require_once PCB_PANEL_DIR . 'includes/seo/maintenance.php';
require_once PCB_PANEL_DIR . 'includes/seo/rest.php';
// калькулятор (0.8.0): формула цены, версии, расчёт для сайта — см. README.md, §9
require_once PCB_PANEL_DIR . 'includes/calc/model.php';
require_once PCB_PANEL_DIR . 'includes/calc/engine.php';
require_once PCB_PANEL_DIR . 'includes/calc/store.php';
require_once PCB_PANEL_DIR . 'includes/calc/rest.php';

register_activation_hook( __FILE__, 'pcb_panel_activate' );
register_deactivation_hook( __FILE__, 'pcb_panel_deactivate' );

function pcb_panel_activate() {
	pcb_panel_install_roles();
	pcb_events_install();
	pcb_crm_log_install();
	pcb_cl_install();
	pcb_rq_register();
	pcb_panel_register_rewrites();
	flush_rewrite_rules();
}

function pcb_panel_deactivate() {
	// Правила пересоберутся при следующем запросе уже без /panel.
	delete_option( 'rewrite_rules' );
	wp_clear_scheduled_hook( 'pcb_events_purge' );
	wp_clear_scheduled_hook( 'pcb_calc_rates' );
}
