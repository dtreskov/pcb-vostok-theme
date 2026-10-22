<?php
/**
 * Калькулятор: REST.
 *   Панель (pcb_calc_view — чтение, pcb_calc_edit — изменения, nonce wp_rest):
 *     POST calc/draft {params, rev}  — сохранить черновик и пересчитать эталоны;
 *     POST calc/reset                — сбросить черновик к опубликованной версии;
 *     POST calc/publish {note, rev}  — опубликовать черновик;
 *     POST calc/restore {v}          — взять старую версию в черновик;
 *     POST calc/rates                — обновить курсы ЦБ сейчас;
 *     POST calc/etalon {number}      — эталон из заявки; POST calc/etalon-delete {id}.
 *   Сайт (без входа): POST calc/quote {данные калькулятора} → оценка. Файлы проекта сюда не попадают —
 *   только параметры, которые клиент видит в полях калькулятора. Ничего не сохраняется.
 *   Комплект — одним запросом: { set: { sets, boards: [ { name, n, data } ] } } → { set: true, lo, hi, per, total,
 *   ship, saving, lines, boards: [ { name, n, qty, ok, engineer, warn, lo, hi, per, goods, lines } ], … } (README §9.5).
 */

defined( 'ABSPATH' ) || exit;

/** Пересчёт эталонов: черновик против опубликованной версии + чувствительность. */
function pcb_calc_preview( array $draft ) {
	$pub   = pcb_calc_published()['params'];
	$fxD   = pcb_calc_fx( $draft );
	$fxP   = pcb_calc_fx( $pub );
	$out   = array();
	foreach ( pcb_calc_etalons() as $e ) {
		$d     = pcb_calc_run( $draft, $e['in'], $fxD );
		$p     = pcb_calc_run( $pub, $e['in'], $fxP );
		$out[] = array(
			'id'   => $e['id'],
			'n'    => $e['n'],
			'd'    => pcb_calc_input_desc( $e['in'] ),
			'from' => $e['from'] ?? '',
			'res'  => $d,
			'pub'  => ! empty( $p['ok'] ) && empty( $p['engineer'] ) ? $p['total'] : null,
			'sens' => pcb_calc_sensitivity( $draft, $e['in'], $fxD ),
		);
	}
	return array( 'etalons' => $out, 'fx' => $fxD, 'errors' => pcb_calc_errors( $draft ), 'changes' => pcb_calc_diff( $pub, $draft ) );
}

/** Изменение цен эталонов при публикации: [мин %, макс %]. */
function pcb_calc_deltas( array $preview ) {
	$d = array();
	foreach ( $preview['etalons'] as $e ) {
		if ( $e['pub'] && ! empty( $e['res']['total'] ) ) {
			$d[] = round( ( $e['res']['total'] - $e['pub'] ) / $e['pub'] * 100, 1 );
		}
	}
	return $d ? array( min( $d ), max( $d ) ) : array();
}

/** Версии для экрана «Версии» (без параметров — они большие). */
function pcb_calc_versions_view() {
	$out = array();
	foreach ( array_reverse( pcb_calc_versions() ) as $v ) {
		$out[] = array(
			'v'       => (int) $v['v'],
			'note'    => $v['note'],
			'user'    => pcb_crm_log_user( (int) $v['user'] ),
			'time'    => mysql2date( 'd.m.Y H:i', $v['time'] ),
			'changes' => $v['changes'] ?? array(),
			'deltas'  => $v['deltas'] ?? array(),
			'rate'    => $v['rate'] ?? 0,
		);
	}
	return $out;
}

