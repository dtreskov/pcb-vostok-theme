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
function pcb_crm_add_note( $id, $text, $important = false ) {
	$text = trim( mb_substr( sanitize_textarea_field( (string) $text ), 0, 5000 ) );
	if ( '' === $text ) {
		return new WP_Error( 'pcb_crm', 'Пустая заметка.', array( 'status' => 400 ) );
	}
	pcb_crm_log( $id, 'note', $important ? 'important' : '', $text );
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
	$mode = 'purge' === $mode ? 'purge' : 'archive';
	$dir  = '';
	if ( 'purge' === $mode ) {
		pcb_rq_files_purge( $id );
	} else {
		$dir = pcb_rq_files_archive( $id );
	}
	pcb_crm_log( $id, 'purge' === $mode ? 'deleted' : 'deleted_archived', '', $dir );
	pcb_crm_scrub_log( $id );

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
	$dir = pcb_rq_files_archive( $id );
	pcb_crm_log( $id, 'deleted_archived', '', $dir );
	pcb_crm_scrub_log( $id );
} );

/** Стереть из журнала тексты, где могут быть персональные данные: заметки и письма удалённой заявки. */
function pcb_crm_scrub_log( $id ) {
	global $wpdb;
	$t = pcb_crm_log_table();
	$wpdb->update( $t, array( 'new_value' => '(заметка удалена вместе с заявкой)' ), array( 'request_id' => (int) $id, 'action' => 'note' ) );
	$wpdb->update( $t, array( 'new_value' => '(письмо удалено вместе с заявкой)' ), array( 'request_id' => (int) $id, 'action' => 'email' ) );
	// правки контактов: оставляем только какое поле менялось
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, old_value FROM {$t} WHERE request_id = %d AND action = 'contact'", $id ), ARRAY_A ) as $r ) {
		$wpdb->update( $t, array( 'new_value' => wp_json_encode( array( 'field' => $r['old_value'], 'scrubbed' => true ) ) ), array( 'id' => (int) $r['id'] ) );
	}
}

/* =================================
   Правка контактов (0.4.0). Исходные значения — в _pcb_original, не меняются.
   В журнал пишется только какое поле изменено — без значений: журнал остаётся без контактов.
   ================================= */

function pcb_crm_contact_fields() {
	return array( 'email' => 'Почта', 'phone' => 'Телефон', 'name' => 'Имя', 'company' => 'Компания' );
}

function pcb_crm_original( $id ) {
	$orig = get_post_meta( $id, '_pcb_original', true );
	return is_array( $orig ) ? $orig : array();
}

function pcb_crm_set_contacts( $id, array $values ) {
	$fields = pcb_crm_contact_fields();
	$clean  = array();
	foreach ( $fields as $k => $label ) {
		if ( ! array_key_exists( $k, $values ) ) {
			continue;
		}
		$raw = (string) $values[ $k ];
		if ( 'email' === $k ) {
			$v = pcb_crm_norm_email( $raw );
			if ( '' === $v && ! is_wp_error( $v ) ) {
				$v = new WP_Error( 'pcb_crm', 'Почта обязательна — по ней мы отвечаем клиенту.', array( 'status' => 400 ) );
			}
		} elseif ( 'phone' === $k ) {
			$v = pcb_crm_norm_phone( $raw );
		} else {
			$v = trim( preg_replace( '/\s+/u', ' ', mb_substr( sanitize_text_field( $raw ), 0, 'company' === $k ? 200 : 120 ) ) );
		}
		if ( is_wp_error( $v ) ) {
			return $v; // ничего не сохраняем, пока все поля не корректны
		}
		$clean[ $k ] = $v;
	}
	foreach ( $clean as $k => $v ) {
		$old = (string) get_post_meta( $id, '_pcb_' . $k, true );
		if ( $old !== $v ) {
			update_post_meta( $id, '_pcb_' . $k, $v );
			// было → стало; при удалении заявки значения стираются (pcb_crm_scrub_log)
			pcb_crm_log( $id, 'contact', $k, wp_json_encode( array( 'field' => $k, 'old' => $old, 'new' => $v ), JSON_UNESCAPED_UNICODE ) );
		}
	}
	pcb_crm_reindex( $id );
	// новая почта или телефон в заявке — ещё один канал связи её контакта (если не принадлежит другому)
	$contact = (int) get_post_meta( $id, '_pcb_contact_id', true );
	if ( $contact && function_exists( 'pcb_cl_contact_add_channels' ) ) {
		$e = strtolower( (string) get_post_meta( $id, '_pcb_email', true ) );
		$p = pcb_crm_phone_digits( get_post_meta( $id, '_pcb_phone', true ) );
		$owner_e = pcb_cl_find( 'email', $e );
		$owner_p = pcb_cl_is_mobile( $p ) ? pcb_cl_find( 'phone', $p ) : 0; // городской может быть общим
		pcb_cl_contact_add_channels( $contact, ( ! $owner_e || $owner_e === $contact ) ? $e : '', ( ! $owner_p || $owner_p === $contact ) ? $p : '' );
	}
	return true;
}

