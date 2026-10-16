<?php
/**
 * Реквизиты компании: состав полей и проверка (контрольные суммы ИНН, ОГРН, ключ расчётного счёта по БИК).
 * Проверка срабатывает только для заполненных полей — пустое поле допустимо.
 */

defined( 'ABSPATH' ) || exit;

/** Поля компании: ключ => array( подпись, группа, тип ). Порядок = порядок в карточке. */
function pcb_cl_company_fields() {
	return array(
		'name'        => array( 'Краткое наименование', 'Наименование', 'text' ),
		'full_name'   => array( 'Полное наименование', 'Наименование', 'text' ),
		'opf'         => array( 'Форма собственности', 'Наименование', 'text' ),
		'inn'         => array( 'ИНН', 'Регистрация', 'digits' ),
		'kpp'         => array( 'КПП', 'Регистрация', 'text' ),
		'ogrn'        => array( 'ОГРН / ОГРНИП', 'Регистрация', 'digits' ),
		'addr_legal'  => array( 'Юридический адрес', 'Адреса', 'area' ),
		'addr_actual' => array( 'Фактический адрес', 'Адреса', 'area' ),
		'addr_postal' => array( 'Почтовый адрес', 'Адреса', 'area' ),
		'bank_name'   => array( 'Банк', 'Банк', 'text' ),
		'bik'         => array( 'БИК', 'Банк', 'digits' ),
		'rs'          => array( 'Расчётный счёт', 'Банк', 'digits' ),
		'ks'          => array( 'Корреспондентский счёт', 'Банк', 'digits' ),
		'head_name'   => array( 'Руководитель (ФИО)', 'Подписант', 'text' ),
		'head_post'   => array( 'Должность', 'Подписант', 'text' ),
		'head_basis'  => array( 'Основание полномочий', 'Подписант', 'text' ),
		'phone'       => array( 'Общий телефон', 'Прочее', 'text' ),
		'site'        => array( 'Сайт', 'Прочее', 'text' ),
		'note'        => array( 'Комментарий', 'Прочее', 'area' ),
	);
}

function pcb_cl_digits_only( $v ) {
	return preg_replace( '/\D+/', '', (string) $v );
}

/** ИНН: 10 цифр — организация, 12 — ИП или физлицо; контрольные числа по алгоритму ФНС. */
function pcb_cl_check_inn( $inn ) {
	$len = strlen( $inn );
	if ( ! ctype_digit( $inn ) || ( 10 !== $len && 12 !== $len ) ) {
		return false;
	}
	$sum = function ( $w ) use ( $inn ) {
		$s = 0;
		foreach ( $w as $i => $k ) {
			$s += $k * (int) $inn[ $i ];
		}
		return $s % 11 % 10;
	};
	if ( 10 === $len ) {
		return $sum( array( 2, 4, 10, 3, 5, 9, 4, 6, 8 ) ) === (int) $inn[9];
	}
	return $sum( array( 7, 2, 4, 10, 3, 5, 9, 4, 6, 8 ) ) === (int) $inn[10]
		&& $sum( array( 3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8 ) ) === (int) $inn[11];
}

/** ОГРН (13) и ОГРНИП (15): последняя цифра — остаток от деления на 11 / 13, последняя его цифра. */
function pcb_cl_check_ogrn( $ogrn ) {
	$len = strlen( $ogrn );
	if ( ! ctype_digit( $ogrn ) || ( 13 !== $len && 15 !== $len ) ) {
		return false;
	}
	$body = substr( $ogrn, 0, $len - 1 );
	$mod  = 13 === $len ? 11 : 13;
	// без bcmath: остаток длинного числа по цифрам
	$r = 0;
	foreach ( str_split( $body ) as $d ) {
		$r = ( $r * 10 + (int) $d ) % $mod;
	}
	return ( $r % 10 ) === (int) $ogrn[ $len - 1 ];
}

/** Ключ счёта: (3 цифры + 20 цифр счёта) с весами 7,1,3… — сумма кратна 10. */
function pcb_cl_check_account( $prefix3, $account ) {
	$s = $prefix3 . $account;
	if ( 23 !== strlen( $s ) || ! ctype_digit( $s ) ) {
		return false;
	}
	$w   = array( 7, 1, 3 );
	$sum = 0;
	for ( $i = 0; $i < 23; $i++ ) {
		$sum += ( (int) $s[ $i ] * $w[ $i % 3 ] ) % 10;
	}
	return 0 === $sum % 10;
}

