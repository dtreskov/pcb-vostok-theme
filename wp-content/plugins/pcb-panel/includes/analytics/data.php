<?php
/**
 * Данные страницы «Аналитика»: REST /wp-json/pcb-panel/v1/analytics?days=7|30|90.
 *
 * Источники и полнота:
 *   заявки (CPT pcb_request из темы)     — все обращения;
 *   свои события (events.php)            — без cookie, кроме посетителей с блокировщиками;
 *   Яндекс Метрика (yandex.php)          — только посетители с согласием на cookie;
 *   Яндекс Вебмастер (yandex.php)        — весь поиск Яндекса.
 * Любой внешний источник может быть не подключён — блок тогда приходит как null,
 * а в status объяснено, что сделать.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route( 'pcb-panel/v1', '/analytics', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () { return current_user_can( 'pcb_analytics_view' ); },
		'args'                => array( 'days' => array( 'default' => 30, 'sanitize_callback' => 'absint' ) ),
		'callback'            => function ( WP_REST_Request $r ) {
			$n = in_array( (int) $r['days'], array( 7, 30, 90 ), true ) ? (int) $r['days'] : 30;
			return pcb_analytics_payload( $n );
		},
	) );
	register_rest_route( 'pcb-panel/v1', '/analytics/refresh', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () { pcb_ya_flush_cache(); return array( 'ok' => true ); },
	) );
	register_rest_route( 'pcb-panel/v1', '/analytics/goals', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () {
			$made = pcb_goals_setup();
			if ( is_wp_error( $made ) ) {
				$msg = $made->get_error_message();
				$st  = (int) ( $made->get_error_data()['status'] ?? 0 );
				if ( 403 === $st ) {
					$msg = 'У токена нет права metrika:write. Создайте цели вручную по списку или выпустите токен с этим правом.';
				}
				return new WP_Error( 'pcb_goals', $msg, array( 'status' => 400 ) );
			}
			pcb_ya_flush_cache();
			return array( 'made' => $made );
		},
	) );
} );

/* ---------- корзины параметров калькулятора (те же, что в site-goals.js) ---------- */

function pcb_an_bucket( $kind, $v ) {
	$v = is_numeric( $v ) ? (float) $v : $v;
	switch ( $kind ) {
		case 'layers':
			$l = (int) $v;
			return $l <= 0 ? '' : ( $l >= 14 ? '14+' : ( $l >= 8 ? '8-12' : (string) $l ) );
		case 'qty':
			$q = (float) $v;
			return $q <= 0 ? '' : ( $q <= 10 ? '1-10' : ( $q <= 50 ? '11-50' : ( $q <= 200 ? '51-200' : ( $q <= 1000 ? '201-1000' : '1000+' ) ) ) );
		case 'size':
			$s = (float) $v;
			return $s <= 0 ? '' : ( $s <= 50 ? '0-50' : ( $s <= 100 ? '50-100' : ( $s <= 200 ? '100-200' : '200+' ) ) );
	}
	return (string) $v;
}

/** Подписи корзин в порядке вывода: ключ => array( подпись, пояснение ). */
function pcb_an_demand_labels() {
	return array(
		'layers' => array( '1' => array( '1 слой', '' ), '2' => array( '2 слоя', '' ), '4' => array( '4 слоя', '' ), '6' => array( '6 слоёв', '' ), '8-12' => array( '8–12 слоёв', '' ), '14+' => array( '14 и более', '' ) ),
		'qty'    => array( '1-10' => array( 'до 10 шт.', '' ), '11-50' => array( '11–50', '' ), '51-200' => array( '51–200', '' ), '201-1000' => array( '201–1000', '' ), '1000+' => array( 'более 1000', '' ) ),
		'finish' => array( 'hasl' => array( 'HASL', '' ), 'enig' => array( 'ENIG', 'иммерсионное золото' ), 'iag' => array( 'ImAg', 'иммерсионное серебро' ), 'enepig' => array( 'ENEPIG', '' ), 'flash' => array( 'Flashgold', '' ), 'hard' => array( 'Hardgold', '' ), 'other' => array( 'Другое', '' ) ),
		'mat'    => array( 'fr4' => array( 'FR-4', '' ), 'fr4tg' => array( 'FR-4 High Tg', '' ), 'alu' => array( 'Алюминий', '' ), 'pi' => array( 'Полиимид', 'гибкие' ), 'hf' => array( 'ВЧ / СВЧ', 'Rogers и аналоги' ) ),
		'size'   => array( '0-50' => array( 'до 50 мм', '' ), '50-100' => array( '50–100 мм', '' ), '100-200' => array( '100–200 мм', '' ), '200+' => array( 'более 200 мм', '' ) ),
	);
}

