<?php
/**
 * Калькулятор: расчёт цены. Единственное место формулы — им пользуются и сайт (pcb-panel/v1/calc/quote),
 * и панель (черновик, эталоны, чувствительность). Поэтому цифры на сайте и в панели не расходятся.
 *
 * pcb_calc_run( $p, $in, $fx ):
 *   $p  — значения параметров (pcb_calc_clean), $in — вход с сайта (pcb_calc_input_clean): одна плата
 *         или комплект { set: { sets, boards: [ { name, n, in } ] } },
 *   $fx — курсы для расчёта: array( 'cny' => ₽ за ¥, 'usd' => ₽ за $, 'vol' => разброс курса юаня, % ).
 * Возвращает итог для сайта (ok, lines, lo, hi, per, vat …) и разбор для панели (x: terms — общая формула,
 * blocks — по блокам).
 *
 * Устройство: pcb_calc_board() — всё, что считается на одну плату (фабрика, монтаж, вес, наши услуги);
 * pcb_calc_sum() — общее на заказ: курс, финансирование, доставка по суммарному весу, таможня, наценка,
 * минимальная сумма, НДС, округление, вилка. У одной платы заказ — она сама; у комплекта общее считается
 * один раз (README §9.5).
 */

defined( 'ABSPATH' ) || exit;

const PCB_CALC_MAX_BOARDS = 20;

/** Оценка числа позиций (катушек) по числу корпусов на плате. */
function pcb_calc_feeders( $n ) {
	return $n > 0 ? min( $n, (int) round( 6 + $n * 0.2 ) ) : 0;
}

/** Комплект или одна плата. */
function pcb_calc_is_set( array $in ) {
	return isset( $in['set'] ) && is_array( $in['set'] );
}

function pcb_calc_run( array $p, array $in, array $fx ) {
	if ( pcb_calc_is_set( $in ) ) {
		return pcb_calc_run_set( $p, $in['set'], $fx );
	}
	$b = pcb_calc_board( $p, $in );
	if ( ! $b['ok'] ) {
		return array( 'ok' => false, 'engineer' => false, 'warn' => array(), 'lines' => array(), 'notes' => array(), 'reason' => $b['reason'] );
	}
	return pcb_calc_sum( $p, array( $b ), $fx, null, $b['engineer'], $b['warn'], $b['notes'] );
}

/** Значение кривой параметра $k (строка $row) в точке $x. */
function pcb_calc_cv( array $p, $k, $row, $x ) {
	$d = pcb_calc_params()[ $k ];
	return pcb_calc_curve( $p[ $k ], $row, $x, ! empty( $d['log'] ) );
}

/** Надбавка опции фабрики: ¥/дм² × площадь, но не меньше минимума; 0, если опция бесплатна. */
function pcb_calc_opt_add( array $o, $area ) {
	return ( $o['dm2'] > 0 || $o['min'] > 0 ) ? max( (float) $o['min'], (float) $o['dm2'] * $area ) : 0.0;
}

/* ---------------- одна плата ---------------- */

/**
 * Всё, что считается на одну плату: проверка заполненности, расчёт инженером, платы и монтаж (¥), вес, наши услуги (₽).
 * ok=false + reason — не хватает данных.
 */
