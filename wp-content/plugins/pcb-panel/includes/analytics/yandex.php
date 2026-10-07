<?php
/**
 * Клиент API Яндекса: Метрика (Reporting + Management) и Вебмастер v4.
 *
 * Настройки — константы в wp-config.php (в репозиторий не попадают):
 *   PCB_METRIKA_ID            номер счётчика (тот же, что для cookie-consent.js в теме)
 *   PCB_YANDEX_TOKEN          OAuth-токен: metrika:read [+ metrika:write], webmaster:hostinfo
 *   PCB_YANDEX_TOKEN_ISSUED   необязательно, дата выдачи токена 'ГГГГ-ММ-ДД' — панель покажет, сколько дней ему осталось
 *   PCB_WEBMASTER_HOST        необязательно, адрес сайта в Вебмастере, если он отличается от адреса WordPress
 *
 * Ответы кешируются в transients на 6 часов: отчёты Метрики обновляются не мгновенно,
 * а у API есть лимиты на число запросов.
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_YA_CACHE_TTL', 6 * HOUR_IN_SECONDS );
define( 'PCB_YA_TOKEN_DAYS', 180 ); // срок жизни токена по документации Вебмастера

function pcb_ya_token() {
	return defined( 'PCB_YANDEX_TOKEN' ) ? (string) PCB_YANDEX_TOKEN : '';
}

function pcb_ya_counter() {
	return defined( 'PCB_METRIKA_ID' ) ? (int) PCB_METRIKA_ID : 0;
}

/**
 * GET/POST к API. Возвращает массив ответа или WP_Error.
 * Последний результат по каждому сервису запоминается для строки «Токен API Яндекса».
 */
function pcb_ya_request( $url, $method = 'GET', $body = null ) {
	$token = pcb_ya_token();
	if ( '' === $token ) {
		return new WP_Error( 'pcb_ya_no_token', 'Не задан токен API Яндекса' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'OAuth ' . $token, 'Accept' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}
	$service = false !== strpos( $url, 'webmaster' ) ? 'webmaster' : 'metrika';
	$res     = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		pcb_ya_remember_status( $service, 'net', '', $res->get_error_message() );
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		// Метрика: {message, errors:[{error_type, message}]}; Вебмастер: {error_code, error_message}
		$d    = is_array( $data ) ? $data : array();
		$ycode = (string) ( $d['error_code'] ?? ( $d['errors'][0]['error_type'] ?? '' ) );
		$msg   = (string) ( $d['error_message'] ?? ( $d['message'] ?? ( $d['errors'][0]['message'] ?? ( 'HTTP ' . $code ) ) ) );
		pcb_ya_remember_status( $service, 401 === $code ? 'auth' : ( 403 === $code ? 'forbidden' : 'http' ), $ycode, $msg );
		return new WP_Error( 'pcb_ya_http_' . $code, $msg, array( 'status' => $code, 'code' => $ycode, 'service' => $service ) );
	}
	pcb_ya_remember_status( $service, 'ok', '', '' );
	return is_array( $data ) ? $data : array();
}

/** Последний ответ каждого сервиса — для строки «Токен API Яндекса». */
function pcb_ya_remember_status( $service, $state, $code, $msg ) {
	$all             = (array) get_option( 'pcb_ya_status', array() );
	$all[ $service ] = array( 'state' => $state, 'code' => $code, 'msg' => mb_substr( (string) $msg, 0, 200 ), 'time' => time() );
	update_option( 'pcb_ya_status', $all, false );
}

