<?php
/**
 * Калькулятор: выгрузка и загрузка параметров формулы в CSV (0.9.0, README §9.6).
 *
 * Назначение — передать параметры (себе в Excel или Claude), пересчитать коэффициенты и загрузить обратно.
 * Одна строка — одно значение; у таблиц (ставка по слоям, покрытия …) — строка на ячейку:
 *
 *   # PCB Восток, формула калькулятора, черновик, 23.10.2026 16:40, курс ЦБ 11,42 ₽ за ¥
 *   key;row;step;name;value;unit;currency;min;max
 *   setup;;Фабрика · Платы;Подготовка производства;450;;CNY;0;20000
 *   dm2;4;Фабрика · Платы;Ставка за дм², 4 слоя;55;/дм²;CNY;;
 *
 * Формат — для Excel и для машины: UTF-8 с BOM, разделитель «;», десятичная запятая. При загрузке принимаются
 * запятая и точка, разделитель «,» или табуляция, кодировка Windows-1251 (Excel «CSV» без UTF-8).
 * Значения — в исходной валюте параметра (cur в модели), без пересчёта. При загрузке значимы только key, row
 * и value; остальные столбцы — подсказка. Строки с «#» в начале пропускаются.
 *
 * Выгрузка: черновик или любая опубликованная версия (право pcb_calc_view).
 * Загрузка — только в черновик (pcb_calc_edit): сначала проверка (calc/csv-check) — что изменится «было → стало»,
 * значения вне допустимого, пропущенные строки, сдвиг эталонов; затем «Применить к черновику» (calc/csv-apply).
 */

defined( 'ABSPATH' ) || exit;

const PCB_CALC_CSV_MAX = 1048576; // 1 МБ — с большим запасом: формула — около сотни строк

/** Столбцы файла. */
function pcb_calc_csv_cols() {
	return array( 'key', 'row', 'step', 'name', 'value', 'unit', 'currency', 'min', 'max' );
}

/** Число для файла: десятичная запятая, без лишних нулей. */
function pcb_calc_csv_num( $x ) {
	$s = number_format( (float) $x, 6, ',', '' );
	$s = rtrim( rtrim( $s, '0' ), ',' );
	return '-0' === $s ? '0' : $s;
}

