<?php
/**
 * SEO на сайте: <title>, description, Open Graph, разметка schema.org (главная и калькулятор), robots, robots.txt, карта сайта.
 * Защита тестового домена работает всегда, остальной вывод — если не включён сторонний SEO-плагин.
 * canonical выводит сам WordPress (rel_canonical) — не дублируем.
 */

defined( 'ABSPATH' ) || exit;

/* =================================
   Защита тестового домена (всегда)
   ================================= */

add_filter( 'wp_robots', function ( $robots ) {
	if ( ! pcb_seo_is_prod() ) {
		return array( 'noindex' => true, 'nofollow' => true );
	}
	if ( is_singular( 'page' ) && pcb_seo_meta( get_queried_object_id() )['noindex'] ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
}, 99 );

add_action( 'send_headers', function () {
	if ( ! pcb_seo_is_prod() ) {
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
} );

add_filter( 'robots_txt', function ( $out, $public ) {
	if ( ! pcb_seo_is_prod() ) {
		return "# Тестовый домен " . pcb_seo_host() . ": индексация запрещена.\n# Боевой домен — " . pcb_seo_prod_host() . " (PCB_SEO_PROD_HOST).\nUser-agent: *\nDisallow: /\n";
	}
	if ( pcb_seo_conflict() ) {
		return $out;
	}
	return pcb_seo_robots_txt( ! $public );
}, 99, 2 );

/** Итоговый robots.txt боевого сайта. $closed — в настройках WordPress запрещена индексация. */
function pcb_seo_robots_txt( $closed = false ) {
	if ( $closed ) {
		return "# В настройках WordPress включено «Попросить поисковые системы не индексировать сайт».\nUser-agent: *\nDisallow: /\n";
	}
	$lines = array(
		'User-agent: *',
		'Disallow: /' . PCB_PANEL_SLUG . '/',
		'Disallow: /wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Disallow: /wp-json/',
		'Disallow: /wp-login.php',
		'Disallow: /?s=',
		'Disallow: /*?s=',
		'Disallow: /search/',
		'Disallow: /*?pcb_preview=',
		// Яндекс: адреса с метками — та же страница (директива межсекционная, Google её пропускает)
		'Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid&ysclid&_openstat&from',
	);
	$extra = trim( (string) pcb_seo_get( 'robots_extra' ) );
	if ( '' !== $extra ) {
		$lines[] = '';
		$lines[] = '# Добавлено в панели';
		$lines[] = $extra;
	}
	$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;
	if ( $server && $server->sitemaps_enabled() ) {
		$lines[] = '';
		$lines[] = 'Sitemap: ' . home_url( '/wp-sitemap.xml' );
	}
	return implode( "\n", $lines ) . "\n";
}

/* =================================
   Мета, Open Graph, разметка
   ================================= */

add_action( 'plugins_loaded', function () {
	if ( pcb_seo_conflict() ) {
		return;
	}

	add_filter( 'document_title_separator', function () {
		return '—';
	} );

	add_filter( 'pre_get_document_title', function ( $title ) {
		$id = pcb_seo_current_page();
		return $id ? pcb_seo_title( $id ) : $title;
	}, 20 );

	add_filter( 'document_title_parts', function ( $parts ) {
		if ( isset( $parts['site'] ) ) {
			$parts['site'] = pcb_seo_get( 'brand' );
		}
		return $parts;
	} );

	add_action( 'wp_head', 'pcb_seo_head', 1 );

	// карта сайта: только страницы и записи, без авторов и рубрик; «не индексировать» — вне карты
	add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
		return in_array( $name, array( 'users', 'taxonomies' ), true ) ? false : $provider;
	}, 10, 2 );
	add_filter( 'wp_sitemaps_post_types', function ( $types ) {
		return array_intersect_key( $types, array_flip( array( 'page', 'post' ) ) );
	} );
	add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $type ) {
		if ( 'page' === $type ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => PCB_SEO_META['noindex'], 'compare' => 'NOT EXISTS' ),
				array( 'key' => PCB_SEO_META['noindex'], 'value' => '1', 'compare' => '!=' ),
			);
		}
		return $args;
	}, 10, 2 );
} );