function pcb_calc_board( array $p, array $in, $name = '' ) {
	$P   = pcb_calc_params();
	$f   = function ( $k ) use ( $in ) {
		$v = str_replace( ',', '.', (string) ( $in[ $k ] ?? '' ) );
		return is_numeric( $v ) ? (float) $v : 0.0;
	};
	$s   = function ( $k ) use ( $in ) {
		return (string) ( $in[ $k ] ?? '' );
	};
	$av  = $in['asm_values'] ?? array();
	$a   = function ( $k ) use ( $av ) {
		return (float) ( $av[ $k ] ?? 0 );
	};
	$b   = array( 'ok' => false, 'name' => $name, 'engineer' => false, 'warn' => array(), 'notes' => array() );

	$qty = $f( 'qty' );
	$len = $f( 'len' );
	$wid = $f( 'wid' );
	if ( ! ( $qty > 0 && $len > 0 && $wid > 0 ) ) {
		$b['reason'] = 'Укажите тираж и габариты платы, чтобы увидеть оценку.';
		return $b;
	}
	$layers = (int) $s( 'layers' );
	$need   = array_filter( array( 'thick', 'cuout', 'mat', 'finish', 'mask', 'silk' ), function ( $k ) use ( $s ) {
		return '' === $s( $k );
	} );
	if ( $layers >= 4 && '' === $s( 'cuin' ) ) {
		$need[] = 'cuin';
	}
	if ( $need || $layers < 1 ) {
		$b['reason'] = 'Заполните отмеченные поля — после этого покажем оценку.';
		return $b;
	}
	$qty = min( $qty, 1e6 );

	// --- когда нужен инженер
	$eng  = function ( $why ) use ( &$b ) {
		$b['engineer'] = true;
		$b['warn'][]   = $why;
	};
	$mat  = $s( 'mat' );
	$spec = (array) ( $in['special'] ?? array() );
	$fmm  = function ( $x ) {
		return str_replace( '.', ',', rtrim( rtrim( number_format( $x, 3, '.', '' ), '0' ), '.' ) );
	};
	if ( 'pi' === $mat ) { $eng( 'полиимидное основание' ); }
	if ( 'hf' === $mat ) { $eng( 'ВЧ / СВЧ-материал' ); }
	if ( $layers > $p['maxLayers'] ) { $eng( 'более ' . pcb_calc_layer_word( (int) $p['maxLayers'] ) ); }
	$imp = in_array( 'imp', $spec, true );
	if ( $imp && $p['impEng'] ) { $eng( 'контроль импеданса' ); }
	if ( in_array( 'blind', $spec, true ) ) { $eng( 'слепые / скрытые отверстия' ); }
	if ( in_array( 'vip', $spec, true ) ) { $eng( 'via-in-pad' ); }
	if ( in_array( 'gold', $spec, true ) ) { $eng( 'золочёные ламели' ); }
	if ( 'other' === $s( 'finish' ) ) { $eng( 'нестандартное покрытие' ); }
	$track = $f( 'track' );
	$hole  = $f( 'hole' );
	if ( $track > 0 && $track < $p['minTrack'] ) { $eng( 'дорожка ' . $fmm( $track ) . ' мм — тоньше стандартной технологии' ); }
	if ( $hole > 0 && $hole < $p['minHole'] ) { $eng( 'отверстие ' . $fmm( $hole ) . ' мм — микроотверстия' ); }
	$mode = (string) ( $in['asm'] ?? 'none' );
	if ( 'detail' === $mode && $a( 'd_0201' ) > 0 ) { $eng( 'чип-компоненты 0201 — по согласованию' ); }
	$lk = (string) $layers;
	if ( ! isset( $p['mrate']['y'][ $lk ] ) ) { $eng( 'нет ставки для ' . pcb_calc_layer_word( $layers ) ); $lk = '2'; }

	// --- монтаж: состав (нужен раньше площади — мелкие платы для монтажа идут в заготовке)
	$joints   = 0;  // паек на плату
	$perY     = 0;  // ¥ за пайки одной платы
	$comps    = 0;  // корпусов на плате
	$bga      = 0;
	$axiOn    = false;
	$asmEmpty = false;
	$J        = $p['joint'];
	if ( 'quick' === $mode ) {
		$smd = $a( 'q_smd' ); $dip = $a( 'q_dip' ); $bga = $a( 'q_bga' ); $qfn = $a( 'q_qfn' );
		$Q   = $p['quick'];
		$parts = array(
			array( $smd * $Q['smd'], $J['chip'] ), array( $qfn * $Q['qfn'], $J['qfn'] ), array( $bga * $Q['bga'], $J['bga'] ), array( $dip, $J['tht'] ),
		);
		$comps    = $smd + $bga + $qfn + ceil( $dip / 4 );
		$axiOn    = $bga + $qfn > 0;
		$asmEmpty = ! ( $smd + $dip + $bga + $qfn > 0 );
	} elseif ( 'detail' === $mode ) {
		$parts = array(
			array( $a( 'd_0201' ) * 2 + $a( 'd_0402' ) * 2 + $a( 'd_0603' ) * 2 + $a( 'd_sot' ) * 3 + $a( 'd_soic' ) * $a( 'p_soic' ), $J['chip'] ),
			array( $a( 'd_qfp' ) * $a( 'p_qfp' ), $J['fine'] ),
			array( $a( 'd_qfn' ) * $a( 'p_qfn' ), $J['qfn'] ),
			array( $a( 'd_bga' ) * $a( 'p_bga' ), $J['bga'] ),
			array( $a( 'd_tht' ) * $a( 'p_tht' ), $J['tht'] ),
			array( $a( 'd_conn' ), $J['conn'] ),
		);
		foreach ( array( 'd_0201', 'd_0402', 'd_0603', 'd_sot', 'd_soic', 'd_qfp', 'd_qfn', 'd_bga', 'd_tht', 'd_conn' ) as $kk ) {
			$comps += $a( $kk );
		}
		$bga      = $a( 'd_bga' );
		$axiOn    = $a( 'd_bga' ) > 0 || $a( 'd_qfn' ) > 0;
		$asmEmpty = ! ( $comps > 0 );
	} else {
		$parts = array();
	}
	foreach ( $parts as $pt ) {
		$joints += $pt[0];
		$perY   += $pt[0] * $pt[1];
	}
	$asmOn = 'none' !== $mode && ! $asmEmpty;
	$sides = '2' === $s( 'sides' ) ? 2 : 1;

	// --- мультиплицирование: площадь по заготовке с техполями и зазорами
	$panel = $s( 'panel' );
	$small = $asmOn && $p['panMinSide'] > 0 && min( $len, $wid ) < $p['panMinSide'];
	$usePan = $small || ( in_array( $panel, array( 'ours', 'own' ), true ) && $p['panArea'] );
	$pan    = null;
	$kPan   = 1.0;
	if ( $usePan ) {
		$L   = max( $len, $wid );
		$W   = min( $len, $wid );
		$gap = max( 0, (float) $p['panGap'] );
		$T   = max( 1, (float) $p['panTarget'] );
		$r   = max( 0, (float) $p['panRail'] );
		$nx  = max( 1, (int) floor( $T / ( $L + $gap ) ) );
		$ny  = max( 1, (int) floor( max( 0, $T - 2 * $r ) / ( $W + $gap ) ) );
		$pL  = $nx * ( $L + $gap );
		$pW  = $ny * ( $W + $gap ) + 2 * $r;
		$kPan = $pL * $pW / ( $nx * $ny * $L * $W );
		$pan  = array( 'nx' => $nx, 'ny' => $ny, 'pl' => $pL, 'pw' => $pW, 'k' => $kPan, 'why' => $small && ! in_array( $panel, array( 'ours', 'own' ), true ) ? 'small' : 'panel' );
	}

	// --- 1. платы, ¥: материал × множители опций + надбавки опций + плёнки + инженерный сбор + электротест
	$area0  = $len * $wid / 10000 * $qty; // дм² плат
	$area   = $area0 * $kPan;             // дм² с заготовкой
	$rateL  = pcb_calc_cv( $p, 'mrate', $lk, $area );
	$matMin = (float) ( $p['matMin'][ $lk ] ?? 0 );
	$matRaw = $area * $rateL;
	$base   = max( $matMin, $matRaw );
	$pend   = ! empty( $in['finish_pending'] );
	$fin    = $pend ? 'hasl' : $s( 'finish' );
	$thickK = $s( 'thick' );
	$opts   = array(
		array( 'Толщина ' . ( $P['thick']['rows'][ $thickK ] ?? $thickK . ' мм' ), $p['thick'][ $thickK ] ?? null ),
		array( 'Медь ' . $s( 'cuout' ) . ' мкм', $p['cu'][ $s( 'cuout' ) ] ?? null ),
		array( 'Внутр. медь ' . $s( 'cuin' ) . ' мкм', $layers >= 4 ? ( $p['cuin'][ $s( 'cuin' ) ] ?? null ) : null ),
		array( 'Покрытие ' . ( $P['finish']['rows'][ $fin ] ?? $fin ), $p['finish'][ $fin ] ?? null ),
		array( 'Материал ' . ( $P['mat']['rows'][ $mat ] ?? $mat ), $p['mat'][ $mat ] ?? null ),
		array( 'Маска: ' . mb_strtolower( $P['mask']['rows'][ $s( 'mask' ) ] ?? $s( 'mask' ) ), $p['mask'][ $s( 'mask' ) ] ?? null ),
		array( 'Две стороны', 2 === $sides ? $p['extra']['sides2'] : null ),
		array( 'Импеданс', $imp && ! $p['impEng'] ? $p['extra']['imp'] : null ),
		array( 'Металлизация торцов', in_array( 'edge', $spec, true ) ? $p['extra']['edge'] : null ),
		array( 'Тонкая дорожка', $track > 0 && $track < $p['fineTrack'] ? $p['extra']['fine'] : null ),
	);
	$mul  = 1.0;
	$addY = 0.0;
	$used = array(); // [подпись, множитель, надбавка ¥]
	foreach ( $opts as $o ) {
		if ( ! $o[1] ) {
			continue;
		}
		$add  = pcb_calc_opt_add( $o[1], $area );
		$mul *= (float) $o[1]['mul'];
		$addY += $add;
		if ( abs( $o[1]['mul'] - 1 ) > 1e-9 || $add > 0 ) {
			$used[] = array( $o[0], (float) $o[1]['mul'], $add );
		}
	}
	$filmY = (float) ( $p['film'][ $lk ] ?? 0 );
	$engY  = max( 0, pcb_calc_cv( $p, 'eng', $lk, $area ) );
	$etY   = max( 0, pcb_calc_cv( $p, 'etest', 'v', $area ) );
	$setupY = $filmY + $engY + $etY;
	$pcbY   = $base * $mul + $addY;
	if ( $pend ) {
		$b['notes'][] = 'Финишное покрытие не уточнено — в расчёте HASL.';
	}

	// --- 2. монтаж, ¥
	$feeders = $asmOn ? pcb_calc_feeders( (int) $comps ) : 0;
	$asmEngY = $asmOn ? max( 0, pcb_calc_cv( $p, 'asmEng', 'v', $qty ) ) : 0;
	$fixY    = $asmOn ? max( 0, pcb_calc_cv( $p, 'fixture', 'v', $qty ) ) : 0;
	$asmY    = $asmOn ? $asmEngY + $fixY + $p['stencil'] * $sides + $feeders * $p['feeder'] + $perY * $qty + $p['asmPack'] * $qty : 0;
	$axiY    = $asmOn && $axiOn ? $p['axi'] : 0;
	if ( $asmOn && ! empty( $in['asm_est'] ) ) {
		$b['notes'][] = 'Монтаж посчитан ориентировочно — компоненты не учтены поштучно.';
	}

	// --- вес и объём для доставки
	$t  = (float) ( $thickK ?: 1.6 );
	$kg = $area * $t * $p['density'] / 100 * ( 1 + $p['pack'] / 100 ) + ( $asmOn ? $p['asmGram'] * $qty / 1000 : 0 ); // дм² × мм × г/см³: 1 дм² × 1 мм = 10 см³
	$m3 = $kg / max( 1, (float) $p['packDens'][ $asmOn ? 'asm' : 'bare' ] );

	// --- наши услуги, ₽: аудит, ОТК по выборке, ПМИ по уровню сложности (только с монтажом, F-1.29)
	$lvl = $asmOn ? ( $bga > 0 || $comps >= $p['pmiHard'] ? 'hard' : ( $comps <= $p['pmiSimple'] ? 'simple' : 'mid' ) ) : null;
	$pmiR = $lvl ? $p['pmiLvl'][ $lvl ]['batch'] + $p['pmiLvl'][ $lvl ]['board'] * $qty : 0;
	$smp  = (int) min( $qty, pcb_calc_step( $p['otkSample'], $qty ) );
	$otkR = $p['otk'] + $p['otkBoard'] * $smp * ( $asmOn ? $p['otkAsmK'] : 1 );

	return array_merge( $b, array(
		'ok'       => true,
		'qty'      => $qty,
		'layers'   => $layers,
		'lk'       => $lk,
		'area'     => $area,
		'area0'    => $area0,
		'pan'      => $pan,
		'rateL'    => $rateL,
		'matMin'   => $matMin,
		'matRaw'   => $matRaw,
		'base'     => $base,
		'mul'      => $mul,
		'addY'     => $addY,
		'used'     => $used,
		'filmY'    => $filmY,
		'engY'     => $engY,
		'etY'      => $etY,
		'setupY'   => $setupY,
		'pcbY'     => $pcbY,
		'factY'    => $setupY + $pcbY,
		'mode'     => $mode,
		'asmOn'    => $asmOn,
		'asmEmpty' => $asmEmpty,
		'sides'    => $sides,
		'joints'   => $joints,
		'perY'     => $perY,
		'comps'    => $comps,
		'feeders'  => $feeders,
		'asmEngY'  => $asmEngY,
		'fixY'     => $fixY,
		'asmY'     => $asmY,
		'axiY'     => $axiY,
		't'        => $t,
		'kg'       => $kg,
		'm3'       => $m3,
		'pmiOn'    => (bool) $lvl,
		'lvl'      => $lvl,
		'pmiR'     => $pmiR,
		'smp'      => $smp,
		'otkR'     => $otkR,
		'ours'     => $p['audit'] + $pmiR + $otkR,
		'flags'    => array(
			'quick'  => $asmOn && 'quick' === $mode,
			'est'    => $asmOn && ! empty( $in['asm_est'] ),
			'finish' => $pend,
			'sample' => $area0 < $p['sampleArea'],
		),
	) );
}

