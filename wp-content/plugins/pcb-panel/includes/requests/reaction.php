<?php
/**
 * «Реакция на заявку» (0.9.0, README §3.6): автоответ клиенту, кому уходят уведомления, состояние почты.
 * Экран — /panel/crm/settings/ (views/crm-settings.php), правят администратор и менеджер (pcb_crm_edit).
 *
 * Опция pcb_rq_reaction (не автозагружается):
 *   auto_on, auto_subject, auto_body — автоответ клиенту; подстановки {имя}, {код}, {услуга}, {сайт};
 *   main   — основной ящик (полное письмо: контакты, описание, расчёт, ссылки на файлы), через запятую;
 *            пусто — константа PCB_REQUEST_NOTIFY (запасное значение), без неё — info@pcb-vostok.ru;
 *   copies — копии менеджерам: [{email, full}]. Копия — без данных клиента: номер, код, услуга, откуда и ссылка
 *            на заявку (по ней нужен вход в панель). full — полное письмо, только для адресов домена компании.
 * Секреты почты по-прежнему только в wp-config.php; здесь — тексты и адреса.
 */

defined( 'ABSPATH' ) || exit;

const PCB_RQ_MAX_COPIES = 20;

function pcb_rq_reaction_defaults() {
	return array(
		'auto_on'      => true,
		'auto_subject' => 'Заявка № {код} получена — PCB Восток',
		'auto_body'    => "Здравствуйте, {имя}!\n\nМы получили вашу заявку № {код}. Изучим файлы и свяжемся с вами.\nЕсли нужно что-то добавить — просто ответьте на это письмо.\n\nPCB Восток\n{сайт}",
		'main'         => '',
		'copies'       => array(),
	);
}

function pcb_rq_reaction() {
	$o = get_option( 'pcb_rq_reaction' );
	$o = array_merge( pcb_rq_reaction_defaults(), is_array( $o ) ? $o : array() );
	$o['copies'] = array_values( array_filter( (array) $o['copies'], function ( $c ) {
		return is_array( $c ) && is_email( $c['email'] ?? '' );
	} ) );
	return $o;
}

/** Список адресов из строки «a@x.ru, b@y.ru»: только корректные, в нижнем регистре, без повторов. */
function pcb_rq_emails( $raw ) {
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $e ) {
		$e = strtolower( sanitize_email( $e ) );
		if ( is_email( $e ) && ! in_array( $e, $out, true ) ) {
			$out[] = $e;
		}
	}
	return $out;
}

/** Основной ящик: из панели, иначе PCB_REQUEST_NOTIFY, иначе info@pcb-vostok.ru. */
function pcb_rq_notify_main() {
	$list = pcb_rq_emails( pcb_rq_reaction()['main'] );
	return $list ? $list : pcb_rq_emails( pcb_rq_const( 'PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru' ) );
}

/**
 * Домены компании — куда можно слать полное письмо (с контактами клиента): домен отправителя (PCB_MAIL_FROM),
 * боевой домен сайта (PCB_SEO_PROD_HOST) и домены основного ящика. Публичные почтовые домены (gmail, mail.ru…) — нет.
 */
function pcb_rq_company_domains() {
	$d = array();
	foreach ( array_merge( array( pcb_rq_mail_from() ), pcb_rq_emails( pcb_rq_const( 'PCB_REQUEST_NOTIFY', '' ) ), pcb_rq_emails( pcb_rq_reaction()['main'] ) ) as $e ) {
		$d[] = strtolower( (string) substr( strrchr( (string) $e, '@' ), 1 ) );
	}
	$d[] = strtolower( preg_replace( '/^www\./', '', pcb_rq_const( 'PCB_SEO_PROD_HOST', 'pcb-vostok.ru' ) ) );
	$public = function_exists( 'pcb_cl_public_domains' ) ? pcb_cl_public_domains() : array();
	return array_values( array_unique( array_filter( $d, function ( $x ) use ( $public ) {
		return '' !== $x && ! in_array( $x, $public, true );
	} ) ) );
}

function pcb_rq_is_company_email( $email ) {
	return in_array( strtolower( (string) substr( strrchr( (string) $email, '@' ), 1 ) ), pcb_rq_company_domains(), true );
}