/** Запрос с кешем. $key — короткое имя, $params — то, от чего зависит ответ. */
function pcb_ya_cached( $key, $params, $fetch ) {
	$tkey = 'pcb_ya_' . $key . '_' . md5( wp_json_encode( $params ) );
	$hit  = get_transient( $tkey );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$data = call_user_func( $fetch );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$wrapped = array( 'time' => time(), 'data' => $data );
	set_transient( $tkey, $wrapped, PCB_YA_CACHE_TTL );
	// список ключей — чтобы кнопка «Обновить» могла сбросить кеш
	$keys          = (array) get_option( 'pcb_ya_cache_keys', array() );
	$keys[ $tkey ] = 1;
	update_option( 'pcb_ya_cache_keys', array_slice( $keys, -300, null, true ), false );
	return $wrapped;
}

function pcb_ya_flush_cache() {
	foreach ( array_keys( (array) get_option( 'pcb_ya_cache_keys', array() ) ) as $k ) {
		delete_transient( $k );
	}
	delete_option( 'pcb_ya_cache_keys' );
	delete_transient( 'pcb_ya_goal_map' );
}

/* =================================
   Метрика
   ================================= */

/**
 * Отчёт Reporting API (stat/v1/data). Возвращает array( 'time' => …, 'data' => ответ ) или WP_Error.
 */
function pcb_metrika_report( $metrics, $dimensions, $date1, $date2, $extra = array() ) {
	$counter = pcb_ya_counter();
	if ( ! $counter ) {
		return new WP_Error( 'pcb_ya_no_counter', 'Не задан номер счётчика' );
	}
	$q = array_merge( array(
		'ids'        => $counter,
		'metrics'    => implode( ',', (array) $metrics ),
		'date1'      => $date1,
		'date2'      => $date2,
		'lang'       => 'ru',
		'limit'      => 100,
		'accuracy'   => 'full',
	), $dimensions ? array( 'dimensions' => implode( ',', (array) $dimensions ) ) : array(), $extra );
	return pcb_ya_cached( 'm', $q, function () use ( $q ) {
		return pcb_ya_request( add_query_arg( array_map( 'rawurlencode', $q ), 'https://api-metrika.yandex.net/stat/v1/data' ) );
	} );
}

/**
 * Цели сайта: идентификатор JavaScript-события => подпись.
 * Те же идентификаторы отправляет тема (assets/js/site-goals.js) — менять только вместе.
 */
function pcb_goals_catalog() {
	return array(
		// секции главной в порядке страницы
		'sec_main'          => 'Секция: первый экран',
		'sec_services'      => 'Секция: услуги',
		'sec_capabilities'  => 'Секция: производственный цикл',
		'sec_order'         => 'Секция: приём заявки',
		'sec_audit'         => 'Секция: аудит файлов',
		'sec_manufacturing' => 'Секция: производство плат',
		'sec_components'    => 'Секция: закупка компонентов',
		'sec_installation'  => 'Секция: монтаж',
		'sec_qc'            => 'Секция: приёмка ОТК',
		'sec_inspection'    => 'Секция: проверка по ПМИ',
		'sec_logistics'     => 'Секция: доставка',
		'sec_overview'      => 'Секция: итог',
		'sec_about'         => 'Секция: о нас',
		'sec_faq'           => 'Секция: FAQ',
		'sec_request'       => 'Секция: заявка на расчёт',
		// вопросы FAQ по порядку на странице
		'faq_1'             => 'FAQ: вопрос 1',
		'faq_2'             => 'FAQ: вопрос 2',
		'faq_3'             => 'FAQ: вопрос 3',
		'faq_4'             => 'FAQ: вопрос 4',
		'faq_5'             => 'FAQ: вопрос 5',
		'faq_6'             => 'FAQ: вопрос 6',
		// действия
		'act_calc'          => 'Действие: переход в калькулятор',
		'act_mail'          => 'Действие: клик по почте',
		'act_phone'         => 'Действие: клик по телефону',
		'act_carousel'      => 'Действие: листали карусель',
		'act_filter'        => 'Действие: фильтр услуг',
		'act_more'          => 'Действие: открыли «Подробнее»',
		// воронки
		'form_start'        => 'Форма: начали заполнять (главная)',
		'request_home'      => 'Заявка отправлена с главной',
		'calc_open'         => 'Калькулятор: открыли',
		'calc_upload'       => 'Калькулятор: загрузили файлы',
		'calc_result'       => 'Калькулятор: получили расчёт',
		'calc_attach'       => 'Калькулятор: прикрепили расчёт к заявке',
		'request_calc'      => 'Заявка отправлена с калькулятора',
	);
}

