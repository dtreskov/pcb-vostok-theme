<?php

/**
 * SVG icon shortcode.
 */
function theme_svg_icon_shortcode($atts): string
{
    $atts = shortcode_atts(
        array(
            'name'  => '',
            'class' => '',
        ),
        $atts,
        'svg_icon'
    );

    $name = sanitize_file_name($atts['name']);

    if ($name === '') {
        return '';
    }

    $file = get_theme_file_path(
        'assets/icons/' . $name . '.svg'
    );

    if (!file_exists($file)) {
        return '';
    }

    $svg = file_get_contents($file);

    if ($svg === false || $svg === '') {
        return '';
    }

    $svg = preg_replace(
        '/<\?xml.*?\?>/i',
        '',
        $svg
    );

    $svg = preg_replace(
        '/<!DOCTYPE.*?>/i',
        '',
        $svg
    );

    $class = sanitize_html_class($atts['class']);

    if ($class !== '') {
        $svg = preg_replace(
            '/<svg\b([^>]*)>/i',
            '<svg$1 class="' . esc_attr($class) . '">',
            $svg,
            1
        );
    }

    return $svg;
}

add_shortcode(
    'svg_icon',
    'theme_svg_icon_shortcode'
);


/**
 * Register SVG Icon Gutenberg block.
 */
function theme_register_svg_icon_block(): void
{
    $block_path = get_theme_file_path(
        'blocks/svg-icon'
    );

    wp_register_script(
        'custom-svg-icon-editor-script',
        get_theme_file_uri(
            'blocks/svg-icon/index.js'
        ),
        array(
            'wp-blocks',
            'wp-element',
            'wp-block-editor',
            'wp-components',
        ),
        filemtime(
            $block_path . '/index.js'
        ),
        true
    );

    wp_register_style(
        'custom-svg-icon-editor-style',
        get_theme_file_uri(
            'blocks/svg-icon/editor.css'
        ),
        array(),
        filemtime(
            $block_path . '/editor.css'
        )
    );

    register_block_type($block_path);
}

add_action(
    'init',
    'theme_register_svg_icon_block'
);


/**
 * Pass SVG icon library to Gutenberg editor.
 */
function theme_enqueue_svg_icon_editor_data(): void
{
    $icons_dir = get_theme_file_path(
        'assets/icons/'
    );

    $icons_url = get_theme_file_uri(
        'assets/icons/'
    );

    $files = glob(
        $icons_dir . '*.svg'
    );

    $icons = array();

    foreach ($files as $file) {

        $name = pathinfo(
            $file,
            PATHINFO_FILENAME
        );

        $svg = file_get_contents($file);

        if ($svg === false || $svg === '') {
            continue;
        }

        $svg = preg_replace(
            '/<\?xml.*?\?>/i',
            '',
            $svg
        );

        $svg = preg_replace(
            '/<!DOCTYPE.*?>/i',
            '',
            $svg
        );

        $icons[] = array(
            'name' => $name,
            'svg'  => trim($svg),
        );
    }

    wp_localize_script(
        'custom-svg-icon-editor-script',
        'svgIconData',
        array(
            'icons' => $icons,
            'url'   => $icons_url,
        )
    );
}

add_action(
    'enqueue_block_editor_assets',
    'theme_enqueue_svg_icon_editor_data'
);