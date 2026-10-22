<?php
/**
 * Калькулятор: расчёт цены. Единственное место формулы — им пользуются и сайт (pcb-panel/v1/calc/quote),
 * и панель (черновик, эталоны, чувствительность). Поэтому цифры на сайте и в панели не расходятся.
 *
 * pcb_calc_run( $p, $in, $fx ):
 *   $p  — значения параметров (pcb_calc_clean), $in — вход с сайта (pcb_calc_input_clean): одна плата
 *         или комплект { set: { sets, boards: [ { name, n, in } ] } },
 *   $fx — курсы для расчёта: array( 'cny' => ₽ за ¥, 'usd' => ₽ за $ ).
 * Возвращает итог для сайта (ok, lines, lo, hi, per, vat …) и разбор для панели (x: terms — общая формула,
 * blocks — по блокам).
 *
 * Устройство: pcb_calc_board() — всё, что считается на одну плату (фабрика, монтаж, вес, наши услуги);
 * pcb_calc_sum() — общее на заказ: курс, доставка по суммарному весу, таможня, наценка, минимальная сумма,
 * НДС, округление. У одной платы заказ — она сама; у комплекта общее считается один раз (README §9.5).
 */

defined( 'ABSPATH' ) || exit;

const PCB_CALC_MAX_BOARDS = 20;

/** Оценка числа позиций (катушек) по числу корпусов на плате. */
function pcb_calc_feeders( $n ) {
	return $n > 0 ? min( $n, (int) round( 6 + $n * 0.2 ) ) : 0;
}

