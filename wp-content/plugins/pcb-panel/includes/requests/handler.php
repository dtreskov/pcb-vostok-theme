<?php
/**
 * Заявки с сайта: тип записей, приём формы, номера, письма.
 * Перенесено из темы (inc/request-handler.php) в 0.3.0 — подробности в README.md, §3.
 *
 * Форма — блоки Gutenberg в страницах темы, отправка — assets/js/request-form.js темы:
 * multipart POST на /wp-json/pcb/v1/request. Адрес и ключ капчи отдаёт inc/request-form.php темы.
 *
 * Настройки — константы в wp-config.php (секреты в git не кладём):
 *   PCB_SMARTCAPTCHA_SECRET, PCB_SMARTCAPTCHA_SITEKEY, PCB_REQUEST_NOTIFY, PCB_MAIL_FROM,
 *   PCB_SMTP_HOST / PORT / USER / PASS / SECURE, PCB_REQUEST_STORAGE, PCB_POLICY_VERSION.
 */

defined( 'ABSPATH' ) || exit;

const PCB_RQ_MAX_FILES  = 20;        // те же лимиты — MAX_FILES / MAX_TOTAL в request-form.js темы
const PCB_RQ_MAX_TOTAL  = 104857600; // 100 МБ; хостинг: post_max_size и upload_max_filesize не меньше 110M
const PCB_RQ_RATE_LIMIT = 5;        // заявок с одного IP
const PCB_RQ_RATE_TTL   = 600;      // за 10 минут
const PCB_RQ_SERVICES   = array(
	'turnkey'    => 'Платы под ключ',
	'pcb'        => 'Только платы',
	'components' => 'Только компоненты',
	'other'      => 'Другое',
);
const PCB_RQ_STATUSES   = array(
	'new'  => 'Новая',
	'work' => 'В работе',
	'done' => 'Завершена',
	'lost' => 'Отказ',
);
const PCB_RQ_LOST_REASONS = array(
	'price'   => 'Дорого',
	'time'    => 'Не устроили сроки',
	'tech'    => 'Не можем сделать',
	'silence' => 'Клиент не ответил',
	'other'   => 'Другое',
);

function pcb_rq_service_label( $code ) {
	$code = (string) $code;
	return PCB_RQ_SERVICES[ $code ] ?? ( '' !== $code ? $code : '—' );
}

function pcb_rq_status_label( $code ) {
	return PCB_RQ_STATUSES[ (string) $code ] ?? '—';
}

function pcb_rq_const( $name, $default = '' ) {
	return defined( $name ) ? constant( $name ) : $default;
}

/** «2026-0012 · 7F3A9C» — как заявку видит менеджер. */
function pcb_rq_label( $post_id ) {
	$number = (string) get_post_meta( $post_id, '_pcb_number', true );
	$code   = (string) get_post_meta( $post_id, '_pcb_code', true );
	return $number . ( '' !== $code ? ' · ' . $code : '' );
}

/** Карточка заявки в панели. */
function pcb_rq_panel_url( $post_id ) {
	return pcb_panel_url( 'crm' ) . rawurlencode( (string) get_post_meta( $post_id, '_pcb_number', true ) ) . '/';
}

/** ID заявки по внутреннему номеру или коду клиента; 0 — не найдена. */
function pcb_rq_find( $number_or_code ) {
	$v = strtoupper( trim( (string) $number_or_code ) );
	if ( '' === $v ) {
		return 0;
	}
	$key = preg_match( '/^\d{4}-\d+$/', $v ) ? '_pcb_number' : '_pcb_code';
	$ids = get_posts( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => $key,
		'meta_value'     => $v,
	) );
	return $ids ? (int) $ids[0] : 0;
}


/* =================================
   Тип записей и права
   ================================= */

