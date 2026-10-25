<?php
/**
 * SEO: настройки сайта, метаполя страниц, боевой/тестовый домен, сброс кэша.
 * Вывод в <head>, robots.txt и карта сайта — head.php; режим техработ — maintenance.php.
 *
 * Настройки (wp-config.php, выше require_once … wp-settings.php):
 *   PCB_SEO_PROD_HOST   боевой домен без www, по умолчанию pcb-vostok.ru.
 *                       На любом другом адресе сайт закрыт от поиска (robots.txt, noindex, X-Robots-Tag).
 */

defined( 'ABSPATH' ) || exit;

/** Метаполя страницы. */
define( 'PCB_SEO_META', array(
	'title'       => '_pcb_seo_title',
	'description' => '_pcb_seo_description',
	'og'          => '_pcb_seo_og',      // id картинки для мессенджеров, 0 — общая
	'noindex'     => '_pcb_seo_noindex', // '1' — не индексировать
) );

/** Ориентиры длины: [мин. хорошо, макс. хорошо, предел предупреждения]. */
define( 'PCB_SEO_LEN', array(
	'title'       => array( 15, 60, 70 ),
	'description' => array( 120, 160, 200 ),
) );

/** Данные сайта по умолчанию — из docs/facts.md (F-1.10, F-1.25, F-1.26). */
function pcb_seo_defaults() {
	return array(
		'brand'        => 'PCB Восток',
		'legal_name'   => 'ООО «ВТК ВОСТОК»',
		'phone'        => '+7 917 510-09-68',
		'email'        => 'info@pcb-vostok.ru',
		'postal_code'  => '125438',
		'locality'     => 'Москва',
		'street'       => 'ул. Михалковская, д. 63Б, стр. 2, помещ. 21/4',
		'logo_id'      => 0,
		'og_id'        => 0,
		'robots_extra' => '',
	);
}

/** Подписи полей данных сайта (для формы и журнала). */
function pcb_seo_site_fields() {
	return array(
		'brand'       => 'Название бренда',
		'legal_name'  => 'Юрлицо',
		'phone'       => 'Телефон',
		'email'       => 'Почта',
		'postal_code' => 'Индекс',
		'locality'    => 'Город',
		'street'      => 'Адрес',
		'logo_id'     => 'Логотип',
		'og_id'       => 'Картинка для мессенджеров',
	);
}

function pcb_seo_get( $key = null ) {
	$all = array_merge( pcb_seo_defaults(), (array) get_option( 'pcb_seo', array() ) );
	return null === $key ? $all : ( $all[ $key ] ?? '' );
}

function pcb_seo_update( array $vals ) {
	$all = array_merge( (array) get_option( 'pcb_seo', array() ), $vals );
	update_option( 'pcb_seo', array_intersect_key( $all, pcb_seo_defaults() ), false );
}

/* ---------- домен ---------- */

function pcb_seo_bare_host( $host ) {
	return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
}

function pcb_seo_prod_host() {
	return pcb_seo_bare_host( defined( 'PCB_SEO_PROD_HOST' ) ? PCB_SEO_PROD_HOST : 'pcb-vostok.ru' );
}

function pcb_seo_host() {
	return pcb_seo_bare_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/** Сайт открыт на боевом домене — только тогда поисковикам что-то разрешено. */
function pcb_seo_is_prod() {
	return pcb_seo_host() === pcb_seo_prod_host();
}

/** Включён сторонний SEO-плагин — наш вывод мета, разметки и robots.txt выключается. */
function pcb_seo_conflict() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'Yoast SEO';
	}
	if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return 'Rank Math';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'All in One SEO';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'SEOPress';
	}
	return '';
}

/* ---------- страницы ---------- */

/** Страницы, которыми управляет вкладка: опубликованные, главная — первой. */
function pcb_seo_pages() {
	$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title' ) );
	$front = (int) get_option( 'page_on_front' );
	usort( $pages, function ( $a, $b ) use ( $front ) {
		return ( (int) ( $b->ID === $front ) ) - ( (int) ( $a->ID === $front ) );
	} );
	return $pages;
}

function pcb_seo_is_page( $id ) {
	$p = get_post( (int) $id );
	return $p && 'page' === $p->post_type && 'publish' === $p->post_status;
}

function pcb_seo_meta( $id ) {
	$out = array();
	foreach ( PCB_SEO_META as $k => $meta ) {
		$out[ $k ] = (string) get_post_meta( (int) $id, $meta, true );
	}
	$out['og']      = (int) $out['og'];
	$out['noindex'] = '1' === $out['noindex'];
	return $out;
}

/** Заголовок по шаблону: главная — бренд, остальные — «Страница — бренд». */
function pcb_seo_default_title( $id ) {
	$brand = pcb_seo_get( 'brand' );
	if ( (int) get_option( 'page_on_front' ) === (int) $id ) {
		return $brand;
	}
	return wp_strip_all_tags( get_the_title( $id ) ) . ' — ' . $brand;
}

