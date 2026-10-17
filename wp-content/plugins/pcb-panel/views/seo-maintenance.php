<?php
/**
 * SEO → Техработы: включение заглушки, её тексты и ссылка предпросмотра.
 * Сохранение — REST pcb-panel/v1/seo/maintenance, новая ссылка — …/maintenance/key.
 */
defined( 'ABSPATH' ) || exit;

$m        = pcb_maint_get();
$can_edit = current_user_can( 'pcb_seo_edit' );
$history  = pcb_cl_log_get( 'seo:maint' );
$seo_nav  = 'maintenance';
include __DIR__ . '/_seo-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Техработы</h1>
		<p>Пока режим включён, посетители видят заглушку с контактами, а поисковики — код 503 «временно недоступен»: страницы не выпадают из индекса. Вы и менеджеры (вошедшие в панель) видите сайт как обычно.</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form" data-api="seo/maintenance"<?php echo $can_edit ? '' : ' inert'; ?>>
			<div class="seo-switch<?php echo $m['on'] ? ' is-on' : ''; ?>">
				<label class="crm-check"><input type="checkbox" name="on" value="1"<?php checked( $m['on'] ); ?>> <b>Сайт закрыт заглушкой</b></label>
				<input type="hidden" name="on_present" value="1">
				<span class="crm-sub"><?php
				if ( $m['since'] ) {
					echo esc_html( ( $m['on'] ? 'Включён ' : 'Выключен ' ) . mysql2date( 'd.m.Y H:i', $m['since'] ) . ' · ' . pcb_crm_log_user( (int) $m['by'] ) );
				} else {
					echo esc_html( $m['on'] ? 'Включён' : 'Выключен' );
				}
				?></span>
			</div>
			<label data-field="title">Заголовок
				<input type="text" name="title" maxlength="120" value="<?php echo esc_attr( $m['title'] ); ?>" required>
				<span class="crm-err" hidden></span>
			</label>
			<label data-field="text">Текст — каждый абзац с новой строки
				<textarea name="text" rows="4"><?php echo esc_textarea( $m['text'] ); ?></textarea>
				<span class="crm-err" hidden></span>
			</label>
			<label data-field="sign">Подпись под текстом — каждая строка отдельно
				<textarea name="sign" rows="3" placeholder="info@pcb-vostok.ru&#10;+7 917 510-09-68"><?php echo esc_textarea( pcb_maint_sign() ); ?></textarea>
				<span class="crm-err" hidden></span>
			</label>
			<p class="crm-sub">Почта и телефон становятся ссылками, остальные строки — обычный текст. Пусто — подписи не будет.</p>
			<?php if ( $can_edit ) : ?><button class="pp-btn" type="submit">Сохранить</button><?php endif; ?>
		</form>

		<?php if ( $can_edit ) : ?>
			<section class="crm-box">
				<h2>Ссылка предпросмотра</h2>
				<p class="crm-sub">Для тех, у кого нет входа в панель: по ссылке сайт откроется как обычно на 7 дней в этом браузере. Новая ссылка отключает все старые.</p>
				<p><code class="crm-copy seo-mono" title="Нажмите, чтобы скопировать"><?php echo esc_html( pcb_maint_preview_url() ); ?></code></p>
				<div class="crm-form__acts"><button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-api-click="seo/maintenance/key" data-confirm="Выпустить новую ссылку? Старые перестанут открывать сайт.">Выпустить новую ссылку</button></div>
			</section>
		<?php endif; ?>
	</div>
	<div class="crm-col crm-col--side">
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

<section class="crm-box seo-preview">
	<div class="crm-box__head"><h2>Как выглядит заглушка</h2><a class="crm-link" href="<?php echo esc_url( add_query_arg( 'pcb_maint_view', '1', pcb_panel_url( 'seo' ) . 'maintenance/' ) ); ?>" target="_blank" rel="noopener">Открыть отдельно</a></div>
	<iframe class="seo-frame" src="<?php echo esc_url( add_query_arg( 'pcb_maint_view', '1', pcb_panel_url( 'seo' ) . 'maintenance/' ) ); ?>" title="Заглушка" loading="lazy"></iframe>
	<p class="crm-sub">Показывает сохранённый вариант — после «Сохранить» обновится.</p>
</section>

<?php include __DIR__ . '/_seo-scripts.php'; ?>