/* ---------------- комплект ---------------- */

/**
 * Комплект: $set = { sets, boards: [ { name, n, in } ] } (pcb_calc_input_clean). Тираж платы = комплекты × n.
 * Платы с расчётом инженером в сумму не входят (как в калькуляторе); если такие все — расчёт инженером.
 */
function pcb_calc_run_set( array $p, array $set, array $fx ) {
	$sets = (int) ( $set['sets'] ?? 0 );
	$fail = function ( $why ) {
		return array( 'ok' => false, 'set' => true, 'engineer' => false, 'warn' => array(), 'lines' => array(), 'notes' => array(), 'reason' => $why );
	};
	if ( $sets < 1 ) {
		return $fail( 'Укажите число комплектов, чтобы увидеть оценку.' );
	}
	$all = array();
	$bad = array();
	foreach ( array_slice( (array) ( $set['boards'] ?? array() ), 0, PCB_CALC_MAX_BOARDS ) as $row ) {
		$n          = max( 1, (int) ( $row['n'] ?? 1 ) );
		$in         = (array) ( $row['in'] ?? array() );
		$in['qty']  = (string) ( $sets * $n );
		$b          = pcb_calc_board( $p, $in, (string) ( $row['name'] ?? '' ) );
		$b['n']     = $n;
		$all[]      = $b;
		if ( ! $b['ok'] ) {
			$bad[] = '«' . $b['name'] . '»';
		}
	}
	if ( ! $all ) {
		return $fail( 'Отметьте платы, которые входят в заказ.' );
	}
	if ( $bad ) {
		return $fail( 'Заполните параметры ' . ( count( $bad ) > 1 ? 'плат ' : 'платы ' ) . implode( ', ', $bad ) . ' — выберите её в блоке «Файлы проекта».' );
	}
	$priced = array_values( array_filter( $all, function ( $b ) { return ! $b['engineer']; } ) );
	$engB   = array_values( array_filter( $all, function ( $b ) { return $b['engineer']; } ) );
	$warn   = array();
	$notes  = array();
	foreach ( $all as $b ) {
		foreach ( $b['warn'] as $w ) {
			$warn[] = '«' . $b['name'] . '»: ' . $w;
		}
		foreach ( $b['notes'] as $t ) {
			$notes[] = '«' . $b['name'] . '»: ' . $t;
		}
	}
	if ( $priced && $engB ) {
		$notes[] = 'В сумму не входят платы с расчётом инженером: ' . implode( ', ', array_map( function ( $b ) { return '«' . $b['name'] . '»'; }, $engB ) ) . '.';
	}
	// все платы — инженер: считаем как сложилась бы цена (для панели), сайту — «расчёт инженером»
	$res = pcb_calc_sum( $p, $priced ?: $all, $fx, $sets, ! $priced, $warn, $notes, $engB );
	$res['partial'] = $priced && $engB;
	return $res;
}

/* ---------------- общее на заказ ---------------- */

/**
 * Курс, финансирование, доставка, таможня, наценка, НДС, округление, вилка — для одной платы ($sets = null) или комплекта.
 * $B — платы, которые входят в сумму; $engB — платы комплекта с расчётом инженером (только для ответа сайту).
 */
