<?php
/**
 * База клиентов (0.6.0): компании, контактные лица, идентификаторы для сопоставления.
 *
 *   {prefix}pcb_companies   компания и её реквизиты
 *   {prefix}pcb_contacts    контактное лицо (человек), company_id — его компания или 0
 *   {prefix}pcb_client_ids  идентификаторы в едином виде: email | phone | domain | inn → контакт или компания.
 *                           По ним — точное сопоставление новой заявки одним запросом.
 *   {prefix}pcb_match_skip  отклонённые предложения очереди «Проверить» («это разные») — больше не показываются
 *
 * Заявка ссылается на клиента метаполями _pcb_contact_id и _pcb_company_id.
 * Объединённая запись не удаляется: merged_into указывает, куда она влита (ссылки по старому ID ведут туда).
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_CLIENTS_DB_VERSION', '1' );

function pcb_cl_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'pcb_' . $name;
}

function pcb_cl_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . pcb_cl_table( 'companies' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(255) NOT NULL DEFAULT '',
		full_name varchar(500) NOT NULL DEFAULT '',
		opf varchar(60) NOT NULL DEFAULT '',
		inn varchar(12) NOT NULL DEFAULT '',
		kpp varchar(9) NOT NULL DEFAULT '',
		ogrn varchar(15) NOT NULL DEFAULT '',
		addr_legal text NOT NULL,
		addr_actual text NOT NULL,
		addr_postal text NOT NULL,
		bank_name varchar(255) NOT NULL DEFAULT '',
		bik varchar(9) NOT NULL DEFAULT '',
		rs varchar(20) NOT NULL DEFAULT '',
		ks varchar(20) NOT NULL DEFAULT '',
		head_name varchar(255) NOT NULL DEFAULT '',
		head_post varchar(255) NOT NULL DEFAULT '',
		head_basis varchar(255) NOT NULL DEFAULT '',
		phone varchar(40) NOT NULL DEFAULT '',
		site varchar(255) NOT NULL DEFAULT '',
		verified tinyint(1) NOT NULL DEFAULT 0,
		note text NOT NULL,
		name_key varchar(255) NOT NULL DEFAULT '',
		merged_into bigint(20) unsigned NOT NULL DEFAULT 0,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY inn (inn),
		KEY name_key (name_key(100))
	) {$c};" );
	dbDelta( 'CREATE TABLE ' . pcb_cl_table( 'contacts' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		company_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(255) NOT NULL DEFAULT '',
		post varchar(255) NOT NULL DEFAULT '',
		note text NOT NULL,
		name_key varchar(255) NOT NULL DEFAULT '',
		merged_into bigint(20) unsigned NOT NULL DEFAULT 0,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY company_id (company_id),
		KEY name_key (name_key(100))
	) {$c};" );
	dbDelta( 'CREATE TABLE ' . pcb_cl_table( 'client_ids' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		kind varchar(10) NOT NULL,
		value varchar(255) NOT NULL,
		contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
		company_id bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY lookup (kind,value(100)),
		KEY contact_id (contact_id),
		KEY company_id (company_id)
	) {$c};" );
	dbDelta( 'CREATE TABLE ' . pcb_cl_table( 'match_skip' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		pair varchar(60) NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		time datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY pair (pair)
	) {$c};" );
	update_option( 'pcb_clients_db_version', PCB_CLIENTS_DB_VERSION, false );
}

add_action( 'init', function () {
	if ( get_option( 'pcb_clients_db_version' ) !== PCB_CLIENTS_DB_VERSION ) {
		pcb_cl_install();
	}
}, 6 );
