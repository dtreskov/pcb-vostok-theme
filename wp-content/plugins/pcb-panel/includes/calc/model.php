<?php
/**
 * Калькулятор: описание параметров формулы, значения по умолчанию, эталонные заказы, разбор входа с сайта.
 *
 * Параметр: g — шаг цепочки, n — подпись, h — подсказка, t — тип
 *   num  — число (u — единица), pct — проценты, mul — множитель,
 *   money — сумма в валюте cur (CNY | RUB): хранится в своей валюте, в панели показывается в выбранной,
 *   table — таблица значений по строкам rows (cur — если суммы, иначе u),
 *   sel — выбор из opts, bool — галочка, text — строка.
 * Значения по умолчанию — демонстрационные: реальные заводит менеджер в панели.
 */

defined( 'ABSPATH' ) || exit;

/** Шаги цепочки расчёта (порядок = порядок на экране). */
function pcb_calc_groups() {
	return array(
		'factory' => array( 'l' => 'Фабрика', 't' => 'Платы', 'h' => 'Себестоимость плат на фабрике: подготовка + площадь партии × ставка за дм² × множители. Прайс фабрики — в юанях.' ),
		'asm'     => array( 'l' => 'Фабрика', 't' => 'Монтаж', 'h' => 'Монтаж на фабрике: запуск и трафарет на каждую сторону, катушки компонентов, пайки × тираж, рентген для BGA и QFN. Прайс — в юанях.' ),
		'money'   => array( 'l' => 'Деньги', 't' => 'Курс и платёж', 'h' => 'Перевод юаней фабрике: курс ЦБ, надбавка банка, комиссия за платёж и запас на рост курса.' ),
		'ship'    => array( 'l' => 'Логистика', 't' => 'Доставка', 'h' => 'Доставка из Китая: вес партии × тариф способа доставки, но не меньше минимума за отправку.' ),
		'customs' => array( 'l' => 'Граница', 't' => 'Таможня', 'h' => 'Таможенные платежи: пошлина от таможенной стоимости, сбор и брокер. НДС на ввоз — отдельно.' ),
		'ours'    => array( 'l' => 'Россия', 't' => 'Наши услуги', 'h' => 'Работа в России, которая для клиента «входит»: аудит файлов, проверка по ПМИ, приёмка ОТК.' ),
		'margin'  => array( 'l' => 'Цена', 't' => 'Наценка', 'h' => 'Наша наценка — отдельно на платы и на монтаж.' ),
		'vat'     => array( 'l' => 'Клиенту', 't' => 'НДС и вид цены', 'h' => 'Как цена выглядит на сайте: НДС, округление, диапазон «от–до», минимальная сумма заказа.' ),
		'rules'   => array( 'l' => 'Правила', 't' => 'Инженер и сроки', 'h' => 'Когда сайт не считает цену сам, а предлагает расчёт инженером, и какие сроки он показывает.' ),
	);
}

function pcb_calc_layer_word( $n ) {
	$n = (int) $n;
	$m = $n % 100;
	if ( $m >= 11 && $m <= 14 ) {
		return $n . ' слоёв';
	}
	switch ( $n % 10 ) {
		case 1: return $n . ' слой';
		case 2: case 3: case 4: return $n . ' слоя';
		default: return $n . ' слоёв';
	}
}

