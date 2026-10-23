<?php
/**
 * Журнал изменений заявок — таблица {prefix}pcb_request_log.
 *
 * Отдельная таблица, а не метаполе: переживает удаление заявки и даёт общий журнал одним запросом.
 * Контактов клиента здесь нет — только номер, код, кто, когда, что сделано, было/стало.
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_RQ_LOG_DB_VERSION', '2' ); // 2: колонка entity — записи о компаниях и контактах

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
		entity varchar(30) NOT NULL DEFAULT '',
		action varchar(24) NOT NULL,
		old_value varchar(255) NOT NULL DEFAULT '',
		new_value text NOT NULL,
		PRIMARY KEY  (id),
		KEY request_id (request_id),
		KEY time (time),
		KEY entity (entity)
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
		'contact'          => 'Исправлены контакты',
		'calc_publish'     => 'Калькулятор: опубликована версия',
		'calc_restore'     => 'Калькулятор: старая версия взята в черновик',
		'calc_csv'         => 'Калькулятор: в черновик загружен CSV',
		'file_added'       => 'Добавлен файл',
		'email'            => 'Письмо клиенту',
		'note_important'   => 'Заметка отмечена как важная',
		'note_resolved'    => 'Снята отметка «Важно»',
		'note_deleted'     => 'Удалена заметка',
		'user_name'        => 'Сменено отображаемое имя',
		'client_link'      => 'Привязана к клиенту',
		'cl_created'       => 'Создан',
		'cl_field'         => 'Изменено',
		'cl_link'          => 'Привязка',
		'cl_merge'         => 'Объединение',
		'cl_skip'          => 'Отмечено «это разные»',
		'tags'             => 'Метки',
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

/** Человекочитаемая строка записи журнала (текст, без разметки). */
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
			$r = function ( $v ) { return PCB_RQ_LOST_REASONS[ $v ] ?? ( '' !== $v ? $v : 'не указана' ); };
			return $what . ': ' . $r( $old ) . ' → ' . $r( $new );
		case 'contact':
			$c      = json_decode( $new, true );
			$fields = pcb_crm_contact_fields();
			if ( ! is_array( $c ) ) {
				return 'Исправлены контакты' . ( '' !== $new ? ': ' . $new : '' ); // записи до 0.5.0
			}
			$label = $fields[ $c['field'] ?? '' ] ?? ( $c['field'] ?? '' );
			if ( ! empty( $c['scrubbed'] ) ) {
				return 'Исправлено поле «' . $label . '» (значения удалены вместе с заявкой)';
			}
			$v = function ( $x ) { return '' !== (string) $x ? '«' . $x . '»' : 'пусто'; };
			return $label . ': ' . $v( $c['old'] ?? '' ) . ' → ' . $v( $c['new'] ?? '' );
		case 'calc_publish':
			$c = json_decode( $new, true );
			return 'Калькулятор: опубликована версия ' . (int) ( $c['v'] ?? 0 ) . ( ! empty( $c['note'] ) ? ' — ' . $c['note'] : '' );
		case 'calc_csv':
			return 'Калькулятор: в черновик загружен CSV' . ( '' !== $new ? ' «' . $new . '»' : '' ) . ', изменено значений: ' . (int) $old;
		case 'calc_restore':
			return 'Калькулятор: версия ' . $new . ' взята в черновик';
		case 'created':
			return $what . ( '' !== $new ? ' · ' . $new : '' );
		case 'email':
			$mail = json_decode( $new, true );
			return $what . ( is_array( $mail ) ? ': ' . ( $mail['subject'] ?? '' ) . ( ! empty( $mail['files'] ) ? ' · вложений: ' . count( $mail['files'] ) : '' ) : '' );
		case 'cl_field':
			$c = json_decode( $new, true );
			if ( is_array( $c ) && isset( $c['text'] ) ) {
				return (string) $c['text']; // готовая строка («Копии: добавлен …»)
			}
			if ( is_array( $c ) ) {
				$v = function ( $x ) { return '' !== (string) $x ? '«' . $x . '»' : 'пусто'; };
				return ( $c['label'] ?? $c['field'] ?? '' ) . ': ' . $v( $c['old'] ?? '' ) . ' → ' . $v( $c['new'] ?? '' );
			}
			return $what;
		case 'client_link':
			return $what . ( 'auto' === $old ? ' автоматически' : '' ) . ( '' !== $new ? ': ' . $new : '' );
		case 'cl_created':
			return ( 'manual' === $old ? 'Создан вручную' : 'Создан из заявки' ) . ( '' !== $new ? ': ' . $new : '' );
		case 'cl_link':
		case 'cl_merge':
		case 'cl_skip':
			return $what . ( '' !== $new ? ': ' . $new : '' );
		case 'tags':
			return pcb_tags_log_text( $new );
		case 'user_name':
			return $what . ': «' . $old . '» → «' . $new . '»';
		case 'note_important':
		case 'note_resolved':
			return $what . ( '' !== $new ? ' (заметка от ' . $new . ')' : '' );
		case 'note_deleted':
			return $what . ( '' !== $new ? ' от ' . $new : '' );
		case 'note':
		case 'file_download':
		case 'file_added':
		case 'migrated':
		case 'export':
			return $what . ( '' !== $new ? ': ' . $new : '' );
		default:
			return $what;
	}
}

/** Имя для вывода в HTML: отображаемое имя, при наведении — логин. Уже экранировано. */
function pcb_crm_user_html( $user_id ) {
	if ( ! $user_id ) {
		return '<span class="crm-user crm-user--site">сайт</span>';
	}
	$u = get_userdata( (int) $user_id );
	if ( ! $u ) {
		return '<span class="crm-user">' . esc_html( 'пользователь #' . (int) $user_id ) . '</span>';
	}
	return '<span class="crm-user" title="' . esc_attr( 'Логин: ' . $u->user_login ) . '">' . esc_html( $u->display_name ) . '</span>';
}

function pcb_crm_log_user( $user_id ) {
	if ( ! $user_id ) {
		return 'сайт';
	}
	$u = get_userdata( (int) $user_id );
	return $u ? $u->display_name : 'пользователь #' . (int) $user_id;
}

/**
 * Запись о клиенте: $entity = 'company:12' | 'contact:34'. Заявка — необязательно (привязка заявки к клиенту).
 */
function pcb_cl_log( $entity, $action, $old = '', $new = '', $request_id = 0 ) {
	global $wpdb;
	$request_id = (int) $request_id;
	$wpdb->insert( pcb_crm_log_table(), array(
		'time'       => current_time( 'mysql' ),
		'user_id'    => get_current_user_id(),
		'request_id' => $request_id,
		'number'     => $request_id ? (string) get_post_meta( $request_id, '_pcb_number', true ) : '',
		'code'       => $request_id ? (string) get_post_meta( $request_id, '_pcb_code', true ) : '',
		'entity'     => (string) $entity,
		'action'     => (string) $action,
		'old_value'  => mb_substr( (string) $old, 0, 255 ),
		'new_value'  => (string) $new,
	) );
}

/** История клиента (новые сверху). */
function pcb_cl_log_get( $entity, $limit = 200 ) {
	global $wpdb;
	$t = pcb_crm_log_table();
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE entity = %s ORDER BY id DESC LIMIT %d", $entity, $limit ), ARRAY_A );
}