function pcb_seo_title( $id ) {
	$t = pcb_seo_meta( $id )['title'];
	return '' !== $t ? $t : pcb_seo_default_title( $id );
}

/** Оценка длины: ok | warn | bad. */
function pcb_seo_len_state( $kind, $text ) {
	$n = mb_strlen( (string) $text );
	list( $min, $max, $limit ) = PCB_SEO_LEN[ $kind ];
	if ( 0 === $n ) {
		return 'bad';
	}
	if ( $n > $limit ) {
		return 'bad';
	}
	return ( $n >= $min && $n <= $max ) ? 'ok' : 'warn';
}

/* ---------- картинки ---------- */

/**
 * Картинка для мессенджеров ('og') или логотип ('logo'): своя из медиатеки, иначе стандартная.
 * Стандартная картинка для мессенджеров — в плагине (assets/seo/og-default.jpg),
 * логотип — в теме (assets/icons/logo/logo.png): он часть фирменного стиля и меняется вместе с темой.
 * $id = -1 — сразу стандартная, без своей. Нет файла — url пустой.
 */
function pcb_seo_image( $kind, $id = 0 ) {
	$id = (int) $id;
	if ( ! $id ) {
		$id = (int) pcb_seo_get( 'logo' === $kind ? 'logo_id' : 'og_id' );
	}
	if ( $id > 0 && ( $src = wp_get_attachment_image_src( $id, 'full' ) ) ) {
		return array( 'url' => $src[0], 'w' => (int) $src[1], 'h' => (int) $src[2], 'id' => $id );
	}
	if ( 'logo' === $kind ) {
		$path = get_theme_file_path( 'assets/icons/logo/logo.png' );
		$url  = get_theme_file_uri( 'assets/icons/logo/logo.png' );
	} else {
		$path = PCB_PANEL_DIR . 'assets/seo/og-default.jpg';
		$url  = PCB_PANEL_URL . 'assets/seo/og-default.jpg';
	}
	if ( ! file_exists( $path ) ) {
		return array( 'url' => '', 'w' => 0, 'h' => 0, 'id' => 0 );
	}
	$info = @getimagesize( $path );
	return array( 'url' => $url . '?ver=' . filemtime( $path ), 'w' => (int) ( $info[0] ?? 0 ), 'h' => (int) ( $info[1] ?? 0 ), 'id' => 0 );
}

/** Векторный логотип темы для тёмного фона (заглушка техработ) или '' — если файла нет. */
function pcb_seo_logo_svg_url() {
	$path = get_theme_file_path( 'assets/icons/logo/logo-light.svg' );
	return file_exists( $path ) ? get_theme_file_uri( 'assets/icons/logo/logo-light.svg' ) . '?ver=' . filemtime( $path ) : '';
}

/** Значок сайта из темы (favicon.svg) — для превью сниппета; '' — если темы со значком нет. */
function pcb_seo_favicon_url( $file = 'favicon.svg' ) {
	return function_exists( 'pcb_theme_icon_url' ) ? pcb_theme_icon_url( $file ) : '';
}

/** Ссылки на значки для <head> страниц, где wp_head() не вызывается (панель, заглушка). */
function pcb_seo_icon_links() {
	return function_exists( 'pcb_theme_icon_links' ) ? pcb_theme_icon_links() : '';
}

/* ---------- кэш страниц (WP Super Cache) ---------- */

/** Сбросить кэш страницы или всего сайта ($post_id = 0), чтобы поисковик и посетители увидели правку. */
function pcb_seo_purge( $post_id = 0 ) {
	if ( $post_id && function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( (int) $post_id );
	} elseif ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( $post_id ) {
		clean_post_cache( (int) $post_id );
	}
}

/* ---------- состояние для панели ---------- */

/** Предупреждения и статус индексации — для шапки вкладки SEO. */
function pcb_seo_status() {
	$s = array(
		'prod'     => pcb_seo_is_prod(),
		'host'     => pcb_seo_host(),
		'prodhost' => pcb_seo_prod_host(),
		'public'   => '1' === (string) get_option( 'blog_public', '1' ),
		'maint'    => pcb_maint_on(),
		'conflict' => pcb_seo_conflict(),
		'ucmm'     => pcb_maint_legacy_active(),
		'robots'   => file_exists( ABSPATH . 'robots.txt' ),
		// карточек организаций, которые пора сверить (сверки не было или данные сайта менялись после неё)
		'cards'    => count( array_filter( pcb_seo_cards_get(), 'pcb_seo_card_stale' ) ),
	);
	$s['open'] = $s['prod'] && $s['public'] && ! $s['maint'];
	return $s;
}