/* ---------- заявки ---------- */

function pcb_an_requests( $from, $to ) {
	$ids = get_posts( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'date_query'     => array( array( 'after' => $from . ' 00:00:00', 'before' => $to . ' 23:59:59', 'inclusive' => true ) ),
	) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = array(
			'day'     => get_the_date( 'Y-m-d', $id ),
			'source'  => get_post_meta( $id, '_pcb_source', true ) === 'calc' ? 'calc' : 'home',
			'service' => (string) get_post_meta( $id, '_pcb_service', true ),
			'calc'    => (array) get_post_meta( $id, '_pcb_calc', true ),
			'mail'    => (array) get_post_meta( $id, '_pcb_mail', true ),
		);
	}
	return $out;
}

/* ---------- вопросы FAQ со страницы (подписи для целей faq_N) ---------- */

function pcb_an_faq_labels() {
	$front = (int) get_option( 'page_on_front' );
	$html  = $front ? (string) get_post_field( 'post_content', $front ) : '';
	$pos   = strpos( $html, 'id="faq"' );
	$out   = array();
	if ( false !== $pos && preg_match_all( '~<details class="wp-block-details">\s*<summary[^>]*>(.*?)</summary>~s', substr( $html, $pos ), $m ) ) {
		foreach ( array_slice( $m[1], 0, 6 ) as $i => $q ) {
			$out[ 'faq_' . ( $i + 1 ) ] = trim( wp_strip_all_tags( $q ) );
		}
	}
	return $out;
}

/* ---------- сборка ---------- */