/**
 * Проверить и привести реквизиты к единому виду.
 * Возвращает array( 'values' => [...], 'errors' => [ поле => текст ] ).
 */
function pcb_cl_clean_company( array $in ) {
	$out    = array();
	$errors = array();
	foreach ( pcb_cl_company_fields() as $k => $f ) {
		if ( ! array_key_exists( $k, $in ) ) {
			continue;
		}
		$v = 'area' === $f[2] ? trim( sanitize_textarea_field( (string) $in[ $k ] ) ) : trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $in[ $k ] ) ) );
		if ( 'digits' === $f[2] ) {
			$v = pcb_cl_digits_only( $v );
		}
		$out[ $k ] = $v;
	}
	$inn = $out['inn'] ?? '';
	if ( '' !== $inn && ! pcb_cl_check_inn( $inn ) ) {
		$errors['inn'] = 'ИНН не проходит проверку: 10 цифр у организации, 12 у ИП — проверьте цифры.';
	}
	if ( isset( $out['kpp'] ) ) {
		$out['kpp'] = strtoupper( str_replace( ' ', '', $out['kpp'] ) );
		if ( '' !== $out['kpp'] && ! preg_match( '/^\d{4}[\dA-Z]{2}\d{3}$/', $out['kpp'] ) ) {
			$errors['kpp'] = 'КПП — 9 знаков: 4 цифры, 2 цифры или буквы, 3 цифры.';
		}
		if ( '' !== $out['kpp'] && 12 === strlen( $inn ) ) {
			$errors['kpp'] = 'У ИП нет КПП — оставьте поле пустым.';
		}
	}
	if ( '' !== ( $out['ogrn'] ?? '' ) ) {
		if ( ! pcb_cl_check_ogrn( $out['ogrn'] ) ) {
			$errors['ogrn'] = 'ОГРН — 13 цифр, ОГРНИП — 15; контрольная цифра не сходится.';
		} elseif ( '' !== $inn && ( 10 === strlen( $inn ) ) !== ( 13 === strlen( $out['ogrn'] ) ) ) {
			$errors['ogrn'] = 'У организации — ОГРН из 13 цифр, у ИП — ОГРНИП из 15.';
		}
	}
	$bik = $out['bik'] ?? '';
	if ( '' !== $bik && ! preg_match( '/^04\d{7}$/', $bik ) ) {
		$errors['bik'] = 'БИК — 9 цифр и начинается с 04.';
	}
	if ( '' !== ( $out['rs'] ?? '' ) ) {
		if ( 20 !== strlen( $out['rs'] ) ) {
			$errors['rs'] = 'Расчётный счёт — 20 цифр.';
		} elseif ( '' !== $bik && empty( $errors['bik'] ) && ! pcb_cl_check_account( substr( $bik, -3 ), $out['rs'] ) ) {
			$errors['rs'] = 'Расчётный счёт не сходится с БИК — проверьте цифры счёта и БИК.';
		}
	}
	if ( '' !== ( $out['ks'] ?? '' ) ) {
		if ( 20 !== strlen( $out['ks'] ) || 0 !== strpos( $out['ks'], '301' ) ) {
			$errors['ks'] = 'Корреспондентский счёт — 20 цифр, начинается с 301.';
		} elseif ( '' !== $bik && empty( $errors['bik'] ) && ! pcb_cl_check_account( '0' . substr( $bik, 4, 2 ), $out['ks'] ) ) {
			$errors['ks'] = 'Корреспондентский счёт не сходится с БИК.';
		}
	}
	if ( '' !== ( $out['phone'] ?? '' ) ) {
		$p = pcb_crm_norm_phone( $out['phone'] );
		if ( is_wp_error( $p ) ) {
			$errors['phone'] = $p->get_error_message();
		} else {
			$out['phone'] = $p;
		}
	}
	if ( '' !== ( $out['site'] ?? '' ) ) {
		$site = preg_match( '~^https?://~i', $out['site'] ) ? $out['site'] : 'https://' . $out['site'];
		$host = wp_parse_url( $site, PHP_URL_HOST );
		if ( ! $host || false === strpos( $host, '.' ) ) {
			$errors['site'] = 'Сайт — адрес вида pcb-vostok.ru.';
		} else {
			$out['site'] = esc_url_raw( $site );
		}
	}
	if ( array_key_exists( 'name', $out ) && '' === $out['name'] ) {
		$errors['name'] = 'Укажите хотя бы краткое наименование.';
	}
	return array( 'values' => $out, 'errors' => $errors );
}