function pcb_calc_sum( array $p, array $B, array $fx, $sets, $engineer, array $warn, array $notes, array $engB = array() ) {
	$P     = pcb_calc_params();
	$isSet = null !== $sets;
	$res   = array( 'ok' => false, 'engineer' => (bool) $engineer, 'warn' => $warn, 'lines' => array(), 'notes' => $notes );
	$sum   = function ( $k ) use ( $B ) {
		return array_sum( array_map( function ( $b ) use ( $k ) { return $b[ $k ]; }, $B ) );
	};

	// --- 3. деньги: курс с надбавками
	$cbr  = (float) $fx['cny'];
	$rate = $cbr * ( 1 + $p['bank'] / 100 ) * ( 1 + $p['agent'] / 100 ) * ( 1 + $p['buffer'] / 100 );
	foreach ( $B as &$b ) {
		$b['pcbR'] = $b['factY'] * $rate;
		$b['asmR'] = ( $b['asmY'] + $b['axiY'] ) * $rate;
	}
	unset( $b );
	$factY  = $sum( 'factY' );
	$asmAll = $sum( 'asmY' ) + $sum( 'axiY' );
	$pcbC   = $factY * $cbr;
	$asmC   = $asmAll * $cbr;
	$pay    = ( $factY + $asmAll ) * ( $rate - $cbr );
	$pcbR   = $factY * $rate;
	$asmR   = $asmAll * $rate;

	// --- 4. доставка: оплачиваемый вес (больший из фактического и объёмного) × тариф по весу, страховка
	$tKey   = $p['shipMode'];
	$kg     = $sum( 'kg' );
	$m3     = $sum( 'm3' );
	$volKg  = $m3 * (float) $p['volK'][ $tKey ];
	$charge = max( $kg, $volKg );
	$tarR   = max( 0, pcb_calc_cv( $p, 'tariff', $tKey, $charge ) );
	$shipT  = $charge * $tarR;
	$shipB  = max( $p['shipMin'], $shipT );
	$ins    = ( $pcbC + $asmC ) * $p['insure'] / 100;
	$ship   = $shipB + $ins;

	// --- 5. таможня: таможенная стоимость — цена фабрики по курсу ЦБ (без наших надбавок) + доставка до границы + страховка
	$cval = $pcbC + $asmC + $shipB * $p['shipBorder'] / 100 + $ins;
	$duty = $cval * $p['duty'] / 100;
	$ivat = ( $cval + $duty ) * $p['importVat'] / 100;
	$cfee = pcb_calc_step( $p['customsFee'], $cval );
	$cust = $duty + $cfee + $p['broker'] + ( $p['vatDeduct'] ? 0 : $ivat );

	// --- 6. наши услуги (на каждую плату)
	$ours   = $sum( 'ours' );
	$anyPmi = false;
	foreach ( $B as $b ) {
		$anyPmi = $anyPmi || ! empty( $b['pmiOn'] );
	}

	// --- 3б. финансирование: деньги, заплаченные раньше оплаты клиента
	$fr   = $p['finRate'] / 100 / 365;
	$pre  = 1 - min( 100, max( 0, $p['finPre'] ) ) / 100;
	$finF = ( $pcbR + $asmR ) * $pre * $fr * $p['finDays'];
	$finC = ( $duty + $cfee + $p['broker'] ) * $pre * $fr * $p['finDaysCust'];
	$finV = $p['vatDeduct'] ? $ivat * $fr * $p['finDaysVat'] : 0;
	$fin  = $finF + $finC + $finV;

	// --- 7. наценка по кривой от себестоимости заказа, 8. НДС и вид цены
	$cost    = $pcbR + $asmR + $ship + $cust + $ours + $fin;
	$mPp     = max( 0, pcb_calc_cv( $p, 'margin', 'pcb', $cost ) );
	$mAp     = max( 0, pcb_calc_cv( $p, 'margin', 'asm', $cost ) );
	$mP      = 1 + $mPp / 100;
	$mA      = 1 + $mAp / 100;
	$basePcb = $pcbR + $ship + $cust + $ours + $fin;
	$net0    = $basePcb * $mP + $asmR * $mA;
	$vat     = $p['vat'] / 100;
	$net     = max( $net0, $p['minOrder'] / ( 1 + $vat ) );
	$top     = $net - $net0;
	$r       = max( 1, (int) $p['round'] );
	$rawG    = $net * ( 1 + $vat );
	$gross   = round( $rawG / $r ) * $r;
	$vatA    = $gross - $gross / ( 1 + $vat );
	$netF    = $gross - $vatA;
	$mrg     = $netF - $top - ( $pcbC + $asmC + $pay + $ship + $cust + $ours + $fin );
	if ( $top > 0.5 ) {
		$res['notes'][] = 'Минимальная сумма заказа — ' . number_format( $p['minOrder'], 0, ',', ' ' ) . ' ₽, оценка доведена до неё.';
	}
	$zero = $res['engineer'];
	$Rnd  = function ( $x ) use ( $r ) {
		return round( $x / $r ) * $r;
	};

	// --- вилка: базовая строка + сработавшие условия, в пределах «не ниже / не выше»
	$flags = array( 'quick' => false, 'est' => false, 'finish' => false, 'sample' => false );
	foreach ( $B as $b ) {
		foreach ( $flags as $fk => $on ) {
			$flags[ $fk ] = $on || ! empty( $b['flags'][ $fk ] );
		}
	}
	$flags['ship'] = $net0 > 0 && ( $ship + $cust ) * $mP / $net0 * 100 > $p['shipShare'];
	$fxVol         = max( 0, (float) ( $fx['vol'] ?? 0 ) );
	$band          = array( array( 'base', $p['band']['base']['lo'], $p['band']['base']['hi'] ) );
	foreach ( array( 'quick', 'est', 'finish', 'sample', 'ship' ) as $fk ) {
		if ( $flags[ $fk ] ) {
			$band[] = array( $fk, $p['band'][ $fk ]['lo'], $p['band'][ $fk ]['hi'] );
		}
	}
	if ( $fxVol > 0 ) {
		$band[] = array( 'fx', $p['band']['fx']['lo'] * $fxVol, $p['band']['fx']['hi'] * $fxVol );
	}
	$loP = max( (float) $p['bandMin'], min( 0, array_sum( array_column( $band, 1 ) ) ) );
	$hiP = min( (float) $p['bandMax'], max( 0, array_sum( array_column( $band, 2 ) ) ) );
	$pct = function ( $x ) {
		return $x / 100;
	};

	// финансирование — по платам пропорционально их расходам у фабрики (для строк сайта)
	$facAll = $pcbR + $asmR;
	foreach ( $B as &$b ) {
		$b['finB'] = $facAll > 0 ? $fin * ( $b['pcbR'] + $b['asmR'] ) / $facAll : $fin / count( $B );
	}
	unset( $b );

	if ( ! $isSet ) {
		// --- итог для сайта: укрупнённые строки, наценка, расходы на платёж и финансирование разнесены внутри
		$b     = $B[0];
		$parts = array(
			'prep' => $b['setupY'] * $rate,
			'pcb'  => $b['pcbY'] * $rate,
			'ship' => $ship + $cust,
		);
		$raw   = array_sum( $parts );
		$inc   = $ours + $fin; // «входит»: наши услуги и финансирование распределены по строкам плат
		foreach ( $parts as $kk => $v ) {
			$parts[ $kk ] = ( $v + ( $raw > 0 ? $inc * $v / $raw : 0 ) ) * $mP;
		}
		$parts['asm'] = $b['asmY'] * $rate * $mA;
		$parts['axi'] = $b['axiY'] * $rate * $mA;
		$scale = array_sum( $parts ) > 0 ? $gross / array_sum( $parts ) : 0; // доплата до минимума, НДС и округление — пропорционально
		$res['lines'] = pcb_calc_board_lines( $b, $parts, $scale, $zero );
		if ( $anyPmi ) {
			$res['lines'][] = array( 'name' => 'Проверка по ПМИ', 'text' => 'входит', 'incl' => true );
		}
		$res['lines'][] = array( 'name' => 'Приёмка ОТК на фабрике', 'text' => 'входит', 'incl' => true );
		$res['lines'][] = array( 'name' => 'Доставка и таможня', 'v' => $zero ? 0 : round( $parts['ship'] * $scale ) );
		$qtyOut = (int) $b['qty'];
	} else {
		// --- комплект: строки по платам (наши услуги и финансирование — в строки своей платы), доставка и таможня — одной партией
		$shipPart = ( $ship + $cust ) * $mP;
		$bp       = array();
		$total    = $shipPart;
		foreach ( $B as $i => $b ) {
			$prep = $b['setupY'] * $rate;
			$pcb  = $b['pcbY'] * $rate;
			$own  = $prep + $pcb;
			$inc  = $b['ours'] + $b['finB'];
			$bp[ $i ] = array(
				'prep' => ( $prep + ( $own > 0 ? $inc * $prep / $own : 0 ) ) * $mP,
				'pcb'  => ( $pcb + ( $own > 0 ? $inc * $pcb / $own : 0 ) ) * $mP,
				'ship' => 0,
				'asm'  => $b['asmY'] * $rate * $mA,
				'axi'  => $b['axiY'] * $rate * $mA,
			);
			$total += array_sum( $bp[ $i ] );
		}
		$scale = $total > 0 ? $gross / $total : 0;
		// экономия: доставка и таможня каждой платы отдельно (свой минимум, свой брокер и сбор) против общей партии
		$apart = 0;
		foreach ( $B as $b ) {
			$chB    = max( $b['kg'], $b['m3'] * (float) $p['volK'][ $tKey ] );
			$sB     = max( $p['shipMin'], $chB * max( 0, pcb_calc_cv( $p, 'tariff', $tKey, $chB ) ) );
			$insB   = ( $b['factY'] + $b['asmY'] + $b['axiY'] ) * $cbr * $p['insure'] / 100;
			$cvB    = ( $b['factY'] + $b['asmY'] + $b['axiY'] ) * $cbr + $sB * $p['shipBorder'] / 100 + $insB;
			$dB     = $cvB * $p['duty'] / 100;
			$apart += $sB + $insB + $dB + pcb_calc_step( $p['customsFee'], $cvB ) + $p['broker'] + ( $p['vatDeduct'] ? 0 : ( $cvB + $dB ) * $p['importVat'] / 100 );
		}
		$saving = max( 0, ( $apart - ( $ship + $cust ) ) * $mP * ( 1 + $vat ) );
		$boards = array();
		foreach ( $B as $i => $b ) {
			$goods    = array_sum( $bp[ $i ] ) * $scale;
			$bl       = pcb_calc_board_lines( $b, $bp[ $i ], $scale, $zero );
			$boards[] = array(
				'name' => $b['name'], 'n' => $b['n'], 'qty' => (int) $b['qty'], 'ok' => true, 'engineer' => false,
				'warn' => $b['warn'], 'goods' => $zero ? 0 : round( $goods ),
				'lo'   => $zero ? 0 : $Rnd( $goods * ( 1 + $pct( $loP ) ) ), 'hi' => $zero ? 0 : $Rnd( $goods * ( 1 + $pct( $hiP ) ) ),
				'per'  => $zero ? 0 : $goods / $b['qty'], 'lines' => $bl,
			);
			$res['lines'][] = array( 'board' => $b['name'], 'sub' => $zero ? null : $bl,
				'name' => '«' . $b['name'] . '»' . ( $b['n'] > 1 ? ' × ' . $b['n'] : '' ) . ' — ' . number_format( $b['qty'], 0, ',', ' ' ) . ' шт',
				'text' => $zero ? 'расчёт инженером' : '≈ ' . number_format( round( $goods ), 0, ',', ' ' ) . ' ₽' );
		}
		foreach ( $engB as $b ) {
			$boards[] = array( 'name' => $b['name'], 'n' => $b['n'], 'qty' => (int) $b['qty'], 'ok' => true, 'engineer' => true, 'warn' => $b['warn'], 'lines' => array() );
			$res['lines'][] = array( 'board' => $b['name'], 'sub' => null, 'name' => '«' . $b['name'] . '»' . ( $b['n'] > 1 ? ' × ' . $b['n'] : '' ) . ' — ' . number_format( $b['qty'], 0, ',', ' ' ) . ' шт', 'text' => 'расчёт инженером' );
		}
		$res['lines'][] = array( 'name' => 'Доставка и таможня — одной партией', 'v' => $zero ? 0 : round( $shipPart * $scale ) );
		if ( ! $zero && $saving > 1 && count( $B ) > 1 ) {
			$res['lines'][] = array( 'name' => 'Экономия против заказа плат по отдельности', 'text' => '≈ ' . number_format( round( $saving ), 0, ',', ' ' ) . ' ₽', 'soft' => true );
		}
		if ( $anyPmi ) {
			$res['lines'][] = array( 'name' => 'Проверка по ПМИ', 'text' => 'входит', 'incl' => true );
		}
		$res['lines'][] = array( 'name' => 'Приёмка ОТК на фабрике', 'text' => 'входит', 'incl' => true );
		$res['set']    = true;
		$res['sets']   = (int) $sets;
		$res['boards'] = $boards;
		$res['ship']   = $zero ? 0 : round( $shipPart * $scale );
		$res['saving'] = $zero || count( $B ) < 2 ? 0 : round( $saving );
		$qtyOut        = (int) $sets;
	}

	// --- срок и Китайский Новый год
	$term = $res['engineer'] || $engB ? $p['termEng'] : $p['termStd'];
	if ( pcb_calc_cny_hit( $p, $res['engineer'] || $engB ) ) {
		$res['notes'][] = $p['cnyNote'];
		$res['cny']     = true;
	}

	$res = array_merge( $res, array(
		'ok'    => true,
		'qty'   => $qtyOut,
		'total' => $zero ? 0 : $gross,
		'lo'    => $zero ? 0 : $Rnd( $gross * ( 1 + $pct( $loP ) ) ),
		'hi'    => $zero ? 0 : $Rnd( $gross * ( 1 + $pct( $hiP ) ) ),
		'per'   => $zero ? 0 : $gross / max( 1, $qtyOut ),
		'vat'   => array( 'pct' => $p['vat'], 'sum' => $zero ? 0 : round( $vatA ), 'show' => (bool) $p['showVat'] ),
		'term'  => $term,
	) );

	// --- разбор для панели (суммы: m — [значение, валюта], числа — num, множители — x, проценты — p)
	$res['x'] = array(
		'gross' => $gross, 'raw' => $rawG, 'rate' => $rate, 'cbr' => $cbr, 'mP' => $mPp, 'mA' => $mAp, 'cost' => $cost,
		'terms' => array(
			array( 'g' => 'factory', 'n' => 'Платы', 'm' => $pcbC ),
			array( 'g' => 'asm', 'n' => 'Монтаж', 'm' => $asmC ),
			array( 'g' => 'money', 'n' => 'Оплата фабрике', 'm' => $pay ),
			array( 'g' => 'money', 'n' => 'Финансирование', 'm' => $fin, 'sub' => 'fin' ),
			array( 'g' => 'ship', 'n' => 'Доставка', 'm' => $ship ),
			array( 'g' => 'customs', 'n' => 'Таможня', 'm' => $cust ),
			array( 'g' => 'ours', 'n' => 'Наши услуги', 'm' => $ours ),
			array( 'g' => 'margin', 'n' => 'Наценка', 'm' => $mrg ),
		),
	);
	if ( $top > 0.5 ) {
		$res['x']['terms'][] = array( 'g' => 'vat', 'n' => 'До минимума', 'm' => $top, 'warn' => true );
	}
	$res['x']['terms'][] = array( 'g' => 'vat', 'n' => 'НДС ' . pcb_calc_n( $p['vat'] ) . ' %', 'm' => $vatA );

	$M  = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N  = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$Pp = function ( $n, $v ) { return array( 'n' => $n, 'p' => $v ); };
	$q  = function ( $b ) { return $b['name'] ? ' «' . $b['name'] . '»' : ''; };

	$blocks = array();
	foreach ( $B as $b ) {
		$blocks[] = pcb_calc_block_factory( $p, $b, $cbr, $q( $b ) );
	}
	$anyAsm = false;
	foreach ( $B as $b ) {
		if ( $b['asmOn'] ) {
			$anyAsm   = true;
			$blocks[] = pcb_calc_block_asm( $p, $b, $cbr, $q( $b ) );
		}
	}
	if ( ! $anyAsm ) {
		$b0       = $B[0];
		$blocks[] = array( 'g' => 'asm', 'n' => 'Монтаж', 'm' => 0, 'f' => array(),
			'note' => $isSet ? 'В этом комплекте монтажа нет.' : ( 'none' === $b0['mode'] ? 'В этом заказе монтажа нет.' : 'Клиент выбрал монтаж, но не указал компоненты — в оценку не входит.' ) );
	}
	$blocks[] = array( 'g' => 'money', 'n' => 'Оплата фабрике', 'm' => $pay,
		'f' => array( '(', $M( 'Платы', $pcbC ), '+', $M( 'Монтаж', $asmC ), ')', '×', $Pp( 'Расходы на платёж', ( $rate / max( $cbr, 1e-9 ) - 1 ) * 100 ) ),
		'note' => 'Надбавка банка ' . pcb_calc_n( $p['bank'] ) . ' %, комиссия за платёж ' . pcb_calc_n( $p['agent'] ) . ' %, запас на курс ' . pcb_calc_n( $p['buffer'] ) . ' % — проценты перемножаются: ' . pcb_calc_n( $cbr ) . ' → ' . pcb_calc_n( $rate ) . ' ₽ за ¥.' );
	$f3 = array( $M( 'Фабрика', $finF ), '+', $M( 'Таможня', $finC ) );
	if ( $p['vatDeduct'] ) {
		array_push( $f3, '+', $M( 'НДС на ввоз до вычета', $finV ) );
	}
	$blocks[] = array( 'g' => 'money', 'n' => 'Финансирование', 'm' => $fin, 'f' => $f3,
		'note' => 'Деньги, заплаченные раньше оплаты клиента, × ' . pcb_calc_n( $p['finRate'] ) . ' % годовых × дни ÷ 365. Без предоплаты клиента (' . pcb_calc_n( $p['finPre'] ) . ' %): фабрика — ' . pcb_calc_n( $p['finDays'] ) . ' дн., таможня — ' . pcb_calc_n( $p['finDaysCust'] ) . ' дн.' . ( $p['vatDeduct'] ? '; НДС на ввоз ' . pcb_calc_n( round( $ivat ) ) . ' ₽ — ' . pcb_calc_n( $p['finDaysVat'] ) . ' дн. до вычета (весь, предоплата его не покрывает).' : '.' ) );
	$wnote = ( $isSet ? 'Все платы комплекта едут одной партией. ' : '' ) . 'Фактический вес ' . pcb_calc_n( $kg ) . ' кг, объём ' . pcb_calc_n( round( $m3, 3 ) ) . ' м³ → объёмный вес ' . pcb_calc_n( $volKg ) . ' кг (× ' . pcb_calc_n( $p['volK'][ $tKey ] ) . ' кг/м³); берём больший. Тариф ' . pcb_calc_n( $tarR ) . ' ₽/кг — по кривой для этого веса.';
	$blocks[] = array( 'g' => 'ship', 'n' => 'Доставка', 'm' => $ship,
		'f' => array( $N( 'Оплачиваемый вес', $charge, 'кг' ), '×', $M( 'Тариф, ' . mb_strtolower( $P['shipMode']['opts'][ $tKey ] ), $tarR, 'RUB', '/кг' ), '+', $M( 'Страховка ' . pcb_calc_n( $p['insure'] ) . ' %', $ins ) ),
		'note' => $wnote,
		'warn' => $shipB > $shipT + 0.5 ? array( 'min' => $p['shipMin'], 'by' => $shipT ) : null );
	$f5 = array( $Pp( 'Пошлина', $p['duty'] ), '×', $M( 'Таможенная стоимость', $cval ), '+', $M( 'Сбор', $cfee ), '+', $M( 'Брокер', $p['broker'] ) );
	if ( ! $p['vatDeduct'] ) {
		$f5[] = '+';
		$f5[] = $M( 'НДС на ввоз', $ivat );
	}
	$blocks[] = array( 'g' => 'customs', 'n' => 'Таможня', 'm' => $cust, 'f' => $f5,
		'note' => 'Таможенная стоимость — цена фабрики по курсу ЦБ (без надбавок банка и запаса на курс) + доставка × ' . pcb_calc_n( $p['shipBorder'] ) . ' % + страховка. Сбор — по шкале: ' . pcb_calc_customs_fee_row( $cval ) . '.' . ( $isSet ? ' Одна декларация на комплект: сбор и брокер — один раз.' : '' ), 'ivat' => $p['vatDeduct'] ? $ivat : null );
	$f6 = array();
	$n6 = array();
	foreach ( $B as $i => $b ) {
		if ( $i ) {
			$f6[] = '+';
		}
		if ( $isSet ) {
			$f6[] = $M( 'Плата' . $q( $b ), $b['ours'] );
		} else {
			array_push( $f6, $M( 'Аудит', $p['audit'] ), '+', $M( 'ОТК: ' . $b['smp'] . ' шт в выборке', $b['otkR'] ) );
			if ( $b['pmiOn'] ) {
				array_push( $f6, '+', $M( 'ПМИ, ' . mb_strtolower( $P['pmiLvl']['rows'][ $b['lvl'] ] ), $b['pmiR'] ) );
			}
		}
		$n6[] = ( $isSet ? '«' . $b['name'] . '»: ' : '' ) . 'ОТК — ' . pcb_calc_n( $p['otk'] ) . ' ₽ + ' . pcb_calc_n( $p['otkBoard'] ) . ' ₽ × ' . $b['smp'] . ' плат из ' . pcb_calc_n( $b['qty'] ) . ( $b['asmOn'] ? ' × ' . pcb_calc_n( $p['otkAsmK'] ) . ' (с монтажом)' : '' ) . '; ' .
			( $b['pmiOn'] ? 'ПМИ — «' . mb_strtolower( $P['pmiLvl']['rows'][ $b['lvl'] ] ) . '» (' . pcb_calc_n( $b['comps'] ) . ' корп.): ' . pcb_calc_n( $p['pmiLvl'][ $b['lvl'] ]['batch'] ) . ' ₽ + ' . pcb_calc_n( $p['pmiLvl'][ $b['lvl'] ]['board'] ) . ' ₽ × ' . pcb_calc_n( $b['qty'] ) : 'без монтажа проверки по ПМИ нет — испытывать нечего' ) . '.';
	}
	$blocks[] = array( 'g' => 'ours', 'n' => 'Наши услуги', 'm' => $ours, 'f' => $f6, 'note' => implode( ' ', $n6 ) );
	$f7 = array( $M( 'Платы с расходами', $basePcb ), '×', $Pp( 'Наценка на платы', $mPp ) );
	if ( $asmR > 0 ) {
		array_push( $f7, '+', $M( 'Монтаж с расходами', $asmR ), '×', $Pp( 'Наценка на монтаж', $mAp ) );
	}
	$blocks[] = array( 'g' => 'margin', 'n' => 'Наценка', 'm' => $mrg, 'f' => $f7,
		'note' => 'Проценты — по кривой при себестоимости заказа ' . pcb_calc_n( round( $cost ) ) . ' ₽. Платы с расходами — платы, их доля оплаты фабрике, доставка, таможня, наши услуги и финансирование. Округление итога — тоже здесь.' );
	$BL = $P['band']['rows'];
	$blocks[] = array( 'g' => 'vat', 'n' => 'НДС', 'm' => $vatA, 'f' => array( $M( 'Цена без НДС', $netF ), '×', $Pp( 'НДС', $p['vat'] ) ),
		'top' => $top > 0.5 ? $top : null, 'round' => $r, 'lo' => $res['lo'], 'hi' => $res['hi'], 'loP' => $loP, 'hiP' => $hiP, 'fxVol' => $fxVol,
		'band' => array_map( function ( $x ) use ( $BL ) { return array( 'n' => $BL[ $x[0] ], 'lo' => $x[1], 'hi' => $x[2] ); }, $band ) );
	$res['x']['blocks'] = $blocks;
	return $res;
}

