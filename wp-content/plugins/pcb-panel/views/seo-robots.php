<?php
/**
 * SEO → robots.txt: свои строки поверх стандартных правил и итоговый файл боевого сайта.
 * Сохранение — REST pcb-panel/v1/seo/robots.
 */
defined( 'ABSPATH' ) || exit;

$can_edit = current_user_can( 'pcb_seo_edit' );
$history  = pcb_cl_log_get( 'seo:robots' );
$seo_nav  = 'robots';
include __DIR__ . '/_seo-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>robots.txt</h1>
		<p>Файл собирается автоматически: служебные разделы закрыты, адреса с utm-метками склеиваются для Яндекса, карта сайта указана. Сюда можно добавить свои строки.</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form" data-api="seo/robots"<?php echo $can_edit ? '' : ' inert'; ?>>
			<label data-field="robots_extra">Свои строки — добавляются в конец, перед строкой Sitemap
				<textarea class="seo-mono" name="robots_extra" rows="6" placeholder="Disallow: /old-page/"><?php echo esc_textarea( pcb_seo_get( 'robots_extra' ) ); ?></textarea>
				<span class="crm-err" hidden></span>
			</label>
			<p class="crm-sub">Допустимы User-agent, Disallow, Allow, Clean-param, Crawl-delay, Sitemap и комментарии «#». Закрыть весь сайт отсюда нельзя — для этого режим техработ.</p>
			<?php if ( $can_edit ) : ?><button class="pp-btn" type="submit">Сохранить</button><?php endif; ?>
		</form>
		<details class="crm-box crm-fold">
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
	<div class="crm-col crm-col--side">
		<section class="crm-box">
			<h2>Итоговый файл на <?php echo esc_html( pcb_seo_prod_host() ); ?></h2>
			<pre class="seo-code"><?php echo esc_html( str_replace( home_url(), 'https://' . pcb_seo_prod_host(), pcb_seo_robots_txt() ) ); ?></pre>
			<p class="crm-sub"><?php echo pcb_seo_is_prod() ? 'Открыть: ' : 'Сейчас, на тестовом домене, отдаётся запрет всего сайта: '; ?><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/robots.txt' ) ); ?></a> · карта сайта: <a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">wp-sitemap.xml</a></p>
		</section>
	</div>
</div>

<?php include __DIR__ . '/_seo-scripts.php'; ?>
