<?php
/**
 * Cookie: уведомление, панель настроек и Яндекс Метрика по согласию.
 *
 * Код счётчика из кабинета Метрики в тему НЕ вставляем — нужен только его номер.
 * Скрипт счётчика загружает assets/js/cookie-consent.js после согласия.
 * Блок <noscript> с пикселем Метрики не выводим: он срабатывает без согласия.
 *
 * Номер счётчика: константа PCB_METRIKA_ID (можно задать в wp-config.php).
 * 0 — Метрика выключена, уведомление и настройки всё равно работают.
 */

if (!defined('PCB_METRIKA_ID')) {
    define('PCB_METRIKA_ID', 0);
}

/**
 * CSS и JS.
 */
function pcb_cookie_enqueue(): void
{
    theme_enqueue_css(
        'cookie-banner',
        'assets/css/components/cookie-banner.css',
        array('globals')
    );

    $js = 'assets/js/cookie-consent.js';

    wp_enqueue_script(
        'theme-cookie-consent',
        get_theme_file_uri($js),
        array(),
        filemtime(get_theme_file_path($js)),
        array('in_footer' => true, 'strategy' => 'defer')
    );

    /*
     * track: визиты вошедших в WordPress (админ, менеджер) не считаем,
     * чтобы своя работа с сайтом не искажала статистику.
     */
    wp_add_inline_script(
        'theme-cookie-consent',
        'window.PCBConsent=' . wp_json_encode(array(
            'metrika' => (int) PCB_METRIKA_ID,
            'track'   => !is_user_logged_in(),
        )) . ';',
        'before'
    );
}

add_action('wp_enqueue_scripts', 'pcb_cookie_enqueue');

/**
 * Разметка: уведомление и панель, обе скрыты до решения скрипта.
 */
function pcb_cookie_markup(): void
{
    $consent = esc_url(home_url('/politics#cookie-consent'));
    $policy  = esc_url(home_url('/politics#cookies'));
    ?>
<div id="cookie-bar" class="cookie cookie--pill" role="region" aria-label="Файлы cookie" hidden>
    <p class="cookie__text">Сайт использует cookie и Яндекс Метрику для статистики. <a class="cookie__link" href="<?php echo $consent; ?>">Подробнее</a></p>
    <div class="cookie__actions">
        <button type="button" class="cookie__btn cookie__btn--text" data-cookie="necessary">Только необходимые</button>
        <button type="button" class="cookie__btn cookie__btn--accept" data-cookie="accept">Принять</button>
    </div>
</div>

<div id="cookie-prefs" class="cookie cookie--prefs" role="dialog" aria-modal="false" aria-labelledby="cookie-prefs-title" hidden>
    <div class="cookie__head">
        <p class="cookie__title" id="cookie-prefs-title">Настройки cookie</p>
        <button type="button" class="cookie__close" data-cookie="close" aria-label="Закрыть">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 3l10 10M13 3L3 13"/></svg>
        </button>
    </div>
    <p class="cookie__text">Выберите, какие файлы cookie разрешить. Изменить выбор можно в любой момент по ссылке «Настройки cookie» внизу страницы.</p>
    <ul class="cookie__opts">
        <li>
            <span><span class="cookie__opt-name">Необходимые</span><span class="cookie__opt-desc">Работа сайта, защита формы заявки, сохранение вашего выбора</span></span>
            <span class="cookie__always">Всегда</span>
        </li>
        <li>
            <label for="cookie-analytics"><span class="cookie__opt-name">Аналитика</span><span class="cookie__opt-desc">Яндекс Метрика: статистика посещений в обобщённом виде, серверы в России</span></label>
            <span class="cookie__switch"><input type="checkbox" id="cookie-analytics" name="cookie-analytics"><i></i></span>
        </li>
    </ul>
    <div class="cookie__actions">
        <button type="button" class="cookie__btn cookie__btn--ghost" data-cookie="save">Сохранить выбор</button>
        <button type="button" class="cookie__btn cookie__btn--accept" data-cookie="accept">Принять все</button>
    </div>
    <a class="cookie__link" href="<?php echo $policy; ?>">Политика использования cookie</a>
</div>
    <?php
}

add_action('wp_footer', 'pcb_cookie_markup');
