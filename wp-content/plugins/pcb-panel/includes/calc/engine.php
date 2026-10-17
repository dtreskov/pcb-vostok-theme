<?php
/**
 * Калькулятор: расчёт цены. Единственное место формулы — им пользуются и сайт (pcb-panel/v1/calc/quote),
 * и панель (черновик, эталоны, чувствительность). Поэтому цифры на сайте и в панели не расходятся.
 *
 * pcb_calc_run( $p, $in, $fx ):
 *   $p  — значения параметров (pcb_calc_clean), $in — вход с сайта (pcb_calc_input_clean),
 *   $fx — курсы для расчёта: array( 'cny' => ₽ за ¥, 'usd' => ₽ за $ ).
 * Возвращает итог для сайта (ok, lines, lo, hi, per, vat …) и разбор для панели (terms — общая формула, blocks — по блокам).
 */

defined( 'ABSPATH' ) || exit;

/** Оценка числа позиций (катушек) по числу корпусов на плате. */
function pcb_calc_feeders( $n ) {
	return $n > 0 ? min( $n, (int) round( 6 + $n * 0.2 ) ) : 0;
}

function pcb_calc_vol_key( $area ) {
	return $area < 2 ? 'lt2' : ( $area < 10 ? 'lt10' : ( $area < 50 ? 'lt50' : ( $area < 200 ? 'lt200' : 'ge200' ) ) );
}