/** Цели счётчика: идентификатор события => ID цели в Метрике. Кеш 6 часов. */
function pcb_goals_map( $fresh = false ) {
	if ( ! $fresh ) {
		$hit = get_transient( 'pcb_ya_goal_map' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
	}
	$counter = pcb_ya_counter();
	if ( ! $counter ) {
		return new WP_Error( 'pcb_ya_no_counter', 'Не задан номер счётчика' );
	}
	$res = pcb_ya_request( 'https://api-metrika.yandex.net/management/v1/counter/' . $counter . '/goals' );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$map = array();
	foreach ( (array) ( $res['goals'] ?? array() ) as $g ) {
		if ( ( $g['type'] ?? '' ) !== 'action' ) {
			continue;
		}
		foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
			if ( ( $c['type'] ?? '' ) === 'exact' && isset( $c['url'] ) ) {
				$map[ (string) $c['url'] ] = (int) $g['id'];
			}
		}
	}
	set_transient( 'pcb_ya_goal_map', $map, PCB_YA_CACHE_TTL );
	return $map;
}

/** Создать недостающие цели (нужно право metrika:write). Возвращает число созданных или WP_Error. */
function pcb_goals_setup() {
	$counter = pcb_ya_counter();
	$map     = pcb_goals_map( true );
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$made = 0;
	foreach ( pcb_goals_catalog() as $id => $name ) {
		if ( isset( $map[ $id ] ) ) {
			continue;
		}
		$res = pcb_ya_request( 'https://api-metrika.yandex.net/management/v1/counter/' . $counter . '/goals', 'POST', array(
			'goal' => array(
				'name'       => 'PCB · ' . $name,
				'type'       => 'action',
				'conditions' => array( array( 'type' => 'exact', 'url' => $id ) ),
			),
		) );
		if ( is_wp_error( $res ) ) {
			return 0 === $made ? $res : $made;
		}
		$made++;
	}
	delete_transient( 'pcb_ya_goal_map' );
	return $made;
}

/**
 * Достижения целей за период: идентификатор события => число.
 * Метрика отдаёт не больше 20 метрик за запрос — делим на пачки.
 */
