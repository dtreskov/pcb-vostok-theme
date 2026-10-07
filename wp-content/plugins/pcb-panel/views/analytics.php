<?php
/**
 * Вкладка «Аналитика». Разметка — каркас секций; цифры и графики рисует assets/analytics.js
 * по данным REST /wp-json/pcb-panel/v1/analytics (includes/analytics/data.php).
 * Секции сворачиваются (<details>), состояние запоминается в браузере.
 */
defined( 'ABSPATH' ) || exit;

$pa_cfg = array(
	'api'      => esc_url_raw( rest_url( 'pcb-panel/v1/analytics' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'canAdmin' => current_user_can( 'manage_options' ),
	'seoUrl'   => esc_url_raw( pcb_panel_url( 'seo' ) ),
);

if ( ! function_exists( 'pcb_pa_src' ) ) {
	/** Метка источника данных у блока. */
	function pcb_pa_src( $kind ) {
		$s = array(
			'own'  => array( 'full', 'Свои события · без cookie', 'Собственные обезличенные события сайта, без cookie и идентификаторов. Не учитываются только посетители с блокировщиками' ),
			'full' => array( 'full', 'Заявки · все', 'Заявки из базы сайта — учтены все обращения' ),
			'ym'   => array( 'part', 'Метрика · с согласием', 'Яндекс Метрика: только посетители, которые приняли cookie и у которых браузер не блокирует счётчик' ),
			'wm'   => array( 'full', 'Вебмастер · все', 'Яндекс Вебмастер: данные поиска, от cookie не зависят' ),
		);
		list( $k, $label, $tip ) = $s[ $kind ];
		return '<span class="pa-src pa-src--' . $k . '" data-tip="' . esc_attr( $tip ) . '">' . esc_html( $label ) . '</span>';
	}

	/** Начало сворачиваемой секции. $extra — готовая разметка из этого файла. */
	function pcb_pa_sec( $id, $title, $lead, $extra = '' ) {
		echo '<details class="pa-sec" id="pa-' . esc_attr( $id ) . '" data-sec="' . esc_attr( $id ) . '" open>';
		echo '<summary class="pa-sec__head"><span class="pa-sec__title"><span class="pa-sec__chev" aria-hidden="true"></span><span><span class="pa-h2">' . esc_html( $title ) . '</span><span class="pa-lead">' . esc_html( $lead ) . '</span></span></span>' . $extra . '</summary>';
		echo '<div class="pa-sec__body">';
	}

	function pcb_pa_sec_end() {
		echo '</div></details>';
	}
}

$two_legend = '<span class="pa-legend"><span><i style="background:var(--pa-s1)"></i>В заявках</span><span><i style="background:var(--pa-s2)"></i>Только расчёт, без заявки</span></span>';
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/analytics.css?ver=' . PCB_PANEL_VERSION ); ?>">

<div class="pp-page-head">
	<div>
		<h1>Аналитика</h1>
		<p>Посещаемость, поведение на сайте, спрос и обращения за <span class="pa-period-word">30 дней</span>.</p>
	</div>
</div>

<div class="pa" id="pa" aria-busy="true">

	<div class="pa-bar">
		<div class="pa-seg" role="group" aria-label="Период">
			<button type="button" data-period="7" aria-pressed="false">7 дней</button>
			<button type="button" data-period="30" aria-pressed="true">30 дней</button>
			<button type="button" data-period="90" aria-pressed="false">90 дней</button>
		</div>
		<nav class="pa-nav" aria-label="Разделы аналитики">
			<a href="#pa-summary">Сводка</a>
			<a href="#pa-demand">Спрос</a>
			<a href="#pa-home">Главная</a>
			<a href="#pa-funnels">Воронки</a>
			<a href="#pa-traffic">Трафик</a>
			<a href="#pa-consent">Согласия</a>
			<a href="#pa-tech">Техническое</a>
		</nav>
		<span class="pa-fresh" id="pa-fresh">Загружаем данные…</span>
		<?php if ( $pa_cfg['canAdmin'] ) : ?>
			<button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" id="pa-refresh">Обновить</button>
		<?php endif; ?>
	</div>

	<div id="pa-status" class="pa-status-list"></div>

	<?php pcb_pa_sec( 'summary', 'Сводка', 'Сравнение с предыдущим периодом такой же длины.' ); ?>
		<div class="pa-grid pa-grid--5" id="pa-tiles"></div>
		<article class="pa-card">
			<div class="pa-card__head">
				<h3>Визиты и заявки по дням</h3>
				<div class="pa-legend"><span><i class="is-line" style="background:var(--pa-s1)"></i>Визиты в Метрике</span><span><i style="background:var(--pa-s1)"></i>Заявки с главной</span><span><i style="background:var(--pa-s2)"></i>Заявки с калькулятора</span></div>
			</div>
			<div class="pa-chart" id="pa-ch-visits"></div>
			<div class="pa-chart" id="pa-ch-req"></div>
			<p class="pa-card__note">Визиты — только посетители с согласием на cookie; заявки — все. Поэтому это два графика на общей оси дат, а не один с двумя шкалами.</p>
		</article>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'demand', 'Спрос по параметрам плат', 'Что считают в калькуляторе и с чем приходят в заявках. Расчёты без заявки показывают спрос, который до обращения не дошёл.', $two_legend ); ?>
		<div class="pa-grid pa-grid--3">
			<article class="pa-card"><div class="pa-card__head"><h3>Слойность</h3><?php echo pcb_pa_src( 'own' ); ?></div><div class="pa-bars" id="pa-d-layers"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Тираж</h3><?php echo pcb_pa_src( 'own' ); ?></div><div class="pa-bars" id="pa-d-qty"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Финишное покрытие</h3><?php echo pcb_pa_src( 'own' ); ?></div><div class="pa-bars" id="pa-d-finish"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Материал основания</h3><?php echo pcb_pa_src( 'own' ); ?></div><div class="pa-bars" id="pa-d-mat"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Больший размер платы</h3><?php echo pcb_pa_src( 'own' ); ?></div><div class="pa-bars" id="pa-d-size"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Услуга в заявке</h3><?php echo pcb_pa_src( 'full' ); ?></div><div class="pa-bars" id="pa-d-services"></div></article>
		</div>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'home', 'Как читают главную', 'Сайт одностраничный, поэтому вместо отчёта по страницам — до каких секций доходят посетители и с чем взаимодействуют.' ); ?>
		<div class="pa-grid pa-grid--2">
			<article class="pa-card">
				<div class="pa-card__head"><h3>Доходят до секции</h3><?php echo pcb_pa_src( 'ym' ); ?></div>
				<div class="pa-bars" id="pa-ladder"></div>
				<p class="pa-card__note">Доля визитов на главную, в которых секция появилась на экране. Отметка — место наибольшего ухода.</p>
			</article>
			<div class="pa-grid">
				<article class="pa-card">
					<div class="pa-card__head"><h3>Какие вопросы открывают в FAQ</h3><?php echo pcb_pa_src( 'ym' ); ?></div>
					<div class="pa-bars" id="pa-faq"></div>
				</article>
				<article class="pa-card">
					<div class="pa-card__head"><h3>Действия на главной</h3><?php echo pcb_pa_src( 'ym' ); ?></div>
					<div id="pa-actions"></div>
				</article>
			</div>
		</div>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'funnels', 'Воронки', 'На каком шаге теряются посетители. Все шаги — из Метрики, поэтому последний шаг меньше числа заявок в сводке.' ); ?>
		<div class="pa-grid pa-grid--2">
			<article class="pa-card"><div class="pa-card__head"><h3>Главная → заявка</h3><?php echo pcb_pa_src( 'ym' ); ?></div><div class="pa-funnel" id="pa-f-home"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Калькулятор → заявка</h3><?php echo pcb_pa_src( 'ym' ); ?></div><div class="pa-funnel" id="pa-f-calc"></div></article>
		</div>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'traffic', 'Трафик', 'Откуда приходят, с каких устройств и в какое время.' ); ?>
		<div class="pa-grid pa-grid--3">
			<article class="pa-card"><div class="pa-card__head"><h3>Источники</h3><?php echo pcb_pa_src( 'ym' ); ?></div><div class="pa-bars" id="pa-sources"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Города</h3><?php echo pcb_pa_src( 'ym' ); ?></div><div class="pa-bars" id="pa-cities"></div></article>
			<article class="pa-card"><div class="pa-card__head"><h3>Устройства</h3><?php echo pcb_pa_src( 'ym' ); ?></div><div id="pa-dev" class="pa-grid pa-grid--tight"></div></article>
		</div>
		<article class="pa-card">
			<div class="pa-card__head"><h3>Когда заходят: день недели и час</h3><?php echo pcb_pa_src( 'ym' ); ?></div>
			<div id="pa-heat-wrap"><div class="pa-heat" id="pa-heat"></div><div class="pa-heat__scale" id="pa-heat-scale"></div></div>
		</article>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'consent', 'Согласия на cookie', 'Выбор посетителей в уведомлении и в «Настройках cookie».' ); ?>
		<article class="pa-card">
			<div class="pa-card__head"><h3>Выбор за <span class="pa-period-word">30 дней</span></h3><?php echo pcb_pa_src( 'own' ); ?></div>
			<div id="pa-consent-box" class="pa-grid pa-grid--tight"></div>
		</article>
	<?php pcb_pa_sec_end(); ?>

	<?php pcb_pa_sec( 'tech', 'Техническое состояние', 'Сбои, которые мешают получить заявку.' ); ?>
		<div class="pp-table-wrap">
			<table class="pp-table pa-table">
				<thead><tr><th>Что проверяем</th><th class="pa-num">За период</th><th>Статус</th></tr></thead>
				<tbody id="pa-tech-rows"></tbody>
			</table>
		</div>
		<article class="pa-card">
			<div class="pa-card__head"><h3>Поиск Яндекса</h3><?php echo pcb_pa_src( 'wm' ); ?></div>
			<div class="pa-seo" id="pa-seo"></div>
		</article>
	<?php pcb_pa_sec_end(); ?>

	<p class="pa-foot-note">
		<span class="pa-src pa-src--full">все данные</span>
		<span class="pa-src pa-src--part">только посетители с согласием на cookie или оценка по ним</span>
		<span>Наведите на элемент графика — появится подсказка с цифрами.</span>
	</p>
</div>

<script>window.PCBAnalytics = <?php echo wp_json_encode( $pa_cfg ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/analytics.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