function pcb_analytics_payload( $n ) {
	$today = wp_date( 'Y-m-d' );
	$from  = wp_date( 'Y-m-d', strtotime( $today . ' -' . ( $n - 1 ) . ' days' ) );
	$pfrom = wp_date( 'Y-m-d', strtotime( $from . ' -' . $n . ' days' ) );
	$pto   = wp_date( 'Y-m-d', strtotime( $from . ' -1 day' ) );

	$status = array( 'metrika' => 'ok', 'metrikaMsg' => '', 'webmaster' => 'ok', 'wmMsg' => '', 'goalsMissing' => 0, 'canSetup' => current_user_can( 'manage_options' ) );
	$fresh  = null;
	$keep   = function ( $r ) use ( &$fresh ) { $fresh = null === $fresh ? $r['time'] : min( $fresh, $r['time'] ); return $r['data']; };

	/* --- заявки --- */
	$reqs  = pcb_an_requests( $from, $today );
	$preqs = pcb_an_requests( $pfrom, $pto );
	$days  = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$d          = wp_date( 'Y-m-d', strtotime( $from . ' +' . $i . ' days' ) );
		$days[ $d ] = array( 'date' => $d, 'visits' => null, 'reqHome' => 0, 'reqCalc' => 0 );
	}
	$home = 0;
	$calc = 0;
	foreach ( $reqs as $q ) {
		$k = 'calc' === $q['source'] ? 'reqCalc' : 'reqHome';
		if ( isset( $days[ $q['day'] ] ) ) {
			$days[ $q['day'] ][ $k ]++;
		}
		'calc' === $q['source'] ? $calc++ : $home++;
	}

	/* --- свои события --- */
	$consent  = pcb_events_sum( 'consent', $from, $today );
	$pconsent = pcb_events_sum( 'consent', $pfrom, $pto );
	$calcEv   = pcb_events_sum( 'calc', $from, $today );
	$pcalcEv  = pcb_events_sum( 'calc', $pfrom, $pto );
	$accept   = (int) ( $consent['accept'] ?? 0 );
	$nec      = (int) ( $consent['necessary'] ?? 0 );
	$share    = ( $accept + $nec ) ? $accept / ( $accept + $nec ) : null;
	$pa       = (int) ( $pconsent['accept'] ?? 0 );
	$pn       = (int) ( $pconsent['necessary'] ?? 0 );
	$pshare   = ( $pa + $pn ) ? $pa / ( $pa + $pn ) : null;

	/* --- спрос: в заявках (s1) и только расчёт (s2) --- */
	$labels = pcb_an_demand_labels();
	$inReq  = array();
	foreach ( $reqs as $q ) {
		$c = $q['calc'];
		if ( ! $c ) {
			continue;
		}
		$vals = array(
			'layers' => pcb_an_bucket( 'layers', $c['layers'] ?? '' ),
			'qty'    => pcb_an_bucket( 'qty', $c['qty'] ?? '' ),
			'finish' => (string) ( $c['finish'] ?? '' ),
			'mat'    => (string) ( $c['mat'] ?? '' ),
			'size'   => pcb_an_bucket( 'size', max( (float) ( $c['len'] ?? 0 ), (float) ( $c['wid'] ?? 0 ) ) ),
		);
		foreach ( $vals as $k => $v ) {
			if ( '' !== $v ) {
				$inReq[ $k ][ $v ] = ( $inReq[ $k ][ $v ] ?? 0 ) + 1;
			}
		}
	}
	$demand = array();
	foreach ( $labels as $k => $set ) {
		$rows = array();
		foreach ( $set as $v => $l ) {
			$s1     = (int) ( $inReq[ $k ][ $v ] ?? 0 );
			$all    = (int) ( $calcEv[ $k . ':' . $v ] ?? 0 ); // все расчёты с этим значением, включая дошедшие до заявки
			$rows[] = array( 'label' => $l[0], 'hint' => $l[1], 's1' => $s1, 's2' => max( 0, $all - $s1 ) );
		}
		$demand[ $k ] = $rows;
	}
	$svc = array();
	foreach ( $reqs as $q ) {
		$label         = function_exists( 'pcb_rq_service_label' ) ? pcb_rq_service_label( $q['service'] ) : $q['service'];
		$svc[ $label ] = ( $svc[ $label ] ?? 0 ) + 1;
	}
	arsort( $svc );
	$demand['services'] = array();
	foreach ( $svc as $l => $v ) {
		$demand['services'][] = array( 'label' => $l, 's1' => $v );
	}

	/* --- Метрика --- */
	$m = array( 'visits' => null, 'pvisits' => null, 'ladder' => null, 'faq' => null, 'actions' => null, 'funnels' => null, 'sources' => null, 'cities' => null, 'devices' => null, 'newShare' => null, 'heat' => null );
	if ( ! pcb_ya_counter() || '' === pcb_ya_token() ) {
		$status['metrika']    = 'off';
		$status['metrikaMsg'] = ! pcb_ya_counter() ? 'Не задан номер счётчика PCB_METRIKA_ID.' : 'Не задан токен PCB_YANDEX_TOKEN.';
	} else {
		$err = null;
		$r   = pcb_metrika_report( 'ym:s:visits', 'ym:s:date', $pfrom, $today );
		if ( is_wp_error( $r ) ) {
			$err = $r;
		} else {
			$m['visits']  = 0;
			$m['pvisits'] = 0;
			foreach ( (array) ( $keep( $r )['data'] ?? array() ) as $row ) {
				$d = $row['dimensions'][0]['name'] ?? '';
				$v = (int) ( $row['metrics'][0] ?? 0 );
				if ( isset( $days[ $d ] ) ) {
					$days[ $d ]['visits'] = $v;
					$m['visits']         += $v;
				} elseif ( $d >= $pfrom && $d <= $pto ) {
					$m['pvisits'] += $v;
				}
			}
			foreach ( $days as $d => $row ) {
				if ( null === $row['visits'] ) {
					$days[ $d ]['visits'] = 0;
				}
			}
			$m['sources'] = pcb_an_dim_rows( pcb_metrika_report( 'ym:s:visits', 'ym:s:lastTrafficSource', $from, $today, array( 'limit' => 8, 'sort' => '-ym:s:visits' ) ), $keep, 6, 'Другие источники' );
			$m['cities']  = pcb_an_dim_rows( pcb_metrika_report( 'ym:s:visits', 'ym:s:regionCity', $from, $today, array( 'limit' => 12, 'sort' => '-ym:s:visits' ) ), $keep, 6, 'Другие города' );
			$dev          = pcb_metrika_report( 'ym:s:visits', 'ym:s:deviceCategory', $from, $today );
			if ( ! is_wp_error( $dev ) ) {
				$desk = 0;
				$mob  = 0;
				foreach ( (array) ( $keep( $dev )['data'] ?? array() ) as $row ) {
					if ( ( $row['dimensions'][0]['id'] ?? '' ) === 'desktop' ) {
						$desk += (int) $row['metrics'][0];
					} else {
						$mob += (int) $row['metrics'][0];
					}
				}
				$m['devices'] = array( 'desk' => $desk, 'mob' => $mob );
			}
			$nv = pcb_metrika_report( 'ym:s:percentNewVisitors', array(), $from, $today );
			if ( ! is_wp_error( $nv ) ) {
				$m['newShare'] = round( (float) ( $keep( $nv )['totals'][0] ?? 0 ) / 100, 3 );
			}
			$ht = pcb_metrika_report( 'ym:s:visits', array( 'ym:s:dayOfWeek', 'ym:s:hour' ), $from, $today, array( 'limit' => 200 ) );
			if ( ! is_wp_error( $ht ) ) {
				$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
				foreach ( (array) ( $keep( $ht )['data'] ?? array() ) as $row ) {
					$wd = (int) ( $row['dimensions'][0]['id'] ?? 0 ); // 1 — понедельник
					$hr = (int) ( $row['dimensions'][1]['id'] ?? -1 );
					if ( $wd >= 1 && $wd <= 7 && $hr >= 0 && $hr <= 23 ) {
						$grid[ $wd - 1 ][ $hr ] += (int) $row['metrics'][0];
					}
				}
				$m['heat'] = $grid;
			}

			/* цели */
			$map = pcb_goals_map();
			if ( is_wp_error( $map ) ) {
				$err = $map;
			} else {
				$status['goalsMissing'] = count( array_diff_key( pcb_goals_catalog(), $map ) );
				$g                      = $map ? pcb_goals_reaches( $from, $today ) : array( 'time' => time(), 'data' => array() );
				if ( is_wp_error( $g ) ) {
					$err = $g;
				} else {
					$g = $keep( $g );
					if ( $map ) {
						pcb_an_goal_blocks( $m, $g );
					}
				}
			}
		}
		if ( $err ) {
			$status['metrika']    = 'error';
			$status['metrikaMsg'] = pcb_an_err_text( $err );
		} elseif ( $status['goalsMissing'] ) {
			$status['metrika'] = 'nogoals';
		}
	}

	$est  = ( null !== $m['visits'] && $share ) ? (int) round( $m['visits'] / $share ) : $m['visits'];
	$pest = ( null !== $m['pvisits'] && $pshare ) ? (int) round( $m['pvisits'] / $pshare ) : $m['pvisits'];
	$req  = count( $reqs );
	$preq = count( $preqs );

	/* --- Вебмастер --- */
	$seo = null;
	if ( '' === pcb_ya_token() ) {
		$status['webmaster'] = 'off';
		$status['wmMsg']     = 'Не задан токен PCB_YANDEX_TOKEN.';
	} else {
		$w = pcb_wm_summary( $from, $today );
		if ( is_wp_error( $w ) ) {
			$status['webmaster'] = 'error';
			$status['wmMsg']     = pcb_an_err_text( $w );
		} else {
			$seo = $w['data'];
		}
	}

	return array(
		'n'      => $n,
		'range'  => array( 'from' => $from, 'to' => $today ),
		'fresh'  => $fresh ? wp_date( 'd.m в H:i', $fresh ) : null,
		'status' => $status,
		'sum'    => array(
			'req'       => $req,
			'preq'      => $preq,
			'reqHome'   => $home,
			'reqCalc'   => $calc,
			'visits'    => $m['visits'],
			'pvisits'   => $m['pvisits'],
			'est'       => $est,
			'pest'      => $pest,
			'conv'      => $est ? $req / $est : null,
			'pconv'     => $pest ? $preq / $pest : null,
			'calcs'     => (int) ( $calcEv['all'] ?? 0 ),
			'pcalcs'    => (int) ( $pcalcEv['all'] ?? 0 ),
			'calcToReq' => $calc,
			'share'     => $share,
			'pshare'    => $pshare,
			'accept'    => $accept,
			'necessary' => $nec,
		),
		'days'     => array_values( $days ),
		'demand'   => $demand,
		'ladder'   => $m['ladder'],
		'faq'      => $m['faq'],
		'actions'  => $m['actions'],
		'funnels'  => $m['funnels'],
		'sources'  => $m['sources'],
		'cities'   => $m['cities'],
		'devices'  => $m['devices'],
		'newShare' => $m['newShare'],
		'heat'     => $m['heat'],
		'tech'     => pcb_an_tech( $reqs, $from, $today ),
		'seo'      => $seo,
		'goals'    => $status['goalsMissing'] ? array_diff_key( pcb_goals_catalog(), is_array( $map ?? null ) ? $map : array() ) : null,
	);
}

