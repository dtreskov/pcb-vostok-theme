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
		<p>Инструкция для поисковых роботов: что не обходить и где лежит карта сайта. Файл собирается автоматически при каждом запросе <code>/robots.txt</code>; ниже — что в нём и зачем, и поле для своих строк.</p>
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
		<details class="crm-box crm-fold seo-help" open>
			<summary><h2>Как это работает</h2></summary>
			<h3>Что делает файл</h3>
			<p>Робот Яндекса или Google перед обходом сайта читает <code>/robots.txt</code> и пропускает адреса из строк <code>Disallow</code>. Это просьба, а не защита: страница по-прежнему открывается по прямой ссылке, а закрытый раздел панели защищён входом, а не этим файлом.</p>
			<h3>Стандартные правила</h3>
			<dl class="seo-rules">
				<dt><code>User-agent: *</code></dt><dd>правила ниже — для всех роботов.</dd>
				<dt><code>Disallow: /panel/</code></dt><dd>панель управления — роботам там нечего делать.</dd>
				<dt><code>Disallow: /wp-admin/</code><br><code>Allow: /wp-admin/admin-ajax.php</code></dt><dd>консоль WordPress закрыта, но служебный адрес для фоновых запросов открыт — через него работают части страниц.</dd>
				<dt><code>Disallow: /wp-json/</code></dt><dd>служебный API (через него отправляется форма заявки). Посетителям и форме запрет не мешает — он только для роботов.</dd>
				<dt><code>Disallow: /wp-login.php</code></dt><dd>страница входа.</dd>
				<dt><code>Disallow: /?s=</code>, <code>/*?s=</code>, <code>/search/</code></dt><dd>результаты поиска по сайту — бесконечное число пустых или повторяющихся страниц.</dd>
				<dt><code>Disallow: /*?pcb_preview=</code></dt><dd>ссылки предпросмотра на время техработ.</dd>
				<dt><code>Clean-param: utm_source&amp;…</code></dt><dd>для Яндекса: адреса с метками рекламы и рассылок (utm, yclid, gclid и др.) — та же страница, а не новая. Иначе одна страница попадает в индекс много раз. Google это правило пропускает — ему хватает canonical на страницах.</dd>
				<dt><code>Sitemap: …/wp-sitemap.xml</code></dt><dd>где лежит карта сайта — список страниц, которые стоит проиндексировать.</dd>
			</dl>
			<h3>Disallow или «Не индексировать»</h3>
			<p><code>Disallow</code> экономит время робота, но <b>не убирает</b> страницу из поиска, если на неё где-то есть ссылки. Чтобы страницы не было в поиске, отметьте у неё «Не индексировать» на вкладке «Страницы» и <b>не</b> закрывайте её здесь — иначе робот не зайдёт и не увидит этот запрет.</p>
			<h3>Свои строки</h3>
			<p>Добавляются в конец, перед <code>Sitemap</code>, и действуют для всех роботов. Пример: <code>Disallow: /old-page/</code> — не обходить старую страницу; <code>Disallow: /*?sort=</code> — адреса с параметром сортировки. <code>Crawl-delay</code> сейчас не учитывают ни Яндекс, ни Google.</p>
			<h3>Когда поисковики увидят изменения</h3>
			<p>Файл перечитывается обычно в течение суток. Проверить, как его понимает робот: Яндекс Вебмастер → «Инструменты» → «Анализ robots.txt», Google Search Console → «Настройки» → «Отчёт о файле robots.txt».</p>
		</details>
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
			<p class="crm-sub"><?php echo pcb_seo_is_prod() ? 'Открыть: ' : 'На тестовом домене сейчас отдаётся запрет всего сайта: '; ?><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/robots.txt' ) ); ?></a> · карта сайта: <a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">wp-sitemap.xml</a></p>
		</section>
	</div>
</div>

<?php include __DIR__ . '/_seo-scripts.php'; ?>
