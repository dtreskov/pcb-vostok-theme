<?php
/**
 * SEO → страница: title, description, картинка для мессенджеров, индексация.
 * Справа — превью сниппета в поиске и карточки ссылки в мессенджере (обновляются на лету, seo.js).
 * Сохранение — REST pcb-panel/v1/seo/page/<id>.
 */
defined( 'ABSPATH' ) || exit;

$id       = (int) $GLOBALS['pcb_panel_seo_page'];
$m        = pcb_seo_meta( $id );
$can_edit = current_user_can( 'pcb_seo_edit' );
$is_front = (int) get_option( 'page_on_front' ) === $id;
$default  = pcb_seo_default_title( $id );
$url      = get_permalink( $id );
$img      = pcb_seo_image( 'og', $m['og'] );
$img_def  = pcb_seo_image( 'og', 0 );
$brand    = pcb_seo_get( 'brand' );
$crumbs   = pcb_seo_host() . implode( '', array_map( function ( $s ) { return ' › ' . $s; }, array_filter( explode( '/', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ) );
$history  = pcb_cl_log_get( 'seo:' . $id );
$L        = PCB_SEO_LEN;

$seo_nav = 'pages';
include __DIR__ . '/_seo-nav.php';
?>
<a class="crm-back" href="<?php echo esc_url( pcb_panel_url( 'seo' ) ); ?>">← Все страницы</a>

<div class="pp-page-head">
	<div>
		<h1><?php echo esc_html( get_the_title( $id ) ); ?><?php echo $is_front ? ' <span class="crm-tag">главная</span>' : ''; // phpcs:ignore ?></h1>
		<p><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a></p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form seo-form" data-api="seo/page/<?php echo (int) $id; ?>"<?php echo $can_edit ? '' : ' inert'; ?>>
			<label data-field="page_title">Название страницы
				<input type="text" name="page_title" maxlength="120" value="<?php echo esc_attr( get_post_field( 'post_title', $id ) ); ?>" required>
				<span class="crm-err" hidden></span>
			</label>
			<p class="crm-sub">Как страница называется в панели, в списке страниц WordPress и в title по шаблону. Адрес страницы (<?php echo esc_html( wp_make_link_relative( $url ) ); ?>) не меняется — латиница в адресе нормальна и правильна.</p>

			<label data-field="title">Title — заголовок в поиске и во вкладке браузера
				<input type="text" name="title" maxlength="120" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="<?php echo esc_attr( $default ); ?>" data-seo-src="title" data-default="<?php echo esc_attr( $default ); ?>">
				<span class="seo-count" data-seo-count="title" data-min="<?php echo (int) $L['title'][0]; ?>" data-max="<?php echo (int) $L['title'][1]; ?>" data-limit="<?php echo (int) $L['title'][2]; ?>"></span>
				<span class="crm-err" hidden></span>
			</label>
			<p class="crm-sub">Пусто — по шаблону: «<?php echo esc_html( $default ); ?>».</p>

			<label data-field="description">Description — описание под заголовком
				<textarea name="description" rows="4" maxlength="300" data-seo-src="description"><?php echo esc_textarea( $m['description'] ); ?></textarea>
				<span class="seo-count" data-seo-count="description" data-min="<?php echo (int) $L['description'][0]; ?>" data-max="<?php echo (int) $L['description'][1]; ?>" data-limit="<?php echo (int) $L['description'][2]; ?>"></span>
				<span class="crm-err" hidden></span>
			</label>
			<p class="crm-sub">Что клиент получит и почему стоит кликнуть: услуга, отличие, призыв. Без описания поисковик соберёт его из текста страницы.</p>

			<div class="seo-img" data-field="og">
				<span class="seo-img__label">Картинка для мессенджеров</span>
				<input type="hidden" name="og" value="<?php echo (int) $m['og']; ?>" data-seo-img-id>
				<?php if ( $can_edit ) : ?>
					<div class="seo-img__acts">
						<label class="pp-btn pp-btn--sm pp-btn--ghost seo-upload">Загрузить свою<input type="file" accept="image/jpeg,image/png,image/webp" data-seo-upload></label>
						<button class="crm-link" type="button" data-seo-img-reset data-url="<?php echo esc_url( $img_def['url'] ); ?>"<?php echo $m['og'] ? '' : ' hidden'; ?>>Вернуть общую</button>
					</div>
				<?php endif; ?>
				<p class="crm-sub" data-seo-img-note><?php echo $m['og'] ? 'Своя картинка: ' . (int) $img['w'] . '×' . (int) $img['h'] : 'Общая картинка сайта — меняется в «Данных сайта»'; ?>. Лучше всего 1200×630, JPG или PNG.</p>
				<span class="crm-err" hidden></span>
			</div>

			<?php if ( ! $is_front ) : ?>
				<label class="crm-check" data-field="noindex"><input type="checkbox" name="noindex" value="1"<?php checked( $m['noindex'] ); ?>> Не индексировать страницу (и убрать из карты сайта)</label>
				<input type="hidden" name="noindex_present" value="1">
			<?php endif; ?>

			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить</button></div>
			<?php else : ?>
				<p class="crm-sub">Изменять может администратор.</p>
			<?php endif; ?>
		</form>
	</div>

	<div class="crm-col crm-col--side">
		<section class="crm-box">
			<h2>В поиске</h2>
			<div class="seo-snippet">
				<div class="seo-snippet__site"><?php if ( $fav = pcb_seo_favicon_url() ) : ?><img class="seo-snippet__fav" src="<?php echo esc_url( $fav ); ?>" alt=""><?php else : ?><span class="seo-snippet__fav"><?php echo esc_html( mb_substr( $brand, 0, 1 ) ); ?></span><?php endif; ?><span><?php echo esc_html( $brand ); ?><span class="seo-snippet__url"><?php echo esc_html( $crumbs ); ?></span></span></div>
				<div class="seo-snippet__title" data-seo-pv="title"><?php echo esc_html( pcb_seo_title( $id ) ); ?></div>
				<div class="seo-snippet__desc" data-seo-pv="description" data-empty="Описание не задано — поисковик возьмёт фрагмент текста страницы."><?php echo esc_html( $m['description'] ?: 'Описание не задано — поисковик возьмёт фрагмент текста страницы.' ); ?></div>
			</div>
			<p class="crm-sub">Примерно так: длинный заголовок поисковик обрежет, а описание может заменить фрагментом текста под запрос.</p>
		</section>

		<section class="crm-box">
			<h2>Ссылка в мессенджере</h2>
			<div class="seo-share">
				<img class="seo-share__img" src="<?php echo esc_url( $img['url'] ); ?>" alt="" data-seo-pv="img">
				<div class="seo-share__body">
					<span class="seo-share__site"><?php echo esc_html( $brand ); ?></span>
					<b data-seo-pv="title"><?php echo esc_html( pcb_seo_title( $id ) ); ?></b>
					<span data-seo-pv="description" data-empty=""><?php echo esc_html( $m['description'] ); ?></span>
				</div>
			</div>
			<p class="crm-sub">Telegram и WhatsApp кэшируют превью: после правки старая карточка может держаться до суток.</p>
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