/** Все параметры формулы. */
function pcb_calc_params() {
	static $p = null;
	if ( null !== $p ) {
		return $p;
	}
	$layers = array();
	foreach ( array( 1, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24 ) as $l ) {
		$layers[ (string) $l ] = pcb_calc_layer_word( $l );
	}
	$thick = array();
	foreach ( array( '0.4', '0.6', '0.8', '1.0', '1.2', '1.6', '2.0', '2.4', '3.2' ) as $t ) {
		$thick[ $t ] = str_replace( '.', ',', $t ) . ' мм';
	}
	$p = array(
		// 1. Фабрика: платы, ¥
		'setup'      => array( 'g' => 'factory', 'n' => 'Подготовка производства', 'h' => 'Инженерный сбор, фотошаблоны, электротест — за заказ', 't' => 'money', 'cur' => 'CNY', 'v' => 450, 'min' => 0, 'max' => 20000 ),
		'setupLayer' => array( 'g' => 'factory', 'n' => 'Подготовка: за каждый слой сверх двух', 't' => 'money', 'cur' => 'CNY', 'v' => 150, 'min' => 0, 'max' => 5000 ),
		'dm2'        => array( 'g' => 'factory', 'n' => 'Ставка за дм² по числу слоёв', 'h' => 'Плата в партии — площадь × ставка', 't' => 'table', 'cur' => 'CNY', 'per' => '/дм²', 'rows' => $layers,
			'v' => array( '1' => 18, '2' => 24, '4' => 55, '6' => 85, '8' => 118, '10' => 152, '12' => 188, '14' => 226, '16' => 268, '18' => 312, '20' => 356, '22' => 400, '24' => 445 ) ),
		'thick'      => array( 'g' => 'factory', 'n' => 'Толщина платы — множитель', 't' => 'table', 'u' => '×', 'rows' => $thick,
			'v' => array( '0.4' => 1.15, '0.6' => 1.08, '0.8' => 1.03, '1.0' => 1, '1.2' => 1, '1.6' => 1, '2.0' => 1.08, '2.4' => 1.15, '3.2' => 1.3 ) ),
		'cu'         => array( 'g' => 'factory', 'n' => 'Медь наружных слоёв — множитель', 't' => 'table', 'u' => '×', 'rows' => array( '18' => '18 мкм', '35' => '35 мкм', '70' => '70 мкм', '105' => '105 мкм' ),
			'v' => array( '18' => 0.97, '35' => 1, '70' => 1.25, '105' => 1.5 ) ),
		'cuin70'     => array( 'g' => 'factory', 'n' => 'Медь внутренних слоёв 70 мкм — множитель', 'h' => 'Для плат от 4 слоёв; 18 и 35 мкм — без надбавки', 't' => 'mul', 'v' => 1.15, 'min' => 0.5, 'max' => 5 ),
		'finish'     => array( 'g' => 'factory', 'n' => 'Финишное покрытие — множитель', 'h' => '«Другое» — расчёт инженером', 't' => 'table', 'u' => '×',
			'rows' => array( 'hasl' => 'HASL', 'enig' => 'ENIG', 'iag' => 'ImAg', 'enepig' => 'ENEPIG', 'flash' => 'Flashgold', 'hard' => 'Hardgold' ),
			'v' => array( 'hasl' => 1, 'enig' => 1.25, 'iag' => 1.18, 'enepig' => 1.4, 'flash' => 1.3, 'hard' => 1.6 ) ),
		'mat'        => array( 'g' => 'factory', 'n' => 'Материал — множитель', 'h' => 'Полиимид и ВЧ/СВЧ — расчёт инженером', 't' => 'table', 'u' => '×',
			'rows' => array( 'fr4' => 'FR-4', 'fr4tg' => 'FR-4 High Tg', 'alu' => 'Алюминий' ),
			'v' => array( 'fr4' => 1, 'fr4tg' => 1.2, 'alu' => 1.35 ) ),
		'mask'       => array( 'g' => 'factory', 'n' => 'Цвет маски — множитель', 't' => 'table', 'u' => '×',
			'rows' => array( 'green' => 'Зелёная', 'black' => 'Чёрная', 'blue' => 'Синяя', 'red' => 'Красная', 'white' => 'Белая', 'yellow' => 'Жёлтая', 'other' => 'Другой цвет' ),
			'v' => array( 'green' => 1, 'black' => 1.03, 'blue' => 1.03, 'red' => 1.03, 'white' => 1.05, 'yellow' => 1.05, 'other' => 1.05 ) ),
		'sides2'     => array( 'g' => 'factory', 'n' => 'Компоненты на двух сторонах — множитель', 'h' => 'Маска и маркировка с обеих сторон', 't' => 'mul', 'v' => 1.03, 'min' => 0.5, 'max' => 3 ),
		'vol'        => array( 'g' => 'factory', 'n' => 'Объём партии — множитель к ставке', 'h' => 'По площади всей партии, дм²', 't' => 'table', 'u' => '×',
			'rows' => array( 'lt2' => 'до 2 дм²', 'lt10' => '2–10 дм²', 'lt50' => '10–50 дм²', 'lt200' => '50–200 дм²', 'ge200' => 'от 200 дм²' ),
			'v' => array( 'lt2' => 1.6, 'lt10' => 1.25, 'lt50' => 1, 'lt200' => 0.85, 'ge200' => 0.72 ) ),

		// 2. Фабрика: монтаж, ¥
		'asmSide'    => array( 'g' => 'asm', 'n' => 'Запуск монтажа, за сторону', 'h' => 'Программа автомата, первая плата', 't' => 'money', 'cur' => 'CNY', 'v' => 1200, 'min' => 0, 'max' => 50000 ),
		'stencil'    => array( 'g' => 'asm', 'n' => 'Трафарет, за сторону', 't' => 'money', 'cur' => 'CNY', 'v' => 350, 'min' => 0, 'max' => 10000 ),
		'feeder'     => array( 'g' => 'asm', 'n' => 'Установка катушки, за позицию', 'h' => 'Позиций — около 6 + 20 % от числа корпусов на плате', 't' => 'money', 'cur' => 'CNY', 'v' => 16, 'min' => 0, 'max' => 1000 ),
		'joint'      => array( 'g' => 'asm', 'n' => 'Цена пайки', 't' => 'table', 'cur' => 'CNY', 'per' => '',
			'rows' => array( 'chip' => 'Чип, SOT, SOIC — за вывод', 'fine' => 'QFP и мелкий шаг — за вывод', 'qfn' => 'QFN — за вывод', 'bga' => 'BGA — за шарик', 'tht' => 'Выводные (DIP) — за вывод', 'conn' => 'Разъём — за штуку' ),
			'v' => array( 'chip' => 0.03, 'fine' => 0.05, 'qfn' => 0.08, 'bga' => 0.12, 'tht' => 0.25, 'conn' => 4 ) ),
		'quick'      => array( 'g' => 'asm', 'n' => 'Быстрая оценка: паек на корпус', 'h' => 'Когда клиент указал только число корпусов', 't' => 'table', 'u' => 'паек',
			'rows' => array( 'smd' => 'Простой SMD', 'qfn' => 'QFN, QFP и др. многовыводные', 'bga' => 'BGA' ),
			'v' => array( 'smd' => 2.4, 'qfn' => 40, 'bga' => 200 ) ),
		'axi'        => array( 'g' => 'asm', 'n' => 'Рентген-контроль BGA / QFN, за партию', 't' => 'money', 'cur' => 'CNY', 'v' => 500, 'min' => 0, 'max' => 50000 ),

		// 3. Деньги
		'rateFix'    => array( 'g' => 'money', 'n' => 'Закрепить курсы вручную', 'h' => 'Иначе — курс ЦБ, обновляется раз в сутки', 't' => 'bool', 'v' => false ),
		'rate'       => array( 'g' => 'money', 'n' => 'Курс юаня', 't' => 'num', 'u' => '₽ за ¥', 'v' => 11.42, 'min' => 5, 'max' => 40, 'step' => 0.01 ),
		'usd'        => array( 'g' => 'money', 'n' => 'Курс доллара', 'h' => 'Только для показа сумм в долларах', 't' => 'num', 'u' => '₽ за $', 'v' => 81.3, 'min' => 30, 'max' => 250, 'step' => 0.01 ),
		'bank'       => array( 'g' => 'money', 'n' => 'Надбавка банка к курсу ЦБ', 'h' => 'Банк или агент продаёт юань дороже официального курса', 't' => 'pct', 'v' => 2, 'min' => 0, 'max' => 15, 'step' => 0.1 ),
		'agent'      => array( 'g' => 'money', 'n' => 'Комиссия за международный платёж', 't' => 'pct', 'v' => 2.5, 'min' => 0, 'max' => 10, 'step' => 0.1 ),
		'buffer'     => array( 'g' => 'money', 'n' => 'Запас на рост курса', 'h' => 'Между оценкой и оплатой фабрике', 't' => 'pct', 'v' => 3, 'min' => 0, 'max' => 20, 'step' => 0.5 ),
		'payWay'     => array( 'g' => 'money', 'n' => 'Способ оплаты фабрике', 'h' => 'Для справки — на расчёт не влияет', 't' => 'sel', 'v' => 'agent',
			'opts' => array( 'bank' => 'Банк, прямой платёж', 'agent' => 'Платёжный агент', 'partner' => 'Через партнёра в Китае' ) ),

		// 4. Доставка
		'shipMode'   => array( 'g' => 'ship', 'n' => 'Способ доставки в расчёте', 't' => 'sel', 'v' => 'auto',
			'opts' => array( 'auto' => 'Авто', 'air' => 'Авиа', 'rail' => 'Ж/д' ) ),
		'tariff'     => array( 'g' => 'ship', 'n' => 'Тарифы доставки', 't' => 'table', 'cur' => 'RUB', 'per' => '/кг',
			'rows' => array( 'auto' => 'Авто', 'air' => 'Авиа', 'rail' => 'Ж/д' ), 'v' => array( 'auto' => 450, 'air' => 1100, 'rail' => 300 ) ),
		'shipMin'    => array( 'g' => 'ship', 'n' => 'Минимум за отправку', 't' => 'money', 'cur' => 'RUB', 'v' => 6000, 'min' => 0, 'max' => 200000 ),
		'density'    => array( 'g' => 'ship', 'n' => 'Плотность платы', 'h' => 'FR-4 — около 1,85 г/см³; вес = площадь × толщина × плотность', 't' => 'num', 'u' => 'г/см³', 'v' => 1.85, 'min' => 0.5, 'max' => 5, 'step' => 0.01 ),
		'pack'       => array( 'g' => 'ship', 'n' => 'Упаковка к весу плат', 't' => 'pct', 'v' => 20, 'min' => 0, 'max' => 200 ),

		// 5. Таможня
		'duty'       => array( 'g' => 'customs', 'n' => 'Пошлина', 'h' => 'Зависит от кода ТН ВЭД — уточнить у брокера', 't' => 'pct', 'v' => 5, 'min' => 0, 'max' => 30, 'step' => 0.1 ),
		'customsFee' => array( 'g' => 'customs', 'n' => 'Таможенный сбор', 't' => 'money', 'cur' => 'RUB', 'v' => 2500, 'min' => 0, 'max' => 100000 ),
		'broker'     => array( 'g' => 'customs', 'n' => 'Услуги брокера', 't' => 'money', 'cur' => 'RUB', 'v' => 7000, 'min' => 0, 'max' => 200000 ),
		'importVat'  => array( 'g' => 'customs', 'n' => 'НДС на ввоз', 't' => 'pct', 'v' => 22, 'min' => 0, 'max' => 30 ),
		'vatDeduct'  => array( 'g' => 'customs', 'n' => 'НДС на ввоз принимаем к вычету', 'h' => 'Тогда он не входит в себестоимость — это движение денег, а не расход', 't' => 'bool', 'v' => true ),

		// 6. Наши услуги, ₽
		'audit'      => array( 'g' => 'ours', 'n' => 'Аудит файлов', 'h' => 'Для клиента входит в стоимость заказа', 't' => 'money', 'cur' => 'RUB', 'v' => 0, 'min' => 0, 'max' => 200000 ),
		'pmi'        => array( 'g' => 'ours', 'n' => 'Проверка по ПМИ, за партию', 't' => 'money', 'cur' => 'RUB', 'v' => 3000, 'min' => 0, 'max' => 500000 ),
		'pmiBoard'   => array( 'g' => 'ours', 'n' => 'Проверка по ПМИ, за плату', 't' => 'money', 'cur' => 'RUB', 'v' => 15, 'min' => 0, 'max' => 10000 ),
		'otk'        => array( 'g' => 'ours', 'n' => 'Приёмка ОТК на фабрике', 't' => 'money', 'cur' => 'RUB', 'v' => 2500, 'min' => 0, 'max' => 200000 ),

		// 7. Наценка
		'mPcb'       => array( 'g' => 'margin', 'n' => 'Наценка на платы', 'h' => 'К платам вместе с доставкой, таможней и нашими услугами', 't' => 'pct', 'v' => 25, 'min' => 0, 'max' => 300 ),
		'mAsm'       => array( 'g' => 'margin', 'n' => 'Наценка на монтаж', 't' => 'pct', 'v' => 30, 'min' => 0, 'max' => 300 ),

		// 8. Клиенту
		'vat'        => array( 'g' => 'vat', 'n' => 'НДС в цене для клиента', 'h' => 'С 1 января 2026 года — 22 %', 't' => 'pct', 'v' => 22, 'min' => 0, 'max' => 30 ),
		'showVat'    => array( 'g' => 'vat', 'n' => 'Показывать на сайте «в т. ч. НДС»', 't' => 'bool', 'v' => true ),
		'round'      => array( 'g' => 'vat', 'n' => 'Округление цены на сайте', 't' => 'sel', 'v' => '100', 'opts' => array( '10' => 'до 10 ₽', '100' => 'до 100 ₽', '1000' => 'до 1 000 ₽' ) ),
		'lo'         => array( 'g' => 'vat', 'n' => 'Нижняя граница диапазона', 't' => 'pct', 'v' => -10, 'min' => -50, 'max' => 0 ),
		'hi'         => array( 'g' => 'vat', 'n' => 'Верхняя граница диапазона', 't' => 'pct', 'v' => 15, 'min' => 0, 'max' => 100 ),
		'minOrder'   => array( 'g' => 'vat', 'n' => 'Минимальная сумма заказа', 'h' => 'С НДС. Меньшая оценка доводится до неё', 't' => 'money', 'cur' => 'RUB', 'v' => 15000, 'min' => 0, 'max' => 1000000 ),

		// Правила: расчёт инженером и сроки
		'maxLayers'  => array( 'g' => 'rules', 'n' => 'Считать автоматически до … слоёв', 'h' => 'Больше — расчёт инженером', 't' => 'num', 'u' => 'слоёв', 'v' => 16, 'min' => 1, 'max' => 24 ),
		'minTrack'   => array( 'g' => 'rules', 'n' => 'Самая тонкая дорожка для автоматического расчёта', 't' => 'num', 'u' => 'мм', 'v' => 0.1, 'min' => 0.03, 'max' => 0.5, 'step' => 0.01 ),
		'minHole'    => array( 'g' => 'rules', 'n' => 'Самое маленькое отверстие для автоматического расчёта', 't' => 'num', 'u' => 'мм', 'v' => 0.2, 'min' => 0.05, 'max' => 1, 'step' => 0.01 ),
		'termStd'    => array( 'g' => 'rules', 'n' => 'Срок на сайте', 't' => 'text', 'v' => 'около 6–8 недель от размещения заказа' ),
		'termEng'    => array( 'g' => 'rules', 'n' => 'Срок при расчёте инженером', 't' => 'text', 'v' => '2–3 месяца от размещения заказа' ),
	);
	return $p;
}

