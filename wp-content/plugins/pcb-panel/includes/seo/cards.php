<?php
/**
 * SEO → Карточки организаций: Яндекс Бизнес и 2ГИС.
 * Открытого API для правки карточки владельцем у этих сервисов нет, поэтому панель хранит эталон
 * (данные сайта + описание компании) с кнопками «скопировать», ссылки на карточки и кабинеты
 * и отметку сверки «кто и когда». Если данные сайта менялись после сверки — панель предупреждает.
 * Ссылки на карточки попадают в разметку организации (sameAs).
 * Хранение — опция pcb_seo_cards: { yandex: {url, checked, by}, gis: {…} }.
 */

defined( 'ABSPATH' ) || exit;

/** Сервисы карточек: название, кабинет, допустимые домены ссылки на карточку. */
function pcb_seo_card_services() {
	return array(
		'yandex' => array(
			'label'   => 'Яндекс Бизнес',
			'cabinet' => 'https://yandex.ru/sprav/',
			'hosts'   => array( 'yandex.ru', 'yandex.com' ),
			'hint'    => 'Ссылка на организацию в Яндекс Картах, например https://yandex.ru/maps/org/…',
		),
		'gis'    => array(
			'label'   => '2ГИС',
			'cabinet' => 'https://account.2gis.com/',
			'hosts'   => array( '2gis.ru', '2gis.com' ),
			'hint'    => 'Ссылка на карточку в 2ГИС, например https://2gis.ru/moscow/firm/…',
		),
	);
}

function pcb_seo_cards_get() {
	$v   = (array) get_option( 'pcb_seo_cards', array() );
	$out = array();
	foreach ( array_keys( pcb_seo_card_services() ) as $k ) {
		$out[ $k ] = array_merge( array( 'url' => '', 'checked' => '', 'by' => 0 ), (array) ( $v[ $k ] ?? array() ) );
	}
	return $out;
}

function pcb_seo_cards_update( array $cards ) {
	update_option( 'pcb_seo_cards', $cards, true );
}

/** Ссылка на карточку годится: http(s) и домен сервиса. '' — пусто, false — не годится. */
function pcb_seo_card_url( $k, $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$host = pcb_seo_bare_host( wp_parse_url( $url, PHP_URL_HOST ) );
	$ok   = false;
	foreach ( pcb_seo_card_services()[ $k ]['hosts'] as $h ) {
		if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
			$ok = true;
		}
	}
	return ( $ok && preg_match( '#^https?://#i', $url ) ) ? esc_url_raw( $url ) : false;
}

/** Ссылки на карточки для sameAs разметки организации. */
function pcb_seo_cards_same_as() {
	return array_values( array_filter( wp_list_pluck( pcb_seo_cards_get(), 'url' ) ) );
}

/** Эталон для карточек: поле → значение. Сайт — боевой домен, даже на тестовом. */
function pcb_seo_card_reference() {
	$s = pcb_seo_get();
	return array(
		'Название'           => $s['brand'],
		'Юрлицо'             => $s['legal_name'],
		'Адрес'              => trim( $s['postal_code'] . ', г. ' . $s['locality'] . ', ' . $s['street'], ', ' ),
		'Телефон'            => $s['phone'],
		'Почта'              => $s['email'],
		'Сайт'               => 'https://' . pcb_seo_prod_host() . '/',
		'Описание'           => pcb_seo_markup_get( 'org_description' ),
		'Рубрики'            => 'Производство печатных плат; Электронные компоненты',
	);
}

/** Когда в последний раз менялись данные, которые идут в карточки (данные сайта, описание компании). */
function pcb_seo_card_data_changed() {
	global $wpdb;
	$t = pcb_crm_log_table();
	return (string) $wpdb->get_var( "SELECT MAX(time) FROM {$t} WHERE entity = 'seo:site' OR ( entity = 'seo:markup' AND new_value LIKE '%Описание компании%' )" ); // phpcs:ignore
}

/** Карточку пора сверить: сверки не было или данные менялись после неё. */
function pcb_seo_card_stale( array $card ) {
	if ( '' === $card['checked'] ) {
		return true;
	}
	$changed = pcb_seo_card_data_changed();
	return '' !== $changed && $changed > $card['checked'];
}