function pcb_rq_register() {
	register_post_type( 'pcb_request', array(
		'labels'              => array(
			'name'          => 'Заявки',
			'singular_name' => 'Заявка',
			'menu_name'     => 'Заявки',
			'all_items'     => 'Все заявки',
			'edit_item'     => 'Заявка',
			'search_items'  => 'Найти заявку',
			'not_found'     => 'Заявок нет',
		),
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => true,  // запасной вход для администратора; основной — /panel/crm
		'show_in_menu'        => true,
		'show_in_rest'        => false,
		'menu_icon'           => 'dashicons-email-alt',
		'menu_position'       => 25,
		'supports'            => array( 'title' ),
		'capability_type'     => array( 'pcb_request', 'pcb_requests' ),
		'map_meta_cap'        => true,
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
	) );
}
add_action( 'init', 'pcb_rq_register' );

/* Права типа записей (раздел «Заявки» в админке WordPress) — только администратору. */
function pcb_rq_caps() {
	if ( '1' === get_option( 'pcb_request_caps' ) ) {
		return;
	}
	$role = get_role( 'administrator' );
	if ( $role ) {
		foreach ( array( 'edit_pcb_requests', 'edit_others_pcb_requests', 'read_private_pcb_requests',
			'edit_private_pcb_requests', 'delete_pcb_requests', 'delete_private_pcb_requests',
			'delete_others_pcb_requests', 'read_pcb_requests' ) as $cap ) {
			$role->add_cap( $cap );
		}
	}
	update_option( 'pcb_request_caps', '1', false );
}
add_action( 'init', 'pcb_rq_caps', 20 );


/* =================================
   Номера
   ================================= */

/**
 * Внутренний номер ГГГГ-NNNN. Счётчик увеличивается одним SQL-запросом —
 * две одновременные заявки не получат один номер; плюс страховочная проверка по базе.
 */
function pcb_rq_next_number() {
	global $wpdb;
	$year = wp_date( 'Y' );
	$key  = 'pcb_request_counter_' . $year;
	add_option( $key, 0, '', false ); // ничего не делает, если счётчик уже есть
	for ( $i = 0; $i < 20; $i++ ) {
		$done = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s",
			$key
		) );
		$n = $done ? (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) : 0;
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( $n < 1 ) {
			$n = (int) get_option( $key, 0 ) + 1;
			update_option( $key, $n, false );
		}
		$number = sprintf( '%s-%04d', $year, $n );
		if ( ! pcb_rq_meta_exists( '_pcb_number', $number ) ) {
			return $number;
		}
	}
	return $year . '-' . strtoupper( wp_generate_password( 6, false, false ) );
}

/** Код для клиента: 6 шестнадцатеричных символов, уникальный. */
function pcb_rq_new_code() {
	for ( $i = 0; $i < 50; $i++ ) {
		$code = strtoupper( bin2hex( random_bytes( 3 ) ) );
		if ( ! pcb_rq_meta_exists( '_pcb_code', $code ) ) {
			return $code;
		}
	}
	return strtoupper( bin2hex( random_bytes( 4 ) ) ); // практически недостижимо
}

function pcb_rq_meta_exists( $key, $value ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
		$key, $value
	) );
}


/* =================================
   Приём заявки: POST /wp-json/pcb/v1/request
   ================================= */

add_action( 'rest_api_init', function () {
	register_rest_route( 'pcb/v1', '/request', array(
		'methods'             => 'POST',
		'callback'            => 'pcb_rq_submit',
		'permission_callback' => '__return_true', // публичная форма; защита — ловушка, лимит, капча
	) );
} );

function pcb_rq_error( $message, $status = 400 ) {
	return new WP_Error( 'pcb_request', $message, array( 'status' => $status ) );
}

function pcb_rq_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

