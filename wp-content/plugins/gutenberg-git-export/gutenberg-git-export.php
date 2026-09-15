<?php
/**
 * Plugin Name: Gutenberg Git Export
 * Description: Экспортирует содержимое WordPress-страниц в файлы внутри активной темы для передачи в Git/LLM.
 * Version: 1.0.0
 * Author: PCB Восток
 */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * ============================================================
 * Настройки
 * ============================================================
 */

const GGE_EXPORT_DIR = '_gutenberg';
const GGE_PAGES_DIR  = '_gutenberg/pages';


/**
 * ============================================================
 * Регистрация страницы настроек
 * ============================================================
 */

add_action('admin_menu', function () {
    add_management_page(
        'Gutenberg Git Export',
        'Gutenberg Git Export',
        'manage_options',
        'gutenberg-git-export',
        'gge_render_admin_page'
    );
});


/**
 * ============================================================
 * Страница администрирования
 * ============================================================
 */

function gge_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав.');
    }

    $result = null;

    if (
        isset($_POST['gge_export']) &&
        check_admin_referer('gge_export_action', 'gge_export_nonce')
    ) {
        $result = gge_export_pages();
    }

    $theme_dir = get_stylesheet_directory();
    $export_dir = trailingslashit($theme_dir) . GGE_EXPORT_DIR;
    ?>

    <div class="wrap">
        <h1>Gutenberg Git Export</h1>

        <p>
            Экспорт создаёт копию содержимого WordPress-страниц
            внутри активной темы.
        </p>

        <p>
            Источник данных:
            <code>wp_posts.post_content</code>
        </p>

        <p>
            Каталог:
            <code><?php echo esc_html($export_dir); ?></code>
        </p>

        <?php if ($result !== null): ?>

            <?php if ($result['success']): ?>

                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong>Экспорт завершён.</strong>
                    </p>

                    <p>
                        Экспортировано страниц:
                        <?php echo esc_html($result['pages_exported']); ?>
                    </p>

                    <p>
                        Удалено устаревших файлов:
                        <?php echo esc_html($result['files_deleted']); ?>
                    </p>

                </div>

            <?php else: ?>

                <div class="notice notice-error">
                    <p>
                        <strong>Экспорт завершён с ошибками.</strong>
                    </p>

                    <?php if (!empty($result['errors'])): ?>

                        <ul>
                            <?php foreach ($result['errors'] as $error): ?>
                                <li>
                                    <?php echo esc_html($error); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

        <form method="post">

            <?php
            wp_nonce_field(
                'gge_export_action',
                'gge_export_nonce'
            );
            ?>

            <p>
                <button
                    type="submit"
                    name="gge_export"
                    class="button button-primary"
                >
                    Экспортировать Gutenberg
                </button>
            </p>

        </form>
    </div>

    <?php
}


/**
 * ============================================================
 * Экспорт
 * ============================================================
 */