/** Всегда — расчёт инженером (показываются в панели справкой). */
function pcb_calc_engineer_fixed() {
	return array( 'полиимидное основание и ВЧ / СВЧ-материалы', 'контроль импеданса', 'слепые / скрытые отверстия', 'via-in-pad', 'золочёные ламели', 'нестандартное покрытие', 'чип-компоненты 0201' );
}

/** Значения по умолчанию: key => value. */
function pcb_calc_defaults() {
	return array_map( function ( $d ) {
		return $d['v'];
	}, pcb_calc_params() );
}

/**
 * Приводит присланные значения к типам модели. Неизвестные ключи отбрасываются, недостающие — из $base.
 * Значения вне min…max сохраняются (менеджер может быть посреди ввода), но попадают в pcb_calc_errors().
 */
function pcb_calc_clean( $in, array $base = null ) {
	$base = $base ?? pcb_calc_defaults();
	$out  = array();
	foreach ( pcb_calc_params() as $k => $d ) {
		$has = is_array( $in ) && array_key_exists( $k, $in );
		$v   = $has ? $in[ $k ] : ( $base[ $k ] ?? $d['v'] );
		switch ( $d['t'] ) {
			case 'bool':
				$out[ $k ] = (bool) $v && 'false' !== $v && '0' !== $v;
				break;
			case 'sel':
				$v         = (string) $v;
				$out[ $k ] = isset( $d['opts'][ $v ] ) ? $v : $d['v'];
				break;
			case 'text':
				$out[ $k ] = mb_substr( trim( wp_strip_all_tags( (string) $v ) ), 0, 120 );
				break;
			case 'table':
				$row = array();
				$src = is_array( $v ) ? $v : array();
				$old = is_array( $base[ $k ] ?? null ) ? $base[ $k ] : $d['v'];
				foreach ( $d['rows'] as $rk => $label ) {
					$rk         = (string) $rk;
					$x          = $src[ $rk ] ?? ( $old[ $rk ] ?? $d['v'][ $rk ] );
					$row[ $rk ] = is_numeric( $x ) ? round( (float) $x, 6 ) : (float) ( $old[ $rk ] ?? $d['v'][ $rk ] );
				}
				$out[ $k ] = $row;
				break;
			default:
				$out[ $k ] = is_numeric( $v ) ? round( (float) $v, 6 ) : (float) ( $base[ $k ] ?? $d['v'] );
		}
	}
	return $out;
}

