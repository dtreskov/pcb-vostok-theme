<?php

/**
 * Форма заявки — шорткод [pcb_request_form context="home|calc"].
 *
 * Одна разметка (inc/request-form.html) для главной (#request) и для
 * страницы калькулятора (этап 4). Блоки <!--home-->…<!--/home--> и
 * <!--calc-->…<!--/calc--> остаются только в своём контексте.
 *
 * Приём заявки — inc/request-handler.php (POST /wp-json/pcb/v1/request).
 */

/* Ключ клиента Yandex SmartCaptcha (публичный). Можно задать здесь или в wp-config.php.
   Пока пуст — вместо виджета выводится заглушка. Серверный ключ — только в wp-config.php. */
if (!defined('PCB_SMARTCAPTCHA_SITEKEY')) {
    define('PCB_SMARTCAPTCHA_SITEKEY', '');
}

function pcb_request_form_shortcode($atts): string
{
    $atts = shortcode_atts(array('context' => 'home'), $atts, 'pcb_request_form');
    $context = $atts['context'] === 'calc' ? 'calc' : 'home';

    $file = get_theme_file_path('inc/request-form.html');
    if (!file_exists($file)) {
        return '';
    }
    $html = file_get_contents($file);

    // служебный комментарий в начале файла
    $html = preg_replace('/^\s*<!--.*?-->\s*/s', '', $html, 1);

    // контекстные блоки
    $other = $context === 'calc' ? 'home' : 'calc';
    $html = preg_replace('/<!--' . $other . '-->.*?<!--\/' . $other . '-->\s*/s', '', $html);
    $html = preg_replace('/<!--\/?' . $context . '-->\s*/', '', $html);

    if (PCB_SMARTCAPTCHA_SITEKEY !== '') {
        $captcha = '<div class="smart-captcha request-captcha" data-sitekey="'
            . esc_attr(PCB_SMARTCAPTCHA_SITEKEY) . '"></div>';
        wp_enqueue_script('yandex-smartcaptcha', 'https://smartcaptcha.yandexcloud.net/captcha.js', array(), null, true);
    } else {
        $captcha = '<div class="request-captcha request-captcha--stub">Антиробот-проверка (SmartCaptcha) — подключится с ключом</div>';
    }

    return strtr($html, array(
        '{{context}}'  => $context,
        '{{endpoint}}' => esc_url(rest_url('pcb/v1/request')),
        '{{captcha}}'  => $captcha,
    ));
}

add_shortcode('pcb_request_form', 'pcb_request_form_shortcode');