/** Статус, причина отказа и услуга одной формой («Работа с заявкой»). */
function pcb_crm_set_work( $id, $status, $reason, $service ) {
	$res = pcb_crm_set_status( $id, $status, $reason );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return '' !== (string) $service ? pcb_crm_set_service( $id, $service ) : true;
}

/* =================================
   Файлы, добавленные менеджером: тот же приём и проверки, что у формы (pcb_rq_collect_files),
   у записи — кто и когда добавил и откуда файл: manager (прислал клиент по почте) | sent (вложение письма).
   ================================= */

function pcb_crm_add_files( $id, array $file_params, $kind = 'manager' ) {
	$files = pcb_rq_collect_files( $file_params );
	if ( is_wp_error( $files ) ) {
		return $files;
	}
	if ( ! $files ) {
		return array();
	}
	$current = (array) get_post_meta( $id, '_pcb_files', true );
	$total   = array_sum( array_map( function ( $f ) { return (int) ( $f['size'] ?? 0 ); }, $current ) );
	$adding  = array_sum( array_map( function ( $f ) { return (int) $f['size']; }, $files ) );
	if ( $total + $adding > 524288000 ) { // 500 МБ на заявку — не зависит от лимита одной отправки
		return new WP_Error( 'pcb_crm', 'В заявке уже слишком много файлов (больше 500 МБ).', array( 'status' => 413 ) );
	}
	$stored = pcb_rq_store_files( $files, (string) get_post_meta( $id, '_pcb_number', true ) );
	foreach ( $stored as &$f ) {
		$f['by']    = get_current_user_id();
		$f['added'] = current_time( 'mysql' );
		$f['kind']  = $kind;
	}
	unset( $f );
	update_post_meta( $id, '_pcb_files', array_merge( $current, $stored ) );
	if ( 'manager' === $kind ) {
		pcb_crm_log( $id, 'file_added', '', implode( ', ', wp_list_pluck( $stored, 'name' ) ) );
	}
	return $stored;
}

/* =================================
   Важные заметки (0.4.0): флаг хранится в old_value записи журнала с действием note:
   '' — обычная, 'important' — важная, не решена, 'resolved' — была важной, отметку сняли.
   ================================= */

function pcb_crm_note_flag( $note_id, $request_id, $flag ) {
	global $wpdb;
	$t   = pcb_crm_log_table();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND request_id = %d AND action = 'note'", $note_id, $request_id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'pcb_crm', 'Заметка не найдена.', array( 'status' => 404 ) );
	}
	$flag = 'important' === $flag ? 'important' : 'resolved';
	if ( $row['old_value'] !== $flag ) {
		$wpdb->update( $t, array( 'old_value' => $flag ), array( 'id' => (int) $note_id ) );
		pcb_crm_log( $request_id, 'important' === $flag ? 'note_important' : 'note_resolved', '', mysql2date( 'd.m.Y H:i', $row['time'] ) );
	}
	return true;
}

/** Может ли текущий пользователь удалить заметку: автор или тот, у кого есть право удалять заявки. */
function pcb_crm_can_delete_note( array $row ) {
	return current_user_can( 'pcb_crm_delete' ) || ( current_user_can( 'pcb_crm_edit' ) && (int) $row['user_id'] === get_current_user_id() );
}

/**
 * Удалить заметку: строка журнала с текстом удаляется целиком, в истории остаётся
 * «Удалена заметка от <дата>» — без текста.
 */
function pcb_crm_note_delete( $note_id, $request_id ) {
	global $wpdb;
	$t   = pcb_crm_log_table();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND request_id = %d AND action = 'note'", $note_id, $request_id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'pcb_crm', 'Заметка не найдена.', array( 'status' => 404 ) );
	}
	if ( ! pcb_crm_can_delete_note( $row ) ) {
		return new WP_Error( 'pcb_crm', 'Удалить заметку может её автор или администратор.', array( 'status' => 403 ) );
	}
	$wpdb->delete( $t, array( 'id' => (int) $note_id ) );
	pcb_crm_log( $request_id, 'note_deleted', '', mysql2date( 'd.m.Y H:i', $row['time'] ) . ', автор — ' . pcb_crm_log_user( (int) $row['user_id'] ) );
	return true;
}