/** id страницы, которую сейчас показываем (включая статическую главную), иначе 0. */
function pcb_seo_current_page() {
	if ( is_singular( 'page' ) ) {
		return (int) get_queried_object_id();
	}
	return 0;
}

function pcb_seo_head() {
	$id    = pcb_seo_current_page();
	$brand = pcb_seo_get( 'brand' );
	$meta  = $id ? pcb_seo_meta( $id ) : array( 'description' => '', 'og' => 0 );
	$title = $id ? pcb_seo_title( $id ) : wp_get_document_title();
	$url   = $id ? get_permalink( $id ) : home_url( add_query_arg( array() ) );
	$img   = pcb_seo_image( 'og', $meta['og'] );

	echo "\n<!-- PCB Восток: SEO -->\n";
	if ( '' !== $meta['description'] ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $meta['description'] ) );
	}
	$og = array(
		'og:type'      => 'website',
		'og:locale'    => 'ru_RU',
		'og:site_name' => $brand,
		'og:title'     => $title,
		'og:description' => $meta['description'],
		'og:url'       => $url,
		'og:image'     => $img['url'],
		'og:image:width'  => $img['w'],
		'og:image:height' => $img['h'],
		'og:image:alt' => $brand,
	);
	foreach ( $og as $p => $v ) {
		if ( '' !== (string) $v ) {
			printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $p ), esc_attr( (string) $v ) );
		}
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

	$ld = null;
	if ( is_front_page() ) {
		$ld = pcb_seo_schema();
	} elseif ( $id && $id === pcb_seo_calc_page() ) {
		$ld = pcb_seo_schema_calc();
	}
	if ( $ld ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
	}
}

/**
 * Разметка главной: организация, сайт, услуги и вопросы из FAQ (анкета — SEO → Разметка, markup.php).
 * Реквизиты (ИНН и др.) не выводим — F-1.10.
 */
function pcb_seo_schema() {
	$s    = pcb_seo_get();
	$home = home_url( '/' );
	$logo = pcb_seo_image( 'logo' );
	$org  = array(
		'@type' => 'Organization',
		'@id'   => $home . '#organization',
		'name'  => $s['brand'],
		'url'   => $home,
	);
	$desc = trim( (string) pcb_seo_markup_get( 'org_description' ) );
	if ( '' !== $desc ) {
		$org['description'] = $desc;
	}
	if ( '' !== $logo['url'] ) {
		$org['logo'] = array( '@type' => 'ImageObject', 'url' => $logo['url'], 'width' => $logo['w'], 'height' => $logo['h'] );
	}
	if ( '' !== $s['legal_name'] ) {
		$org['legalName'] = $s['legal_name'];
	}
	if ( '' !== $s['email'] ) {
		$org['email'] = $s['email'];
	}
	$digits = pcb_crm_phone_digits( $s['phone'] );
	if ( '' !== $digits ) {
		$org['telephone'] = '+' . $digits;
	}
	if ( '' !== $s['street'] ) {
		$org['address'] = array_filter( array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $s['street'],
			'addressLocality' => $s['locality'],
			'postalCode'      => $s['postal_code'],
			'addressCountry'  => 'RU',
		) );
	}
	$org['areaServed'] = pcb_seo_area_served();
	$same = pcb_seo_cards_same_as();
	if ( $same ) {
		$org['sameAs'] = $same;
	}
	$graph = array_merge(
		array(
			$org,
			array(
				'@type'      => 'WebSite',
				'@id'        => $home . '#website',
				'url'        => $home,
				'name'       => $s['brand'],
				'inLanguage' => 'ru-RU',
				'publisher'  => array( '@id' => $home . '#organization' ),
			),
		),
		pcb_seo_schema_services( $home )
	);
	$faq = pcb_seo_schema_faq( $home );
	if ( $faq ) {
		$graph[] = $faq;
	}
	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);
}