function pcb_rq_submit( WP_REST_Request $req ) {
	$p    = $req->get_body_params();
	$mail = 'info@pcb-vostok.ru';

	// ловушка для ботов: скрытое поле должно остаться пустым — отвечаем «успехом», ничего не сохраняя
	if ( ! empty( $p['pcb_hp_check'] ) ) {
		error_log( 'PCB request: honeypot filled, ip ' . pcb_rq_ip() );
		return rest_ensure_response( array( 'success' => true, 'number' => '' ) );
	}

	// не чаще PCB_RQ_RATE_LIMIT заявок с IP за PCB_RQ_RATE_TTL
	$ip    = pcb_rq_ip();
	$rk    = 'pcb_rq_rate_' . md5( $ip );
	$count = (int) get_transient( $rk );
	if ( $count >= PCB_RQ_RATE_LIMIT ) {
		do_action( 'pcb_event', 'form_err', 'rate' );
		return pcb_rq_error( 'Слишком много заявок подряд. Попробуйте через несколько минут или напишите нам на почту ' . $mail . '.', 429 );
	}

	$email = sanitize_email( isset( $p['email'] ) ? wp_unslash( $p['email'] ) : '' );
	if ( ! is_email( $email ) ) {
		return pcb_rq_error( 'Укажите почту в формате имя@компания.ru.' );
	}
	if ( empty( $p['consent'] ) ) {
		return pcb_rq_error( 'Без согласия на обработку персональных данных отправить заявку нельзя.' );
	}
	$field = function ( $k, $max = 200 ) use ( $p ) {
		$v = isset( $p[ $k ] ) ? sanitize_text_field( wp_unslash( $p[ $k ] ) ) : '';
		return mb_substr( $v, 0, $max );
	};
	$phone   = $field( 'phone', 40 );
	$name    = $field( 'name', 120 );
	$company = $field( 'company', 200 );
	// варианты услуги правятся в редакторе страницы: известный код или текст варианта как есть
	$service = $field( 'service', 80 );
	if ( '' === $service ) {
		$service = 'other';
	}
	$source  = ( isset( $p['source'] ) && 'calc' === $p['source'] ) ? 'calc' : 'home';
	$message = isset( $p['message'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $p['message'] ) ), 0, 20000 ) : '';
	// данные калькулятора — единственный источник расчёта в заявке (панель, письмо); только известные поля
	$calc = pcb_rq_clean_calc( isset( $p['calc_data'] ) ? (string) wp_unslash( $p['calc_data'] ) : '' );

	$captcha = pcb_rq_check_captcha( isset( $p['smart-token'] ) ? (string) wp_unslash( $p['smart-token'] ) : '', $ip );
	if ( 'failed' === $captcha ) {
		do_action( 'pcb_event', 'form_err', 'captcha' );
		return pcb_rq_error( 'Не пройдена проверка «Я не робот». Отметьте её ещё раз и отправьте заявку.' );
	}

	$files = pcb_rq_collect_files( $req->get_file_params() );
	if ( is_wp_error( $files ) ) {
		do_action( 'pcb_event', 'form_err', 'files' );
		return $files;
	}

	// пустая заявка не нужна: описание, хотя бы один файл или прикреплённый расчёт калькулятора
	if ( '' === trim( $message ) && ! $files && ! pcb_rq_calc_filled( $calc ) ) {
		do_action( 'pcb_event', 'form_err', 'empty' );
		return pcb_rq_error( 'calc' === $source
			? 'Приложите файлы, опишите задачу или прикрепите данные калькулятора.'
			: 'Приложите файлы или опишите задачу — хотя бы одно из двух.' );
	}

	set_transient( $rk, $count + 1, PCB_RQ_RATE_TTL );

	$number = pcb_rq_next_number();
	$code   = pcb_rq_new_code();

	$post_id = wp_insert_post( array(
		'post_type'    => 'pcb_request',
		'post_status'  => 'private',
		'post_title'   => 'Заявка ' . $number . ' — ' . ( '' !== $company ? $company : $email ),
		'post_content' => $message,
	), true );
	if ( is_wp_error( $post_id ) ) {
		do_action( 'pcb_event', 'form_err', 'save' );
		return pcb_rq_error( 'Не удалось сохранить заявку. Попробуйте ещё раз или напишите нам на почту ' . $mail . '.', 500 );
	}

	$page = isset( $p['page'] ) ? (string) wp_unslash( $p['page'] ) : '';
	$meta = array(
		'_pcb_number'          => $number,
		'_pcb_code'            => $code,
		'_pcb_status'          => 'new',
		'_pcb_email'           => $email,
		'_pcb_phone'           => $phone,
		'_pcb_name'            => $name,
		'_pcb_company'         => $company,
		'_pcb_service'         => $service,
		'_pcb_service_client'  => $service,
		'_pcb_source'          => $source,
		'_pcb_files'           => pcb_rq_store_files( $files, $number ),
		'_pcb_calc'            => $calc,
		'_pcb_captcha'         => $captcha,
		// исходные контакты — не меняются; правки менеджера пишутся в поля выше (см. includes/crm/actions.php)
		'_pcb_original'        => array( 'email' => $email, 'phone' => $phone, 'name' => $name, 'company' => $company ),
		// ClientID Яндекс Метрики — только если посетитель согласился на аналитику
		'_pcb_ym_client'       => mb_substr( preg_replace( '/\D/', '', isset( $p['ym_client_id'] ) ? (string) wp_unslash( $p['ym_client_id'] ) : '' ), 0, 30 ),
		'_pcb_page'            => esc_url_raw( $page ),
		'_pcb_referrer'        => esc_url_raw( isset( $p['referrer'] ) ? wp_unslash( $p['referrer'] ) : '' ),
		'_pcb_utm'             => pcb_rq_utm( $page ),
		'_pcb_consent'         => array(
			'time'    => current_time( 'mysql' ),
			'ip'      => $ip,
			'ua'      => mb_substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 300 ),
			'policy'  => home_url( '/politics#privacy' ),
			'consent' => home_url( '/politics#consent' ),
			'version' => pcb_rq_const( 'PCB_POLICY_VERSION', '2026-10' ),
		),
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $post_id, $k, $v );
	}
	pcb_crm_reindex( $post_id ); // ключи для связанных заявок
	pcb_crm_log( $post_id, 'created', '', pcb_rq_service_label( $service ), 0 );
	pcb_cl_match_request( $post_id ); // контакт и компания — точные совпадения сразу, вероятные — в очередь

	// результат отправки писем хранится в заявке — видно в карточке, если почта не настроена
	update_post_meta( $post_id, '_pcb_mail', array(
		'notify' => pcb_rq_notify( (int) $post_id ),
		'client' => pcb_rq_autoreply( (int) $post_id ),
	) );

	// клиент видит только код; внутренний номер наружу не уходит
	return rest_ensure_response( array( 'success' => true, 'number' => $code ) );
}

