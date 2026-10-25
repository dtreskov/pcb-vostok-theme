<?php
/**
 * SEO → Семантика: ключевые фразы и минус-слова для Яндекс Директа.
 * Правит администратор (pcb_seo_edit), менеджер смотрит и копирует. Сохранение — REST pcb-panel/v1/seo/semantic.
 */
defined( 'ABSPATH' ) || exit;

$sem       = pcb_seo_semantic_get();
$can_edit  = current_user_can( 'pcb_seo_edit' );
$history   = pcb_cl_log_get( 'seo:semantic' );
$minus     = pcb_seo_minus_words( $sem );
$phrases   = pcb_seo_sem_lines( $sem['phrases'] );
$conflicts = pcb_seo_sem_conflicts( $sem );
$seo_nav   = 'semantic';
include __DIR__ . '/_seo-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Семантика</h1>
		<p>Рабочий список для Яндекс Директа: по каким фразам показываться и при каких словах — не показываться. На сайт отсюда ничего не выводится: списки копируются в кабинет Директа. Стартовый набор собран по Вордстату (октябрь 2026).</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form" data-api="seo/semantic"<?php echo $can_edit ? '' : ' inert'; ?>>
			<fieldset>
				<legend>Ключевые фразы <span class="crm-sub"><?php echo (int) count( $phrases ); ?></span></legend>
				<label data-field="phrases">По строке на фразу
					<textarea name="phrases" rows="8" class="seo-mono"><?php echo esc_textarea( $sem['phrases'] ); ?></textarea>
					<span class="crm-err" hidden></span>
				</label>
				<p class="crm-sub">Узкие и коммерческие: «многослойные печатные платы на заказ», «изготовление свч плат». Слово «свч» без «печатные платы» не используйте — это платы микроволновок.</p>
			</fieldset>

			<fieldset>
				<legend>Минус-слова <span class="crm-sub"><?php echo (int) count( $minus ); ?></span></legend>
				<div class="seo-groups" data-seo-groups>
					<?php foreach ( $sem['groups'] as $i => $g ) : ?>
						<div class="seo-group" data-seo-group>
							<input type="text" name="g_<?php echo (int) $i; ?>_name" value="<?php echo esc_attr( $g['name'] ); ?>" maxlength="60" aria-label="Название группы">
							<textarea name="g_<?php echo (int) $i; ?>_words" rows="4" class="seo-mono" aria-label="Слова группы"><?php echo esc_textarea( $g['words'] ); ?></textarea>
							<?php if ( $can_edit ) : ?><button class="crm-link" type="button" data-seo-group-del>Убрать группу</button><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $can_edit ) : ?>
					<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-seo-group-add>+ Группа</button>
				<?php endif; ?>
				<p class="crm-sub">По строке на слово, без «-». Словоформы Директ учитывает сам: «вакансия» отсечёт и «вакансии». Осторожно с короткими словами: «монтажник» — можно, «монтаж» — нельзя, это наше ключевое слово.</p>
			</fieldset>

			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить</button></div>
			<?php endif; ?>
		</form>
	</div>

	<div class="crm-col crm-col--side">
		<?php if ( $conflicts ) : ?>
			<section class="crm-box seo-box--warn">
				<h2>Конфликты <span class="crm-sub"><?php echo (int) count( $conflicts ); ?></span></h2>
				<ul class="seo-list">
					<?php foreach ( $conflicts as $c ) : ?>
						<li>Минус «<?php echo esc_html( $c[0] ); ?>» отсечёт фразу «<?php echo esc_html( $c[1] ); ?>»</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<section class="crm-box">
			<h2>Для кабинета Директа</h2>
			<p class="crm-sub">Минус-фразы на кампанию — по строке:</p>
			<pre class="seo-code seo-code--short" id="seo-minus-lines"><?php echo esc_html( pcb_seo_minus_export( 'lines', $sem ) ); ?></pre>
			<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-seo-copy="#seo-minus-lines">Скопировать списком</button>
			<p class="crm-sub">К отдельной ключевой фразе — в одну строку:</p>
			<pre class="seo-code seo-code--short" id="seo-minus-inline"><?php echo esc_html( pcb_seo_minus_export( 'inline', $sem ) ); ?></pre>
			<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-seo-copy="#seo-minus-inline">Скопировать строкой</button>
			<p class="crm-sub">Ключевые фразы:</p>
			<pre class="seo-code seo-code--short" id="seo-phrases"><?php echo esc_html( implode( "\n", $phrases ) ); ?></pre>
			<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-seo-copy="#seo-phrases">Скопировать фразы</button>
			<p class="crm-sub">После запуска раз в неделю смотрите в Директе отчёт «Поисковые запросы» и дополняйте минус-слова здесь.</p>
		</section>
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