/** Попадает ли срок заказа от сегодня на период Китайского Нового года. */
function pcb_calc_cny_hit( array $p, $eng ) {
	$from = strtotime( (string) $p['cnyFrom'] . ' 00:00:00 UTC' );
	$to   = strtotime( (string) $p['cnyTo'] . ' 23:59:59 UTC' );
	if ( ! $from || ! $to || $to < $from ) {
		return false;
	}
	$now  = time();
	$days = (float) $p['termDays'] * ( $eng ? 1.5 : 1 );
	return $now <= $to && $now + $days * 86400 >= $from;
}

/** Строки «из чего складывается» одной платы: подготовка, производство, монтаж, рентген, компоненты. */
function pcb_calc_board_lines( array $b, array $parts, $scale, $zero ) {
	$out = array();
	$L   = function ( $name, $v = null, $extra = array() ) use ( &$out, $scale, $zero ) {
		$out[] = array_merge( array( 'name' => $name ), null === $v ? array() : array( 'v' => $zero ? 0 : round( $v * $scale ) ), $extra );
	};
	$L( 'Подготовка производства', $parts['prep'] );
	$L( 'Производство плат', $parts['pcb'] );
	if ( 'none' !== $b['mode'] && $b['asmEmpty'] ) {
		$L( 'Монтаж', null, array( 'text' => 'заполните блок 3', 'soft' => true ) );
		$L( 'Компоненты', null, array( 'text' => 'по BOM', 'soft' => true ) );
	} elseif ( $b['asmOn'] ) {
		$L( 'Монтаж' . ( 2 === $b['sides'] ? ', 2 стороны' : '' ), $parts['asm'] );
		if ( $b['axiY'] > 0 ) {
			$L( 'Рентген-контроль BGA / QFN', $parts['axi'] );
		}
		$L( 'Компоненты', null, array( 'text' => 'по BOM', 'soft' => true ) );
	}
	return $out;
}