/** Число открытых важных заметок: по заявке или array( id => n ) по списку заявок. */
function pcb_crm_important_counts( array $ids ) {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', $ids ) );
	if ( ! $ids ) {
		return array();
	}
	$t    = pcb_crm_log_table();
	$rows = $wpdb->get_results( "SELECT request_id, COUNT(*) AS n FROM {$t} WHERE action = 'note' AND old_value = 'important' AND request_id IN (" . implode( ',', $ids ) . ') GROUP BY request_id', ARRAY_A );
	return wp_list_pluck( (array) $rows, 'n', 'request_id' );
}

/* =================================
   Письмо клиенту из панели (0.4.0)
   From и Reply-To — общий ящик (PCB_MAIL_FROM), скрытая копия — туда же: SMTP-отправка не попадает
   в «Отправленные», копия сохраняет переписку в ящике. Подпись — из профиля менеджера.
   ================================= */

function pcb_crm_signature( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$sig     = (string) get_user_meta( $user_id, 'pcb_mail_signature', true );
	if ( '' === $sig ) {
		$u   = get_userdata( $user_id );
		$sig = "С уважением,\n" . ( $u ? $u->display_name : '' ) . "\nPCB Восток\n" . home_url( '/' );
	}
	return $sig;
}

function pcb_crm_mailbox() {
	return trim( (string) pcb_rq_mail_from() );
}

/**
 * Отправка. $keys — ключи уже прикреплённых к заявке файлов, $uploads — новые файлы (сохраняются в заявке как sent).
 */
function pcb_crm_send_email( $id, $subject, $body, array $keys, array $uploads ) {
	$to      = (string) get_post_meta( $id, '_pcb_email', true );
	$subject = trim( mb_substr( sanitize_text_field( (string) $subject ), 0, 200 ) );
	$body    = trim( mb_substr( sanitize_textarea_field( (string) $body ), 0, 20000 ) );
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'pcb_crm', 'У заявки нет корректной почты клиента.', array( 'status' => 400 ) );
	}
	if ( '' === $subject || '' === $body ) {
		return new WP_Error( 'pcb_crm', 'Заполните тему и текст письма.', array( 'status' => 400 ) );
	}

	// новые вложения — сначала в файлы заявки
	$new = pcb_crm_add_files( $id, $uploads, 'sent' );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	$keys = array_merge( array_map( 'sanitize_key', $keys ), wp_list_pluck( $new, 'key' ) );

	// вложения уходят под исходными именами: копии во временной папке, после отправки удаляются
	$tmp    = trailingslashit( get_temp_dir() ) . 'pcb-mail-' . wp_generate_password( 12, false, false );
	$attach = array();
	$names  = array();
	$size   = 0;
	foreach ( (array) get_post_meta( $id, '_pcb_files', true ) as $f ) {
		if ( ! in_array( strtolower( (string) $f['key'] ), $keys, true ) ) {
			continue;
		}
		$path = pcb_rq_file_path( $f );
		if ( '' === $path ) {
			continue;
		}
		$size += filesize( $path );
		if ( $size > 20 * MB_IN_BYTES ) {
			pcb_rq_rmdir( $tmp );
			return new WP_Error( 'pcb_crm', 'Вложения больше 20 МБ — почтовые серверы такое часто не принимают. Отправьте ссылкой.', array( 'status' => 413 ) );
		}
		wp_mkdir_p( $tmp . '/' . count( $attach ) );
		$copy = $tmp . '/' . count( $attach ) . '/' . sanitize_file_name( $f['name'] );
		if ( @copy( $path, $copy ) ) {
			$attach[] = $copy;
			$names[]  = $f['name'];
		}
	}

	$box     = pcb_crm_mailbox();
	$full    = $body . "\n\n-- \n" . pcb_crm_signature();
	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: PCB Восток <' . $box . '>',
		'Reply-To: ' . $box,
		'Bcc: ' . $box,
	);
	$GLOBALS['pcb_rq_mail_error'] = '';
	$ok = wp_mail( $to, $subject, $full, $headers, $attach );
	pcb_rq_rmdir( $tmp );
	if ( ! $ok ) {
		return new WP_Error( 'pcb_crm', 'Письмо не отправлено: ' . ( '' !== $GLOBALS['pcb_rq_mail_error'] ? $GLOBALS['pcb_rq_mail_error'] : 'ошибка почты' ), array( 'status' => 502 ) );
	}
	pcb_crm_log( $id, 'email', '', wp_json_encode( array( 'subject' => $subject, 'body' => $full, 'files' => $names ), JSON_UNESCAPED_UNICODE ) );
	return true;
}
