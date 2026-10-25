<?php
/**
 * SEO → Разметка: «анкета» компании для поисковиков (schema.org) сверх данных сайта —
 * описание компании, три услуги, калькулятор; вопросы из FAQ — только просмотр (берутся с главной).
 * Сохранение — REST pcb-panel/v1/seo/markup.
 */
defined( 'ABSPATH' ) || exit;

$m        = pcb_seo_markup_get();
$L        = PCB_SEO_MARKUP_LEN;
$can_edit = current_user_can( 'pcb_seo_edit' );
$history  = pcb_cl_log_get( 'seo:markup' );
$faq      = pcb_seo_faq_items();
$calc_id  = pcb_seo_calc_page();
$front    = (int) get_option( 'page_on_front' );
$calc_ld  = pcb_seo_schema_calc();
$seo_nav  = 'markup';
include __DIR__ . '/_seo-nav.php';

/** Поле textarea со счётчиком знаков. */
$area = function ( $name, $label, $value, $max, $rows = 3 ) {
	?>
	<label class="crm-grid__wide" data-field="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?>
		<textarea name="<?php echo esc_attr( $name ); ?>" rows="<?php echo (int) $rows; ?>" maxlength="<?php echo (int) $max; ?>" data-seo-len><?php echo esc_textarea( $value ); ?></textarea>
		<span class="crm-sub seo-len" data-seo-len-out="<?php echo (int) $max; ?>"></span>
		<span class="crm-err" hidden></span>
	</label>
	<?php
};
?>
<div class="pp-page-head">
	<div>
		<h1>Разметка</h1>
		<p>«Анкета» компании для поисковиков: посетитель её не видит, а Яндекс и Google читают её как таблицу — кто мы, что делаем, какие вопросы и ответы. На позиции напрямую не влияет, но делает ссылку в выдаче понятнее. Название, адрес и телефон берутся из <a href="<?php echo esc_url( pcb_panel_url( 'seo' ) . 'site/' ); ?>">данных сайта</a>.</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form crm-requisites" data-api="seo/markup"<?php echo $can_edit ? '' : ' inert'; ?>>
			<fieldset>
				<legend>Компания</legend>
				<div class="crm-grid">
					<?php $area( 'org_description', 'Описание компании', $m['org_description'], $L['org_description'], 3 ); ?>
				</div>
				<p class="crm-sub">Одно-два предложения: что делаем и чем отличаемся. Это же описание — эталон для карточек в Яндекс Бизнесе и 2ГИС. Регион — вся Россия.</p>
			</fieldset>

			<fieldset>
				<legend>Услуги</legend>
				<?php foreach ( $m['services'] as $i => $s ) : $n = $i + 1; ?>
					<div class="crm-grid seo-svc">
						<label class="crm-grid__wide" data-field="svc<?php echo $n; ?>_name">Услуга <?php echo $n; ?>
							<input type="text" name="svc<?php echo $n; ?>_name" maxlength="<?php echo (int) $L['svc_name']; ?>" value="<?php echo esc_attr( $s['name'] ); ?>">
							<span class="crm-err" hidden></span>
						</label>
						<?php $area( "svc{$n}_desc", 'Описание', $s['description'], $L['svc_desc'], 2 ); ?>
					</div>
				<?php endforeach; ?>
				<p class="crm-sub">Три услуги сайта (F-1.21). Пустое название — услуга не выводится. Цен не указываем: калькулятор даёт ориентир, а не оферту.</p>
			</fieldset>

			<fieldset>
				<legend>Калькулятор</legend>
				<input type="hidden" name="webapp_present" value="1">
				<label class="crm-check"><input type="checkbox" name="webapp_on" value="1"<?php checked( ! empty( $m['webapp_on'] ) ); ?>> Размечать страницу калькулятора как бесплатный онлайн-инструмент</label>
				<div class="crm-grid">
					<label data-field="webapp_page">Страница
						<select name="webapp_page">
							<option value="0"<?php selected( 0, (int) $m['webapp_page'] ); ?>>Адрес /calculator/<?php echo $calc_id && ! $m['webapp_page'] ? ' — ' . esc_html( get_the_title( $calc_id ) ) : ''; ?></option>
							<?php foreach ( pcb_seo_pages() as $p ) : if ( $p->ID === $front ) { continue; } ?>
								<option value="<?php echo (int) $p->ID; ?>"<?php selected( (int) $p->ID, (int) $m['webapp_page'] ); ?>><?php echo esc_html( get_the_title( $p ) ?: '#' . $p->ID ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="crm-err" hidden></span>
					</label>
					<label data-field="webapp_name">Название
						<input type="text" name="webapp_name" maxlength="<?php echo (int) $L['webapp_name']; ?>" value="<?php echo esc_attr( $m['webapp_name'] ); ?>">
						<span class="crm-err" hidden></span>
					</label>
					<?php $area( 'webapp_desc', 'Описание', $m['webapp_desc'], $L['webapp_desc'], 2 ); ?>
				</div>
				<?php if ( ! $calc_id ) : ?><p class="crm-sub seo-warn">Страница калькулятора не найдена — разметка не выводится. Выберите страницу.</p><?php endif; ?>
			</fieldset>

			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить</button></div>
			<?php endif; ?>
		</form>

		<form class="crm-box crm-form" data-api="seo/markup"<?php echo $can_edit ? '' : ' inert'; ?>>
			<h2>Вопросы и ответы <span class="crm-sub"><?php echo (int) count( $faq ); ?></span></h2>
			<input type="hidden" name="faq_present" value="1">
			<div class="crm-form__acts">
				<label class="crm-check"><input type="checkbox" name="faq_on" value="1"<?php checked( ! empty( $m['faq_on'] ) ); ?>> Выводить вопросы из FAQ в разметке</label>
				<?php if ( $can_edit ) : ?><button class="pp-btn pp-btn--sm" type="submit">Сохранить</button><?php endif; ?>
			</div>
			<?php if ( $faq ) : ?>
				<ol class="seo-faq">
					<?php foreach ( $faq as $qa ) : ?>
						<li><b><?php echo esc_html( $qa[0] ); ?></b><span class="crm-sub"><?php echo esc_html( mb_strlen( $qa[1] ) > 160 ? mb_substr( $qa[1], 0, 157 ) . '…' : $qa[1] ); ?></span></li>
					<?php endforeach; ?>
				</ol>
				<p class="crm-sub">Берутся из секции FAQ главной автоматически: правите FAQ в редакторе страницы — разметка обновляется сама.<?php if ( $front ) : ?> <a href="<?php echo esc_url( get_edit_post_link( $front ) ); ?>">Открыть главную в редакторе</a><?php endif; ?></p>
			<?php else : ?>
				<p class="pp-muted">На главной нет секции с якорем <code>faq</code> или в ней нет блоков «Детали» — разметка вопросов не выводится.</p>
			<?php endif; ?>
			<p class="crm-sub">Ответы попадают в разметку дословно. Пока тексты FAQ не сверены с «Фактами», вывод можно выключить.</p>
		</form>
	</div>

	<div class="crm-col crm-col--side">
		<details class="crm-box crm-fold" open>
			<summary><h2>Разметка главной</h2></summary>
			<pre class="seo-code"><?php echo esc_html( wp_json_encode( pcb_seo_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></pre>
		</details>
		<details class="crm-box crm-fold">
			<summary><h2>Разметка калькулятора</h2></summary>
			<?php if ( $calc_ld ) : ?>
				<pre class="seo-code"><?php echo esc_html( wp_json_encode( $calc_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></pre>
			<?php else : ?>
				<p class="pp-muted">Выключена или страница не найдена.</p>
			<?php endif; ?>
		</details>
		<p class="crm-sub">Проверить на боевом сайте: <a href="https://webmaster.yandex.ru/tools/microtest/" target="_blank" rel="noopener">валидатор Яндекса</a>, <a href="https://validator.schema.org/" target="_blank" rel="noopener">validator.schema.org</a>.</p>
		<details class="crm-box crm-fold"<?php echo $history ? ' open' : ''; ?>>
			<summary><h2>История</h2><span class="crm-sub"><?php echo (int) count( $history ); ?></span></summary>
			<ul class="crm-history crm-scroll">
				<?php foreach ( $history as $r ) : ?>
					<li><span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
						<span class="crm-sub"><?php echo pcb_crm_user_html( $r['user_id'] ) . ' · ' . esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); // phpcs:ignore ?></span></li>
				<?php endforeach; ?>
				<?php if ( ! $history ) : ?><li class="pp-muted">Изменений пока не было.</li><?php endif; ?>
			</ul>
		</details>
	</div>
</div>

<?php include __DIR__ . '/_seo-scripts.php'; ?>