/** Блок разбора «Платы» одной платы. */
function pcb_calc_block_factory( array $p, array $b, $cbr, $suffix ) {
	$M  = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N  = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$X  = function ( $n, $v ) { return array( 'n' => $n, 'x' => $v ); };
	$L  = $b['layers'];
	$byMin = $b['matMin'] > $b['matRaw'];
	$f1 = $byMin
		? array( $M( 'Минимум материала, ' . pcb_calc_layer_word( $L ), $b['matMin'], 'CNY' ) )
		: array( $N( 'Площадь партии', $b['area'], 'дм²' ), '×', $M( 'Ставка, ' . pcb_calc_layer_word( $L ), $b['rateL'], 'CNY', '/дм²' ) );
	foreach ( $b['used'] as $u ) {
		if ( abs( $u[1] - 1 ) > 1e-9 ) {
			$f1[] = '×';
			$f1[] = $X( $u[0], $u[1] );
		}
	}
	foreach ( $b['used'] as $u ) {
		if ( $u[2] > 0 ) {
			$f1[] = '+';
			$f1[] = $M( $u[0], $u[2], 'CNY' );
		}
	}
	array_push( $f1, '+', $M( 'Плёнки', $b['filmY'], 'CNY' ) );
	if ( $b['engY'] > 0 ) {
		array_push( $f1, '+', $M( 'Инженерный сбор', $b['engY'], 'CNY' ) );
	}
	if ( $b['etY'] > 0 ) {
		array_push( $f1, '+', $M( 'Электротест', $b['etY'], 'CNY' ) );
	}
	$note = ( $suffix ? pcb_calc_n( $b['qty'] ) . ' шт · ' : '' ) . 'Прайс фабрики в юанях, пересчитан по курсу ЦБ ' . pcb_calc_n( $cbr ) . ' ₽ за ¥. ';
	$note .= $byMin
		? 'Материал по ставке — ' . pcb_calc_n( round( $b['matRaw'] ) ) . ' ¥, меньше минимума за заказ — берём минимум.'
		: 'Ставка ' . pcb_calc_n( round( $b['rateL'], 2 ) ) . ' ¥/дм² — по кривой при площади ' . pcb_calc_n( round( $b['area'], 1 ) ) . ' дм².';
	if ( $b['pan'] ) {
		$pn    = $b['pan'];
		$note .= ' Заготовка ' . $pn['nx'] . ' × ' . $pn['ny'] . ' плат, ' . pcb_calc_n( round( $pn['pl'] ) ) . ' × ' . pcb_calc_n( round( $pn['pw'] ) ) . ' мм (' . ( 'small' === $pn['why'] ? 'плата мельче порога для монтажа' : 'клиент выбрал поставку в панели' ) . '): площадь ×' . pcb_calc_n( round( $pn['k'], 2 ) ) . ' к площади плат.';
	}
	return array( 'g' => 'factory', 'n' => 'Платы' . $suffix, 'm' => $b['factY'] * $cbr, 'f' => $f1, 'note' => $note );
}

