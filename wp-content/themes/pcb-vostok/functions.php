<?php

/**
 * Подключение кастомных php
 */

require_once get_theme_file_path(
    'inc/svg-icons.php'
);

require_once get_theme_file_path(
    'inc/fluent-forms.php'
);

/**
 * Отключаем автогенерируемые WordPress классы и inline-CSS
 * для layout-атрибутов блоков (is-layout-*, wp-container-core-*,
 * плюс инлайновый <style> с расчётом gap/flex-wrap/justify-content).
 *
 * Раскладка становится предсказуемой: то, что лежит в сыром экспорте
 * post_content (_gutenberg/pages/) плюс CSS темы — это ровно то,
 * что рендерится на фронтенде, без рантайм-довески от ядра.
 *
 * Внесено: 2026-09-18.
 * Компенсирующий CSS: .site-header__inner, .site-header__contacts
 * (header.css) и .site-main (globals.css/layout.css) — раскладка
 * этих блоков раньше держалась именно на layout support.
 */
remove_filter( 'render_block', 'wp_render_layout_support_flag', 10 );

/**
 * Подключение CSS-файла темы.
 *
 * Версия файла определяется по времени его изменения,
 * чтобы браузер автоматически загружал изменённую версию.
 */
function theme_enqueue_css(
    string $handle,
    string $file,
    array $dependencies = array()
): void {

    $path = get_theme_file_path($file);

    wp_enqueue_style(
        $handle,
        get_theme_file_uri($file),
        $dependencies,
        file_exists($path) ? filemtime($path) : null
    );
}


/**
 * Подключение CSS на фронтенде.
 */
function theme_enqueue_frontend_assets(): void
{
        theme_enqueue_css(
        'fonts',
        'assets/css/fonts.css'
    );

    theme_enqueue_css(
        'globals',
        'assets/css/globals.css',
        array('fonts')
    );

    theme_enqueue_css(
        'typography',
        'assets/css/typography.css',
        array('globals')
    );

    theme_enqueue_css(
        'layout',
        'assets/css/layout.css',
        array('globals')
    );

    theme_enqueue_css(
        'header',
        'assets/css/components/header.css',
        array('layout')
    );
    
    theme_enqueue_css(
        'utils',
        'assets/css/utils.css',
        array('globals')
    );

    theme_enqueue_css(
        'hero',
        'assets/css/components/hero.css',
        array('layout')
    );

    theme_enqueue_css(
        'faq',
        'assets/css/components/faq.css',
        array('layout')
    );

    theme_enqueue_css(
        'details',
        'assets/css/components/details.css',
        array('layout')
    );

    theme_enqueue_css(
        'carousel',
        'assets/css/components/carousel.css',
        array('layout')
    );

    theme_enqueue_css(
        'calculator',
        'assets/css/components/calculator.css',
        array('layout')
    );

    theme_enqueue_css(
        'frontend',
        'assets/css/frontend.css',
        array(
            'layout',
            'header',
            'utils',
            'hero',
            'faq',
            'details',
            'carousel',
            'calculator'
        )
    );
}

add_action(
    'wp_enqueue_scripts',
    'theme_enqueue_frontend_assets'
);


/**
 * Подключение CSS компонентов в редакторе Gutenberg.
 */
function theme_enqueue_editor_assets(): void
{
    if (!is_admin()) {
        return;
    }

    theme_enqueue_css(
        'fonts',
        'assets/css/fonts.css'
    );

    theme_enqueue_css(
        'globals',
        'assets/css/globals.css',
        array('fonts')
    );

    theme_enqueue_css(
        'typography',
        'assets/css/typography.css',
        array('globals')
    );

    theme_enqueue_css(
        'layout',
        'assets/css/layout.css',
        array('globals')
    );

    theme_enqueue_css(
        'utils',
        'assets/css/utils.css',
        array('globals')
    );

    theme_enqueue_css(
        'hero',
        'assets/css/components/hero.css',
        array('layout')
    );

    theme_enqueue_css(
        'faq',
        'assets/css/components/faq.css',
        array('layout')
    );

    theme_enqueue_css(
        'details',
        'assets/css/components/details.css',
        array('layout')
    );

    theme_enqueue_css(
        'carousel',
        'assets/css/components/carousel.css',
        array('layout')
    );

    theme_enqueue_css(
        'calculator',
        'assets/css/components/calculator.css',
        array('layout')
    );

    theme_enqueue_css(
        'gutenberg',
        'assets/css/gutenberg.css',
        array(
            'layout',
            'utils',
            'hero',
            'faq',
            'details',
            'carousel',
            'calculator'
        )
    );
}

add_action(
    'enqueue_block_assets',
    'theme_enqueue_editor_assets'
);

/**
 * Подключение JS компонентов.
 */

function theme_enqueue_scripts(): void
{
    wp_enqueue_script(
        'theme-utils',
        get_theme_file_uri('assets/js/utils.js'),
        array(),
        filemtime(
            get_theme_file_path('assets/js/utils.js')
        ),
        true
    );

    wp_enqueue_script(
        'theme-carousel',
        get_theme_file_uri('assets/js/carousel.js'),
        array('theme-utils'),
        filemtime(
            get_theme_file_path('assets/js/carousel.js')
        ),
        true
    );

    wp_enqueue_script(
        'theme-file-uploader',
        get_theme_file_uri('assets/js/file-uploader.js'),
        array(),
        filemtime(
            get_theme_file_path('assets/js/file-uploader.js')
        ),
        true
    );

    wp_enqueue_script(
        'theme-calculator',
        get_theme_file_uri('assets/js/calculator.js'),
        array(),
        filemtime(
            get_theme_file_path('assets/js/calculator.js')
        ),
        true
    );
}

add_action(
    'wp_enqueue_scripts',
    'theme_enqueue_scripts'
);