function gge_export_pages(): array
{
    $result = [
        'success'         => true,
        'pages_exported'  => 0,
        'files_deleted'   => 0,
        'errors'          => [],
    ];

    $theme_dir = get_stylesheet_directory();

    if (!$theme_dir || !is_dir($theme_dir)) {
        $result['success'] = false;
        $result['errors'][] = 'Не удалось определить директорию активной темы.';
        return $result;
    }

    $export_dir = trailingslashit($theme_dir) . GGE_EXPORT_DIR;
    $pages_dir  = trailingslashit($theme_dir) . GGE_PAGES_DIR;

    /*
     * Создаём каталоги.
     */
    if (!wp_mkdir_p($pages_dir)) {
        $result['success'] = false;
        $result['errors'][] = 'Не удалось создать каталог экспорта.';
        return $result;
    }

    /*
     * Получаем все страницы, кроме удалённых.
     *
     * Автосохранения здесь не попадут:
     * они имеют post_status = auto-draft.
     */
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => [
            'publish',
            'draft',
            'pending',
            'future',
            'private',
        ],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    /*
     * Сохраняем список файлов, которые должны существовать
     * после текущего экспорта.
     */
    $expected_files = [];

    $index = [
        'generated_at' => current_time('mysql'),
        'theme' => [
            'stylesheet' => get_stylesheet(),
            'template'   => get_template(),
        ],
        'pages' => [],
    ];

    foreach ($pages as $page) {

        $id = (int) $page->ID;

        /*
         * Используем ID в имени файла, чтобы не было конфликтов
         * у страниц с одинаковыми slug в разных ветках.
         */
        $slug = sanitize_title($page->post_name);

        if ($slug === '') {
            $slug = 'page';
        }

        $basename = $id . '-' . $slug;

        $html_filename = $basename . '.html';
        $json_filename = $basename . '.json';

        $html_path = trailingslashit($pages_dir) . $html_filename;
        $json_path = trailingslashit($pages_dir) . $json_filename;

        /*
         * ВАЖНО:
         * Берём именно сохранённый post_content.
         * Никакой генерации HTML здесь нет.
         */
        $content = get_post_field(
            'post_content',
            $id,
            'raw'
        );

        if ($content === null) {
            $result['success'] = false;
            $result['errors'][] =
                "Не удалось получить post_content страницы ID {$id}.";
            continue;
        }

        /*
         * Метаданные страницы.
         */
        $metadata = [
            'id'          => $id,
            'title'       => $page->post_title,
            'slug'        => $page->post_name,
            'status'      => $page->post_status,
            'parent'      => (int) $page->post_parent,
            'menu_order'  => (int) $page->menu_order,
            'modified'    => $page->post_modified,
            'file'        => $html_filename,
        ];

        /*
         * Записываем HTML.
         */
        $html_written = file_put_contents(
            $html_path,
            $content
        );

        if ($html_written === false) {
            $result['success'] = false;
            $result['errors'][] =
                "Не удалось записать {$html_filename}.";
            continue;
        }

        /*
         * Записываем JSON.
         */
        $json = wp_json_encode(
            $metadata,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            $result['success'] = false;
            $result['errors'][] =
                "Не удалось сформировать JSON для страницы ID {$id}.";
            continue;
        }

        $json_written = file_put_contents(
            $json_path,
            $json . PHP_EOL
        );

        if ($json_written === false) {
            $result['success'] = false;
            $result['errors'][] =
                "Не удалось записать {$json_filename}.";
            continue;
        }

        /*
         * Регистрируем ожидаемые файлы.
         */
        $expected_files[$html_filename] = true;
        $expected_files[$json_filename] = true;

        /*
         * Добавляем страницу в индекс.
         */
        $index['pages'][] = [
            'id'     => $id,
            'title'  => $page->post_title,
            'slug'   => $page->post_name,
            'status' => $page->post_status,
            'file'   => 'pages/' . $html_filename,
        ];

        $result['pages_exported']++;
    }

    /*
     * ========================================================
     * Удаление устаревших файлов
     * ========================================================
     *
     * Папка pages считается полностью принадлежащей экспортёру.
     */

    $existing_files = scandir($pages_dir);

    if ($existing_files !== false) {

        foreach ($existing_files as $filename) {

            if (
                $filename === '.' ||
                $filename === '..'
            ) {
                continue;
            }

            $path = trailingslashit($pages_dir) . $filename;

            if (!is_file($path)) {
                continue;
            }

            if (isset($expected_files[$filename])) {
                continue;
            }

            /*
             * Удаляем только наши типы файлов.
             */
            $extension = strtolower(
                pathinfo($filename, PATHINFO_EXTENSION)
            );

            if (
                $extension !== 'html' &&
                $extension !== 'json'
            ) {
                continue;
            }

            if (unlink($path)) {
                $result['files_deleted']++;
            } else {
                $result['success'] = false;
                $result['errors'][] =
                    "Не удалось удалить устаревший файл {$filename}.";
            }
        }
    }

    /*
     * ========================================================
     * Индекс
     * ========================================================
     */

    $index_json = wp_json_encode(
        $index,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($index_json === false) {

        $result['success'] = false;
        $result['errors'][] =
            'Не удалось сформировать index.json.';

        return $result;
    }

    $index_path = trailingslashit($export_dir) . 'index.json';

    if (
        file_put_contents(
            $index_path,
            $index_json . PHP_EOL
        ) === false
    ) {
        $result['success'] = false;
        $result['errors'][] =
            'Не удалось записать index.json.';
    }

    /*
     * README создаём один раз/обновляем при каждом экспорте.
     * Это поясняет Claude назначение каталога.
     */
    $readme = <<<README
# Gutenberg export

This directory is generated automatically from the WordPress database.

Source of truth:
- WordPress database
- `wp_posts.post_content` for page content

Generated files must not be edited manually.

Export direction:

WordPress database → files → Git

README;

    $readme_path = trailingslashit($export_dir) . 'README.md';

    if (
        file_put_contents(
            $readme_path,
            $readme
        ) === false
    ) {
        $result['success'] = false;
        $result['errors'][] =
            'Не удалось записать README.md.';
    }

    return $result;
}