/* Yandex SmartCaptcha: 'ok' | 'failed' | 'unchecked' (сервис недоступен) | 'disabled' (нет ключа) */
function pcb_rq_check_captcha( $token, $ip ) {
	$secret = pcb_rq_const( 'PCB_SMARTCAPTCHA_SECRET' );
	if ( '' === $secret ) {
		return 'disabled';
	}
	if ( '' === $token ) {
		return 'failed';
	}
	$res = wp_remote_post( 'https://smartcaptcha.yandexcloud.net/validate', array(
		'timeout' => 5,
		'body'    => array( 'secret' => $secret, 'token' => $token, 'ip' => $ip ),
	) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return 'unchecked'; // при недоступности сервиса заявку не теряем, а помечаем
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return ( is_array( $body ) && isset( $body['status'] ) && 'ok' === $body['status'] ) ? 'ok' : 'failed';
}

function pcb_rq_utm( $url ) {
	$q     = array();
	$query = wp_parse_url( $url, PHP_URL_QUERY );
	if ( $query ) {
		parse_str( $query, $q );
	}
	$out = array();
	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $k ) {
		if ( ! empty( $q[ $k ] ) ) {
			$out[ $k ] = sanitize_text_field( $q[ $k ] );
		}
	}
	return $out;
}

/* Исполняемые файлы и скрипты не принимаем; остальное (Gerber с любыми расширениями, архивы, PDF…) — да. */
function pcb_rq_blocked( $name ) {
	return (bool) preg_match( '/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|bash|exe|msi|bat|cmd|com|scr|ps1|vbs|js|jar|dll|htaccess|htpasswd)$/i', $name )
		|| false !== strpos( $name, "\0" );
}

