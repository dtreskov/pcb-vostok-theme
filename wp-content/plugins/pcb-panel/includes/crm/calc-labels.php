<?php
/**
 * Данные калькулятора в карточке заявки — с теми же названиями, что на странице калькулятора.
 *
 * Названия не прописаны в коде: они берутся из разметки страницы калькулятора (поля с data-key,
 * варианты выпадающих списков, переключатель монтажа, таблица «Группа корпусов»). Результат кешируется
 * и пересобирается, когда страницу калькулятора сохраняют. Если страница не найдена — запасные подписи ниже.
 *
 * Структура входа — PCBCalc.getData() темы (assets/js/calculator.js), сохранённая в _pcb_calc.
 */

defined( 'ABSPATH' ) || exit;

/** Запасные подписи — на случай, если страницу калькулятора не удалось разобрать. */
function pcb_crm_calc_fallback() {
	return array(
		'fields'    => array(
			'qty' => 'Тираж, шт', 'len' => 'Габариты платы, мм', 'layers' => 'Число слоёв', 'thick' => 'Толщина платы, мм',
			'cuout' => 'Медь наружных слоёв, мкм', 'cuin' => 'Медь внутренних слоёв, мкм', 'mat' => 'Материал',
			'finish' => 'Финишное покрытие', 'mask' => 'Цвет маски', 'silk' => 'Цвет маркировки',
			'track' => 'Мин. ширина дорожки, мм', 'hole' => 'Мин. отверстие, мм', 'sides' => 'Расположение компонентов',
			'panel' => 'Поставка', 'q_smd' => 'Простые SMD', 'q_dip' => 'DIP-компоненты, выводов',
			'q_bga' => 'BGA-корпуса', 'q_qfn' => 'QFN, QFP и др. многовыводные',
		),
		'options'   => array(),
		'checks'    => array(),
		'asm_modes' => array( 'none' => 'Без монтажа', 'quick' => 'Быстрая оценка', 'detail' => 'Подробная' ),
		'asm_rows'  => array(),
		'asm_cols'  => array( 'd' => 'Корпусов на плате', 'p' => 'Выводов на корпус' ),
	);
}

/** Страница калькулятора: опубликованная страница, в разметке которой есть поля калькулятора. */
function pcb_crm_calc_page() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'
		 AND post_content LIKE '%data-key=\"qty\"%' AND post_content LIKE '%name=\"asm\"%'
		 ORDER BY ID LIMIT 1"
	);
}

/** Словарь подписей; кеш сбрасывается при изменении страницы калькулятора. */
function pcb_crm_calc_dict() {
	static $dict = null;
	if ( null !== $dict ) {
		return $dict;
	}
	$page = pcb_crm_calc_page();
	$base = pcb_crm_calc_fallback();
	if ( ! $page || ! class_exists( 'DOMDocument' ) ) {
		return $dict = $base;
	}
	$stamp  = $page . ':' . get_post_modified_time( 'U', true, $page );
	$cached = get_transient( 'pcb_crm_calc_dict' );
	if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp ) {
		return $dict = $cached['dict'];
	}
	$parsed = pcb_crm_calc_parse( (string) get_post_field( 'post_content', $page ) );
	$dict   = array();
	foreach ( $base as $k => $v ) {
		$dict[ $k ] = array_merge( $v, $parsed[ $k ] ?? array() );
	}
	set_transient( 'pcb_crm_calc_dict', array( 'stamp' => $stamp, 'dict' => $dict ), WEEK_IN_SECONDS );
	return $dict;
}

