<?php
/**
 * Все изменения заявок — только через эти функции: они проверяют значения и пишут журнал.
 * Ими пользуются и REST панели (includes/crm/rest.php), и карточка в админке WordPress.
 * Права здесь НЕ проверяются — это делает вызывающий код (permission_callback, current_user_can).
 */

defined( 'ABSPATH' ) || exit;

function pcb_crm_is_request( $id ) {
	return 'pcb_request' === get_post_type( (int) $id );
}

/** Статус (+ причина при отказе). Возвращает true или WP_Error. */
function pcb_crm_set_status( $id, $status, $reason = '' ) {
	if ( ! isset( PCB_RQ_STATUSES[ $status ] ) ) {
		return new WP_Error( 'pcb_crm', 'Неизвестный статус.', array( 'status' => 400 ) );
	}
	$old = (string) get_post_meta( $id, '_pcb_status', true );
	if ( $old !== $status ) {
		update_post_meta( $id, '_pcb_status', $status );
		pcb_crm_log( $id, 'status', $old, $status );
	}
	if ( 'lost' === $status ) {
		$reason     = sanitize_key( $reason );
		$reason     = isset( PCB_RQ_LOST_REASONS[ $reason ] ) ? $reason : '';
		$old_reason = (string) get_post_meta( $id, '_pcb_lost_reason', true );
		if ( $old_reason !== $reason ) {
			update_post_meta( $id, '_pcb_lost_reason', $reason );
			pcb_crm_log( $id, 'lost_reason', $old_reason, $reason );
		}
	} elseif ( '' !== (string) get_post_meta( $id, '_pcb_lost_reason', true ) ) {
		delete_post_meta( $id, '_pcb_lost_reason' );
	}
	return true;
}

/** Услуга. Выбор клиента (_pcb_service_client) не меняется. */
function pcb_crm_set_service( $id, $service ) {
	$service = mb_substr( sanitize_text_field( (string) $service ), 0, 80 );
	if ( '' === $service ) {
		return new WP_Error( 'pcb_crm', 'Укажите услугу.', array( 'status' => 400 ) );
	}
	$old = (string) get_post_meta( $id, '_pcb_service', true );
	if ( '' === (string) get_post_meta( $id, '_pcb_service_client', true ) ) {
		update_post_meta( $id, '_pcb_service_client', $old ); // заявки до 0.3.0
	}
	if ( $old !== $service ) {
		update_post_meta( $id, '_pcb_service', $service );
		pcb_crm_log( $id, 'service', $old, $service );
	}
	return true;
}

/** Заметка хранится только в журнале (действие note). */
function pcb_crm_add_note( $id, $text ) {
	$text = trim( mb_substr( sanitize_textarea_field( (string) $text ), 0, 5000 ) );
	if ( '' === $text ) {
		return new WP_Error( 'pcb_crm', 'Пустая заметка.', array( 'status' => 400 ) );
	}
	pcb_crm_log( $id, 'note', '', $text );
	return true;
}

/** Первое открытие карточки — снимает пометку «новая, не просмотрена». */
function pcb_crm_mark_seen( $id ) {
	if ( ! get_post_meta( $id, '_pcb_seen', true ) ) {
		update_post_meta( $id, '_pcb_seen', array( 'time' => current_time( 'mysql' ), 'user' => get_current_user_id() ) );
	}
}

function pcb_crm_unseen_count() {
	$q = new WP_Query( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_pcb_seen', 'compare' => 'NOT EXISTS' ) ),
	) );
	return (int) $q->found_posts;
}

/**
 * Удаление заявки. $mode: 'archive' — файлы в архив (по умолчанию), 'purge' — удалить и файлы.
 * Тексты заметок в журнале стираются: в них могут быть персональные данные.
 */
function pcb_crm_delete( $id, $mode = 'archive' ) {
	global $wpdb;
	$mode = 'purge' === $mode ? 'purge' : 'archive';
	$dir  = '';
	if ( 'purge' === $mode ) {
		pcb_rq_files_purge( $id );
	} else {
		$dir = pcb_rq_files_archive( $id );
	}
	pcb_crm_log( $id, 'purge' === $mode ? 'deleted' : 'deleted_archived', '', $dir );
	$wpdb->update( pcb_crm_log_table(), array( 'new_value' => '(заметка удалена вместе с заявкой)' ), array( 'request_id' => (int) $id, 'action' => 'note' ) );

	$GLOBALS['pcb_crm_deleting'] = (int) $id; // чтобы хук ниже не обработал файлы второй раз
	$ok = wp_delete_post( $id, true );
	unset( $GLOBALS['pcb_crm_deleting'] );
	return $ok ? true : new WP_Error( 'pcb_crm', 'Не удалось удалить заявку.', array( 'status' => 500 ) );
}

/* Удаление мимо панели (корзина в админке WordPress) — файлы не теряем, уводим в архив. */
add_action( 'before_delete_post', function ( $id ) {
	if ( ! pcb_crm_is_request( $id ) || ( $GLOBALS['pcb_crm_deleting'] ?? 0 ) === (int) $id ) {
		return;
	}
	global $wpdb;
	$dir = pcb_rq_files_archive( $id );
	pcb_crm_log( $id, 'deleted_archived', '', $dir );
	$wpdb->update( pcb_crm_log_table(), array( 'new_value' => '(заметка удалена вместе с заявкой)' ), array( 'request_id' => (int) $id, 'action' => 'note' ) );
} );
