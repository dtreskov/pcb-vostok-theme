<?php

/**
 * Форма заявки — подключение на сайте.
 *
 * Сама форма собрана из блоков Gutenberg прямо в страницах (секция #request главной
 * и этап 4 страницы калькулятора) — её тексты, порядок и оформление правятся в редакторе.
 * Стили: assets/css/components/request-form.css, логика: assets/js/request-form.js,
 * приём заявок: inc/request-handler.php (POST /wp-json/pcb/v1/request).
 *
 * Здесь — настройки для скрипта формы и виджет Yandex SmartCaptcha.
 */

/* Ключ клиента Yandex SmartCaptcha (публичный). Можно задать здесь или в wp-config.php.
   Пока пуст — вместо виджета выводится заглушка. Серверный ключ — только в wp-config.php. */
if (!defined('PCB_SMARTCAPTCHA_SITEKEY')) {
    define('PCB_SMARTCAPTCHA_SITEKEY', '');
}

/* Есть ли форма на текущей странице: ищем корневую группу формы в контенте */
function pcb_request_form_on_page(): bool
{
    if (!is_singular()) {
        return false;
    }
    $post = get_post();
    return $post && strpos((string) $post->post_content, 'request-form') !== false;
}

add_action('wp_enqueue_scripts', function () {
    if (!wp_script_is('theme-request-form', 'registered') && !wp_script_is('theme-request-form', 'enqueued')) {
        return;
    }
    wp_add_inline_script('theme-request-form', 'window.PCBRequest = ' . wp_json_encode(array(
        'endpoint' => rest_url('pcb/v1/request'),
        'sitekey'  => PCB_SMARTCAPTCHA_SITEKEY,
        'mail'     => 'info@pcb-vostok.ru',
    )) . ';', 'before');

    if (PCB_SMARTCAPTCHA_SITEKEY !== '' && pcb_request_form_on_page()) {
        // render=onload: виджет рендерит request-form.js в .request-captcha (window.pcbCaptchaReady)
        wp_enqueue_script('yandex-smartcaptcha', 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=pcbCaptchaReady', array('theme-request-form'), null, true);
    }
}, 20);
