<?php
/**
 * SEO → Разметка: «анкета» schema.org сверх данных сайта.
 *   — описание компании и регион (Organization);
 *   — три услуги (Service) — F-1.21;
 *   — вопросы и ответы (FAQPage) — автоматически из секции #faq главной, правятся в редакторе страницы;
 *   — калькулятор как онлайн-инструмент (WebApplication) — на странице калькулятора.
 * Хранение — опция pcb_seo_markup. Вывод — head.php (pcb_seo_schema(), pcb_seo_schema_calc()).
 * Цены и отзывы не размечаем: цены калькулятора не оферта (F-8.12), отзывов не публикуем (F-1.16).
 */

defined( 'ABSPATH' ) || exit;

/** Значения по умолчанию — из docs/facts.md (P-1, F-1.21, F-2.1, F-3.1, F-3.2). */
function pcb_seo_markup_defaults() {
	return array(
		'org_description' => 'Изготовление и монтаж печатных плат под ключ — полный цикл от аудита файлов до проверки по ПМИ и доставки. Специализация — сложные платы: 24+ слоя, СВЧ и высокочастотные, редкие финишные покрытия.',
		'services'        => array(
			array(
				'name'        => 'Печатные платы под ключ',
				'description' => 'Полный цикл: аудит производственных файлов, изготовление плат, поставка компонентов, монтаж, приёмка ОТК, проверка по ПМИ и доставка — от файлов до работающего устройства.',
			),
			array(
				'name'        => 'Изготовление печатных плат',
				'description' => 'Многослойные платы 24+ слоя, СВЧ и высокочастотные, гибкие и гибко-жёсткие, с контролем импеданса и редкими финишными покрытиями.',
			),
			array(
				'name'        => 'Поставка электронных компонентов',
				'description' => 'Подбор, проверка и поставка электронных компонентов от заводов-изготовителей и официальных дистрибьюторов Китая, США, Европы и Тайваня, включая позиции, недоступные в России.',
			),
		),
		'faq_on'          => 1, // вопросы из FAQ главной в разметке
		'webapp_on'       => 1,
		'webapp_page'     => 0, // 0 — страница с адресом /calculator/
		'webapp_name'     => 'Онлайн-калькулятор стоимости печатных плат',
		'webapp_desc'     => 'Загрузите проект: Gerber, ODB++, IPC-2581 или архив из KiCad, Altium, OrCAD. Калькулятор сам определит параметры платы и посчитает ориентировочную цену.',
	);
}

/** Пределы длины полей анкеты. */
define( 'PCB_SEO_MARKUP_LEN', array(
	'org_description' => 500,
	'svc_name'        => 80,
	'svc_desc'        => 300,
	'webapp_name'     => 120,
	'webapp_desc'     => 300,
) );

function pcb_seo_markup_get( $key = null ) {
	$def = pcb_seo_markup_defaults();
	$all = array_merge( $def, (array) get_option( 'pcb_seo_markup', array() ) );
	// услуги — всегда три, недостающие поля берутся по умолчанию
	$svc = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$svc[] = array_merge( $def['services'][ $i ], (array) ( $all['services'][ $i ] ?? array() ) );
	}
	$all['services'] = $svc;
	return null === $key ? $all : ( $all[ $key ] ?? '' );
}

function pcb_seo_markup_update( array $vals ) {
	$all = array_merge( (array) get_option( 'pcb_seo_markup', array() ), $vals );
	update_option( 'pcb_seo_markup', array_intersect_key( $all, pcb_seo_markup_defaults() ), true );
}

/** Страница калькулятора для разметки: выбранная в анкете или с адресом /calculator/; 0 — нет. */
function pcb_seo_calc_page() {
	$id = (int) pcb_seo_markup_get( 'webapp_page' );
	if ( $id && pcb_seo_is_page( $id ) ) {
		return $id;
	}
	$p = get_page_by_path( 'calculator' );
	return ( $p && 'publish' === $p->post_status ) ? (int) $p->ID : 0;
}

/* ---------- FAQ из секции #faq главной ---------- */

/**
 * Вопросы и ответы секции с якорем faq: [ [вопрос, ответ], … ].
 * Источник — содержимое страницы (блоки «Детали» внутри группы с якорем faq). Кэш — до следующего сохранения страницы.
 */