/** Подстановки в текст: {имя}, {код}, {услуга}, {сайт}. Без имени «Здравствуйте, {имя}!» → «Здравствуйте!». */
function pcb_rq_fill( $tpl, array $v ) {
	$tpl = (string) $tpl;
	if ( '' === trim( (string) ( $v['имя'] ?? '' ) ) ) {
		$tpl = preg_replace( '/,\s*\{имя\}/u', '', $tpl );
	}
	foreach ( array( 'имя', 'код', 'услуга', 'сайт' ) as $k ) {
		$tpl = str_replace( '{' . $k . '}', (string) ( $v[ $k ] ?? '' ), $tpl );
	}
	return $tpl;
}

/** Значения подстановок для заявки. */
function pcb_rq_fill_vars( $id ) {
	return array(
		'имя'    => (string) get_post_meta( $id, '_pcb_name', true ),
		'код'    => (string) get_post_meta( $id, '_pcb_code', true ),
		'услуга' => pcb_rq_service_label( get_post_meta( $id, '_pcb_service', true ) ),
		'сайт'   => home_url( '/' ),
	);
}

/** Пример для превью и пробного письма. */
function pcb_rq_sample_vars() {
	return array( 'имя' => 'Алексей', 'код' => '7F3A9C', 'услуга' => 'Платы под ключ', 'сайт' => home_url( '/' ) );
}

/** Короткая копия для менеджера — без данных клиента. */
function pcb_rq_copy_text( array $r ) {
	return array(
		'subject' => 'Новая заявка ' . $r['number'] . ' (' . $r['code'] . ') · ' . $r['service'],
		'body'    => implode( "\n", array(
			'Новая заявка ' . $r['number'] . ' · для клиента ' . $r['code'],
			'Услуга: ' . $r['service'] . ' · ' . $r['source'],
			'',
			'Открыть заявку: ' . $r['url'],
			'',
			'Контакты клиента, описание и файлы — в панели, после входа: в копию они не попадают.',
		) ),
	);
}

/** Копии менеджерам по заявке: «ok» или текст ошибок. Пустой список — ''. */
function pcb_rq_notify_copies( $id, $full_subject, $full_body, $reply_to ) {
	$copies = pcb_rq_reaction()['copies'];
	if ( ! $copies ) {
		return '';
	}
	$short = pcb_rq_copy_text( array(
		'number'  => (string) get_post_meta( $id, '_pcb_number', true ),
		'code'    => (string) get_post_meta( $id, '_pcb_code', true ),
		'service' => pcb_rq_service_label( get_post_meta( $id, '_pcb_service', true ) ),
		'source'  => 'calc' === get_post_meta( $id, '_pcb_source', true ) ? 'с калькулятора' : 'с главной',
		'url'     => pcb_rq_panel_url( $id ),
	) );
	$main = pcb_rq_notify_main();
	$errs = array();
	foreach ( $copies as $c ) {
		if ( in_array( $c['email'], $main, true ) ) {
			continue; // и так получил полное письмо
		}
		$full = ! empty( $c['full'] ) && pcb_rq_is_company_email( $c['email'] );
		$res  = $full
			? pcb_rq_send( $c['email'], $full_subject, $full_body, pcb_rq_headers( $reply_to ) )
			: pcb_rq_send( $c['email'], $short['subject'], $short['body'], pcb_rq_headers( pcb_rq_mail_from() ) );
		if ( 'ok' !== $res ) {
			$errs[] = $c['email'] . ' — ' . $res;
		}
	}
	return $errs ? 'ошибка: ' . implode( '; ', $errs ) : 'ok';
}

/* ---------------- состояние почты (только чтение) ---------------- */