/** Число из файла: «1 250,5», «1250.5», «25 %», «×1,25» → float; не число — null. */
function pcb_calc_csv_parse_num( $raw ) {
	$s = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", ' ', '%', '×', '₽', '¥' ), '', trim( (string) $raw ) );
	$s = ltrim( $s, "'" );
	$s = str_replace( array( '−', '–' ), '-', $s ); // минус из Word/Excel
	if ( preg_match( '/^-?\d+,\d+$/', $s ) ) {
		$s = str_replace( ',', '.', $s );
	} elseif ( preg_match( '/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s ) ) {
		$s = str_replace( ',', '', $s ); // 1,250.5 — разделитель тысяч
	}
	return is_numeric( $s ) ? (float) $s : null;
}

/** Поле CSV: в кавычках, если нужно; текст, похожий на формулу Excel, — с апострофом. */
function pcb_calc_csv_cell( $v ) {
	$v = (string) $v;
	if ( '' !== $v && in_array( $v[0], array( '=', '+', '@' ), true ) ) {
		$v = "'" . $v;
	}
	if ( preg_match( '/[;"\r\n]/', $v ) ) {
		$v = '"' . str_replace( '"', '""', $v ) . '"';
	}
	return $v;
}

/** Строки файла по параметрам: array( array( key, row, step, name, value, unit, currency, min, max ) ). */
function pcb_calc_csv_rows( array $params ) {
	$groups = pcb_calc_groups();
	$rows   = array();
	foreach ( pcb_calc_params() as $k => $d ) {
		$step = isset( $groups[ $d['g'] ] ) ? $groups[ $d['g'] ]['l'] . ' · ' . $groups[ $d['g'] ]['t'] : $d['g'];
		$v    = $params[ $k ] ?? $d['v'];
		switch ( $d['t'] ) {
			case 'table':
				$unit = isset( $d['cur'] ) ? ( $d['per'] ?? '' ) : ( $d['u'] ?? '' );
				foreach ( $d['rows'] as $rk => $label ) {
					$name   = preg_replace( '/ — множитель$/u', '', $d['n'] ) . ', ' . $label . ( '×' === $unit ? ' — множитель' : '' );
					$rows[] = array( $k, (string) $rk, $step, $name, pcb_calc_csv_num( $v[ (string) $rk ] ?? 0 ), $unit, $d['cur'] ?? '', '', '' );
				}
				break;
			case 'bool':
				$rows[] = array( $k, '', $step, $d['n'], $v ? '1' : '0', '1 = да, 0 = нет', '', '', '' );
				break;
			case 'sel':
				$opts = array();
				foreach ( $d['opts'] as $ok => $ol ) {
					$opts[] = $ok . ' = ' . $ol;
				}
				$rows[] = array( $k, '', $step, $d['n'], (string) $v, implode( ' | ', $opts ), '', '', '' );
				break;
			case 'text':
				$rows[] = array( $k, '', $step, $d['n'], (string) $v, 'текст', '', '', '' );
				break;
			default:
				$unit   = 'pct' === $d['t'] ? '%' : ( 'mul' === $d['t'] ? '×' : ( $d['u'] ?? '' ) );
				$rows[] = array( $k, '', $step, $d['n'], pcb_calc_csv_num( $v ), $unit, $d['cur'] ?? '', isset( $d['min'] ) ? pcb_calc_csv_num( $d['min'] ) : '', isset( $d['max'] ) ? pcb_calc_csv_num( $d['max'] ) : '' );
		}
	}
	return $rows;
}

/** Текст файла (UTF-8 с BOM, «;», CRLF — как сохраняет Excel). */
function pcb_calc_csv_build( array $params, $title ) {
	$lines = array( '# ' . str_replace( array( "\r", "\n", ';' ), array( ' ', ' ', ',' ), $title ), implode( ';', pcb_calc_csv_cols() ) );
	foreach ( pcb_calc_csv_rows( $params ) as $r ) {
		$lines[] = implode( ';', array_map( 'pcb_calc_csv_cell', $r ) );
	}
	return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
}

/** Заголовок первой строки: что выгружено, когда, курс ЦБ. */
function pcb_calc_csv_title( $what, array $params ) {
	$fx = pcb_calc_fx( $params );
	return 'PCB Восток, формула калькулятора, ' . $what . ', выгружено ' . wp_date( 'd.m.Y H:i' )
		. ', курс ' . ( 'cbr' === ( $fx['src'] ?? '' ) ? 'ЦБ' : 'ручной' ) . ' ' . pcb_calc_csv_num( round( (float) $fx['cny'], 4 ) ) . ' ₽ за ¥'
		. '. Значения — в валюте столбца currency, без пересчёта; при загрузке важны только key, row и value';
}

/** Значение параметра для показа: «1,25 ×», «450 ¥», «Авиа», «да». */
function pcb_calc_csv_show( array $d, $v ) {
	if ( 'bool' === $d['t'] ) {
		return $v ? 'да' : 'нет';
	}
	if ( 'sel' === $d['t'] ) {
		return $d['opts'][ $v ] ?? (string) $v;
	}
	if ( 'text' === $d['t'] ) {
		return '«' . $v . '»';
	}
	$num = pcb_calc_csv_num( $v );
	if ( isset( $d['cur'] ) ) {
		return $num . ' ' . ( 'CNY' === $d['cur'] ? '¥' : ( 'USD' === $d['cur'] ? '$' : '₽' ) ) . ( $d['per'] ?? '' );
	}
	$u = 'pct' === $d['t'] ? '%' : ( 'mul' === $d['t'] || '×' === ( $d['u'] ?? '' ) ? '×' : ( $d['u'] ?? '' ) );
	return trim( $num . ' ' . $u );
}

/**
 * Разбор файла поверх $base (текущий черновик).
 * Возвращает array( 'params' => новый набор, 'changes' => [...], 'range' => [...], 'warn' => [...], 'lines' => n )
 * или WP_Error, если файл не похож на выгрузку формулы.
 */
function pcb_calc_csv_parse( $text, array $base ) {
	$text = (string) $text;
	if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
		$text = substr( $text, 3 );
	} elseif ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
		$conv = @iconv( 'CP1251', 'UTF-8//IGNORE', $text ); // phpcs:ignore -- Excel «CSV» на русской Windows
		$text = false !== $conv ? $conv : $text;
	}
	$lines = preg_split( '/\r\n|\r|\n/', $text );
	$defs  = pcb_calc_params();

	// разделитель — по строке заголовка (или первой строке данных)
	$delim = ';';
	foreach ( $lines as $l ) {
		if ( '' === trim( $l ) || '#' === ltrim( $l )[0] ) {
			continue;
		}
		$n = array( ';' => substr_count( $l, ';' ), "\t" => substr_count( $l, "\t" ), ',' => substr_count( $l, ',' ) );
		arsort( $n );
		$delim = (string) key( $n );
		if ( 0 === reset( $n ) ) {
			$delim = ';';
		}
		break;
	}

	$cols    = array( 'key' => 0, 'row' => 1, 'value' => 4 );
	$params  = $base;
	$seen    = array();
	$warn    = array();
	$count   = 0;
	$headerd = false;
	foreach ( $lines as $i => $l ) {
		$num = $i + 1;
		if ( '' === trim( $l ) || '#' === ltrim( $l )[0] ) {
			continue;
		}
		$cells = array_map( 'trim', str_getcsv( $l, $delim, '"', '' ) );
		if ( ! $headerd && in_array( 'key', array_map( 'strtolower', $cells ), true ) ) {
			$map = array_flip( array_map( 'strtolower', $cells ) );
			if ( ! isset( $map['value'] ) ) {
				return new WP_Error( 'pcb_calc', 'В строке заголовка нет столбца value.', array( 'status' => 400 ) );
			}
			$cols    = array( 'key' => $map['key'], 'row' => $map['row'] ?? -1, 'value' => $map['value'] );
			$headerd = true;
			continue;
		}
		$headerd = true; // заголовка нет — порядок столбцов как в выгрузке
		$key     = $cells[ $cols['key'] ] ?? '';
		$row     = $cols['row'] >= 0 ? (string) ( $cells[ $cols['row'] ] ?? '' ) : '';
		$raw     = $cells[ $cols['value'] ] ?? '';
		if ( '' === $key ) {
			continue;
		}
		if ( ! isset( $defs[ $key ] ) ) {
			$warn[] = 'Строка ' . $num . ': неизвестный параметр «' . mb_substr( $key, 0, 40 ) . '» — пропущена.';
			continue;
		}
		$d = $defs[ $key ];
		if ( 'table' === $d['t'] && ! isset( $d['rows'][ $row ] ) ) {
			$warn[] = 'Строка ' . $num . ': у параметра «' . $d['n'] . '» нет строки «' . mb_substr( $row, 0, 20 ) . '» — пропущена.';
			continue;
		}
		$label = 'table' === $d['t'] ? $d['n'] . ', ' . $d['rows'][ $row ] : $d['n'];
		if ( '' === $raw ) {
			$warn[] = 'Строка ' . $num . ': «' . $label . '» — пустое значение, оставлено как было.';
			continue;
		}
		$id = $key . '.' . $row;
		if ( isset( $seen[ $id ] ) ) {
			$warn[] = 'Строка ' . $num . ': «' . $label . '» уже было в строке ' . $seen[ $id ] . ' — взято последнее значение.';
		}
		$seen[ $id ] = $num;

		switch ( $d['t'] ) {
			case 'bool':
				$b = mb_strtolower( $raw );
				if ( in_array( $b, array( '1', 'да', 'true', 'yes', 'истина' ), true ) ) {
					$val = true;
				} elseif ( in_array( $b, array( '0', 'нет', 'false', 'no', 'ложь' ), true ) ) {
					$val = false;
				} else {
					$warn[] = 'Строка ' . $num . ': «' . $label . '» — ожидается 1 или 0, а не «' . mb_substr( $raw, 0, 20 ) . '», оставлено как было.';
					continue 2;
				}
				break;
			case 'sel':
				$val = isset( $d['opts'][ $raw ] ) ? $raw : (string) array_search( mb_strtolower( $raw ), array_map( 'mb_strtolower', $d['opts'] ), true );
				if ( '' === $val || ! isset( $d['opts'][ $val ] ) ) {
					$warn[] = 'Строка ' . $num . ': «' . $label . '» — нет варианта «' . mb_substr( $raw, 0, 30 ) . '» (можно: ' . implode( ', ', array_keys( $d['opts'] ) ) . '), оставлено как было.';
					continue 2;
				}
				break;
			case 'text':
				$val = mb_substr( trim( wp_strip_all_tags( ltrim( $raw, "'" ) ) ), 0, 120 );
				break;
			default:
				$val = pcb_calc_csv_parse_num( $raw );
				if ( null === $val ) {
					$warn[] = 'Строка ' . $num . ': «' . $label . '» — не число («' . mb_substr( $raw, 0, 20 ) . '»), оставлено как было.';
					continue 2;
				}
				$val = round( $val, 6 );
		}
		if ( 'table' === $d['t'] ) {
			$params[ $key ][ $row ] = $val;
		} else {
			$params[ $key ] = $val;
		}
		++$count;
	}
	if ( ! $count && ! $warn ) {
		return new WP_Error( 'pcb_calc', 'В файле нет строк с параметрами. Выгрузите CSV из панели и правьте его.', array( 'status' => 400 ) );
	}
	if ( ! $count ) {
		return new WP_Error( 'pcb_calc', 'Ни одна строка не подошла: ' . implode( ' ', array_slice( $warn, 0, 3 ) ), array( 'status' => 400 ) );
	}
	$params = pcb_calc_clean( $params, $base );

	// что изменится и что вне допустимого
	$changes = array();
	foreach ( $defs as $k => $d ) {
		if ( 'table' === $d['t'] ) {
			foreach ( $d['rows'] as $rk => $label ) {
				$rk = (string) $rk;
				if ( (float) $base[ $k ][ $rk ] !== (float) $params[ $k ][ $rk ] ) {
					$changes[] = array( 'key' => $k, 'row' => $rk, 'g' => $d['g'], 'name' => $d['n'] . ', ' . $label, 'old' => pcb_calc_csv_show( $d, $base[ $k ][ $rk ] ), 'new' => pcb_calc_csv_show( $d, $params[ $k ][ $rk ] ),
						'pct' => $base[ $k ][ $rk ] ? round( ( $params[ $k ][ $rk ] - $base[ $k ][ $rk ] ) / abs( $base[ $k ][ $rk ] ) * 100, 1 ) : null );
				}
			}
		} elseif ( wp_json_encode( $base[ $k ] ) !== wp_json_encode( $params[ $k ] ) ) {
			$num       = ! in_array( $d['t'], array( 'bool', 'sel', 'text' ), true ) && $base[ $k ];
			$changes[] = array( 'key' => $k, 'row' => '', 'g' => $d['g'], 'name' => $d['n'], 'old' => pcb_calc_csv_show( $d, $base[ $k ] ), 'new' => pcb_calc_csv_show( $d, $params[ $k ] ),
				'pct' => $num ? round( ( $params[ $k ] - $base[ $k ] ) / abs( $base[ $k ] ) * 100, 1 ) : null );
		}
	}
	return array( 'params' => $params, 'changes' => $changes, 'range' => array_values( pcb_calc_errors( $params ) ), 'warn' => $warn, 'lines' => $count );
}

