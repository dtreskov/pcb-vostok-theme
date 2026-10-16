<?php
/**
 * Раздел «Заявки» в админке WordPress — запасной вход администратора (основной — /panel/crm):
 * колонки списка, карточка с историей, страница «Проверка почты».
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_pcb_request_posts_columns', function ( $cols ) {
	return array(
		'cb'          => $cols['cb'],
		'title'       => 'Заявка',
		'pcb_code'    => 'Код клиента',
		'pcb_status'  => 'Статус',
		'pcb_contact' => 'Контакты',
		'pcb_service' => 'Услуга',
		'pcb_source'  => 'Откуда',
		'pcb_files'   => 'Файлы',
		'pcb_mail'    => 'Письма',
		'date'        => 'Дата',
	);
} );

add_action( 'manage_pcb_request_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'pcb_code':
			echo esc_html( get_post_meta( $id, '_pcb_code', true ) );
			break;
		case 'pcb_status':
			echo esc_html( pcb_rq_status_label( get_post_meta( $id, '_pcb_status', true ) ) );
			break;
		case 'pcb_contact':
			echo esc_html( get_post_meta( $id, '_pcb_email', true ) );
			$ph = get_post_meta( $id, '_pcb_phone', true );
			if ( $ph ) {
				echo '<br>' . esc_html( $ph );
			}
			break;
		case 'pcb_service':
			echo esc_html( pcb_rq_service_label( get_post_meta( $id, '_pcb_service', true ) ) );
			break;
		case 'pcb_source':
			echo 'calc' === get_post_meta( $id, '_pcb_source', true ) ? 'Калькулятор' : 'Главная';
			break;
		case 'pcb_files':
			echo (int) count( (array) get_post_meta( $id, '_pcb_files', true ) );
			break;
		case 'pcb_mail':
			$mail = (array) get_post_meta( $id, '_pcb_mail', true );
			$ok   = 'ok' === ( $mail['notify'] ?? '' ) && 'ok' === ( $mail['client'] ?? '' );
			echo $ok ? 'отправлены' : '<span style="color:#b32d2e">не ушли — откройте заявку</span>';
			break;
	}
}, 10, 2 );

add_action( 'add_meta_boxes_pcb_request', function () {
	add_meta_box( 'pcb_request_details', 'Заявка', 'pcb_rq_metabox', 'pcb_request', 'normal', 'high' );
	add_meta_box( 'pcb_request_history', 'История', 'pcb_rq_metabox_history', 'pcb_request', 'normal', 'default' );
} );

function pcb_rq_metabox( $post ) {
	$id = $post->ID;
	$m  = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
	wp_nonce_field( 'pcb_rq_status', 'pcb_rq_nonce' );
	printf( '<p><a class="button" href="%s">Открыть в панели</a></p>', esc_url( pcb_rq_panel_url( $id ) ) );
	echo '<p><label><b>Статус</b> <select name="pcb_status">';
	foreach ( PCB_RQ_STATUSES as $k => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $m( 'status' ), $k, false ), esc_html( $label ) );
	}
	echo '</select></label> <label>Причина отказа <select name="pcb_lost_reason"><option value="">—</option>';
	foreach ( PCB_RQ_LOST_REASONS as $k => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $m( 'lost_reason' ), $k, false ), esc_html( $label ) );
	}
	echo '</select></label></p><table class="widefat striped"><tbody>';
	$mail = (array) $m( 'mail' );
	$rows = array(
		'Номер'             => $m( 'number' ),
		'Код клиента'       => $m( 'code' ),
		'Почта'             => $m( 'email' ),
		'Телефон'           => $m( 'phone' ),
		'Имя'               => $m( 'name' ),
		'Компания'          => $m( 'company' ),
		'Услуга'            => pcb_rq_service_label( $m( 'service' ) ),
		'Выбор клиента'     => pcb_rq_service_label( $m( 'service_client' ) ),
		'Откуда'            => 'calc' === $m( 'source' ) ? 'Калькулятор' : 'Главная',
		'Страница'          => $m( 'page' ),
		'Переход с'         => $m( 'referrer' ),
		'Капча'             => $m( 'captcha' ),
		'Письмо менеджерам' => $mail['notify'] ?? '—',
		'Письмо клиенту'    => $mail['client'] ?? '—',
	);
	foreach ( $rows as $label => $value ) {
		printf( '<tr><th style="width:160px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
	}
	echo '</tbody></table><h4>Описание задачи</h4><pre style="white-space:pre-wrap">' . esc_html( $post->post_content ) . '</pre>';
	$files = (array) $m( 'files' );
	echo '<h4>Файлы</h4>';
	if ( ! $files ) {
		echo '<p>Не приложены.</p>';
	} else {
		echo '<ul>';
		foreach ( $files as $f ) {
			printf( '<li><a href="%s">%s</a> — %s</li>', esc_url( pcb_rq_file_url( $id, $f['key'] ) ), esc_html( $f['name'] ), esc_html( size_format( $f['size'] ) ) );
		}
		echo '</ul>';
	}
	$consent = (array) $m( 'consent' );
	if ( $consent ) {
		printf( '<p style="color:#666">Согласие на обработку ПДн: %s, IP %s, политика версии %s.</p>',
			esc_html( $consent['time'] ?? '' ), esc_html( $consent['ip'] ?? '' ), esc_html( $consent['version'] ?? '' ) );
	}
}

function pcb_rq_metabox_history( $post ) {
	$rows = pcb_crm_log_get( $post->ID );
	if ( ! $rows ) {
		echo '<p>Записей нет.</p>';
		return;
	}
	echo '<table class="widefat striped"><tbody>';
	foreach ( $rows as $r ) {
		printf( '<tr><td style="width:130px">%s</td><td style="width:160px">%s</td><td>%s</td></tr>',
			esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ), esc_html( pcb_crm_log_user( $r['user_id'] ) ), esc_html( pcb_crm_log_text( $r ) ) );
	}
	echo '</tbody></table>';
}

add_action( 'save_post_pcb_request', function ( $id ) {
	if ( ! isset( $_POST['pcb_rq_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pcb_rq_nonce'] ) ), 'pcb_rq_status' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'pcb_crm_edit' ) || ! isset( $_POST['pcb_status'] ) ) {
		return;
	}
	pcb_crm_set_status(
		$id,
		sanitize_key( wp_unslash( $_POST['pcb_status'] ) ),
		isset( $_POST['pcb_lost_reason'] ) ? sanitize_key( wp_unslash( $_POST['pcb_lost_reason'] ) ) : ''
	);
} );


/* =================================
   Заявки → «Проверка почты»: настройки и тестовое письмо с журналом SMTP
   ================================= */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=pcb_request', 'Проверка почты', 'Проверка почты', 'edit_pcb_requests', 'pcb-request-mail', 'pcb_rq_mail_page' );
} );