function pcb_rq_mail_state() {
	$smtp  = '' !== pcb_rq_const( 'PCB_SMTP_HOST' );
	$fails = array();
	$ids   = get_posts( array( 'post_type' => 'pcb_request', 'post_status' => 'any', 'posts_per_page' => 40, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
	$sent  = 0;
	foreach ( $ids as $id ) {
		$m = get_post_meta( $id, '_pcb_mail', true );
		if ( ! is_array( $m ) || ! $m ) {
			continue;
		}
		++$sent;
		$bad = array();
		foreach ( array( 'notify' => 'основной ящик', 'copies' => 'копии', 'client' => 'клиенту' ) as $k => $label ) {
			$v = (string) ( $m[ $k ] ?? '' );
			if ( '' !== $v && ! in_array( $v, array( 'ok', 'off' ), true ) ) {
				$bad[] = $label . ': ' . $v;
			}
		}
		if ( $bad && count( $fails ) < 5 ) {
			$fails[] = array( 'id' => $id, 'number' => (string) get_post_meta( $id, '_pcb_number', true ), 'date' => get_post_field( 'post_date', $id ), 'text' => implode( '; ', $bad ) );
		}
	}
	return array(
		'smtp'      => $smtp,
		'host'      => $smtp ? pcb_rq_const( 'PCB_SMTP_HOST' ) . ':' . (int) pcb_rq_const( 'PCB_SMTP_PORT', 465 ) : '',
		'pass'      => '' !== pcb_rq_const( 'PCB_SMTP_PASS' ),
		'from'      => pcb_rq_mail_from(),
		'main_from' => pcb_rq_emails( pcb_rq_reaction()['main'] ) ? 'панель' : ( defined( 'PCB_REQUEST_NOTIFY' ) ? 'wp-config.php (PCB_REQUEST_NOTIFY)' : 'по умолчанию' ),
		'checked'   => $sent,
		'fails'     => $fails,
	);
}

/* ---------------- сохранение ---------------- */

/** Адрес для журнала: «iv***@pcb-vostok.ru» — журнал без полных адресов. */
function pcb_rq_mask_email( $e ) {
	$p = explode( '@', (string) $e );
	return 2 === count( $p ) ? mb_substr( $p[0], 0, 2 ) . '***@' . $p[1] : '***';
}

/** Проверка и сохранение. Возвращает новую опцию или WP_Error. Изменения — в журнал (entity crm:settings). */
function pcb_rq_reaction_save( array $in ) {
	$old = pcb_rq_reaction();
	$new = $old;
	$err = array();

	$new['auto_on']      = ! empty( $in['auto_on'] ) && 'false' !== $in['auto_on'] && '0' !== $in['auto_on'];
	$new['auto_subject'] = mb_substr( trim( sanitize_text_field( (string) ( $in['auto_subject'] ?? $old['auto_subject'] ) ) ), 0, 200 );
	$new['auto_body']    = mb_substr( trim( str_replace( "\r", '', sanitize_textarea_field( (string) ( $in['auto_body'] ?? $old['auto_body'] ) ) ) ), 0, 5000 );
	if ( $new['auto_on'] && ( '' === $new['auto_subject'] || '' === $new['auto_body'] ) ) {
		$err[] = 'У включённого автоответа нужны тема и текст.';
	}

	$main_raw    = (string) ( $in['main'] ?? $old['main'] );
	$main        = pcb_rq_emails( $main_raw );
	$bad_main    = array_filter( preg_split( '/[\s,;]+/', $main_raw ), function ( $e ) { return '' !== $e && ! is_email( $e ); } );
	if ( $bad_main ) {
		$err[] = 'Основной ящик: неверный адрес «' . mb_substr( (string) reset( $bad_main ), 0, 60 ) . '».';
	}
	$new['main'] = implode( ', ', $main );

	$copies = array();
	foreach ( (array) ( $in['copies'] ?? array() ) as $c ) {
		$e = strtolower( trim( (string) ( is_array( $c ) ? ( $c['email'] ?? '' ) : $c ) ) );
		if ( '' === $e ) {
			continue;
		}
		if ( ! is_email( $e ) ) {
			$err[] = 'Копии: неверный адрес «' . mb_substr( $e, 0, 60 ) . '».';
			continue;
		}
		if ( isset( $copies[ $e ] ) ) {
			continue;
		}
		$full = is_array( $c ) && ! empty( $c['full'] ) && 'false' !== $c['full'];
		if ( $full && ! pcb_rq_is_company_email( $e ) ) {
			$err[] = 'Полное письмо можно слать только на адреса домена компании (' . implode( ', ', pcb_rq_company_domains() ) . '), а не на ' . $e . '.';
			$full  = false;
		}
		$copies[ $e ] = array( 'email' => $e, 'full' => $full );
	}
	if ( count( $copies ) > PCB_RQ_MAX_COPIES ) {
		$err[] = 'Не больше ' . PCB_RQ_MAX_COPIES . ' адресов для копий.';
	}
	$new['copies'] = array_values( $copies );
	if ( $err ) {
		return new WP_Error( 'pcb_rq_reaction', implode( ' ', $err ), array( 'status' => 400 ) );
	}

	update_option( 'pcb_rq_reaction', $new, false );

	// журнал: «было → стало»; длинные тексты — коротко, адреса — замаскированы
	$log = function ( $label, $o, $n ) {
		pcb_cl_log( 'crm:settings', 'cl_field', '', wp_json_encode( null === $o ? array( 'text' => $label ) : array( 'label' => $label, 'old' => $o, 'new' => $n ), JSON_UNESCAPED_UNICODE ) );
	};
	$cut = function ( $t ) { return mb_strimwidth( preg_replace( '/\s+/u', ' ', (string) $t ), 0, 80, '…' ); };
	if ( $old['auto_on'] !== $new['auto_on'] ) {
		$log( 'Автоответ клиенту', $old['auto_on'] ? 'включён' : 'выключен', $new['auto_on'] ? 'включён' : 'выключен' );
	}
	if ( $old['auto_subject'] !== $new['auto_subject'] ) {
		$log( 'Тема автоответа', $old['auto_subject'], $new['auto_subject'] );
	}
	if ( $old['auto_body'] !== $new['auto_body'] ) {
		$log( 'Текст автоответа', $cut( $old['auto_body'] ), $cut( $new['auto_body'] ) );
	}
	if ( $old['main'] !== $new['main'] ) {
		$m = function ( $s ) { return implode( ', ', array_map( 'pcb_rq_mask_email', pcb_rq_emails( $s ) ) ); };
		$log( 'Основной ящик', '' !== $old['main'] ? $m( $old['main'] ) : 'из wp-config.php', '' !== $new['main'] ? $m( $new['main'] ) : 'из wp-config.php' );
	}
	$was = array();
	foreach ( $old['copies'] as $c ) {
		$was[ $c['email'] ] = ! empty( $c['full'] );
	}
	$now = array();
	foreach ( $new['copies'] as $c ) {
		$now[ $c['email'] ] = ! empty( $c['full'] );
	}
	foreach ( $now as $e => $full ) {
		if ( ! array_key_exists( $e, $was ) ) {
			$log( 'Копии: добавлен ' . pcb_rq_mask_email( $e ) . ( $full ? ' (полное письмо)' : '' ), null, null );
		} elseif ( $was[ $e ] !== $full ) {
			$log( 'Копии: ' . pcb_rq_mask_email( $e ), $was[ $e ] ? 'полное письмо' : 'без данных клиента', $full ? 'полное письмо' : 'без данных клиента' );
		}
	}
	foreach ( $was as $e => $full ) {
		if ( ! array_key_exists( $e, $now ) ) {
			$log( 'Копии: убран ' . pcb_rq_mask_email( $e ), null, null );
		}
	}
	return $new;
}

/* ---------------- REST: сохранить, пробное письмо ---------------- */

add_action( 'rest_api_init', function () {
	$edit = function () {
		return current_user_can( 'pcb_crm_edit' );
	};
	register_rest_route( 'pcb-panel/v1', '/crm/reaction', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$res = pcb_rq_reaction_save( (array) $r->get_json_params() );
			return is_wp_error( $res ) ? $res : array( 'ok' => true );
		},
	) );
	// пробное письмо: what = auto (автоответ по сохранённому или присланному тексту) | copy (копия менеджеру)
	register_rest_route( 'pcb-panel/v1', '/crm/reaction/test', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$to = strtolower( sanitize_email( (string) $r['to'] ) );
			if ( ! is_email( $to ) ) {
				return new WP_Error( 'pcb_rq_reaction', 'Укажите адрес для пробного письма.', array( 'status' => 400 ) );
			}
			$v = pcb_rq_sample_vars();
			if ( 'copy' === $r['what'] ) {
				$t   = pcb_rq_copy_text( array( 'number' => wp_date( 'Y' ) . '-0000', 'code' => $v['код'], 'service' => $v['услуга'], 'source' => 'с калькулятора', 'url' => pcb_panel_url( 'crm' ) ) );
				$res = pcb_rq_send( $to, '[Проверка] ' . $t['subject'], $t['body'], pcb_rq_headers( pcb_rq_mail_from() ) );
			} else {
				$o       = pcb_rq_reaction();
				$subject = '' !== (string) $r['subject'] ? sanitize_text_field( (string) $r['subject'] ) : $o['auto_subject'];
				$body    = '' !== (string) $r['body'] ? sanitize_textarea_field( (string) $r['body'] ) : $o['auto_body'];
				$res     = pcb_rq_send( $to, '[Проверка] ' . pcb_rq_fill( $subject, $v ), pcb_rq_fill( $body, $v ), pcb_rq_headers( pcb_rq_notify_main()[0] ?? pcb_rq_mail_from() ) );
			}
			return 'ok' === $res ? array( 'ok' => true, 'message' => 'Письмо отправлено на ' . $to . ' — проверьте почту (и «Спам»).' ) : new WP_Error( 'pcb_rq_reaction', 'Не отправилось: ' . $res, array( 'status' => 502 ) );
		},
	) );
} );
