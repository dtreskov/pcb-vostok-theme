<?php
/**
 * SEO → Семантика: ключевые фразы и минус-слова для Яндекс Директа.
 * На сайт ничего не выводится — это рабочий список: правится здесь, копируется в кабинет Директа.
 * Хранение — опция pcb_seo_semantic: { phrases: "строки", groups: [ {name, words: "строки"} ] }.
 * Стартовый набор — из выгрузки Вордстата (чат SEO, октябрь 2026).
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_SEO_SEM_GROUPS', 20 );  // групп минус-слов не больше
define( 'PCB_SEO_SEM_WORDS', 1000 ); // слов и фраз в списке не больше

function pcb_seo_semantic_defaults() {
	return array(
		'phrases' => implode( "\n", array(
			'изготовление печатных плат на заказ',
			'заказать печатную плату',
			'изготовление и монтаж печатных плат',
			'производство и монтаж печатных плат',
			'многослойные печатные платы на заказ',
			'изготовление многослойных печатных плат',
			'изготовление свч плат',
			'свч печатные платы',
			'печатные платы для свч',
			'высокочастотные печатные платы',
			'гибко-жесткие печатные платы',
			'печатные платы с контролем импеданса',
			'поставка электронных компонентов',
			'калькулятор стоимости печатных плат',
		) ),
		'groups'  => array(
			array( 'name' => 'Самодельщики', 'words' => "своими руками\nдомашних\nлут\nфоторезист\nтравление\nтекстолит\nлак\nстанок\nчпу\nгравер\nфреза\nсверло" ),
			array( 'name' => 'Справка и учёба', 'words' => "схема\nпрограмма\nгост\nкласс\nвид\nфото\nкартинка\nкурсовая\nреферат\nчертеж\nобозначение" ),
			array( 'name' => 'Работа', 'words' => "вакансия\nработа\nоператор\nмонтажник\nзарплата\nрезюме\nобучение" ),
			array( 'name' => 'Ремонт и быт', 'words' => "ремонт\nматеринская\nинвертор\nусилитель\nкроссворд\nсканворд\nтелевизор" ),
			array( 'name' => 'Микроволновки', 'words' => "самсунг\nsamsung\nlg\nпечь\nмикроволновка\nгорение" ),
			array( 'name' => 'Соседние темы', 'words' => "заработная\nотходы\nкарта\nбанк\nкосметика\nтн вэд\nокпд" ),
			array( 'name' => 'Не наше', 'words' => "разработка\nпроектирование\nтрассировка\nразводка\nалиэкспресс\nдешево\nбесплатно\njlcpcb\npcbway\nрезонит\nэлектроконнект" ),
			array( 'name' => 'Розничная фурнитура', 'words' => "стойка\nразъем\nдержатель\nклемма" ),
		),
	);
}

function pcb_seo_semantic_get() {
	$v = get_option( 'pcb_seo_semantic', null );
	return is_array( $v ) ? array_merge( pcb_seo_semantic_defaults(), $v ) : pcb_seo_semantic_defaults();
}

function pcb_seo_semantic_update( array $v ) {
	update_option( 'pcb_seo_semantic', array( 'phrases' => (string) $v['phrases'], 'groups' => array_values( (array) $v['groups'] ) ), false );
}

/** Строки списка: нижний регистр, без «-» и «!» в начале, без пустых и повторов (порядок сохраняется). */
function pcb_seo_sem_lines( $text ) {
	$out = array();
	foreach ( preg_split( '/[\r\n,;]+/u', (string) $text ) as $l ) {
		$l = mb_strtolower( trim( wp_strip_all_tags( $l ) ) );
		$l = trim( preg_replace( '/\s+/u', ' ', ltrim( $l, '-!+ ' ) ) );
		$l = str_replace( 'ё', 'е', $l ); // Директ не различает е и ё
		if ( '' !== $l && mb_strlen( $l ) <= 80 && ! in_array( $l, $out, true ) ) {
			$out[] = $l;
		}
	}
	return $out;
}

/** Все минус-слова без повторов. */
function pcb_seo_minus_words( $sem = null ) {
	$sem = $sem ?: pcb_seo_semantic_get();
	$all = array();
	foreach ( $sem['groups'] as $g ) {
		foreach ( pcb_seo_sem_lines( $g['words'] ) as $w ) {
			if ( ! in_array( $w, $all, true ) ) {
				$all[] = $w;
			}
		}
	}
	return $all;
}

/**
 * Конфликты: минус-слово совпадает со словом ключевой фразы (или его началом, от 4 букв) —
 * объявление по этой фразе показываться не будет. [ [минус-слово, фраза], … ].
 */
function pcb_seo_sem_conflicts( $sem = null ) {
	$sem     = $sem ?: pcb_seo_semantic_get();
	$phrases = pcb_seo_sem_lines( $sem['phrases'] );
	$out     = array();
	foreach ( pcb_seo_minus_words( $sem ) as $m ) {
		foreach ( $phrases as $p ) {
			if ( false !== mb_strpos( ' ' . $p . ' ', ' ' . $m . ' ' ) ) {
				$out[] = array( $m, $p );
				continue;
			}
			if ( false === mb_strpos( $m, ' ' ) && mb_strlen( $m ) >= 4 ) {
				foreach ( preg_split( '/[\s-]+/u', $p ) as $w ) {
					if ( 0 === mb_strpos( $w, $m ) ) {
						$out[] = array( $m, $p );
						break;
					}
				}
			}
		}
	}
	return $out;
}

/** Текст для Директа: 'lines' — по строке (минус-фразы на кампанию), 'inline' — «-слово -слово» к фразе. */
function pcb_seo_minus_export( $format = 'lines', $sem = null ) {
	$words = pcb_seo_minus_words( $sem );
	if ( 'inline' === $format ) {
		return implode( ' ', array_map( function ( $w ) {
			// в строке ключевой фразы минусуется каждое слово отдельно: «своими руками» → «-своими -руками»
			return '-' . str_replace( ' ', ' -', $w );
		}, $words ) );
	}
	return implode( "\n", $words );
}