/** Строки «значение измерения → визиты» с хвостом «Другие». */
function pcb_an_dim_rows( $r, $keep, $top, $rest_label ) {
	if ( is_wp_error( $r ) ) {
		return null;
	}
	$data  = $keep( $r );
	$rows  = array();
	$total = (int) ( $data['totals'][0] ?? 0 );
	$sum   = 0;
	foreach ( array_slice( (array) ( $data['data'] ?? array() ), 0, $top ) as $row ) {
		$v      = (int) ( $row['metrics'][0] ?? 0 );
		$sum   += $v;
		$rows[] = array( 'label' => (string) ( $row['dimensions'][0]['name'] ?? '—' ), 's1' => $v );
	}
	if ( $total > $sum ) {
		$rows[] = array( 'label' => $rest_label, 's1' => $total - $sum );
	}
	return $rows;
}

/** Лестница секций, FAQ, действия и воронки — из достижений целей. */
function pcb_an_goal_blocks( &$m, $g ) {
	$cat  = pcb_goals_catalog();
	$base = max( 1, (int) ( $g['sec_main'] ?? 0 ) );
	$m['ladder'] = array();
	foreach ( $cat as $id => $name ) {
		if ( 0 !== strpos( $id, 'sec_' ) || ! isset( $g[ $id ] ) ) {
			continue;
		}
		$t             = str_replace( 'Секция: ', '', $name );
		$m['ladder'][] = array( 'label' => mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 ), 'hint' => '#' . substr( $id, 4 ), 'n' => $g[ $id ], 'pct' => (int) round( 100 * $g[ $id ] / $base ) );
	}
	$faq      = pcb_an_faq_labels();
	$m['faq'] = array();
	for ( $i = 1; $i <= 6; $i++ ) {
		if ( isset( $g[ 'faq_' . $i ] ) ) {
			$m['faq'][] = array( 'label' => $faq[ 'faq_' . $i ] ?? ( 'Вопрос ' . $i ), 's1' => $g[ 'faq_' . $i ] );
		}
	}
	usort( $m['faq'], function ( $a, $b ) { return $b['s1'] - $a['s1']; } );
	$m['actions'] = array();
	foreach ( array( 'act_calc' => 'Переход в калькулятор', 'act_mail' => 'Клик по почте', 'act_phone' => 'Клик по телефону', 'act_carousel' => 'Листали карусель услуг', 'act_filter' => 'Фильтр услуг в цикле', 'act_more' => 'Открыли «Подробнее»' ) as $id => $l ) {
		if ( isset( $g[ $id ] ) ) {
			$m['actions'][] = array( $l, $g[ $id ] );
		}
	}
	$f            = function ( $id ) use ( $g ) { return (int) ( $g[ $id ] ?? 0 ); };
	$m['funnels'] = array(
		'home' => array( array( 'Визит на главную', $f( 'sec_main' ) ), array( 'Дошли до формы заявки', $f( 'sec_request' ) ), array( 'Начали заполнять', $f( 'form_start' ) ), array( 'Отправили заявку', $f( 'request_home' ) ) ),
		'calc' => array( array( 'Открыли калькулятор', $f( 'calc_open' ) ), array( 'Загрузили файлы', $f( 'calc_upload' ) ), array( 'Получили расчёт', $f( 'calc_result' ) ), array( 'Прикрепили расчёт к заявке', $f( 'calc_attach' ) ), array( 'Отправили заявку', $f( 'request_calc' ) ) ),
	);
}

