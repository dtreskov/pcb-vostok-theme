<?php
/**
 * REST для правок заявок из панели: /wp-json/pcb-panel/v1/requests/<id>/…
 * У каждого маршрута — своя проверка права. Запросы из панели передают nonce wp_rest (X-WP-Nonce).
 * Выгрузка CSV — admin-post.php?action=pcb_crm_export (право pcb_crm_delete).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$id_arg = array(
		'id' => array(
			'validate_callback' => function ( $v ) {
				return pcb_crm_is_request( (int) $v );
			},
		),
	);
	$can = function ( $cap ) {
		return function () use ( $cap ) {
			return current_user_can( $cap );
		};
	};

	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_set_status( (int) $r['id'], sanitize_key( (string) $r['status'] ), (string) $r['reason'] ) );
		},
	) );

	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/service', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_set_service( (int) $r['id'], (string) $r['service'] ) );
		},
	) );

	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/note', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_add_note( (int) $r['id'], (string) $r['text'], ! empty( $r['important'] ) ) );
		},
	) );

	// «Работа с заявкой» одной формой: { status, reason, service }
	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\\d+)/work', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_set_work( (int) $r['id'], sanitize_key( (string) $r['status'] ), (string) $r['reason'], (string) $r['service'] ) );
		},
	) );

	// снять / поставить «Важно» у заметки: { note: <id записи журнала>, flag: important | resolved }
	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/note-flag', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_note_flag( (int) $r['note'], (int) $r['id'], (string) $r['flag'] ) );
		},
	) );

	// правка контактов: { email, phone, name, company } — любые из полей
	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/contacts', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			$vals = array_intersect_key( (array) $r->get_params(), pcb_crm_contact_fields() );
			return pcb_crm_rest_result( pcb_crm_set_contacts( (int) $r['id'], $vals ) );
		},
	) );

	// добавить файлы (multipart, поле files[])
	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/files', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			$res = pcb_crm_add_files( (int) $r['id'], $r->get_file_params(), 'manager' );
			return is_wp_error( $res ) ? $res : array( 'ok' => true, 'added' => count( $res ) );
		},
	) );

	// письмо клиенту (multipart): subject, body, attach[] — ключи файлов заявки, files[] — новые вложения
	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/email', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_crm_rest_result( pcb_crm_send_email(
				(int) $r['id'], (string) $r['subject'], (string) $r['body'], (array) ( $r['attach'] ?? array() ), $r->get_file_params()
			) );
		},
	) );

	register_rest_route( 'pcb-panel/v1', '/requests/(?P<id>\d+)/delete', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_delete' ),
		'args'                => $id_arg,
		'callback'            => function ( WP_REST_Request $r ) {
			$res = pcb_crm_delete( (int) $r['id'], 'purge' === $r['mode'] ? 'purge' : 'archive' );
			return is_wp_error( $res ) ? $res : array( 'ok' => true, 'redirect' => pcb_panel_url( 'crm' ) );
		},
	) );
} );

// подпись в письмах — своя у каждого пользователя панели
add_action( 'rest_api_init', function () {
	register_rest_route( 'pcb-panel/v1', '/me/signature', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'pcb_panel_access' );
		},
		'callback'            => function ( WP_REST_Request $r ) {
			$sig = trim( mb_substr( sanitize_textarea_field( (string) $r['signature'] ), 0, 1000 ) );
			update_user_meta( get_current_user_id(), 'pcb_mail_signature', $sig );
			return array( 'ok' => true );
		},
	) );

	// отображаемое имя — меняется только display_name, логин остаётся; смена пишется в журнал
	register_rest_route( 'pcb-panel/v1', '/me/name', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'pcb_panel_access' );
		},
		'callback'            => function ( WP_REST_Request $r ) {
			$name = trim( preg_replace( '/\s+/u', ' ', mb_substr( sanitize_text_field( (string) $r['display_name'] ), 0, 60 ) ) );
			if ( mb_strlen( $name ) < 2 ) {
				return new WP_Error( 'pcb_crm', 'Имя — не короче двух символов.', array( 'status' => 400 ) );
			}
			$u   = wp_get_current_user();
			$old = $u->display_name;
			if ( $old !== $name ) {
				$res = wp_update_user( array( 'ID' => $u->ID, 'display_name' => $name ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				pcb_crm_log( 0, 'user_name', $old, $name );
			}
			return array( 'ok' => true );
		},
	) );
} );

function pcb_crm_rest_result( $res ) {
	return is_wp_error( $res ) ? $res : array( 'ok' => true );
}

/* ---------- выгрузка CSV ---------- */

add_action( 'admin_post_pcb_crm_export', function () {
	if ( ! current_user_can( 'pcb_crm_delete' ) ) {
		wp_die( 'Нет доступа.', 403 );
	}
	check_admin_referer( 'pcb_crm_export' );
	$ids = get_posts( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	pcb_crm_log( 0, 'export', '', count( $ids ) . ' шт.' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="pcb-requests-' . wp_date( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM — чтобы Excel открыл кириллицу
	fputcsv( $out, array( 'Номер', 'Код клиента', 'Дата', 'Статус', 'Причина отказа', 'Услуга', 'Услуга (выбор клиента)', 'Почта', 'Телефон', 'Имя', 'Компания', 'Откуда', 'Файлов' ), ';' );
	foreach ( $ids as $id ) {
		$m = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
		fputcsv( $out, array_map( 'pcb_crm_csv_cell', array(
			$m( 'number' ), $m( 'code' ), get_the_date( 'Y-m-d H:i', $id ),
			pcb_rq_status_label( $m( 'status' ) ), PCB_RQ_LOST_REASONS[ $m( 'lost_reason' ) ] ?? '',
			pcb_rq_service_label( $m( 'service' ) ), pcb_rq_service_label( $m( 'service_client' ) ),
			$m( 'email' ), $m( 'phone' ), $m( 'name' ), $m( 'company' ),
			'calc' === $m( 'source' ) ? 'Калькулятор' : 'Главная', count( (array) $m( 'files' ) ),
		) ), ';' );
	}
	fclose( $out );
	exit;
} );

/** Защита от формул в Excel: значения из формы, начинающиеся с = + - @, не исполняются. */
function pcb_crm_csv_cell( $v ) {
	$v = (string) $v;
	return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
}