/** Разбор разметки калькулятора. */
function pcb_crm_calc_parse( $html ) {
	$out = array( 'fields' => array(), 'options' => array(), 'checks' => array(), 'asm_modes' => array(), 'asm_rows' => array(), 'asm_cols' => array() );
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
	libxml_clear_errors();
	$xp   = new DOMXPath( $doc );
	$text = function ( $node ) {
		if ( ! $node ) {
			return '';
		}
		$clone = $node->cloneNode( true );
		foreach ( iterator_to_array( $clone->getElementsByTagName( 'span' ) ) as $span ) {
			if ( false !== strpos( (string) $span->getAttribute( 'class' ), 'cx-badge' ) ) {
				$span->parentNode->removeChild( $span ); // служебные значки рядом с подписью
			}
		}
		return trim( preg_replace( '/\s+/u', ' ', $clone->textContent ) );
	};

	foreach ( $xp->query( '//*[@data-key]' ) as $el ) {
		$key = $el->getAttribute( 'data-key' );
		$tag = strtolower( $el->nodeName );
		$typ = strtolower( $el->getAttribute( 'type' ) );

		// галочки «Особые требования»: подпись — текст label вокруг
		if ( 'checkbox' === $typ ) {
			$out['checks'][ $key ] = $text( $el->parentNode );
			continue;
		}
		// строки таблицы монтажа: d_<группа> — корпусов, p_<группа> — выводов
		if ( preg_match( '/^([dp])_(.+)$/', $key, $m ) ) {
			$tr = $el->parentNode;
			while ( $tr && 'tr' !== strtolower( $tr->nodeName ) ) {
				$tr = $tr->parentNode;
			}
			if ( $tr ) {
				$th = $xp->query( './th', $tr )->item( 0 );
				if ( $th ) {
					$out['asm_rows'][ $m[2] ] = $text( $th );
				}
			}
			continue;
		}
		$label = '';
		$id    = $el->getAttribute( 'id' );
		if ( '' !== $id ) {
			$lab = $xp->query( '//label[@for="' . $id . '"]' )->item( 0 );
			$label = $text( $lab );
		}
		if ( '' === $label ) {
			$label = trim( $el->getAttribute( 'aria-label' ) );
		}
		if ( '' !== $label ) {
			$out['fields'][ $key ] = $label;
		}
		if ( 'select' === $tag ) {
			foreach ( $xp->query( './/option', $el ) as $opt ) {
				$v = $opt->getAttribute( 'value' );
				if ( '' !== $v ) {
					$out['options'][ $key ][ $v ] = $text( $opt );
				}
			}
		}
	}
	// переключатель режима монтажа
	foreach ( $xp->query( '//input[@name="asm"]' ) as $r ) {
		$out['asm_modes'][ $r->getAttribute( 'value' ) ] = $text( $r->parentNode );
	}
	// заголовки столбцов таблицы монтажа
	$heads = $xp->query( '//table[contains(@class,"cx-table")]//thead//th' );
	if ( $heads->length >= 3 ) {
		$out['asm_cols'] = array( 'd' => $text( $heads->item( 1 ) ), 'p' => $text( $heads->item( 2 ) ) );
	}
	// габариты: у len/wid подпись общая («Габариты платы, мм»), aria-label — «Длина, мм»
	$len = $xp->query( '//label[@for="f-len"]' )->item( 0 );
	if ( $len ) {
		$out['fields']['len'] = $text( $len );
	}
	return $out;
}

/**
 * Строки для карточки: array( array( название, значение ), … ) в порядке калькулятора.
 * Пустые и нулевые значения пропускаются.
 */
/** Адрес страницы калькулятора (страница со слагом calculator). */
function pcb_crm_calc_page_url() {
	$page = get_page_by_path( 'calculator' );
	return $page ? get_permalink( $page ) : home_url( '/calculator/' );
}

/**
 * Пакет для кнопки «Показать в калькуляторе»: данные расчёта из заявки и файлы, которые клиент
 * загружал в калькулятор (совпадают по имени со списком calc.files). Файлы калькулятор скачивает
 * сам по ссылкам панели (нужен вход с правом pcb_crm_view), данные — через localStorage браузера.
 * Пусто — кнопки нет.
 */
function pcb_crm_calc_restore( $id ) {
	$calc = (array) get_post_meta( $id, '_pcb_calc', true );
	if ( ! $calc ) {
		return array();
	}
	$names = array_map( 'strval', (array) ( $calc['files'] ?? array() ) );
	$files = array();
	foreach ( (array) get_post_meta( $id, '_pcb_files', true ) as $f ) {
		if ( empty( $f['kind'] ) && isset( $f['key'], $f['name'] ) && in_array( $f['name'], $names, true ) ) {
			$files[] = array( 'name' => $f['name'], 'url' => pcb_rq_file_url( $id, $f['key'] ) );
		}
	}
	return array(
		'n'     => pcb_rq_label( $id ),
		'data'  => $calc,
		'files' => $files,
		'miss'  => max( 0, count( $names ) - count( $files ) ),
	);
}