function pcb_rq_collect_files( array $params ) {
	if ( empty( $params['files'] ) || ! is_array( $params['files']['name'] ) ) {
		return array();
	}
	$f     = $params['files'];
	$out   = array();
	$total = 0;
	foreach ( $f['name'] as $i => $raw ) {
		$err = (int) $f['error'][ $i ];
		if ( UPLOAD_ERR_NO_FILE === $err ) {
			continue;
		}
		$name = sanitize_file_name( wp_basename( (string) $raw ) );
		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			return pcb_rq_error( 'Файл «' . $name . '» слишком большой. Пришлите его на почту info@pcb-vostok.ru или ссылкой.', 413 );
		}
		if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( $f['tmp_name'][ $i ] ) ) {
			return pcb_rq_error( 'Не удалось принять файл «' . $name . '». Попробуйте ещё раз.' );
		}
		if ( pcb_rq_blocked( $name ) ) {
			return pcb_rq_error( 'Файл «' . $name . '»: такой тип файлов не принимаем — упакуйте проект в архив.' );
		}
		$total += (int) $f['size'][ $i ];
		$out[]  = array( 'name' => $name, 'size' => (int) $f['size'][ $i ], 'tmp' => $f['tmp_name'][ $i ] );
	}
	if ( count( $out ) > PCB_RQ_MAX_FILES ) {
		return pcb_rq_error( 'Не больше ' . PCB_RQ_MAX_FILES . ' файлов — упакуйте их в один архив.' );
	}
	if ( $total > PCB_RQ_MAX_TOTAL ) {
		return pcb_rq_error( 'Файлы вместе больше ' . (int) ( PCB_RQ_MAX_TOTAL / 1048576 ) . ' МБ. Пришлите их на почту info@pcb-vostok.ru или ссылкой.', 413 );
	}
	return $out;
}


/* =================================
   Письма
   ================================= */

function pcb_rq_mail_from() {
	return pcb_rq_const( 'PCB_MAIL_FROM', pcb_rq_const( 'PCB_SMTP_USER', 'info@pcb-vostok.ru' ) );
}

$GLOBALS['pcb_rq_mail_error'] = '';
add_action( 'wp_mail_failed', function ( $error ) {
	$GLOBALS['pcb_rq_mail_error'] = is_wp_error( $error ) ? $error->get_error_message() : 'ошибка отправки';
	error_log( 'PCB request mail failed: ' . $GLOBALS['pcb_rq_mail_error'] );
} );

function pcb_rq_send( $to, $subject, $body, array $headers ) {
	$GLOBALS['pcb_rq_mail_error'] = '';
	$ok = wp_mail( $to, $subject, $body, $headers );
	return $ok ? 'ok' : ( 'ошибка: ' . ( '' !== $GLOBALS['pcb_rq_mail_error'] ? $GLOBALS['pcb_rq_mail_error'] : 'wp_mail вернул false' ) );
}