/** Блок разбора «Монтаж» одной платы. */
function pcb_calc_block_asm( array $p, array $b, $cbr, $suffix ) {
	$M  = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N  = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$f2 = array( $M( 'Инженерный сбор', $b['asmEngY'], 'CNY' ), '+', $M( 'Оснастка', $b['fixY'], 'CNY' ), '+', $M( 'Трафарет', $p['stencil'], 'CNY' ), '×', $N( 'Сторон', $b['sides'] ),
		'+', $N( 'Позиций', $b['feeders'] ), '×', $M( 'Катушка', $p['feeder'], 'CNY' ),
		'+', '(', $N( 'Паек на плату', $b['joints'] ), '×', $M( 'Средняя пайка', $b['joints'] > 0 ? $b['perY'] / $b['joints'] : 0, 'CNY' ), '+', $M( 'Упаковка', $p['asmPack'], 'CNY' ), ')', '×', $N( 'Плат', $b['qty'] ) );
	if ( $b['axiY'] > 0 ) {
		$f2[] = '+';
		$f2[] = $M( 'Рентген', $b['axiY'], 'CNY' );
	}
	return array( 'g' => 'asm', 'n' => 'Монтаж' . $suffix, 'm' => ( $b['asmY'] + $b['axiY'] ) * $cbr, 'f' => $f2,
		'note' => 'Корпусов на плате: ' . pcb_calc_n( $b['comps'] ) . ( 'quick' === $b['mode'] ? ' (быстрая оценка — пайки по средним значениям).' : '.' ) . ' Инженерный сбор и оснастка — по кривым от тиража.' );
}