/** Всё, что нужно странице панели. */
function pcb_calc_bootstrap() {
	$draft = pcb_calc_draft();
	$pub   = pcb_calc_published();
	$defs  = array();
	foreach ( pcb_calc_params() as $k => $d ) {
		unset( $d['v'] );
		$defs[ $k ] = $d;
	}
	return array(
		'api'      => esc_url_raw( rest_url( 'pcb-panel/v1/calc/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'canEdit'  => current_user_can( 'pcb_calc_edit' ),
		'groups'   => pcb_calc_groups(),
		'defs'     => $defs,
		'engFixed' => pcb_calc_engineer_fixed(),
		'draft'    => $draft['params'],
		'rev'      => (int) $draft['rev'],
		'draftBy'  => $draft['user'] ? pcb_crm_log_user( (int) $draft['user'] ) . ', ' . mysql2date( 'd.m.Y H:i', $draft['time'] ) : '',
		'pub'      => array( 'v' => (int) $pub['v'], 'params' => $pub['params'], 'user' => $pub['user'] ? pcb_crm_log_user( (int) $pub['user'] ) : '', 'time' => $pub['time'] ? mysql2date( 'd.m.Y', $pub['time'] ) : '', 'rate' => $pub['rate'] ),
		'siteOn'   => pcb_calc_site_on(),
		'rates'    => pcb_calc_rates(),
		'versions' => pcb_calc_versions_view(),
		'preview'  => pcb_calc_preview( $draft['params'] ),
	);
}

function pcb_calc_err( $msg, $status = 400, $extra = array() ) {
	return new WP_Error( 'pcb_calc', $msg, array_merge( array( 'status' => $status ), $extra ) );
}

add_action( 'rest_api_init', function () {
	$ns   = 'pcb-panel/v1';
	$edit = function () {
		return current_user_can( 'pcb_calc_edit' );
	};
	// ответ после любого изменения: свежий черновик, версии и пересчёт
	$state = function () {
		$b = pcb_calc_bootstrap();
		unset( $b['api'], $b['nonce'], $b['canEdit'], $b['groups'], $b['defs'], $b['engFixed'] );
		return $b;
	};

	register_rest_route( $ns, '/calc/draft', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$cur = pcb_calc_draft();
			if ( (int) $r['rev'] !== (int) $cur['rev'] ) {
				return pcb_calc_err( 'Черновик только что изменил другой сотрудник — загружена его версия.', 409, array( 'draft' => $cur['params'], 'rev' => (int) $cur['rev'] ) );
			}
			$params = pcb_calc_clean( (array) $r['params'], $cur['params'] );
			$rev    = pcb_calc_draft_save( $params );
			return array( 'rev' => $rev, 'draft' => $params, 'preview' => pcb_calc_preview( $params ) );
		},
	) );

	register_rest_route( $ns, '/calc/reset', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function () use ( $state ) {
			pcb_calc_draft_drop();
			return $state();
		},
	) );

	register_rest_route( $ns, '/calc/publish', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) use ( $state ) {
			$cur = pcb_calc_draft();
			if ( (int) $r['rev'] !== (int) $cur['rev'] ) {
				return pcb_calc_err( 'Черновик изменился с момента проверки — посмотрите изменения ещё раз.', 409 );
			}
			if ( $err = pcb_calc_errors( $cur['params'] ) ) {
				return pcb_calc_err( 'Исправьте значения вне допустимого: ' . implode( '; ', array_slice( $err, 0, 3 ) ) . '.' );
			}
			$pub = pcb_calc_published();
			if ( $pub['v'] && ! pcb_calc_diff( $pub['params'], $cur['params'] ) ) {
				return pcb_calc_err( 'Черновик совпадает с опубликованной версией — публиковать нечего.' );
			}
			$prev = pcb_calc_preview( $cur['params'] );
			pcb_calc_publish( $cur['params'], (string) $r['note'], pcb_calc_deltas( $prev ) );
			return $state();
		},
	) );

	register_rest_route( $ns, '/calc/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) use ( $state ) {
			$v = pcb_calc_version( (int) $r['v'] );
			if ( ! $v ) {
				return pcb_calc_err( 'Версия не найдена.', 404 );
			}
			pcb_calc_draft_save( pcb_calc_clean( $v['params'] ) );
			pcb_cl_log( 'calc', 'calc_restore', '', (string) (int) $v['v'] );
			return $state();
		},
	) );

	register_rest_route( $ns, '/calc/rates', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function () use ( $state ) {
			$ok = pcb_calc_rates_fetch();
			if ( true !== $ok ) {
				return pcb_calc_err( $ok, 502 );
			}
			return $state();
		},
	) );

	register_rest_route( $ns, '/calc/etalon', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) use ( $state ) {
			$id = pcb_rq_find( trim( (string) $r['number'] ) );
			if ( ! $id ) {
				return pcb_calc_err( 'Заявка не найдена — проверьте номер.', 404 );
			}
			$calc = get_post_meta( $id, '_pcb_calc', true );
			if ( ! is_array( $calc ) || empty( $calc['qty'] ) ) {
				return pcb_calc_err( 'В этой заявке нет данных калькулятора.' );
			}
			// комплект — эталоном целиком (платы «не в заказе» отбрасываются); «посчитана отдельно» — одна плата
			$isSet = empty( $calc['solo'] ) && ! empty( $calc['set']['boards'] );
			$in    = pcb_calc_input_clean( $isSet ? array( 'set' => array( 'sets' => $calc['set']['sets'] ?? $calc['qty'], 'boards' => $calc['set']['boards'] ) ) : $calc );
			if ( $isSet && ! $in['set']['boards'] ) {
				return pcb_calc_err( 'В комплекте этой заявки нет плат в заказе.' );
			}
			$list = pcb_calc_etalons();
			if ( count( $list ) >= 12 ) {
				return pcb_calc_err( 'Эталонов уже 12 — удалите лишний.' );
			}
			$number = (string) get_post_meta( $id, '_pcb_number', true );
			$list[] = array( 'id' => 'r' . $id . '-' . wp_generate_password( 4, false ), 'n' => 'Заявка ' . $number . ( $isSet ? ', комплект' : '' ), 'from' => $number, 'in' => $in );
			pcb_calc_etalons_save( $list );
			return $state();
		},
	) );

	register_rest_route( $ns, '/calc/etalon-delete', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) use ( $state ) {
			$list = array_values( array_filter( pcb_calc_etalons(), function ( $e ) use ( $r ) {
				return $e['id'] !== (string) $r['id'];
			} ) );
			if ( ! $list ) {
				return pcb_calc_err( 'Нужен хотя бы один эталон.' );
			}
			pcb_calc_etalons_save( $list );
			return $state();
		},
	) );

	// сайт: оценка по опубликованной формуле
	register_rest_route( $ns, '/calc/quote', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			if ( ! pcb_calc_site_on() ) {
				return pcb_calc_err( 'Расчёт на сервере ещё не включён.', 404 );
			}
			// защита от перебора: не больше 600 расчётов за 10 минут с одного адреса (адрес не хранится — только хеш)
			$key = 'pcb_cq_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) );
			$n   = (int) get_transient( $key );
			if ( $n >= 600 ) {
				return pcb_calc_err( 'Слишком много запросов — попробуйте через несколько минут.', 429 );
			}
			set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
			$pub = pcb_calc_published();
			$res = pcb_calc_public( pcb_calc_run( $pub['params'], pcb_calc_input_clean( $r->get_json_params() ?: $r->get_params() ), pcb_calc_fx( $pub['params'] ) ) );
			$res['version'] = (int) $pub['v'];
			$resp = rest_ensure_response( $res );
			$resp->header( 'Cache-Control', 'no-store' );
			return $resp;
		},
	) );
} );

// Калькулятор темы узнаёт, что считать нужно на сервере, — только после первой публикации.
// set: true — сервер считает комплект одним запросом (доставка, таможня, минимум, округление — один раз).
add_action( 'wp_enqueue_scripts', function () {
	if ( wp_script_is( 'theme-calculator', 'enqueued' ) && pcb_calc_site_on() ) {
		wp_add_inline_script( 'theme-calculator', 'window.PCBCalcServer=' . wp_json_encode( array( 'url' => esc_url_raw( rest_url( 'pcb-panel/v1/calc/quote' ) ), 'set' => true ) ) . ';', 'before' );
	}
}, 20 );
