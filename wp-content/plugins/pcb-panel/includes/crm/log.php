<?php
/**
 * Журнал изменений заявок — таблица {prefix}pcb_request_log.
 *
 * Отдельная таблица, а не метаполе: переживает удаление заявки и даёт общий журнал одним запросом.
 * Контактов клиента здесь нет — только номер, код, кто, когда, что сделано, было/стало.
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_RQ_LOG_DB_VERSION', '1' );

function pcb_crm_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcb_request_log';
}

function pcb_crm_log_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = pcb_crm_log_table();
	dbDelta( "CREATE TABLE {$t} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		time datetime NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		request_id bigint(20) unsigned NOT NULL DEFAULT 0,
		number varchar(20) NOT NULL DEFAULT '',
		code varchar(12) NOT NULL DEFAULT '',
		action varchar(24) NOT NULL,
		old_value varchar(255) NOT NULL DEFAULT '',
		new_value text NOT NULL,
		PRIMARY KEY  (id),
		KEY request_id (request_id),
		KEY time (time)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'pcb_request_log_db_version', PCB_RQ_LOG_DB_VERSION, false );
}

add_action( 'init', function () {
	if ( get_option( 'pcb_request_log_db_version' ) !== PCB_RQ_LOG_DB_VERSION ) {
		pcb_crm_log_install();
	}
}, 6 );

/** Подписи действий. */
function pcb_crm_log_actions() {
	return array(
		'created'          => 'Заявка поступила',
		'status'           => 'Статус',
		'lost_reason'      => 'Причина отказа',
		'service'          => 'Услуга',
		'note'             => 'Заметка',
		'file_download'    => 'Скачан файл',
		'export'           => 'Выгрузка заявок',
		'deleted'          => 'Удалена вместе с файлами',
		'deleted_archived' => 'Удалена, файлы в архиве',
		'migrated'         => 'Обновление данных',
	);
}

/**
 * Запись в журнал. $user_id = null — текущий пользователь, 0 — система/сайт.
 * Значения — только служебные (статус, услуга, имя файла, текст заметки), не контакты клиента.
 */
function pcb_crm_log( $request_id, $action, $old = '', $new = '', $user_id = null ) {
	global $wpdb;
	$request_id = (int) $request_id;
	$wpdb->insert( pcb_crm_log_table(), array(
		'time'       => current_time( 'mysql' ),
		'user_id'    => null === $user_id ? get_current_user_id() : (int) $user_id,
		'request_id' => $request_id,
		'number'     => $request_id ? (string) get_post_meta( $request_id, '_pcb_number', true ) : '',
		'code'       => $request_id ? (string) get_post_meta( $request_id, '_pcb_code', true ) : '',
		'action'     => (string) $action,
		'old_value'  => mb_substr( (string) $old, 0, 255 ),
		'new_value'  => (string) $new,
	) );
}

/** История одной заявки (новые сверху) или общий журнал ($request_id = 0). */
function pcb_crm_log_get( $request_id = 0, $limit = 200, $offset = 0 ) {
	global $wpdb;
	$t = pcb_crm_log_table();
	if ( $request_id ) {
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE request_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
			$request_id, $limit, $offset
		), ARRAY_A );
	}
	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d",
		$limit, $offset
	), ARRAY_A );
}

/** Человекочитаемая строка записи журнала. */
function pcb_crm_log_text( array $row ) {
	$labels = pcb_crm_log_actions();
	$what   = $labels[ $row['action'] ] ?? $row['action'];
	$old    = (string) $row['old_value'];
	$new    = (string) $row['new_value'];
	switch ( $row['action'] ) {
		case 'status':
			return $what . ': ' . pcb_rq_status_label( $old ) . ' → ' . pcb_rq_status_label( $new );
		case 'service':
			return $what . ': ' . pcb_rq_service_label( $old ) . ' → ' . pcb_rq_service_label( $new );
		case 'lost_reason':
			return $what . ': ' . ( PCB_RQ_LOST_REASONS[ $new ] ?? ( '' !== $new ? $new : 'не указана' ) );
		case 'created':
			return $what . ( '' !== $new ? ' · ' . $new : '' );
		case 'note':
		case 'file_download':
		case 'migrated':
		case 'export':
			return $what . ( '' !== $new ? ': ' . $new : '' );
		default:
			return $what;
	}
}

function pcb_crm_log_user( $user_id ) {
	if ( ! $user_id ) {
		return 'сайт';
	}
	$u = get_userdata( (int) $user_id );
	return $u ? $u->display_name : 'пользователь #' . (int) $user_id;
}