function pcb_rq_mail_page() {
	if ( ! current_user_can( 'edit_pcb_requests' ) ) {
		return;
	}
	$notify = pcb_rq_const( 'PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru' );
	$rows   = array(
		'Кому слать заявки (PCB_REQUEST_NOTIFY)' => $notify . ( defined( 'PCB_REQUEST_NOTIFY' ) ? '' : ' — по умолчанию, константа не задана' ),
		'Отправитель (From)'                     => pcb_rq_mail_from(),
		'Способ отправки'                        => '' !== pcb_rq_const( 'PCB_SMTP_HOST' ) ? 'SMTP' : 'PHP mail() — PCB_SMTP_HOST не задан',
		'PCB_SMTP_HOST'                          => pcb_rq_const( 'PCB_SMTP_HOST', '—' ),
		'PCB_SMTP_PORT / PCB_SMTP_SECURE'        => pcb_rq_const( 'PCB_SMTP_PORT', '— (465)' ) . ' / ' . pcb_rq_const( 'PCB_SMTP_SECURE', '— (ssl)' ),
		'PCB_SMTP_USER'                          => pcb_rq_const( 'PCB_SMTP_USER', '—' ),
		'PCB_SMTP_PASS'                          => '' !== pcb_rq_const( 'PCB_SMTP_PASS' ) ? 'задан' : 'не задан',
		'Ключ клиента SmartCaptcha'              => '' !== pcb_rq_const( 'PCB_SMARTCAPTCHA_SITEKEY' ) ? 'задан' : 'не задан',
		'Серверный ключ SmartCaptcha'            => '' !== pcb_rq_const( 'PCB_SMARTCAPTCHA_SECRET' ) ? 'задан' : 'не задан',
		'Папка файлов заявок'                    => pcb_rq_storage_root() . ( pcb_rq_storage_is_public() ? ' — внутри веб-корня, защищена только .htaccess' : '' ),
	);
	echo '<div class="wrap"><h1>Проверка почты</h1>';
	echo '<p>Если константы ниже показаны как «не задан», хотя в wp-config.php они есть, — строки define стоят ниже <code>require_once ABSPATH . \'wp-settings.php\';</code>. Перенесите их выше строки «That\'s all, stop editing».</p>';
	echo '<table class="widefat striped" style="max-width:820px"><tbody>';
	foreach ( $rows as $k => $v ) {
		printf( '<tr><th style="width:320px">%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( (string) $v ) );
	}
	echo '</tbody></table>';

	if ( isset( $_POST['pcb_rq_test'] ) && check_admin_referer( 'pcb_rq_test' ) ) {
		$to                           = sanitize_email( wp_unslash( $_POST['pcb_rq_to'] ?? '' ) );
		$GLOBALS['pcb_rq_smtp_debug'] = true;
		$GLOBALS['pcb_rq_smtp_log']   = array();
		$res                          = pcb_rq_send( $to, 'Проверка почты — PCB Восток', "Тестовое письмо с сайта: если вы его видите, отправка заявок работает.\n\n" . home_url( '/' ), pcb_rq_headers( pcb_rq_mail_from() ) );
		$GLOBALS['pcb_rq_smtp_debug'] = false;
		printf( '<h2>Результат: %s</h2>', esc_html( 'ok' === $res ? 'письмо передано почтовому серверу' : $res ) );
		if ( 'ok' === $res ) {
			echo '<p>Если письмо не пришло — проверьте «Спам» и записи SPF/DKIM домена отправителя.</p>';
		}
		if ( ! empty( $GLOBALS['pcb_rq_smtp_log'] ) ) {
			echo '<h3>Журнал SMTP</h3><pre style="max-width:980px;max-height:420px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;white-space:pre-wrap">' . esc_html( implode( "\n", $GLOBALS['pcb_rq_smtp_log'] ) ) . '</pre>';
		}
	}

	$first = trim( explode( ',', $notify )[0] );
	echo '<h2>Тестовое письмо</h2><form method="post">';
	wp_nonce_field( 'pcb_rq_test' );
	printf( '<p><input type="email" name="pcb_rq_to" value="%s" class="regular-text" required> <button class="button button-primary" name="pcb_rq_test" value="1">Отправить</button></p>', esc_attr( $first ) );
	echo '</form></div>';
}