function pcb_calc_run( array $p, array $in, array $fx ) {
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
	$res = array( 'ok' => false, 'engineer' => false, 'warn' => array(), 'lines' => array(), 'notes' => array() );

	$qty = $f( 'qty' );
	$len = $f( 'len' );
	$wid = $f( 'wid' );
	if ( ! ( $qty > 0 && $len > 0 && $wid > 0 ) ) {
		$res['reason'] = 'Укажите тираж и габариты платы, чтобы увидеть оценку.';
		return $res;
	}
	$layers = (int) $s( 'layers' );
	$need   = array_filter( array( 'thick', 'cuout', 'mat', 'finish', 'mask', 'silk' ), function ( $k ) use ( $s ) {
		return '' === $s( $k );
	} );
	if ( $layers >= 4 && '' === $s( 'cuin' ) ) {
		$need[] = 'cuin';
	}
	if ( $need || $layers < 1 ) {
		$res['reason'] = 'Заполните отмеченные поля — после этого покажем оценку.';
		return $res;
	}
	$qty = min( $qty, 1e6 );

	// --- когда нужен инженер
	$eng  = function ( $why ) use ( &$res ) {
		$res['engineer'] = true;
		$res['warn'][]   = $why;
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
	$fin    = $in['finish_pending'] ? 'hasl' : $s( 'finish' );
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
	if ( $in['finish_pending'] ) {
		$res['notes'][] = 'Финишное покрытие не уточнено — в расчёте HASL.';
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
	$asmSetY = $asmOn ? ( $p['asmSide'] + $p['stencil'] ) * $sides : 0;
	$asmY    = $asmOn ? $asmSetY + $feeders * $p['feeder'] + $perY * $qty : 0;
	$axiY    = $asmOn && $axiOn ? $p['axi'] : 0;

	// --- 3. деньги
	$cbr    = (float) $fx['cny'];
	$rate   = $cbr * ( 1 + $p['bank'] / 100 ) * ( 1 + $p['agent'] / 100 ) * ( 1 + $p['buffer'] / 100 );
	$factY  = $setupY + $pcbY;
	$pcbC   = $factY * $cbr;
	$asmC   = ( $asmY + $axiY ) * $cbr;
	$pay    = ( $factY + $asmY + $axiY ) * ( $rate - $cbr );
	$pcbR   = $factY * $rate;
	$asmR   = ( $asmY + $axiY ) * $rate;

	// --- 4. доставка, 5. таможня, 6. наши услуги — ₽
	$t      = (float) ( $thickK ?: 1.6 );
	$kg     = $area * $t * $p['density'] / 100 * ( 1 + $p['pack'] / 100 ); // дм² × мм × г/см³: 1 дм² × 1 мм = 10 см³
	$tKey   = $p['shipMode'];
	$shipT  = $kg * $p['tariff'][ $tKey ];
	$ship   = max( $p['shipMin'], $shipT );
	$cval   = $pcbR + $asmR + $ship;
	$duty   = $cval * $p['duty'] / 100;
	$ivat   = ( $cval + $duty ) * $p['importVat'] / 100;
	$cust   = $duty + $p['customsFee'] + $p['broker'] + ( $p['vatDeduct'] ? 0 : $ivat );
	$ours   = $p['audit'] + $p['pmi'] + $p['pmiBoard'] * $qty + $p['otk'];

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

	// --- итог для сайта: укрупнённые строки, наценка и расходы на платёж разнесены внутри
	$parts = array(
		'prep' => $setupY * $rate,
		'pcb'  => $pcbY * $rate,
		'ship' => $ship + $cust,
	);
	$raw   = array_sum( $parts );
	foreach ( $parts as $kk => $v ) { // наши услуги — «входит»: распределены по строкам плат
		$parts[ $kk ] = ( $v + ( $raw > 0 ? $ours * $v / $raw : 0 ) ) * ( 1 + $p['mPcb'] / 100 );
	}
	$parts['asm'] = $asmY * $rate * ( 1 + $p['mAsm'] / 100 );
	$parts['axi'] = $axiY * $rate * ( 1 + $p['mAsm'] / 100 );
	$sum   = array_sum( $parts );
	$scale = $sum > 0 ? $gross / $sum : 0; // доплата до минимума, НДС и округление — пропорционально
	$L     = function ( $name, $v = null, $extra = array() ) use ( &$res, $scale ) {
		$res['lines'][] = array_merge( array( 'name' => $name ), null === $v ? array() : array( 'v' => $res['engineer'] ? 0 : round( $v * $scale ) ), $extra );
	};
	$L( 'Подготовка производства', $parts['prep'] );
	$L( 'Производство плат', $parts['pcb'] );
	if ( 'none' !== $mode && $asmEmpty ) {
		$L( 'Монтаж', null, array( 'text' => 'заполните блок 3', 'soft' => true ) );
		$L( 'Компоненты', null, array( 'text' => 'по BOM', 'soft' => true ) );
	} elseif ( $asmOn ) {
		$L( 'Монтаж' . ( 2 === $sides ? ', 2 стороны' : '' ), $parts['asm'] );
		if ( $axiY > 0 ) {
			$L( 'Рентген-контроль BGA / QFN', $parts['axi'] );
		}
		$L( 'Компоненты', null, array( 'text' => 'по BOM', 'soft' => true ) );
	}
	$L( 'Проверка по ПМИ', null, array( 'text' => 'входит', 'incl' => true ) );
	$L( 'Приёмка ОТК на фабрике', null, array( 'text' => 'входит', 'incl' => true ) );
	$L( 'Доставка и таможня', $parts['ship'] );

	$pct = function ( $x ) {
		return $x / 100;
	};
	$res = array_merge( $res, array(
		'ok'    => true,
		'qty'   => (int) $qty,
		'total' => $res['engineer'] ? 0 : $gross,
		'lo'    => $res['engineer'] ? 0 : round( $gross * ( 1 + $pct( $p['lo'] ) ) / $r ) * $r,
		'hi'    => $res['engineer'] ? 0 : round( $gross * ( 1 + $pct( $p['hi'] ) ) / $r ) * $r,
		'per'   => $res['engineer'] ? 0 : $gross / $qty,
		'vat'   => array( 'pct' => $p['vat'], 'sum' => $res['engineer'] ? 0 : round( $vatA ), 'show' => (bool) $p['showVat'] ),
		'term'  => $res['engineer'] ? $p['termEng'] : $p['termStd'],
	) );

	// --- разбор для панели (суммы: m — [значение, валюта], числа — n, множители — x, проценты — p)
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

	$M = function ( $n, $v, $cur = 'RUB', $per = '' ) { return array( 'n' => $n, 'm' => array( $v, $cur ), 'per' => $per ); };
	$N = function ( $n, $v, $u = '' ) { return array( 'n' => $n, 'num' => $v, 'u' => $u ); };
	$X = function ( $n, $v ) { return array( 'n' => $n, 'x' => $v ); };
	$Pp = function ( $n, $v ) { return array( 'n' => $n, 'p' => $v ); };

	$f1 = array( $M( 'Подготовка' . ( $layers > 2 ? ', ' . pcb_calc_layer_word( $layers ) : '' ), $setupY, 'CNY' ), '+', $N( 'Площадь партии', $area, 'дм²' ), '×', $M( 'Ставка, ' . pcb_calc_layer_word( $layers ), $rateL, 'CNY', '/дм²' ) );
	$hidden = 0;
	foreach ( $mults as $m ) {
		if ( abs( $m[1] - 1 ) < 1e-9 ) {
			$hidden++;
			continue;
		}
		$f1[] = '×';
		$f1[] = $X( $m[0], $m[1] );
	}
	$blocks = array();
	$blocks[] = array( 'g' => 'factory', 'n' => 'Платы', 'm' => $pcbC, 'f' => $f1,
		'note' => 'Прайс фабрики в юанях, пересчитан по курсу ЦБ ' . pcb_calc_n( $cbr ) . ' ₽ за ¥.' . ( $hidden ? ' Множители, равные 1, не показаны.' : '' ) );
	if ( $asmOn ) {
		$f2 = array( '(', $M( 'Запуск', $p['asmSide'], 'CNY' ), '+', $M( 'Трафарет', $p['stencil'], 'CNY' ), ')', '×', $N( 'Сторон', $sides ),
			'+', $N( 'Позиций', $feeders ), '×', $M( 'Катушка', $p['feeder'], 'CNY' ),
			'+', $N( 'Паек на плату', $joints ), '×', $M( 'Средняя пайка', $joints > 0 ? $perY / $joints : 0, 'CNY' ), '×', $N( 'Плат', $qty ) );
		if ( $axiY > 0 ) {
			$f2[] = '+';
			$f2[] = $M( 'Рентген', $axiY, 'CNY' );
		}
		$blocks[] = array( 'g' => 'asm', 'n' => 'Монтаж', 'm' => $asmC, 'f' => $f2, 'note' => 'Корпусов на плате: ' . pcb_calc_n( $comps ) . ( 'quick' === $mode ? ' (быстрая оценка — пайки по средним значениям).' : '.' ) );
	} else {
		$blocks[] = array( 'g' => 'asm', 'n' => 'Монтаж', 'm' => 0, 'f' => array(), 'note' => 'none' === $mode ? 'В этом заказе монтажа нет.' : 'Клиент выбрал монтаж, но не указал компоненты — в оценку не входит.' );
	}
	$blocks[] = array( 'g' => 'money', 'n' => 'Оплата фабрике', 'm' => $pay,
		'f' => array( '(', $M( 'Платы', $pcbC ), '+', $M( 'Монтаж', $asmC ), ')', '×', $Pp( 'Расходы на платёж', ( $rate / max( $cbr, 1e-9 ) - 1 ) * 100 ) ),
		'note' => 'Надбавка банка ' . pcb_calc_n( $p['bank'] ) . ' %, комиссия за платёж ' . pcb_calc_n( $p['agent'] ) . ' %, запас на курс ' . pcb_calc_n( $p['buffer'] ) . ' % — проценты перемножаются: ' . pcb_calc_n( $cbr ) . ' → ' . pcb_calc_n( $rate ) . ' ₽ за ¥.' );
	$blocks[] = array( 'g' => 'ship', 'n' => 'Доставка', 'm' => $ship,
		'f' => array( $N( 'Вес партии', $kg, 'кг' ), '×', $M( 'Тариф, ' . mb_strtolower( $P['shipMode']['opts'][ $tKey ] ), $p['tariff'][ $tKey ], 'RUB', '/кг' ) ),
		'note' => 'Вес — площадь × толщина ' . pcb_calc_n( $t ) . ' мм × плотность ' . pcb_calc_n( $p['density'] ) . ' г/см³ + упаковка ' . pcb_calc_n( $p['pack'] ) . ' %.',
		'warn' => $ship > $shipT + 0.5 ? array( 'min' => $p['shipMin'], 'by' => $shipT ) : null );
	$f5 = array( $Pp( 'Пошлина', $p['duty'] ), '×', $M( 'Таможенная стоимость', $cval ), '+', $M( 'Сбор', $p['customsFee'] ), '+', $M( 'Брокер', $p['broker'] ) );
	if ( ! $p['vatDeduct'] ) {
		$f5[] = '+';
		$f5[] = $M( 'НДС на ввоз', $ivat );
	}
	$blocks[] = array( 'g' => 'customs', 'n' => 'Таможня', 'm' => $cust, 'f' => $f5,
		'note' => 'Таможенная стоимость — платы и монтаж с расходами на платёж плюс доставка.', 'ivat' => $p['vatDeduct'] ? $ivat : null );
	$blocks[] = array( 'g' => 'ours', 'n' => 'Наши услуги', 'm' => $ours,
		'f' => array( $M( 'Аудит файлов', $p['audit'] ), '+', $M( 'ПМИ за партию', $p['pmi'] ), '+', $M( 'ПМИ за плату', $p['pmiBoard'] ), '×', $N( 'Плат', $qty ), '+', $M( 'Приёмка ОТК', $p['otk'] ) ) );
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

/**
 * Чувствительность: насколько изменится цена эталона, если параметр вырастет на 10 %.
 * Скалярные денежные и процентные параметры + используемая строка таблиц ставок и тарифа.
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
	$l = (string) (int) $in['layers'];
	if ( isset( $p['dm2'][ $l ] ) ) {
		$p2               = $p;
		$p2['dm2'][ $l ] *= 1.1;
		$try( 'Ставка за дм², ' . pcb_calc_layer_word( (int) $l ), $p2 );
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