/** Ошибки диапазонов: key[.row] => текст. Пустой массив — можно публиковать. */
function pcb_calc_errors( array $p ) {
	$err = array();
	foreach ( pcb_calc_params() as $k => $d ) {
		if ( 'table' === $d['t'] ) {
			foreach ( $p[ $k ] as $rk => $x ) {
				if ( $x < 0 || $x > 1e6 ) {
					$err[ $k . '.' . $rk ] = $d['n'] . ', ' . $d['rows'][ $rk ] . ': значение вне допустимого';
				}
			}
			continue;
		}
		if ( isset( $d['min'] ) && ( $p[ $k ] < $d['min'] || $p[ $k ] > $d['max'] ) ) {
			$err[ $k ] = $d['n'] . ': допустимо от ' . $d['min'] . ' до ' . $d['max'];
		}
	}
	if ( isset( $p['termStd'] ) && '' === $p['termStd'] ) {
		$err['termStd'] = 'Срок на сайте не может быть пустым';
	}
	return $err;
}

/** Подписи изменённых параметров между двумя наборами. */
function pcb_calc_diff( array $a, array $b ) {
	$out = array();
	foreach ( pcb_calc_params() as $k => $d ) {
		if ( wp_json_encode( $a[ $k ] ?? null ) !== wp_json_encode( $b[ $k ] ?? null ) ) {
			$out[] = $d['n'];
		}
	}
	return $out;
}

