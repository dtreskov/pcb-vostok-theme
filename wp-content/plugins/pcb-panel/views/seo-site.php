<?php
/**
 * SEO → Данные сайта: бренд и контакты для разметки организации на главной и для заглушки техработ,
 * логотип и общая картинка для мессенджеров. Сохранение — REST pcb-panel/v1/seo/site.
 */
defined( 'ABSPATH' ) || exit;

$s        = pcb_seo_get();
$fields   = pcb_seo_site_fields();
$can_edit = current_user_can( 'pcb_seo_edit' );
$history  = pcb_cl_log_get( 'seo:site' );
$imgs     = array(
	'logo_id' => array( 'logo', 'Логотип', 'Для разметки организации (карточка компании в поиске). Стандартный — из темы (assets/icons/logo/logo.png); свой — PNG от 112 px по меньшей стороне. На заглушке техработ — векторный логотип темы.' ),
	'og_id'   => array( 'og', 'Картинка для мессенджеров', 'Показывается в превью ссылок, если у страницы нет своей. 1200×630, JPG или PNG.' ),
);
$seo_nav = 'site';
include __DIR__ . '/_seo-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Данные сайта</h1>
		<p>Из этих данных собирается разметка организации на главной (карточка компании в поиске) и контакты на заглушке техработ.</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form crm-requisites" data-api="seo/site"<?php echo $can_edit ? '' : ' inert'; ?>>
			<fieldset>
				<legend>Компания</legend>
				<div class="crm-grid">
					<?php foreach ( array( 'brand', 'legal_name', 'phone', 'email' ) as $k ) : ?>
						<label data-field="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $fields[ $k ] ); ?>
							<input type="<?php echo 'email' === $k ? 'email' : ( 'phone' === $k ? 'tel' : 'text' ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"<?php echo 'brand' === $k ? ' required' : ''; ?>>
							<span class="crm-err" hidden></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<fieldset>
				<legend>Адрес офиса</legend>
				<div class="crm-grid">
					<label data-field="postal_code"><?php echo esc_html( $fields['postal_code'] ); ?><input type="text" inputmode="numeric" name="postal_code" value="<?php echo esc_attr( $s['postal_code'] ); ?>"><span class="crm-err" hidden></span></label>
					<label data-field="locality"><?php echo esc_html( $fields['locality'] ); ?><input type="text" name="locality" value="<?php echo esc_attr( $s['locality'] ); ?>"><span class="crm-err" hidden></span></label>
					<label class="crm-grid__wide" data-field="street"><?php echo esc_html( $fields['street'] ); ?><input type="text" name="street" value="<?php echo esc_attr( $s['street'] ); ?>"><span class="crm-err" hidden></span></label>
				</div>
			</fieldset>
			<fieldset>
				<legend>Картинки</legend>
				<?php foreach ( $imgs as $k => $im ) :
					$cur = pcb_seo_image( $im[0], (int) $s[ $k ] );
					$def = pcb_seo_image( $im[0], -1 ); // -1 — заведомо нет в медиатеке → картинка плагина
					?>
					<div class="seo-img seo-img--row" data-field="<?php echo esc_attr( $k ); ?>">
						<img class="seo-img__thumb seo-img__thumb--<?php echo esc_attr( $im[0] ); ?>" src="<?php echo esc_url( $cur['url'] ); ?>" alt="" data-seo-pv="img">
						<div>
							<span class="seo-img__label"><?php echo esc_html( $im[1] ); ?></span>
							<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo (int) $s[ $k ]; ?>" data-seo-img-id>
							<?php if ( $can_edit ) : ?>
								<div class="seo-img__acts">
									<label class="pp-btn pp-btn--sm pp-btn--ghost seo-upload">Загрузить<input type="file" accept="image/jpeg,image/png,image/webp" data-seo-upload></label>
									<button class="crm-link" type="button" data-seo-img-reset data-url="<?php echo esc_url( $def['url'] ); ?>"<?php echo $s[ $k ] ? '' : ' hidden'; ?>>Вернуть стандартную</button>
								</div>
							<?php endif; ?>
							<p class="crm-sub" data-seo-img-note><?php echo esc_html( $im[2] ); ?></p>
							<span class="crm-err" hidden></span>
						</div>
					</div>
				<?php endforeach; ?>
			</fieldset>
			<fieldset>
				<legend>Значок сайта (favicon)</legend>
				<?php $fav = pcb_seo_favicon_url(); if ( $fav ) : ?>
					<div class="seo-fav">
						<span class="seo-fav__tab"><img src="<?php echo esc_url( $fav ); ?>" width="16" height="16" alt=""><?php echo esc_html( $s['brand'] ); ?></span>
						<span class="seo-fav__tab seo-fav__tab--dark"><img src="<?php echo esc_url( $fav ); ?>" width="16" height="16" alt=""><?php echo esc_html( $s['brand'] ); ?></span>
						<img src="<?php echo esc_url( $fav ); ?>" width="48" height="48" alt="">
						<img src="<?php echo esc_url( pcb_seo_favicon_url( 'apple-touch-icon.png' ) ); ?>" width="60" height="60" alt="" style="border-radius:13px">
					</div>
					<p class="crm-sub">Из темы (<code>assets/icons/favicon/</code>): вкладки браузера, Яндекс и Google, iPhone, Android. Меняется через репозиторий, не здесь.</p>
				<?php else : ?>
					<p class="crm-sub">В теме нет значка (<code>assets/icons/favicon/</code>) — браузеры покажут значок WordPress.</p>
				<?php endif; ?>
			</fieldset>
			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить</button></div>
			<?php endif; ?>
			<p class="crm-sub">ИНН, ОГРН и другие реквизиты в разметку не попадают — они публикуются только в политике обработки ПДн. Часы работы не указываем.</p>
		</form>
	</div>

	<div class="crm-col crm-col--side">
		<section class="crm-box">
			<h2>Разметка на главной</h2>
			<pre class="seo-code"><?php echo esc_html( wp_json_encode( pcb_seo_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></pre>
			<p class="crm-sub">Проверить на боевом сайте: <a href="https://webmaster.yandex.ru/tools/microtest/" target="_blank" rel="noopener">валидатор Яндекса</a>, <a href="https://validator.schema.org/" target="_blank" rel="noopener">validator.schema.org</a>.</p>
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