/* SMTP вместо mail(): включается, если в wp-config.php задан PCB_SMTP_HOST */
add_action( 'phpmailer_init', function ( $mailer ) {
	if ( '' === pcb_rq_const( 'PCB_SMTP_HOST' ) ) {
		return;
	}
	$mailer->isSMTP();
	$mailer->Host       = PCB_SMTP_HOST;
	$mailer->Port       = (int) pcb_rq_const( 'PCB_SMTP_PORT', 465 );
	$mailer->SMTPSecure = pcb_rq_const( 'PCB_SMTP_SECURE', 'ssl' );
	$mailer->SMTPAuth   = true;
	$mailer->Username   = pcb_rq_const( 'PCB_SMTP_USER' );
	$mailer->Password   = pcb_rq_const( 'PCB_SMTP_PASS' );
	$mailer->CharSet    = 'UTF-8';
	if ( ! empty( $GLOBALS['pcb_rq_smtp_debug'] ) ) { // подробный журнал — только при проверке почты из админки
		$mailer->SMTPDebug   = 2;
		$mailer->Debugoutput = function ( $str ) {
			$str = rtrim( $str );
			// логин и пароль в SMTP-диалоге идут base64-строками — не показываем
			if ( preg_match( '/^CLIENT -> SERVER:\s*[A-Za-z0-9+\/=]{6,}$/', $str ) || preg_match( '/AUTH\s+PLAIN\s+\S+/i', $str ) ) {
				$str = preg_replace( '/(CLIENT -> SERVER:\s*(AUTH\s+PLAIN\s+)?).*/i', '$1***', $str );
			}
			$GLOBALS['pcb_rq_smtp_log'][] = $str;
		};
	}
} );

function pcb_rq_headers( $reply_to ) {
	return array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: PCB Восток <' . pcb_rq_mail_from() . '>',
		'Reply-To: ' . $reply_to,
	);
}

/**
 * Данные калькулятора из формы (PCBCalc.getData() темы) → безопасный массив для _pcb_calc.
 * Пропускаются только известные поля, строки и списки укорачиваются; не JSON или больше 64 КБ — пусто.
 * Несколько плат (docs/calculator.md темы, §7.2): board, boards[], solo, set { sets, ship, saving, boards[] }.
 */
function pcb_rq_clean_calc( $raw ) {
	if ( '' === $raw || strlen( $raw ) > 65536 ) {
		return array();
	}
	$in = json_decode( $raw, true );
	return is_array( $in ) ? pcb_rq_clean_calc_data( $in, true ) : array();
}