function pcb_goals_reaches( $date1, $date2 ) {
	$map = pcb_goals_map();
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$out  = array();
	$time = time();
	foreach ( array_chunk( $map, 20, true ) as $chunk ) {
		$metrics = array();
		foreach ( $chunk as $gid ) {
			$metrics[] = 'ym:s:goal' . $gid . 'reaches';
		}
		$r = pcb_metrika_report( $metrics, array(), $date1, $date2 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$time   = min( $time, $r['time'] );
		$totals = $r['data']['totals'] ?? array();
		$i      = 0;
		foreach ( $chunk as $ev => $gid ) {
			$out[ $ev ] = (int) round( $totals[ $i++ ] ?? 0 );
		}
	}
	return array( 'time' => $time, 'data' => $out );
}

/* =================================
   Вебмастер
   ================================= */

function pcb_wm_ids() {
	$uid = (int) get_option( 'pcb_wm_user_id', 0 );
	if ( ! $uid ) {
		$r = pcb_ya_request( 'https://api.webmaster.yandex.net/v4/user' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$uid = (int) ( $r['user_id'] ?? 0 );
		update_option( 'pcb_wm_user_id', $uid, false );
	}
	$host = get_transient( 'pcb_wm_host' );
	if ( ! $host ) {
		$r = pcb_ya_request( 'https://api.webmaster.yandex.net/v4/user/' . $uid . '/hosts' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$want  = wp_parse_url( defined( 'PCB_WEBMASTER_HOST' ) ? PCB_WEBMASTER_HOST : home_url(), PHP_URL_HOST );
		$first = '';
		foreach ( (array) ( $r['hosts'] ?? array() ) as $h ) {
			if ( empty( $h['verified'] ) ) {
				continue;
			}
			$first = $first ?: $h['host_id'];
			if ( wp_parse_url( $h['ascii_host_url'] ?? '', PHP_URL_HOST ) === $want ) {
				$host = $h['host_id'];
				break;
			}
		}
		$host = $host ?: $first;
		if ( ! $host ) {
			return new WP_Error( 'pcb_wm_no_host', 'В Вебмастере нет подтверждённого сайта' );
		}
		set_transient( 'pcb_wm_host', $host, DAY_IN_SECONDS );
	}
	return array( $uid, $host );
}

/** Показы, клики, средняя позиция за период и число страниц в поиске. */
function pcb_wm_summary( $date1, $date2 ) {
	return pcb_ya_cached( 'wm', array( $date1, $date2 ), function () use ( $date1, $date2 ) {
		$ids = pcb_wm_ids();
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		list( $uid, $host ) = $ids;
		// host_id вида https:pcb-vostok.ru:443 передаётся в пути как есть — двоеточия не кодируем
		$hid  = preg_match( '~^[a-z]+:[A-Za-z0-9.-]+:\d+$~', $host ) ? $host : rawurlencode( $host );
		$base = 'https://api.webmaster.yandex.net/v4/user/' . $uid . '/hosts/' . $hid;
		$q    = 'query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&query_indicator=AVG_SHOW_POSITION&date_from=' . $date1 . '&date_to=' . $date2;
		$hist = pcb_ya_request( $base . '/search-queries/all/history?' . $q );
		$idx  = pcb_ya_request( $base . '/search-urls/in-search/history?date_from=' . $date1 . '&date_to=' . $date2 );
		$hist_idx = is_wp_error( $idx ) ? array() : (array) ( $idx['history'] ?? array() );
		$last     = $hist_idx ? end( $hist_idx ) : null;
		if ( is_wp_error( $hist ) ) {
			// 404: сайт подтверждён недавно, Вебмастер ещё не собрал статистику поиска — показываем то, что есть
			if ( 404 === (int) ( $hist->get_error_data()['status'] ?? 0 ) && ! is_wp_error( $idx ) ) {
				return array( 'shows' => null, 'clicks' => null, 'pos' => null, 'idx' => $last ? (int) $last['value'] : null, 'note' => 'Вебмастер ещё не собрал статистику поисковых запросов — после подтверждения сайта это занимает до нескольких дней.' );
			}
			return $hist;
		}
		$ind   = $hist['indicators'] ?? array();
		$sum   = function ( $k ) use ( $ind ) { return array_sum( array_column( (array) ( $ind[ $k ] ?? array() ), 'value' ) ); };
		$shows = $sum( 'TOTAL_SHOWS' );
		// средняя позиция за период — взвешенная по показам
		$pos = 0;
		$w   = 0;
		$sh  = array_column( (array) ( $ind['TOTAL_SHOWS'] ?? array() ), 'value', 'date' );
		foreach ( (array) ( $ind['AVG_SHOW_POSITION'] ?? array() ) as $p ) {
			$k    = $sh[ $p['date'] ] ?? 0;
			$pos += $p['value'] * $k;
			$w   += $k;
		}
		return array(
			'shows'  => (int) round( $shows ),
			'clicks' => (int) round( $sum( 'TOTAL_CLICKS' ) ),
			'pos'    => $w ? round( $pos / $w, 1 ) : null,
			'idx'    => $last ? (int) $last['value'] : null,
		);
	} );
}