function pcb_an_err_text( WP_Error $e ) {
	$st = (int) ( $e->get_error_data()['status'] ?? 0 );
	if ( 401 === $st || 403 === $st ) {
		return 'Яндекс отклонил токен: он истёк или у него нет нужных прав. Выпустите новый и замените PCB_YANDEX_TOKEN.';
	}
	return $e->get_error_message();
}

/* ---------- техническое состояние ---------- */

function pcb_an_tech( $reqs, $from, $to ) {
	$rows = array();

	$bad = 0;
	foreach ( $reqs as $q ) {
		if ( isset( $q['mail']['notify'] ) && 'ok' !== $q['mail']['notify'] ) {
			$bad++;
		}
	}
	$rows[] = array( 'name' => 'Письма менеджеру', 'sub' => 'уведомления о новых заявках', 'val' => $bad . ' не доставлено', 'st' => $bad ? 'bad' : 'ok', 'stl' => $bad ? 'Проверить почту' : 'В порядке' );

	$fe   = pcb_events_sum( 'form_err', $from, $to );
	$fl   = array( 'captcha' => 'капча не пройдена', 'files' => 'файлы отклонены', 'empty' => 'пустая заявка', 'rate' => 'слишком часто', 'save' => 'не сохранилась' );
	$sum  = array_sum( $fe );
	$sub  = array();
	foreach ( $fl as $k => $l ) {
		if ( ! empty( $fe[ $k ] ) ) {
			$sub[] = $l . ' — ' . $fe[ $k ];
		}
	}
	$rows[] = array( 'name' => 'Отказы при отправке формы', 'sub' => $sub ? implode( ', ', $sub ) : 'капча, файлы, пустые заявки', 'val' => $sum, 'st' => ! empty( $fe['save'] ) ? 'bad' : ( $sum ? 'warn' : 'ok' ), 'stl' => ! empty( $fe['save'] ) ? 'Разобрать' : ( $sum ? 'Проверить' : 'В порядке' ) );

	$ce  = pcb_events_sum( 'calc_err', $from, $to );
	$cl  = array( 'zip' => 'повреждённый ZIP', 'rar' => 'RAR / 7z', 'format' => 'неподдерживаемый формат', 'ipc' => 'не IPC-2581', 'xml' => 'не читается XML' );
	$sum = array_sum( $ce );
	$sub = array();
	foreach ( $cl as $k => $l ) {
		if ( ! empty( $ce[ $k ] ) ) {
			$sub[] = $l . ' — ' . $ce[ $k ];
		}
	}
	$rows[] = array( 'name' => 'Файлы, которые калькулятор не разобрал', 'sub' => $sub ? implode( ', ', $sub ) : 'ZIP, RAR, IPC-2581', 'val' => $sum, 'st' => $sum ? 'warn' : 'ok', 'stl' => $sum ? 'Проверить' : 'В порядке' );

	$tok = pcb_ya_token();
	$ys  = (array) get_option( 'pcb_ya_status', array() );
	if ( '' === $tok ) {
		$rows[] = array( 'name' => 'Токен API Яндекса', 'sub' => 'Метрика и Вебмастер', 'val' => 'не задан', 'st' => 'warn', 'stl' => 'Подключить' );
	} elseif ( 'auth' === ( $ys['state'] ?? '' ) ) {
		$rows[] = array( 'name' => 'Токен API Яндекса', 'sub' => 'Яндекс отклонил токен — выпустите новый', 'val' => 'отклонён', 'st' => 'bad', 'stl' => 'Заменить' );
	} else {
		$left = null;
		if ( defined( 'PCB_YANDEX_TOKEN_ISSUED' ) && strtotime( PCB_YANDEX_TOKEN_ISSUED ) ) {
			$left = PCB_YA_TOKEN_DAYS - (int) floor( ( time() - strtotime( PCB_YANDEX_TOKEN_ISSUED ) ) / DAY_IN_SECONDS );
		}
		$rows[] = array(
			'name' => 'Токен API Яндекса',
			'sub'  => null === $left ? 'Метрика и Вебмастер · дату выдачи можно указать в PCB_YANDEX_TOKEN_ISSUED' : 'Метрика и Вебмастер',
			'val'  => null === $left ? 'работает' : ( $left > 0 ? 'ещё ' . $left . ' дн.' : 'срок вышел' ),
			'st'   => ( null !== $left && $left < 14 ) ? 'warn' : 'ok',
			'stl'  => ( null !== $left && $left < 14 ) ? 'Скоро заменить' : 'Действует',
		);
	}
	return $rows;
}