/** Одна плата (верхний уровень или set.boards[].data); $top — ещё и ключи проекта из нескольких плат. */
function pcb_rq_clean_calc_data( array $in, $top ) {
	$str  = function ( $v, $max ) { return is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, $max ) : ''; };
	$int  = function ( $v, $max ) { return is_numeric( $v ) ? max( 0, min( $max, (int) round( $v ) ) ) : 0; };
	$list = function ( $v, $n, $max ) use ( $str ) {
		return is_array( $v ) ? array_values( array_filter( array_map( function ( $x ) use ( $str, $max ) { return $str( $x, $max ); }, array_slice( $v, 0, $n ) ), 'strlen' ) ) : array();
	};
	$est  = function ( $v ) use ( $int ) {
		if ( 'engineer' === $v ) {
			return 'engineer';
		}
		if ( ! is_array( $v ) ) {
			return null;
		}
		$e = array();
		foreach ( array( 'lo', 'hi', 'per' ) as $k ) {
			$e[ $k ] = $int( $v[ $k ] ?? 0, PHP_INT_MAX );
		}
		if ( isset( $v['goods'] ) && is_numeric( $v['goods'] ) ) {
			$e['goods'] = $int( $v['goods'], PHP_INT_MAX ); // плата комплекта без доставки
		}
		if ( isset( $v['vat'] ) && is_numeric( $v['vat'] ) ) {
			$e['vat'] = max( 0, min( 50, (float) $v['vat'] ) );
		}
		return $e;
	};
	$out = array();
	foreach ( array( 'qty', 'len', 'wid', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = $str( $in[ $k ], 40 );
		}
	}
	if ( isset( $in['special'] ) ) {
		$out['special'] = array_values( array_filter( $list( $in['special'], 20, 40 ), function ( $k ) { return (bool) preg_match( '/^[a-z0-9_-]+$/i', $k ); } ) );
	}
	if ( isset( $in['asm'] ) && in_array( $in['asm'], array( 'none', 'quick', 'detail' ), true ) ) {
		$out['asm'] = $in['asm'];
	}
	if ( isset( $in['asm_values'] ) && is_array( $in['asm_values'] ) ) {
		$out['asm_values'] = array();
		foreach ( array_slice( $in['asm_values'], 0, 60, true ) as $k => $v ) {
			if ( preg_match( '/^[qdp]_[a-z0-9_]{1,24}$/i', (string) $k ) && is_numeric( $v ) ) {
				$out['asm_values'][ $k ] = $int( $v, 1000000 );
			}
		}
	}
	foreach ( array( 'finish_pending', 'asm_est' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = (bool) $in[ $k ];
		}
	}
	if ( isset( $in['estimate'] ) && null !== ( $e = $est( $in['estimate'] ) ) ) {
		$out['estimate'] = $e;
	}
	if ( ! $top ) {
		return array_filter( $out, function ( $v ) { return '' !== $v && array() !== $v; } );
	}

	if ( isset( $in['calc_version'] ) && is_numeric( $in['calc_version'] ) ) {
		$out['calc_version'] = (int) $in['calc_version'];
	}
	if ( isset( $in['term'] ) ) {
		$out['term'] = $str( $in['term'], 120 );
	}
	foreach ( array( 'warn' => array( 10, 200 ), 'files' => array( 40, 200 ) ) as $k => $lim ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = $list( $in[ $k ], $lim[0], $lim[1] );
		}
	}
	// несколько плат в проекте
	if ( isset( $in['board'] ) ) {
		$out['board'] = $str( $in['board'], 80 );
	}
	if ( ! empty( $in['solo'] ) ) {
		$out['solo'] = true;
	}
	if ( isset( $in['boards'] ) && is_array( $in['boards'] ) ) {
		$out['boards'] = array();
		foreach ( array_slice( $in['boards'], 0, 20 ) as $b ) {
			if ( is_array( $b ) && '' !== ( $nm = $str( $b['name'] ?? '', 80 ) ) ) {
				$out['boards'][] = array( 'name' => $nm, 'size' => $str( $b['size'] ?? '', 40 ), 'layers' => $int( $b['layers'] ?? 0, 100 ), 'comps' => $int( $b['comps'] ?? 0, 1000000 ) );
			}
		}
	}
	if ( isset( $in['set'] ) && is_array( $in['set'] ) && empty( $out['solo'] ) ) {
		$s   = $in['set'];
		$set = array( 'sets' => $int( $s['sets'] ?? 0, 10000000 ), 'boards' => array() );
		foreach ( array( 'ship', 'saving' ) as $k ) {
			if ( isset( $s[ $k ] ) && is_numeric( $s[ $k ] ) ) {
				$set[ $k ] = $int( $s[ $k ], PHP_INT_MAX );
			}
		}
		foreach ( array_slice( (array) ( $s['boards'] ?? array() ), 0, 20 ) as $b ) {
			if ( ! is_array( $b ) || '' === ( $nm = $str( $b['name'] ?? '', 80 ) ) ) {
				continue;
			}
			$row = array( 'name' => $nm, 'n' => $int( $b['n'] ?? 0, 1000 ), 'on' => ! isset( $b['on'] ) || (bool) $b['on'] );
			if ( isset( $b['data'] ) && is_array( $b['data'] ) ) {
				$row['data'] = pcb_rq_clean_calc_data( $b['data'], false );
			}
			if ( isset( $b['estimate'] ) && null !== ( $e = $est( $b['estimate'] ) ) ) {
				$row['estimate'] = $e;
			}
			$set['boards'][] = $row;
		}
		if ( $set['boards'] ) {
			$out['set'] = $set;
		}
	}
	return array_filter( $out, function ( $v ) { return '' !== $v && array() !== $v; } );
}