function pcb_calc_vol_key( $area ) {
	return $area < 2 ? 'lt2' : ( $area < 10 ? 'lt10' : ( $area < 50 ? 'lt50' : ( $area < 200 ? 'lt200' : 'ge200' ) ) );
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
	if ( in_array( 'imp', $spec, true ) ) { $eng( 'контроль импеданса' ); }
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
	$rateL = $p['dm2'][ (string) $layers ] ?? null;
	if ( null === $rateL ) { $eng( 'нет ставки для ' . pcb_calc_layer_word( $layers ) ); $rateL = 0; }

	// --- 1. платы, ¥
	$area   = $len * $wid / 10000 * $qty; // дм² на партию
	$setupY = $p['setup'] + $p['setupLayer'] * max( 0, $layers - 2 );
	$pend   = ! empty( $in['finish_pending'] );
	$fin    = $pend ? 'hasl' : $s( 'finish' );
	$thickK = $s( 'thick' );
	$sides  = '2' === $s( 'sides' ) ? 2 : 1;
	$vk     = pcb_calc_vol_key( $area );
	$mults  = array(
		array( 'Толщина ' . ( $P['thick']['rows'][ $thickK ] ?? $thickK . ' мм' ), $p['thick'][ $thickK ] ?? 1 ),
		array( 'Медь ' . $s( 'cuout' ) . ' мкм', $p['cu'][ $s( 'cuout' ) ] ?? 1 ),
		array( 'Внутр. медь 70 мкм', $layers >= 4 && '70' === $s( 'cuin' ) ? $p['cuin70'] : 1 ),
		array( 'Покрытие ' . ( $P['finish']['rows'][ $fin ] ?? $fin ), $p['finish'][ $fin ] ?? 1 ),
		array( 'Материал ' . ( $P['mat']['rows'][ $mat ] ?? $mat ), $p['mat'][ $mat ] ?? 1 ),
		array( 'Маска: ' . mb_strtolower( $P['mask']['rows'][ $s( 'mask' ) ] ?? $s( 'mask' ) ), $p['mask'][ $s( 'mask' ) ] ?? 1 ),
		array( 'Две стороны', 2 === $sides ? $p['sides2'] : 1 ),
		array( 'Объём партии', $p['vol'][ $vk ] ),
	);
	$k = 1;
	foreach ( $mults as $m ) {
		$k *= $m[1];
	}
	$pcbY = $area * $rateL * $k;
	if ( $pend ) {
		$b['notes'][] = 'Финишное покрытие не уточнено — в расчёте HASL.';
	}

	// --- 2. монтаж, ¥
	$asmEmpty = false;
	$joints   = 0;  // паек на плату
	$perY     = 0;  // ¥ за пайки одной платы
	$comps    = 0;  // корпусов на плате
	$axiOn    = false;
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
		$axiOn    = $a( 'd_bga' ) > 0 || $a( 'd_qfn' ) > 0;
		$asmEmpty = ! ( $comps > 0 );
	} else {
		$parts = array();
	}
	foreach ( $parts as $pt ) {
		$joints += $pt[0];
		$perY   += $pt[0] * $pt[1];
	}
	$asmOn   = 'none' !== $mode && ! $asmEmpty;
	$feeders = $asmOn ? pcb_calc_feeders( (int) $comps ) : 0;
	$asmY    = $asmOn ? ( $p['asmSide'] + $p['stencil'] ) * $sides + $feeders * $p['feeder'] + $perY * $qty : 0;
	$axiY    = $asmOn && $axiOn ? $p['axi'] : 0;
	if ( $asmOn && ! empty( $in['asm_est'] ) ) {
		$b['notes'][] = 'Монтаж посчитан ориентировочно — компоненты не учтены поштучно.';
	}

	// --- вес для доставки и наши услуги (₽) — на плату
	$t  = (float) ( $thickK ?: 1.6 );
	$kg = $area * $t * $p['density'] / 100 * ( 1 + $p['pack'] / 100 ); // дм² × мм × г/см³: 1 дм² × 1 мм = 10 см³

	return array_merge( $b, array(
		'ok'       => true,
		'qty'      => $qty,
		'layers'   => $layers,
		'area'     => $area,
		'setupY'   => $setupY,
		'pcbY'     => $pcbY,
		'factY'    => $setupY + $pcbY,
		'rateL'    => $rateL,
		'mults'    => $mults,
		'mode'     => $mode,
		'asmOn'    => $asmOn,
		'asmEmpty' => $asmEmpty,
		'sides'    => $sides,
		'joints'   => $joints,
		'perY'     => $perY,
		'comps'    => $comps,
		'feeders'  => $feeders,
		'asmY'     => $asmY,
		'axiY'     => $axiY,
		't'        => $t,
		'kg'       => $kg,
		'ours'     => $p['audit'] + $p['pmi'] + $p['pmiBoard'] * $qty + $p['otk'],
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
 * Курс, доставка, таможня, наценка, НДС, округление — для одной платы ($sets = null) или комплекта.
 * $B — платы, которые входят в сумму; $engB — платы комплекта с расчётом инженером (только для ответа сайту).
 */
function pcb_calc_sum( array $p, array $B, array $fx, $sets, $engineer, array $warn, array $notes, array $engB = array() ) {
	$P     = pcb_calc_params();
	$isSet = null !== $sets;
	$res   = array( 'ok' => false, 'engineer' => (bool) $engineer, 'warn' => $warn, 'lines' => array(), 'notes' => $notes );
	$sum   = function ( $k ) use ( $B ) {
		return array_sum( array_map( function ( $b ) use ( $k ) { return $b[ $k ]; }, $B ) );
	};

	// --- 3. деньги
	$cbr  = (float) $fx['cny'];
	$rate = $cbr * ( 1 + $p['bank'] / 100 ) * ( 1 + $p['agent'] / 100 ) * ( 1 + $p['buffer'] / 100 );
	foreach ( $B as &$b ) {
		$b['pcbC'] = $b['factY'] * $cbr;
		$b['asmC'] = ( $b['asmY'] + $b['axiY'] ) * $cbr;
		$b['pcbR'] = $b['factY'] * $rate;
		$b['asmR'] = ( $b['asmY'] + $b['axiY'] ) * $rate;
	}
	unset( $b );
	$factY = $sum( 'factY' );
	$asmAll = $sum( 'asmY' ) + $sum( 'axiY' );
	$pcbC  = $factY * $cbr;
	$asmC  = $asmAll * $cbr;
	$pay   = ( $factY + $asmAll ) * ( $rate - $cbr );
	$pcbR  = $factY * $rate;
	$asmR  = $asmAll * $rate;

	// --- 4. доставка (вес всех плат — одной партией), 5. таможня (один раз), 6. наши услуги (на каждую плату)
	$kg    = $sum( 'kg' );
	$tKey  = $p['shipMode'];
	$shipT = $kg * $p['tariff'][ $tKey ];
	$ship  = max( $p['shipMin'], $shipT );
	$cval  = $pcbR + $asmR + $ship;
	$duty  = $cval * $p['duty'] / 100;
	$ivat  = ( $cval + $duty ) * $p['importVat'] / 100;
	$cust  = $duty + $p['customsFee'] + $p['broker'] + ( $p['vatDeduct'] ? 0 : $ivat );
	$ours  = $sum( 'ours' );

	// --- 7. наценка, 8. НДС и вид цены
	$basePcb = $pcbR + $ship + $cust + $ours;
	$net0    = $basePcb * ( 1 + $p['mPcb'] / 100 ) + $asmR * ( 1 + $p['mAsm'] / 100 );
	$vat     = $p['vat'] / 100;
	$net     = max( $net0, $p['minOrder'] / ( 1 + $vat ) );
	$top     = $net - $net0;
	$r       = max( 1, (int) $p['round'] );
	$rawG    = $net * ( 1 + $vat );
	$gross   = round( $rawG / $r ) * $r;
	$vatA    = $gross - $gross / ( 1 + $vat );
	$netF    = $gross - $vatA;
	$mrg     = $netF - $top - ( $pcbC + $asmC + $pay + $ship + $cust + $ours );
	if ( $top > 0.5 ) {
		$res['notes'][] = 'Минимальная сумма заказа — ' . number_format( $p['minOrder'], 0, ',', ' ' ) . ' ₽, оценка доведена до неё.';
	}
	$mP   = 1 + $p['mPcb'] / 100;
	$mA   = 1 + $p['mAsm'] / 100;
	$pct  = function ( $x ) {
		return $x / 100;
	};
	$zero = $res['engineer'];
	$Rnd  = function ( $x ) use ( $r ) {
		return round( $x / $r ) * $r;
	};

	if ( ! $isSet ) {
		// --- итог для сайта: укрупнённые строки, наценка и расходы на платёж разнесены внутри
		$b     = $B[0];
		$parts = array(
			'prep' => $b['setupY'] * $rate,
			'pcb'  => $b['pcbY'] * $rate,
			'ship' => $ship + $cust,
		);
		$raw   = array_sum( $parts );
		foreach ( $parts as $kk => $v ) { // наши услуги — «входит»: распределены по строкам плат
			$parts[ $kk ] = ( $v + ( $raw > 0 ? $ours * $v / $raw : 0 ) ) * $mP;
		}
		$parts['asm'] = $b['asmY'] * $rate * $mA;
		$parts['axi'] = $b['axiY'] * $rate * $mA;
		$scale = array_sum( $parts ) > 0 ? $gross / array_sum( $parts ) : 0; // доплата до минимума, НДС и округление — пропорционально
		$res['lines'] = pcb_calc_board_lines( $b, $parts, $scale, $zero );
		$res['lines'][] = array( 'name' => 'Проверка по ПМИ', 'text' => 'входит', 'incl' => true );
		$res['lines'][] = array( 'name' => 'Приёмка ОТК на фабрике', 'text' => 'входит', 'incl' => true );
		$res['lines'][] = array( 'name' => 'Доставка и таможня', 'v' => $zero ? 0 : round( $parts['ship'] * $scale ) );
		$qtyOut = (int) $b['qty'];
	} else {
		// --- комплект: строки по платам (наши услуги — в строки своей платы), доставка и таможня — одной партией
		$shipPart = ( $ship + $cust ) * $mP;
		$bp       = array();
		$total    = $shipPart;
		foreach ( $B as $i => $b ) {
			$prep = $b['setupY'] * $rate;
			$pcb  = $b['pcbY'] * $rate;
			$own  = $prep + $pcb;
			$bp[ $i ] = array(
				'prep' => ( $prep + ( $own > 0 ? $b['ours'] * $prep / $own : 0 ) ) * $mP,
				'pcb'  => ( $pcb + ( $own > 0 ? $b['ours'] * $pcb / $own : 0 ) ) * $mP,
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
			$sB     = max( $p['shipMin'], $b['kg'] * $p['tariff'][ $tKey ] );
			$cvB    = $b['pcbR'] + $b['asmR'] + $sB;
			$dB     = $cvB * $p['duty'] / 100;
			$apart += $sB + $dB + $p['customsFee'] + $p['broker'] + ( $p['vatDeduct'] ? 0 : ( $cvB + $dB ) * $p['importVat'] / 100 );
		}
		$saving = max( 0, ( $apart - ( $ship + $cust ) ) * $mP * ( 1 + $vat ) );
		$boards = array();
		foreach ( $B as $i => $b ) {
			$goods    = array_sum( $bp[ $i ] ) * $scale;
			$bl       = pcb_calc_board_lines( $b, $bp[ $i ], $scale, $zero );
			$boards[] = array(
				'name' => $b['name'], 'n' => $b['n'], 'qty' => (int) $b['qty'], 'ok' => true, 'engineer' => false,
				'warn' => $b['warn'], 'goods' => $zero ? 0 : round( $goods ),
				'lo'   => $zero ? 0 : $Rnd( $goods * ( 1 + $pct( $p['lo'] ) ) ), 'hi' => $zero ? 0 : $Rnd( $goods * ( 1 + $pct( $p['hi'] ) ) ),
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
		$res['lines'][] = array( 'name' => 'Проверка по ПМИ', 'text' => 'входит', 'incl' => true );
		$res['lines'][] = array( 'name' => 'Приёмка ОТК на фабрике', 'text' => 'входит', 'incl' => true );
		$res['set']    = true;
		$res['sets']   = (int) $sets;
		$res['boards'] = $boards;
		$res['ship']   = $zero ? 0 : round( $shipPart * $scale );
		$res['saving'] = $zero || count( $B ) < 2 ? 0 : round( $saving );
		$qtyOut        = (int) $sets;
	}

	$res = array_merge( $res, array(
		'ok'    => true,
		'qty'   => $qtyOut,
		'total' => $zero ? 0 : $gross,
		'lo'    => $zero ? 0 : $Rnd( $gross * ( 1 + $pct( $p['lo'] ) ) ),
		'hi'    => $zero ? 0 : $Rnd( $gross * ( 1 + $pct( $p['hi'] ) ) ),
		'per'   => $zero ? 0 : $gross / max( 1, $qtyOut ),
		'vat'   => array( 'pct' => $p['vat'], 'sum' => $zero ? 0 : round( $vatA ), 'show' => (bool) $p['showVat'] ),
		'term'  => $res['engineer'] || $engB ? $p['termEng'] : $p['termStd'],
	) );

	// --- разбор для панели (суммы: m — [значение, валюта], числа — num, множители — x, проценты — p)
	$res['x'] = array(
		'gross' => $gross, 'raw' => $rawG, 'rate' => $rate, 'cbr' => $cbr,
		'terms' => array(
			array( 'g' => 'factory', 'n' => 'Платы', 'm' => $pcbC ),
			array( 'g' => 'asm', 'n' => 'Монтаж', 'm' => $asmC ),
			array( 'g' => 'money', 'n' => 'Оплата фабрике', 'm' => $pay ),
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
		$blocks[] = pcb_calc_block_factory( $b, $cbr, $q( $b ) );
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
	$wnote = $isSet
		? 'Все платы комплекта едут одной партией: вес — сумма по платам, минимум за отправку — один раз.'
		: 'Вес — площадь × толщина ' . pcb_calc_n( $B[0]['t'] ) . ' мм × плотность ' . pcb_calc_n( $p['density'] ) . ' г/см³ + упаковка ' . pcb_calc_n( $p['pack'] ) . ' %.';
	$blocks[] = array( 'g' => 'ship', 'n' => 'Доставка', 'm' => $ship,
		'f' => array( $N( 'Вес партии', $kg, 'кг' ), '×', $M( 'Тариф, ' . mb_strtolower( $P['shipMode']['opts'][ $tKey ] ), $p['tariff'][ $tKey ], 'RUB', '/кг' ) ),
		'note' => $wnote,
		'warn' => $ship > $shipT + 0.5 ? array( 'min' => $p['shipMin'], 'by' => $shipT ) : null );
	$f5 = array( $Pp( 'Пошлина', $p['duty'] ), '×', $M( 'Таможенная стоимость', $cval ), '+', $M( 'Сбор', $p['customsFee'] ), '+', $M( 'Брокер', $p['broker'] ) );
	if ( ! $p['vatDeduct'] ) {
		$f5[] = '+';
		$f5[] = $M( 'НДС на ввоз', $ivat );
	}
	$blocks[] = array( 'g' => 'customs', 'n' => 'Таможня', 'm' => $cust, 'f' => $f5,
		'note' => 'Таможенная стоимость — платы и монтаж с расходами на платёж плюс доставка.' . ( $isSet ? ' Одна декларация на комплект: сбор и брокер — один раз.' : '' ), 'ivat' => $p['vatDeduct'] ? $ivat : null );
	if ( $isSet ) {
		$f6 = array();
		foreach ( $B as $i => $b ) {
			if ( $i ) {
				$f6[] = '+';
			}
			$f6[] = $M( 'Плата' . $q( $b ), $b['ours'] );
		}
		$blocks[] = array( 'g' => 'ours', 'n' => 'Наши услуги', 'm' => $ours, 'f' => $f6,
			'note' => 'На каждую плату комплекта: аудит файлов ' . pcb_calc_n( $p['audit'] ) . ' ₽ + ПМИ за партию ' . pcb_calc_n( $p['pmi'] ) . ' ₽ + ПМИ ' . pcb_calc_n( $p['pmiBoard'] ) . ' ₽ × штук + приёмка ОТК ' . pcb_calc_n( $p['otk'] ) . ' ₽.' );
	} else {
		$blocks[] = array( 'g' => 'ours', 'n' => 'Наши услуги', 'm' => $ours,
			'f' => array( $M( 'Аудит файлов', $p['audit'] ), '+', $M( 'ПМИ за партию', $p['pmi'] ), '+', $M( 'ПМИ за плату', $p['pmiBoard'] ), '×', $N( 'Плат', $B[0]['qty'] ), '+', $M( 'Приёмка ОТК', $p['otk'] ) ) );
	}
	$f7 = array( $M( 'Платы с расходами', $basePcb ), '×', $Pp( 'Наценка на платы', $p['mPcb'] ) );
	if ( $asmR > 0 ) {
		array_push( $f7, '+', $M( 'Монтаж с расходами', $asmR ), '×', $Pp( 'Наценка на монтаж', $p['mAsm'] ) );
	}
	$blocks[] = array( 'g' => 'margin', 'n' => 'Наценка', 'm' => $mrg, 'f' => $f7,
		'note' => 'Платы с расходами — платы, их доля оплаты фабрике, доставка, таможня и наши услуги. Округление итога — тоже здесь.' );
	$blocks[] = array( 'g' => 'vat', 'n' => 'НДС', 'm' => $vatA, 'f' => array( $M( 'Цена без НДС', $netF ), '×', $Pp( 'НДС', $p['vat'] ) ),
		'top' => $top > 0.5 ? $top : null, 'round' => $r, 'lo' => $res['lo'], 'hi' => $res['hi'] );
	$res['x']['blocks'] = $blocks;
	return $res;
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
function pcb_calc_block_factory( array $b, $cbr, $suffix ) {
	$M  = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N  = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$X  = function ( $n, $v ) { return array( 'n' => $n, 'x' => $v ); };
	$L  = $b['layers'];
	$f1 = array( $M( 'Подготовка' . ( $L > 2 ? ', ' . pcb_calc_layer_word( $L ) : '' ), $b['setupY'], 'CNY' ), '+', $N( 'Площадь партии', $b['area'], 'дм²' ), '×', $M( 'Ставка, ' . pcb_calc_layer_word( $L ), $b['rateL'], 'CNY', '/дм²' ) );
	$hidden = 0;
	foreach ( $b['mults'] as $m ) {
		if ( abs( $m[1] - 1 ) < 1e-9 ) {
			$hidden++;
			continue;
		}
		$f1[] = '×';
		$f1[] = $X( $m[0], $m[1] );
	}
	return array( 'g' => 'factory', 'n' => 'Платы' . $suffix, 'm' => $b['factY'] * $cbr, 'f' => $f1,
		'note' => ( $suffix ? pcb_calc_n( $b['qty'] ) . ' шт · ' : '' ) . 'Прайс фабрики в юанях, пересчитан по курсу ЦБ ' . pcb_calc_n( $cbr ) . ' ₽ за ¥.' . ( $hidden ? ' Множители, равные 1, не показаны.' : '' ) );
}

/** Блок разбора «Монтаж» одной платы. */
function pcb_calc_block_asm( array $p, array $b, $cbr, $suffix ) {
	$M  = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N  = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$f2 = array( '(', $M( 'Запуск', $p['asmSide'], 'CNY' ), '+', $M( 'Трафарет', $p['stencil'], 'CNY' ), ')', '×', $N( 'Сторон', $b['sides'] ),
		'+', $N( 'Позиций', $b['feeders'] ), '×', $M( 'Катушка', $p['feeder'], 'CNY' ),
		'+', $N( 'Паек на плату', $b['joints'] ), '×', $M( 'Средняя пайка', $b['joints'] > 0 ? $b['perY'] / $b['joints'] : 0, 'CNY' ), '×', $N( 'Плат', $b['qty'] ) );
	if ( $b['axiY'] > 0 ) {
		$f2[] = '+';
		$f2[] = $M( 'Рентген', $b['axiY'], 'CNY' );
	}
	return array( 'g' => 'asm', 'n' => 'Монтаж' . $suffix, 'm' => ( $b['asmY'] + $b['axiY'] ) * $cbr, 'f' => $f2,
		'note' => 'Корпусов на плате: ' . pcb_calc_n( $b['comps'] ) . ( 'quick' === $b['mode'] ? ' (быстрая оценка — пайки по средним значениям).' : '.' ) );
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

/**
 * Чувствительность: насколько изменится цена эталона, если параметр вырастет на 10 %.
 * Скалярные денежные и процентные параметры + используемые строки таблиц ставок и тарифа.
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
	$try( 'Курс юаня', $p, array( 'cny' => $fx['cny'] * 1.1, 'usd' => $fx['usd'] ) );
	foreach ( array( 'setup', 'asmSide', 'stencil', 'feeder', 'axi', 'bank', 'agent', 'buffer', 'shipMin', 'pack', 'duty', 'customsFee', 'broker', 'pmi', 'pmiBoard', 'otk', 'audit', 'mPcb', 'mAsm' ) as $k ) {
		if ( (float) $p[ $k ] <= 0 ) {
			continue;
		}
		$p2       = $p;
		$p2[ $k ] = $p[ $k ] * 1.1;
		$try( $P[ $k ]['n'], $p2 );
	}
	foreach ( pcb_calc_input_layers( $in ) as $l ) {
		if ( isset( $p['dm2'][ $l ] ) ) {
			$p2               = $p;
			$p2['dm2'][ $l ] *= 1.1;
			$try( 'Ставка за дм², ' . pcb_calc_layer_word( (int) $l ), $p2 );
		}
	}
	$p2 = $p;
	$p2['tariff'][ $p['shipMode'] ] *= 1.1;
	$try( 'Тариф доставки', $p2 );
	usort( $out, function ( $a, $b ) {
		return $b[1] <=> $a[1];
	} );
	return array_slice( array_values( array_filter( $out, function ( $r ) {
		return $r[1] > 0.005;
	} ) ), 0, 6 );
}