/** Подпись строки шкалы сбора для разбора в панели. */
function pcb_calc_customs_fee_row( $cval ) {
	$rows = pcb_calc_params()['customsFee']['rows'];
	foreach ( $rows as $lim => $label ) {
		if ( 'inf' === (string) $lim || $cval <= (float) $lim ) {
			return $label;
		}
	}
	return end( $rows );
}

/** Число для подписей: 11,42 · 2,5 · 16. */
function pcb_calc_n( $x ) {
	$s = number_format( (float) $x, 2, ',', ' ' );
	return rtrim( rtrim( $s, '0' ), ',' );
}

/** Итог для сайта: без разбора для панели. */
function pcb_calc_public( array $res ) {
	unset( $res['x'] );
	return $res;
}

/** Слойности во входе (у комплекта — всех плат): для чувствительности к ставке за дм². */
function pcb_calc_input_layers( array $in ) {
	if ( pcb_calc_is_set( $in ) ) {
		$out = array();
		foreach ( (array) ( $in['set']['boards'] ?? array() ) as $b ) {
			$out[] = (string) (int) ( $b['in']['layers'] ?? 0 );
		}
		return array_values( array_unique( $out ) );
	}
	return array( (string) (int) ( $in['layers'] ?? 0 ) );
}

/** Кривая с Y, умноженными на $k (у строки $row или у всех). */
function pcb_calc_curve_scale( array $c, $k, $row = null ) {
	foreach ( $c['y'] as $rk => $ys ) {
		if ( null === $row || (string) $rk === (string) $row ) {
			$c['y'][ $rk ] = array_map( function ( $y ) use ( $k ) { return $y * $k; }, $ys );
		}
	}
	return $c;
}

/**
 * Чувствительность: насколько изменится цена эталона, если параметр вырастет на 10 %.
 * Скалярные денежные и процентные параметры + используемые строки кривых ставок, тарифа и наценки.
 */
function pcb_calc_sensitivity( array $p, array $in, array $fx ) {
	$base = pcb_calc_run( $p, $in, $fx );
	if ( empty( $base['ok'] ) || ! empty( $base['engineer'] ) || $base['x']['raw'] <= 0 ) {
		return array();
	}
	$b0   = $base['x']['raw'];
	$P    = pcb_calc_params();
	$out  = array();
	$try  = function ( $label, $p2, $fx2 = null ) use ( $in, $fx, $b0, &$out ) {
		$r = pcb_calc_run( $p2, $in, $fx2 ?? $fx );
		if ( ! empty( $r['ok'] ) ) {
			$out[] = array( $label, ( $r['x']['raw'] - $b0 ) / $b0 * 100 );
		}
	};
	$try( 'Курс юаня', $p, array_merge( $fx, array( 'cny' => $fx['cny'] * 1.1 ) ) );
	foreach ( array( 'stencil', 'feeder', 'asmPack', 'axi', 'bank', 'agent', 'buffer', 'finRate', 'shipMin', 'pack', 'insure', 'duty', 'broker', 'otk', 'otkBoard', 'audit' ) as $k ) {
		if ( (float) $p[ $k ] <= 0 ) {
			continue;
		}
		$p2       = $p;
		$p2[ $k ] = $p[ $k ] * 1.1;
		$try( $P[ $k ]['n'], $p2 );
	}
	foreach ( pcb_calc_input_layers( $in ) as $l ) {
		if ( isset( $p['mrate']['y'][ $l ] ) ) {
			$p2         = $p;
			$p2['mrate'] = pcb_calc_curve_scale( $p['mrate'], 1.1, $l );
			$try( 'Ставка материала, ' . pcb_calc_layer_word( (int) $l ), $p2 );
		}
	}
	$p2           = $p;
	$p2['tariff'] = pcb_calc_curve_scale( $p['tariff'], 1.1, $p['shipMode'] );
	$try( 'Тариф доставки', $p2 );
	$p2           = $p;
	$p2['margin'] = pcb_calc_curve_scale( $p['margin'], 1.1 );
	$try( 'Наценка', $p2 );
	usort( $out, function ( $a, $b ) {
		return $b[1] <=> $a[1];
	} );
	return array_slice( array_values( array_filter( $out, function ( $r ) {
		return $r[1] > 0.005;
	} ) ), 0, 6 );
}