/** Эталоны: цена по текущему черновику и после загрузки. */
function pcb_calc_csv_etalons( array $now, array $next ) {
	$fxN = pcb_calc_fx( $now );
	$fxX = pcb_calc_fx( $next );
	$out = array();
	foreach ( pcb_calc_etalons() as $e ) {
		$a     = pcb_calc_run( $now, $e['in'], $fxN );
		$b     = pcb_calc_run( $next, $e['in'], $fxX );
		$ok    = function ( $r ) { return ! empty( $r['ok'] ) && empty( $r['engineer'] ) ? (float) $r['total'] : null; };
		$x     = $ok( $a );
		$y     = $ok( $b );
		$out[] = array( 'n' => $e['n'], 'now' => $x, 'next' => $y, 'pct' => $x && $y ? round( ( $y - $x ) / $x * 100, 1 ) : null );
	}
	return $out;
}

/* ---------------- выгрузка: admin-post.php?action=pcb_calc_csv&src=draft|<версия> ---------------- */

add_action( 'admin_post_pcb_calc_csv', function () {
	if ( ! current_user_can( 'pcb_calc_view' ) ) {
		wp_die( 'Недостаточно прав.', 403 );
	}
	check_admin_referer( 'pcb_calc_csv' );
	$src = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : 'draft';
	if ( 'draft' === $src ) {
		$params = pcb_calc_draft()['params'];
		$pub    = pcb_calc_published();
		$what   = 'черновик' . ( $pub['v'] ? ' (на сайте — версия ' . (int) $pub['v'] . ')' : '' );
		$file   = 'pcb-calc-draft';
	} else {
		$v = pcb_calc_version( (int) $src );
		if ( ! $v ) {
			wp_die( 'Версия не найдена.', 404 );
		}
		$params = pcb_calc_clean( $v['params'] );
		$what   = 'версия ' . (int) $v['v'] . ' от ' . mysql2date( 'd.m.Y', $v['time'] );
		$file   = 'pcb-calc-v' . (int) $v['v'];
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $file . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
	echo pcb_calc_csv_build( $params, pcb_calc_csv_title( $what, $params ) ); // phpcs:ignore -- CSV, не HTML
	exit;
} );

/** Адрес выгрузки (с nonce) — для экрана панели. */
function pcb_calc_csv_url( $src = 'draft' ) {
	return add_query_arg( array( 'action' => 'pcb_calc_csv', 'src' => $src, '_wpnonce' => wp_create_nonce( 'pcb_calc_csv' ) ), admin_url( 'admin-post.php' ) );
}

/* ---------------- загрузка: проверка и применение ---------------- */

add_action( 'rest_api_init', function () {
	$edit = function () {
		return current_user_can( 'pcb_calc_edit' );
	};

	// POST calc/csv-check (multipart: file) → что изменится; разобранный набор ждёт применения 30 минут
	register_rest_route( 'pcb-panel/v1', '/calc/csv-check', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$f = $r->get_file_params()['file'] ?? null;
			if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
				return pcb_calc_err( 'Файл не загрузился — выберите его ещё раз.' );
			}
			if ( $f['size'] > PCB_CALC_CSV_MAX ) {
				return pcb_calc_err( 'Файл больше 1 МБ — это не выгрузка формулы.' );
			}
			if ( ! preg_match( '/\.(csv|txt|tsv)$/i', (string) $f['name'] ) ) {
				return pcb_calc_err( 'Нужен файл .csv — в Excel: «Сохранить как» → «CSV UTF-8» или «CSV (разделители — точки с запятой)».' );
			}
			$draft = pcb_calc_draft();
			$res   = pcb_calc_csv_parse( (string) file_get_contents( $f['tmp_name'] ), $draft['params'] ); // phpcs:ignore
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$token = wp_generate_password( 16, false );
			set_transient( 'pcb_calc_csv_' . get_current_user_id() . '_' . $token, array( 'params' => $res['params'], 'rev' => (int) $draft['rev'], 'n' => count( $res['changes'] ), 'file' => sanitize_file_name( $f['name'] ) ), 30 * MINUTE_IN_SECONDS );
			return array(
				'token'   => $token,
				'file'    => sanitize_file_name( $f['name'] ),
				'lines'   => $res['lines'],
				'changes' => $res['changes'],
				'range'   => $res['range'],
				'warn'    => array_slice( $res['warn'], 0, 50 ),
				'more'    => max( 0, count( $res['warn'] ) - 50 ),
				'etalons' => pcb_calc_csv_etalons( $draft['params'], $res['params'] ),
			);
		},
	) );

	// POST calc/csv-apply {token} → черновик; если черновик успели изменить после проверки — 409
	register_rest_route( 'pcb-panel/v1', '/calc/csv-apply', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$key = 'pcb_calc_csv_' . get_current_user_id() . '_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $r['token'] );
			$job = get_transient( $key );
			if ( ! is_array( $job ) ) {
				return pcb_calc_err( 'Проверка устарела — загрузите файл ещё раз.', 410 );
			}
			$cur = pcb_calc_draft();
			if ( (int) $cur['rev'] !== (int) $job['rev'] ) {
				delete_transient( $key );
				return pcb_calc_err( 'Черновик изменился после проверки файла — загрузите файл ещё раз, чтобы увидеть изменения поверх новых значений.', 409 );
			}
			delete_transient( $key );
			if ( ! $job['n'] ) {
				return pcb_calc_err( 'Файл совпадает с черновиком — менять нечего.' );
			}
			pcb_calc_draft_save( pcb_calc_clean( $job['params'], $cur['params'] ) );
			pcb_cl_log( 'calc', 'calc_csv', (string) (int) $job['n'], $job['file'] );
			$b = pcb_calc_bootstrap();
			unset( $b['api'], $b['nonce'], $b['canEdit'], $b['groups'], $b['defs'], $b['engFixed'] );
			return $b;
		},
	) );
} );