function pcb_seo_faq_items( $post_id = 0 ) {
	$post_id = (int) ( $post_id ?: get_option( 'page_on_front' ) );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		return array();
	}
	$key    = 'pcb_seo_faq_' . $post_id;
	$cached = get_transient( $key );
	$stamp  = md5( $post->post_modified_gmt . '|' . strlen( $post->post_content ) );
	if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp ) {
		return $cached['items'];
	}
	$items = pcb_seo_faq_parse( (string) $post->post_content );
	set_transient( $key, array( 'stamp' => $stamp, 'items' => $items ), WEEK_IN_SECONDS );
	return $items;
}

/** Разбор HTML страницы: details внутри элемента с id="faq". Без DOM-расширения — пусто. */
function pcb_seo_faq_parse( $html ) {
	if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
		return array();
	}
	$html = preg_replace( '/<!--.*?-->/s', '', $html ); // комментарии блоков Gutenberg
	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp    = new DOMXPath( $doc );
	$out   = array();
	foreach ( $xp->query( '//*[@id="faq"]//details' ) as $d ) {
		$sum = $xp->query( './summary', $d )->item( 0 );
		if ( ! $sum ) {
			continue;
		}
		$q = pcb_seo_faq_text( $sum->textContent );
		$d->removeChild( $sum );
		$a = pcb_seo_faq_text( $d->textContent );
		if ( '' !== $q && '' !== $a ) {
			$out[] = array( $q, $a );
		}
	}
	return $out;
}

function pcb_seo_faq_text( $t ) {
	$t = html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$t = str_replace( "\xC2\xA0", ' ', $t );
	return trim( preg_replace( '/\s+/u', ' ', $t ) );
}

// Страница сохранена — FAQ перечитается при следующем показе
add_action( 'save_post_page', function ( $post_id ) {
	delete_transient( 'pcb_seo_faq_' . (int) $post_id );
} );

/* ---------- узлы разметки ---------- */

function pcb_seo_area_served() {
	return array( '@type' => 'Country', 'name' => 'Россия' );
}

/** Услуги для @graph главной. */
function pcb_seo_schema_services( $home ) {
	$out = array();
	foreach ( pcb_seo_markup_get( 'services' ) as $i => $s ) {
		if ( '' === trim( $s['name'] ) ) {
			continue;
		}
		$out[] = array_filter( array(
			'@type'       => 'Service',
			'@id'         => $home . '#service-' . ( $i + 1 ),
			'name'        => $s['name'],
			'serviceType' => $s['name'],
			'description' => $s['description'],
			'provider'    => array( '@id' => $home . '#organization' ),
			'areaServed'  => pcb_seo_area_served(),
		) );
	}
	return $out;
}

/** FAQPage для @graph главной или null — если вопросов нет. */
function pcb_seo_schema_faq( $home ) {
	if ( ! pcb_seo_markup_get( 'faq_on' ) ) {
		return null;
	}
	$items = pcb_seo_faq_items();
	if ( ! $items ) {
		return null;
	}
	return array(
		'@type'      => 'FAQPage',
		'@id'        => $home . '#faq',
		'mainEntity' => array_map( function ( $qa ) {
			return array(
				'@type'          => 'Question',
				'name'           => $qa[0],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $qa[1] ),
			);
		}, $items ),
	);
}

/** Разметка страницы калькулятора (WebApplication) или null. */
function pcb_seo_schema_calc() {
	$m  = pcb_seo_markup_get();
	$id = pcb_seo_calc_page();
	if ( empty( $m['webapp_on'] ) || ! $id ) {
		return null;
	}
	$home = home_url( '/' );
	return array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array_filter( array(
				'@type'               => 'WebApplication',
				'@id'                 => $home . '#calculator',
				'name'                => $m['webapp_name'],
				'description'         => $m['webapp_desc'],
				'url'                 => get_permalink( $id ),
				'applicationCategory' => 'BusinessApplication',
				'operatingSystem'     => 'Любая, работает в браузере',
				'inLanguage'          => 'ru-RU',
				'isAccessibleForFree' => true,
				// организация полностью описана на главной; здесь — ссылка на неё с названием
				'provider'            => array( '@type' => 'Organization', '@id' => $home . '#organization', 'name' => pcb_seo_get( 'brand' ), 'url' => $home ),
			) ),
		),
	);
}
