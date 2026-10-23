<?php
/**
 * Калькулятор: формула цены, черновик и публикация, эталонные заказы, курс ЦБ, версии.
 * Всё рисует assets/calc.js из PCBCalcPanel; считает только сервер (includes/calc/engine.php).
 */
defined( 'ABSPATH' ) || exit;

$boot = pcb_calc_bootstrap();
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/calc.css?ver=' . PCB_PANEL_VERSION ); ?>">
<div class="cs" id="cs">
	<?php if ( ! $boot['siteOn'] ) : ?>
		<p class="cs-note" role="note"><b>Сайт пока считает по старой формуле.</b> Он перейдёт на эту, как только вы опубликуете первую версию. Сейчас здесь демонстрационные ставки — замените их реальными и опубликуйте.</p>
	<?php endif; ?>

	<nav class="cs-sub" role="tablist" aria-label="Разделы калькулятора">
		<button type="button" role="tab" aria-selected="true" data-view="formula">Формула</button>
		<button type="button" role="tab" aria-selected="false" data-view="formula" data-jump="money">Курсы и платежи</button>
		<button type="button" role="tab" aria-selected="false" data-view="formula" data-jump="ship">Доставка</button>
		<button type="button" role="tab" aria-selected="false" data-view="formula" data-jump="rules">Инженер и сроки</button>
		<button type="button" role="tab" aria-selected="false" data-view="history">Версии</button>
	</nav>

	<div class="cs-head">
		<div>
			<h1>Калькулятор</h1>
			<p>Как складывается цена на сайте: от прайса фабрики до суммы с НДС для клиента. Правки копятся в черновике и попадают на сайт только после публикации.</p>
		</div>
		<div class="cs-cur">
			<div class="cs-seg" role="group" aria-label="Валюта отображения">
				<button type="button" data-cur="RUB" aria-pressed="true">₽ Рубль</button>
				<button type="button" data-cur="USD" aria-pressed="false">$ Доллар</button>
				<button type="button" data-cur="CNY" aria-pressed="false">¥ Юань</button>
			</div>
			<div class="cs-rates num" id="cs-rates"></div>
		</div>
	</div>

	<div class="cs-draft">
		<span class="cs-draft__ver" id="cs-ver"></span>
		<span class="cs-pill" id="cs-pill"></span>
		<div class="cs-csvacts">
			<a class="cs-link" href="<?php echo esc_url( pcb_calc_csv_url( 'draft' ) ); ?>" title="Параметры черновика — одна строка на значение; открывается в Excel">Скачать CSV</a>
			<?php if ( $boot['canEdit'] ) : ?>
				<label class="cs-link" title="Проверим, что изменится, и только потом применим к черновику">Загрузить CSV…<input type="file" id="cs-csv-file" accept=".csv,.txt,.tsv,text/csv" hidden></label>
			<?php endif; ?>
		</div>
		<?php if ( $boot['canEdit'] ) : ?>
			<div class="cs-acts">
				<button class="pp-btn pp-btn--ghost" type="button" id="cs-reset" disabled>Сбросить черновик</button>
				<button class="pp-btn" type="button" id="cs-publish" disabled>Опубликовать…</button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( $boot['canEdit'] ) : ?>
		<div class="cs-confirm cs-csv" id="cs-csv" hidden aria-live="polite"></div>
	<?php endif; ?>
	<div class="cs-confirm" id="cs-confirm" hidden>
		<h3 id="cs-confirm-h"></h3>
		<ul id="cs-confirm-list"></ul>
		<label for="cs-confirm-note">Комментарий к версии (виден в истории)
			<input id="cs-confirm-note" type="text" maxlength="200" placeholder="Например: новый прайс фабрики от 15.10">
		</label>
		<div class="cs-acts">
			<button class="pp-btn pp-btn--ghost" type="button" id="cs-cancel">Отмена</button>
			<button class="pp-btn" type="button" id="cs-do-publish">Опубликовать</button>
		</div>
	</div>

	<section id="cs-view-formula" class="cs-grid">
		<div class="cs-col">
			<section class="cs-box">
				<div class="cs-boxhead"><h2>Формула цены</h2><span class="cs-hint" style="margin:0!important">Пример — для выбранного эталона справа</span></div>
				<p class="cs-hint">Шаги расчёта по порядку. Нажмите на шаг, чтобы изменить его параметры.</p>
				<div class="cs-fx cs-fx--main" id="cs-fx"></div>
				<button type="button" class="cs-rulebtn" data-g="rules" id="cs-rulebtn">Когда сайт не считает сам и какие сроки показывает →</button>
				<div class="cs-fxbox" id="cs-fxrows"></div>
			</section>
			<section class="cs-box<?php echo $boot['canEdit'] ? '' : ' cs-ro'; ?>" id="cs-editor"></section>
			<section class="cs-box">
				<h2>Что сильнее всего влияет на цену</h2>
				<p class="cs-hint">Насколько изменится цена выбранного эталона, если параметр вырастет на 10 %.</p>
				<div class="cs-sens" id="cs-sens"></div>
			</section>
		</div>
		<div class="cs-col">
			<section class="cs-box">
				<h2>Эталонные заказы</h2>
				<p class="cs-hint">Цена на сайте сейчас и по черновику. Больше 15 % разницы — красным, при публикации это видно в подтверждении.</p>
				<div class="cs-etal" id="cs-etal"></div>
				<?php if ( $boot['canEdit'] ) : ?>
					<form class="cs-addet" id="cs-addet">
						<input type="text" name="number" placeholder="Номер заявки, напр. 2026-0012" aria-label="Номер заявки для нового эталона" required>
						<button class="pp-btn pp-btn--sm pp-btn--ghost" type="submit">+ эталон</button>
					</form>
				<?php endif; ?>
			</section>
			<section class="cs-box" id="cs-client"></section>
			<section class="cs-box">
				<div class="cs-boxhead"><h2>Курс юаня</h2><?php if ( $boot['canEdit'] ) : ?><button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" id="cs-rates-refresh">Обновить</button><?php endif; ?></div>
				<p class="cs-hint">ЦБ РФ, обновляется автоматически дважды в сутки.</p>
				<div class="cs-rate" id="cs-rate"></div>
			</section>
		</div>
	</section>

	<section id="cs-view-history" class="cs-box" hidden>
		<h2>Версии формулы</h2>
		<p class="cs-hint">Каждая заявка с сайта запоминает номер версии, по которой клиент увидел оценку. Старую версию можно взять в черновик и опубликовать снова или скачать в CSV.</p>
		<ul class="cs-hist" id="cs-hist"></ul>
	</section>
</div>
<div class="cs-toast" id="cs-toast" role="status" hidden></div>
<script>window.PCBCalcPanel = <?php echo wp_json_encode( $boot, JSON_UNESCAPED_UNICODE ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/calc.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