/** В расчёте есть что-то по делу: размеры, тираж, слои или оценка. */
function pcb_rq_calc_filled( array $calc ) {
	foreach ( array( 'qty', 'len', 'wid', 'layers', 'estimate' ) as $k ) {
		if ( ! empty( $calc[ $k ] ) ) {
			return true;
		}
	}
	return false;
}

function pcb_rq_notify( $id ) {
	$m       = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
	$service = pcb_rq_service_label( $m( 'service' ) );
	$lines   = array(
		'Заявка ' . $m( 'number' ) . ' · для клиента ' . $m( 'code' ) . ' · ' . ( 'calc' === $m( 'source' ) ? 'со страницы калькулятора' : 'с главной' ),
		'',
		'Почта:    ' . $m( 'email' ),
		'Телефон:  ' . ( $m( 'phone' ) ?: '—' ),
		'Имя:      ' . ( $m( 'name' ) ?: '—' ),
		'Компания: ' . ( $m( 'company' ) ?: '—' ),
		'Услуга:   ' . $service,
		'',
		'Описание задачи:',
		get_post_field( 'post_content', $id ) ?: '—',
		'',
	);
	// расчёт — из _pcb_calc, с теми же подписями, что в карточке панели (calc-labels.php)
	$calc = (array) $m( 'calc' );
	if ( $calc && function_exists( 'pcb_crm_calc_rows' ) ) {
		$lines[] = 'Данные калькулятора:';
		foreach ( pcb_crm_calc_rows( $calc ) as $row ) {
			$sub     = 0 === strpos( $row[0], '· ' );
			$lines[] = ( $sub ? '    ' . ltrim( $row[0], '· ' ) : '  ' . $row[0] ) . ': ' . $row[1];
		}
		$lines[] = '';
	}
	$files = (array) $m( 'files' );
	if ( $files ) {
		$lines[] = 'Файлы (скачиваются после входа на сайт):';
		foreach ( $files as $f ) {
			$lines[] = '  ' . $f['name'] . ' (' . size_format( $f['size'] ) . '): ' . pcb_rq_file_url( $id, $f['key'] );
		}
	} else {
		$lines[] = 'Файлы не приложены.';
	}
	$lines[] = '';
	$cap     = $m( 'captcha' );
	if ( 'ok' !== $cap ) {
		$lines[] = 'Проверка капчи: ' . ( 'unchecked' === $cap ? 'сервис Яндекса не ответил — заявка принята без проверки' : 'не настроена (нет PCB_SMARTCAPTCHA_SECRET)' );
		$lines[] = '';
	}
	$lines[] = 'Открыть заявку: ' . pcb_rq_panel_url( $id );

	$to = array_filter( array_map( 'trim', explode( ',', pcb_rq_const( 'PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru' ) ) ) );
	return pcb_rq_send( $to, 'Новая заявка ' . $m( 'number' ) . ' (' . $m( 'code' ) . ') · ' . $service, implode( "\n", $lines ), pcb_rq_headers( $m( 'email' ) ) );
}

function pcb_rq_autoreply( $id ) {
	$email  = get_post_meta( $id, '_pcb_email', true );
	$name   = get_post_meta( $id, '_pcb_name', true );
	$code   = get_post_meta( $id, '_pcb_code', true );
	$notify = trim( explode( ',', pcb_rq_const( 'PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru' ) )[0] );
	$body   = implode( "\n", array(
		( '' !== $name ? 'Здравствуйте, ' . $name . '!' : 'Здравствуйте!' ),
		'',
		'Мы получили вашу заявку № ' . $code . '. Изучим файлы и свяжемся с вами.',
		'Если нужно что-то добавить — просто ответьте на это письмо.',
		'',
		'PCB Восток',
		home_url( '/' ),
	) );
	return pcb_rq_send( $email, 'Заявка № ' . $code . ' получена — PCB Восток', $body, pcb_rq_headers( $notify ) );
}