/* ---------------- вход с сайта ---------------- */

/** Ключи полей калькулятора сайта (как в PCBCalc.getData()). */
function pcb_calc_input_keys() {
	return array( 'qty', 'len', 'wid', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel' );
}

/**
 * Очистка входа: только известные поля, строки — короткие, числа — в разумных пределах.
 * Комплект: { set: { sets, boards: [ { name, n, data | in } ] } } → { set: { sets, boards: [ { name, n, in } ] } }
 * (data — как в запросе сайта и в заявке, in — как в эталоне). Тираж платы считается в расчёте: комплекты × n.
 */
function pcb_calc_input_clean( $in ) {
	$in = is_array( $in ) ? $in : array();
	if ( isset( $in['set'] ) && is_array( $in['set'] ) ) {
		$s   = $in['set'];
		$out = array( 'sets' => max( 0, min( 1000000, (int) ( $s['sets'] ?? 0 ) ) ), 'boards' => array() );
		foreach ( array_slice( (array) ( $s['boards'] ?? array() ), 0, PCB_CALC_MAX_BOARDS ) as $b ) {
			if ( ! is_array( $b ) || ( isset( $b['on'] ) && ! $b['on'] ) ) {
				continue; // плата не в заказе
			}
			$out['boards'][] = array(
				'name' => mb_substr( sanitize_text_field( (string) ( $b['name'] ?? '' ) ), 0, 80 ) ?: 'Плата ' . ( count( $out['boards'] ) + 1 ),
				'n'    => max( 1, min( 1000, (int) ( $b['n'] ?? 1 ) ) ),
				'in'   => pcb_calc_input_clean( (array) ( $b['data'] ?? $b['in'] ?? array() ) ),
			);
		}
		return array( 'set' => $out );
	}
	$out = array();
	foreach ( pcb_calc_input_keys() as $k ) {
		$v         = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
		$out[ $k ] = mb_substr( preg_replace( '/[^0-9A-Za-z.,_-]/', '', $v ), 0, 12 );
	}
	$out['special'] = array();
	foreach ( (array) ( $in['special'] ?? array() ) as $s ) {
		if ( is_string( $s ) && preg_match( '/^[a-z0-9_]{1,20}$/', $s ) ) {
			$out['special'][] = $s;
		}
	}
	$out['special'] = array_slice( array_unique( $out['special'] ), 0, 20 );
	$asm            = (string) ( $in['asm'] ?? 'none' );
	$out['asm']     = in_array( $asm, array( 'none', 'quick', 'detail' ), true ) ? $asm : 'none';
	$out['asm_values'] = array();
	foreach ( (array) ( $in['asm_values'] ?? array() ) as $k => $v ) {
		if ( preg_match( '/^[qdp]_[a-z0-9]{1,10}$/', (string) $k ) && is_numeric( $v ) ) {
			$out['asm_values'][ $k ] = max( 0, min( 100000, (float) $v ) );
		}
	}
	$out['finish_pending'] = ! empty( $in['finish_pending'] ) && 'false' !== $in['finish_pending'];
	return $out;
}

/** Короткое описание заказа: «4 слоя · 80×55 мм · 250 шт · ENIG · монтаж 2 стороны». */
function pcb_calc_input_desc( array $in ) {
	if ( isset( $in['set'] ) ) { // комплект: «Комплект × 20: «main» 4 слоя, 100×80 мм × 1; «power» …»
		$n = (int) $in['set']['sets'];
		return 'Комплект × ' . number_format( $n, 0, ',', ' ' ) . ': ' . implode( '; ', array_map( function ( $b ) {
			$d = explode( ' · ', pcb_calc_input_desc( $b['in'] ) );
			unset( $d[2] ); // тираж — общий
			return '«' . $b['name'] . '» ' . implode( ', ', $d ) . ( $b['n'] > 1 ? ' × ' . $b['n'] : '' );
		}, $in['set']['boards'] ) );
	}
	$P   = pcb_calc_params();
	$num = function ( $x ) {
		return str_replace( '.', ',', rtrim( rtrim( number_format( (float) $x, 2, '.', '' ), '0' ), '.' ) );
	};
	$out = array( pcb_calc_layer_word( (int) $in['layers'] ), $num( $in['len'] ) . '×' . $num( $in['wid'] ) . ' мм', number_format( (float) $in['qty'], 0, ',', ' ' ) . ' шт' );
	$out[] = $P['finish']['rows'][ $in['finish'] ] ?? 'покрытие уточняется';
	if ( 'none' !== $in['asm'] ) {
		$out[] = 'монтаж' . ( '2' === (string) $in['sides'] ? ' 2 стороны' : '' );
		$av    = $in['asm_values'];
		if ( ( $av['d_bga'] ?? 0 ) > 0 || ( $av['q_bga'] ?? 0 ) > 0 ) {
			$out[] = 'BGA';
		}
	}
	return implode( ' · ', $out );
}

/** Эталонные заказы по умолчанию — в формате входа с сайта. */
function pcb_calc_default_etalons() {
	$base = array( 'thick' => '1.6', 'cuout' => '35', 'cuin' => '35', 'mat' => 'fr4', 'finish' => 'hasl', 'mask' => 'green', 'silk' => 'white', 'track' => '', 'hole' => '', 'sides' => '1', 'panel' => 'single', 'special' => array(), 'asm' => 'none', 'asm_values' => array(), 'finish_pending' => false );
	$list = array(
		array( 'id' => 'e1', 'n' => 'Прототип', 'in' => array( 'qty' => '10', 'len' => '100', 'wid' => '80', 'layers' => '2' ) ),
		array( 'id' => 'e2', 'n' => 'Серия плат', 'in' => array( 'qty' => '500', 'len' => '120', 'wid' => '90', 'layers' => '4', 'finish' => 'enig' ) ),
		array( 'id' => 'e3', 'n' => 'Платы с монтажом', 'in' => array( 'qty' => '250', 'len' => '80', 'wid' => '55', 'layers' => '4', 'finish' => 'enig', 'sides' => '2', 'asm' => 'detail',
			'asm_values' => array( 'd_0402' => 60, 'd_0603' => 24, 'd_qfn' => 1, 'p_qfn' => 33, 'd_tht' => 1, 'p_tht' => 13 ) ) ),
		array( 'id' => 'e4', 'n' => 'Сложная плата', 'in' => array( 'qty' => '100', 'len' => '150', 'wid' => '100', 'layers' => '8', 'finish' => 'enig', 'asm' => 'detail',
			'asm_values' => array( 'd_0402' => 300, 'd_bga' => 1, 'p_bga' => 400, 'd_qfp' => 1, 'p_qfp' => 100 ) ) ),
		array( 'id' => 'e5', 'n' => 'Крупная партия', 'in' => array( 'qty' => '2000', 'len' => '200', 'wid' => '150', 'layers' => '2' ) ),
	);
	foreach ( $list as &$e ) {
		$e['in'] = pcb_calc_input_clean( array_merge( $base, $e['in'] ) );
	}
	return $list;
}