function pcb_crm_calc_rows( array $calc ) {
	$d    = pcb_crm_calc_dict();
	$rows = array();
	$num  = function ( $v ) { return str_replace( '.', ',', (string) $v ); };
	$opt  = function ( $k, $v ) use ( $d ) { return $d['options'][ $k ][ (string) $v ] ?? (string) $v; };
	$name = function ( $k ) use ( $d ) { return $d['fields'][ $k ] ?? $k; };
	$done = array();

	foreach ( array( 'qty', 'len', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel' ) as $k ) {
		$done[ $k ] = true;
		if ( 'len' === $k ) {
			$done['wid'] = true;
			if ( '' !== (string) ( $calc['len'] ?? '' ) || '' !== (string) ( $calc['wid'] ?? '' ) ) {
				$rows[] = array( $name( 'len' ), $num( $calc['len'] ?? '—' ) . ' × ' . $num( $calc['wid'] ?? '—' ) );
			}
			continue;
		}
		if ( ! isset( $calc[ $k ] ) || '' === (string) $calc[ $k ] ) {
			continue;
		}
		$v = isset( $d['options'][ $k ] ) ? $opt( $k, $calc[ $k ] ) : $num( $calc[ $k ] );
		if ( 'finish' === $k && ! empty( $calc['finish_pending'] ) ) {
			$v = 'не уточнено (в расчёте — ' . $v . ')';
		}
		$rows[] = array( $name( $k ), $v );
	}
	$done['finish_pending'] = true;

	if ( ! empty( $calc['special'] ) ) {
		$labels = array_map( function ( $k ) use ( $d ) { return $d['checks'][ $k ] ?? $k; }, (array) $calc['special'] );
		$rows[] = array( 'Особые требования', implode( '; ', $labels ) );
	}
	$done['special'] = true;

	if ( isset( $calc['asm'] ) ) {
		$rows[] = array( 'Монтаж', $d['asm_modes'][ $calc['asm'] ] ?? (string) $calc['asm'] );
		$vals   = (array) ( $calc['asm_values'] ?? array() );
		if ( 'none' !== $calc['asm'] && $vals ) {
			// быстрая оценка: q_* — отдельные поля
			foreach ( $vals as $k => $v ) {
				if ( 0 === strpos( $k, 'q_' ) && ( 'quick' === $calc['asm'] ) ) {
					$rows[] = array( '· ' . $name( $k ), $num( $v ) );
				}
			}
			// подробная оценка: d_<группа> корпусов × p_<группа> выводов
			if ( 'detail' === $calc['asm'] ) {
				foreach ( $vals as $k => $v ) {
					if ( ! preg_match( '/^d_(.+)$/', $k, $m ) ) {
						continue;
					}
					$g    = $m[1];
					$pins = $vals[ 'p_' . $g ] ?? 0;
					$rows[] = array(
						'· ' . ( $d['asm_rows'][ $g ] ?? $g ),
						$num( $v ) . ' шт' . ( $pins ? ' × ' . $num( $pins ) . ' выв.' : '' ),
					);
				}
			}
		}
	}
	$done['asm'] = $done['asm_values'] = true;

	if ( isset( $calc['estimate'] ) ) {
		$e      = $calc['estimate'];
		$fmt    = function ( $n ) { return number_format( (float) $n, 0, ',', ' ' ); };
		$rows[] = array( 'Оценка калькулятора', is_array( $e )
			? $fmt( $e['lo'] ?? 0 ) . ' – ' . $fmt( $e['hi'] ?? 0 ) . ' ₽ (≈ ' . $fmt( $e['per'] ?? 0 ) . ' ₽ за плату'
				. ( ! empty( $e['vat'] ) ? '; в т. ч. НДС ' . str_replace( '.', ',', (string) ( 0 + $e['vat'] ) ) . ' %' : '' ) . ')'
			: 'расчёт инженером' );
	}
	if ( ! empty( $calc['warn'] ) ) {
		$rows[] = array( 'Почему нужен инженер', implode( ', ', (array) $calc['warn'] ) );
	}
	if ( ! empty( $calc['term'] ) ) {
		$rows[] = array( 'Срок', (string) $calc['term'] );
	}
	if ( ! empty( $calc['files'] ) ) {
		$rows[] = array( 'Файлы в калькуляторе', implode( ', ', (array) $calc['files'] ) );
	}
	if ( ! empty( $calc['calc_version'] ) ) {
		$rows[] = array( 'Версия формулы', 'v' . (int) $calc['calc_version'] . ' — по ней клиент увидел оценку (Калькулятор → Версии)' );
	}
	$done['estimate'] = $done['warn'] = $done['term'] = $done['files'] = $done['calc_version'] = true;

	// всё, что калькулятор начнёт передавать позже, — как есть, чтобы ничего не потерялось
	foreach ( $calc as $k => $v ) {
		if ( isset( $done[ $k ] ) || '' === $v || null === $v || array() === $v ) {
			continue;
		}
		$rows[] = array( $name( $k ), is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : (string) $v );
	}
	return $rows;
}
