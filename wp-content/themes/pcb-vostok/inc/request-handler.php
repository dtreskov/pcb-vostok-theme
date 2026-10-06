<?php

/**
 * Заявки с сайта: приём формы, проверка, хранение, письма.
 *
 * Форма — inc/request-form.html (шорткод [pcb_request_form]), отправка —
 * assets/js/request-form.js: multipart POST на /wp-json/pcb/v1/request.
 *
 * Хранение: закрытый тип записей pcb_request («Заявки» в админке).
 * Файлы — в закрытой папке под случайными именами, скачиваются только
 * через admin-post.php?action=pcb_request_file залогиненным пользователем
 * с правом read_pcb_requests.
 *
 * Настройки — константы в wp-config.php (секреты в тему и git не кладём):
 *   PCB_SMARTCAPTCHA_SECRET  серверный ключ Yandex SmartCaptcha (обязательно на проде)
 *   PCB_SMARTCAPTCHA_SITEKEY ключ клиента (можно и здесь, он публичный)
 *   PCB_REQUEST_NOTIFY       адреса менеджеров через запятую (по умолчанию info@pcb-vostok.ru)
 *   PCB_MAIL_FROM            адрес отправителя писем (по умолчанию PCB_SMTP_USER или info@pcb-vostok.ru)
 *   PCB_SMTP_HOST, PCB_SMTP_PORT, PCB_SMTP_USER, PCB_SMTP_PASS, PCB_SMTP_SECURE ('ssl'|'tls')
 *   PCB_REQUEST_STORAGE      папка для файлов заявок вне веб-корня (по умолчанию uploads/pcb-requests)
 *   PCB_POLICY_VERSION       версия политики ПДн, фиксируется в согласии (по умолчанию '2026-10')
 */

if (!defined('ABSPATH')) {
    exit;
}

const PCB_RQ_MAX_FILES  = 10;
const PCB_RQ_MAX_TOTAL  = 52428800;               // 50 МБ
const PCB_RQ_RATE_LIMIT = 5;                       // заявок с одного IP
const PCB_RQ_RATE_TTL   = 600;                     // за 10 минут
const PCB_RQ_SERVICES   = array(
    'turnkey'    => 'Платы под ключ',
    'pcb'        => 'Только платы',
    'components' => 'Только компоненты',
    'other'      => 'Другое',
);
const PCB_RQ_STATUSES   = array(
    'new'    => 'Новая',
    'work'   => 'В работе',
    'quoted' => 'Расчёт отправлен',
    'closed' => 'Закрыта',
);

function pcb_rq_const(string $name, $default = '')
{
    return defined($name) ? constant($name) : $default;
}


/* =================================
   Тип записей и права
   ================================= */

function pcb_rq_register(): void
{
    register_post_type('pcb_request', array(
        'labels' => array(
            'name'          => 'Заявки',
            'singular_name' => 'Заявка',
            'menu_name'     => 'Заявки',
            'all_items'     => 'Все заявки',
            'edit_item'     => 'Заявка',
            'search_items'  => 'Найти заявку',
            'not_found'     => 'Заявок нет',
        ),
        'public'             => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => false,
        'menu_icon'          => 'dashicons-email-alt',
        'menu_position'      => 25,
        'supports'           => array('title'),
        'capability_type'    => array('pcb_request', 'pcb_requests'),
        'map_meta_cap'       => true,
        'capabilities'       => array('create_posts' => 'do_not_allow'),
    ));
}
add_action('init', 'pcb_rq_register');

/* Права на заявки — администратору (роль «менеджер» добавится вместе с /panel). */
function pcb_rq_caps(): void
{
    if (get_option('pcb_request_caps') === '1') {
        return;
    }
    $role = get_role('administrator');
    if ($role) {
        foreach (array('edit_pcb_requests', 'edit_others_pcb_requests', 'read_private_pcb_requests',
                     'edit_private_pcb_requests', 'delete_pcb_requests', 'delete_private_pcb_requests',
                     'delete_others_pcb_requests', 'read_pcb_requests') as $cap) {
            $role->add_cap($cap);
        }
    }
    update_option('pcb_request_caps', '1', false);
}
add_action('init', 'pcb_rq_caps', 20);


/* =================================
   Приём заявки: POST /wp-json/pcb/v1/request
   ================================= */

add_action('rest_api_init', function () {
    register_rest_route('pcb/v1', '/request', array(
        'methods'             => 'POST',
        'callback'            => 'pcb_rq_submit',
        'permission_callback' => '__return_true',
    ));
});

function pcb_rq_error(string $message, int $status = 400): WP_Error
{
    return new WP_Error('pcb_request', $message, array('status' => $status));
}

function pcb_rq_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function pcb_rq_submit(WP_REST_Request $req)
{
    $p = $req->get_body_params();
    $mail = 'info@pcb-vostok.ru';

    // ловушка для ботов: скрытое поле должно остаться пустым — отвечаем «успехом», ничего не сохраняя
    if (!empty($p['website'])) {
        return rest_ensure_response(array('success' => true, 'number' => ''));
    }

    // не чаще PCB_RQ_RATE_LIMIT заявок с IP за PCB_RQ_RATE_TTL
    $ip = pcb_rq_ip();
    $rk = 'pcb_rq_rate_' . md5($ip);
    $count = (int) get_transient($rk);
    if ($count >= PCB_RQ_RATE_LIMIT) {
        return pcb_rq_error('Слишком много заявок подряд. Попробуйте через несколько минут или напишите нам на почту ' . $mail . '.', 429);
    }

    // поля
    $email = sanitize_email(isset($p['email']) ? wp_unslash($p['email']) : '');
    if (!is_email($email)) {
        return pcb_rq_error('Укажите почту в формате имя@компания.ru.');
    }
    if (empty($p['consent'])) {
        return pcb_rq_error('Без согласия на обработку персональных данных отправить заявку нельзя.');
    }
    $field = function (string $k, int $max = 200) use ($p): string {
        $v = isset($p[$k]) ? sanitize_text_field(wp_unslash($p[$k])) : '';
        return mb_substr($v, 0, $max);
    };
    $phone   = $field('phone', 40);
    $name    = $field('name', 120);
    $company = $field('company', 200);
    $service = isset($p['service']) && isset(PCB_RQ_SERVICES[$p['service']]) ? $p['service'] : 'other';
    $source  = isset($p['source']) && $p['source'] === 'calc' ? 'calc' : 'home';
    $message = isset($p['message']) ? mb_substr(sanitize_textarea_field(wp_unslash($p['message'])), 0, 20000) : '';
    $calc    = array();
    if (!empty($p['calc_data'])) {
        $decoded = json_decode(wp_unslash($p['calc_data']), true);
        if (is_array($decoded)) {
            $calc = $decoded;
        }
    }

    // капча
    $captcha = pcb_rq_check_captcha(isset($p['smart-token']) ? (string) wp_unslash($p['smart-token']) : '', $ip);
    if ($captcha === 'failed') {
        return pcb_rq_error('Не пройдена проверка «Я не робот». Отметьте её ещё раз и отправьте заявку.');
    }

    // файлы — проверяем до сохранения чего-либо
    $files = pcb_rq_collect_files($req->get_file_params());
    if (is_wp_error($files)) {
        return $files;
    }

    set_transient($rk, $count + 1, PCB_RQ_RATE_TTL);

    // номер заявки: ГГГГ-NNNN, счётчик по году
    $year = wp_date('Y');
    $key  = 'pcb_request_counter_' . $year;
    $n    = (int) get_option($key, 0) + 1;
    update_option($key, $n, false);
    $number = sprintf('%s-%04d', $year, $n);

    $post_id = wp_insert_post(array(
        'post_type'    => 'pcb_request',
        'post_status'  => 'private',
        'post_title'   => 'Заявка ' . $number . ' — ' . ($company !== '' ? $company : $email),
        'post_content' => $message,
    ), true);
    if (is_wp_error($post_id)) {
        return pcb_rq_error('Не удалось сохранить заявку. Попробуйте ещё раз или напишите нам на почту ' . $mail . '.', 500);
    }

    $stored = pcb_rq_store_files($files, (int) $post_id, $number);

    $meta = array(
        '_pcb_number'   => $number,
        '_pcb_status'   => 'new',
        '_pcb_email'    => $email,
        '_pcb_phone'    => $phone,
        '_pcb_name'     => $name,
        '_pcb_company'  => $company,
        '_pcb_service'  => $service,
        '_pcb_source'   => $source,
        '_pcb_files'    => $stored,
        '_pcb_calc'     => $calc,
        '_pcb_captcha'  => $captcha,
        '_pcb_page'     => esc_url_raw(isset($p['page']) ? wp_unslash($p['page']) : ''),
        '_pcb_referrer' => esc_url_raw(isset($p['referrer']) ? wp_unslash($p['referrer']) : ''),
        '_pcb_utm'      => pcb_rq_utm(isset($p['page']) ? (string) wp_unslash($p['page']) : ''),
        '_pcb_consent'  => array(
            'time'    => current_time('mysql'),
            'ip'      => $ip,
            'ua'      => mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', 0, 300),
            'policy'  => home_url('/politics#privacy'),
            'consent' => home_url('/politics#consent'),
            'version' => pcb_rq_const('PCB_POLICY_VERSION', '2026-10'),
        ),
    );
    foreach ($meta as $k => $v) {
        update_post_meta($post_id, $k, $v);
    }

    pcb_rq_notify((int) $post_id);
    pcb_rq_autoreply((int) $post_id);

    return rest_ensure_response(array('success' => true, 'number' => $number));
}

/* Yandex SmartCaptcha: 'ok' | 'failed' | 'unchecked' (сервис недоступен) | 'disabled' (нет ключа) */
function pcb_rq_check_captcha(string $token, string $ip): string
{
    $secret = pcb_rq_const('PCB_SMARTCAPTCHA_SECRET');
    if ($secret === '') {
        return 'disabled';
    }
    if ($token === '') {
        return 'failed';
    }
    $res = wp_remote_post('https://smartcaptcha.yandexcloud.net/validate', array(
        'timeout' => 5,
        'body'    => array('secret' => $secret, 'token' => $token, 'ip' => $ip),
    ));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        // при недоступности сервиса заявку не теряем, а помечаем
        return 'unchecked';
    }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return (is_array($body) && isset($body['status']) && $body['status'] === 'ok') ? 'ok' : 'failed';
}

function pcb_rq_utm(string $url): array
{
    $q = array();
    $query = wp_parse_url($url, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $q);
    }
    $out = array();
    foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term') as $k) {
        if (!empty($q[$k])) {
            $out[$k] = sanitize_text_field($q[$k]);
        }
    }
    return $out;
}


/* =================================
   Файлы
   ================================= */

/* Исполняемые файлы и скрипты не принимаем; остальное (Gerber с любыми расширениями, архивы, PDF…) — да. */
function pcb_rq_blocked(string $name): bool
{
    return (bool) preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|bash|exe|msi|bat|cmd|com|scr|ps1|vbs|js|jar|dll|htaccess|htpasswd)$/i', $name)
        || strpos($name, "\0") !== false;
}

function pcb_rq_collect_files(array $params)
{
    if (empty($params['files']) || !is_array($params['files']['name'])) {
        return array();
    }
    $f = $params['files'];
    $out = array();
    $total = 0;
    foreach ($f['name'] as $i => $raw) {
        $err = (int) $f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = sanitize_file_name(wp_basename((string) $raw));
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return pcb_rq_error('Файл «' . $name . '» слишком большой. Пришлите его на почту info@pcb-vostok.ru или ссылкой.', 413);
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) {
            return pcb_rq_error('Не удалось принять файл «' . $name . '». Попробуйте ещё раз.');
        }
        if (pcb_rq_blocked($name)) {
            return pcb_rq_error('Файл «' . $name . '»: такой тип файлов не принимаем — упакуйте проект в архив.');
        }
        $total += (int) $f['size'][$i];
        $out[] = array('name' => $name, 'size' => (int) $f['size'][$i], 'tmp' => $f['tmp_name'][$i]);
    }
    if (count($out) > PCB_RQ_MAX_FILES) {
        return pcb_rq_error('Не больше ' . PCB_RQ_MAX_FILES . ' файлов — упакуйте их в один архив.');
    }
    if ($total > PCB_RQ_MAX_TOTAL) {
        return pcb_rq_error('Файлы вместе больше 50 МБ. Пришлите их на почту info@pcb-vostok.ru или ссылкой.', 413);
    }
    return $out;
}

function pcb_rq_storage(): string
{
    $dir = pcb_rq_const('PCB_REQUEST_STORAGE');
    if ($dir === '') {
        $up  = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . 'pcb-requests';
    }
    $dir = untrailingslashit($dir);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    // если папка внутри веб-корня — закрываем прямой доступ (Apache). Для nginx — правило в конфиге сервера.
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!file_exists($dir . '/index.php')) {
        @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
    }
    return $dir;
}

function pcb_rq_store_files(array $files, int $post_id, string $number): array
{
    if (!$files) {
        return array();
    }
    $dir = pcb_rq_storage() . '/' . $number;
    wp_mkdir_p($dir);
    $stored = array();
    foreach ($files as $file) {
        $key  = wp_generate_password(24, false, false);
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $disk = $key . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) . '.bin' : '.bin');
        if (@move_uploaded_file($file['tmp'], $dir . '/' . $disk)) {
            @chmod($dir . '/' . $disk, 0640);
            $stored[] = array('key' => $key, 'name' => $file['name'], 'size' => $file['size'], 'path' => $number . '/' . $disk);
        }
    }
    return $stored;
}

/* Ссылка на скачивание файла заявки (только для залогиненных с правом read_pcb_requests) */
function pcb_rq_file_url(int $post_id, string $key): string
{
    return add_query_arg(array('action' => 'pcb_request_file', 'id' => $post_id, 'f' => $key), admin_url('admin-post.php'));
}

add_action('admin_post_pcb_request_file', function () {
    if (!current_user_can('read_pcb_requests')) {
        wp_die('Нет доступа к файлам заявок.', 403);
    }
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $key = isset($_GET['f']) ? sanitize_key(wp_unslash($_GET['f'])) : '';
    $files = (array) get_post_meta($id, '_pcb_files', true);
    foreach ($files as $file) {
        if (isset($file['key']) && strtolower($file['key']) === $key) {
            $path = pcb_rq_storage() . '/' . $file['path'];
            if (!is_file($path)) {
                break;
            }
            nocache_headers();
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['name']));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }
    wp_die('Файл не найден.', 404);
});
add_action('admin_post_nopriv_pcb_request_file', function () {
    auth_redirect();
});


/* =================================
   Письма
   ================================= */

function pcb_rq_mail_from(): string
{
    return pcb_rq_const('PCB_MAIL_FROM', pcb_rq_const('PCB_SMTP_USER', 'info@pcb-vostok.ru'));
}

/* SMTP вместо mail(): включается, если в wp-config.php задан PCB_SMTP_HOST */
add_action('phpmailer_init', function ($mailer) {
    if (pcb_rq_const('PCB_SMTP_HOST') === '') {
        return;
    }
    $mailer->isSMTP();
    $mailer->Host       = PCB_SMTP_HOST;
    $mailer->Port       = (int) pcb_rq_const('PCB_SMTP_PORT', 465);
    $mailer->SMTPSecure = pcb_rq_const('PCB_SMTP_SECURE', 'ssl');
    $mailer->SMTPAuth   = true;
    $mailer->Username   = pcb_rq_const('PCB_SMTP_USER');
    $mailer->Password   = pcb_rq_const('PCB_SMTP_PASS');
    $mailer->CharSet    = 'UTF-8';
});

function pcb_rq_headers(string $reply_to): array
{
    return array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: PCB Восток <' . pcb_rq_mail_from() . '>',
        'Reply-To: ' . $reply_to,
    );
}

function pcb_rq_notify(int $id): void
{
    $m = function ($k) use ($id) { return get_post_meta($id, '_pcb_' . $k, true); };
    $number = $m('number');
    $service = PCB_RQ_SERVICES[$m('service')] ?? '';
    $lines = array(
        'Заявка ' . $number . ' · ' . ($m('source') === 'calc' ? 'со страницы калькулятора' : 'с главной'),
        '',
        'Почта:    ' . $m('email'),
        'Телефон:  ' . ($m('phone') ?: '—'),
        'Имя:      ' . ($m('name') ?: '—'),
        'Компания: ' . ($m('company') ?: '—'),
        'Услуга:   ' . $service,
        '',
        'Описание задачи:',
        get_post_field('post_content', $id) ?: '—',
        '',
    );
    $files = (array) $m('files');
    if ($files) {
        $lines[] = 'Файлы (скачиваются после входа в админку):';
        foreach ($files as $f) {
            $lines[] = '  ' . $f['name'] . ' (' . size_format($f['size']) . '): ' . pcb_rq_file_url($id, $f['key']);
        }
        $lines[] = '';
    } else {
        $lines[] = 'Файлы не приложены.';
        $lines[] = '';
    }
    $cap = $m('captcha');
    if ($cap !== 'ok') {
        $lines[] = 'Проверка капчи: ' . ($cap === 'unchecked' ? 'сервис Яндекса не ответил — заявка принята без проверки' : 'не настроена (нет PCB_SMARTCAPTCHA_SECRET)');
        $lines[] = '';
    }
    $lines[] = 'Открыть заявку: ' . admin_url('post.php?post=' . $id . '&action=edit');

    $to = array_filter(array_map('trim', explode(',', pcb_rq_const('PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru'))));
    wp_mail($to, 'Новая заявка ' . $number . ' · ' . $service, implode("\n", $lines), pcb_rq_headers($m('email')));
}

function pcb_rq_autoreply(int $id): void
{
    $email  = get_post_meta($id, '_pcb_email', true);
    $name   = get_post_meta($id, '_pcb_name', true);
    $number = get_post_meta($id, '_pcb_number', true);
    $notify = trim(explode(',', pcb_rq_const('PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru'))[0]);
    $body = implode("\n", array(
        ($name !== '' ? 'Здравствуйте, ' . $name . '!' : 'Здравствуйте!'),
        '',
        'Мы получили вашу заявку ' . $number . '. Изучим файлы и свяжемся с вами.',
        'Если нужно что-то добавить — просто ответьте на это письмо.',
        '',
        'PCB Восток',
        home_url('/'),
    ));
    wp_mail($email, 'Заявка ' . $number . ' получена — PCB Восток', $body, pcb_rq_headers($notify));
}


/* =================================
   Админка: колонки списка и карточка заявки
   ================================= */

add_filter('manage_pcb_request_posts_columns', function ($cols) {
    return array(
        'cb'          => $cols['cb'],
        'title'       => 'Заявка',
        'pcb_status'  => 'Статус',
        'pcb_contact' => 'Контакты',
        'pcb_service' => 'Услуга',
        'pcb_source'  => 'Откуда',
        'pcb_files'   => 'Файлы',
        'date'        => 'Дата',
    );
});

add_action('manage_pcb_request_posts_custom_column', function ($col, $id) {
    switch ($col) {
        case 'pcb_status':
            echo esc_html(PCB_RQ_STATUSES[get_post_meta($id, '_pcb_status', true)] ?? '—');
            break;
        case 'pcb_contact':
            echo esc_html(get_post_meta($id, '_pcb_email', true));
            $ph = get_post_meta($id, '_pcb_phone', true);
            if ($ph) {
                echo '<br>' . esc_html($ph);
            }
            break;
        case 'pcb_service':
            echo esc_html(PCB_RQ_SERVICES[get_post_meta($id, '_pcb_service', true)] ?? '—');
            break;
        case 'pcb_source':
            echo get_post_meta($id, '_pcb_source', true) === 'calc' ? 'Калькулятор' : 'Главная';
            break;
        case 'pcb_files':
            echo (int) count((array) get_post_meta($id, '_pcb_files', true));
            break;
    }
}, 10, 2);

add_action('add_meta_boxes_pcb_request', function () {
    add_meta_box('pcb_request_details', 'Заявка', 'pcb_rq_metabox', 'pcb_request', 'normal', 'high');
});

function pcb_rq_metabox($post): void
{
    $id = $post->ID;
    $m = function ($k) use ($id) { return get_post_meta($id, '_pcb_' . $k, true); };
    wp_nonce_field('pcb_rq_status', 'pcb_rq_nonce');
    echo '<p><label><b>Статус</b> <select name="pcb_status">';
    foreach (PCB_RQ_STATUSES as $k => $label) {
        printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($m('status'), $k, false), esc_html($label));
    }
    echo '</select></label></p><table class="widefat striped"><tbody>';
    $rows = array(
        'Номер'    => $m('number'),
        'Почта'    => $m('email'),
        'Телефон'  => $m('phone'),
        'Имя'      => $m('name'),
        'Компания' => $m('company'),
        'Услуга'   => PCB_RQ_SERVICES[$m('service')] ?? '',
        'Откуда'   => $m('source') === 'calc' ? 'Калькулятор' : 'Главная',
        'Страница' => $m('page'),
        'Переход с' => $m('referrer'),
        'Капча'    => $m('captcha'),
    );
    foreach ($rows as $label => $value) {
        printf('<tr><th style="width:140px">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
    }
    echo '</tbody></table><h4>Описание задачи</h4><pre style="white-space:pre-wrap">' . esc_html($post->post_content) . '</pre>';
    $files = (array) $m('files');
    echo '<h4>Файлы</h4>';
    if (!$files) {
        echo '<p>Не приложены.</p>';
    } else {
        echo '<ul>';
        foreach ($files as $f) {
            printf('<li><a href="%s">%s</a> — %s</li>', esc_url(pcb_rq_file_url($id, $f['key'])), esc_html($f['name']), esc_html(size_format($f['size'])));
        }
        echo '</ul>';
    }
    $consent = (array) $m('consent');
    if ($consent) {
        printf('<p style="color:#666">Согласие на обработку ПДн: %s, IP %s, политика версии %s.</p>',
            esc_html($consent['time'] ?? ''), esc_html($consent['ip'] ?? ''), esc_html($consent['version'] ?? ''));
    }
}

add_action('save_post_pcb_request', function ($id) {
    if (!isset($_POST['pcb_rq_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['pcb_rq_nonce'])), 'pcb_rq_status')) {
        return;
    }
    if (!current_user_can('edit_post', $id) || !isset($_POST['pcb_status'])) {
        return;
    }
    $s = sanitize_key(wp_unslash($_POST['pcb_status']));
    if (isset(PCB_RQ_STATUSES[$s])) {
        update_post_meta($id, '_pcb_status', $s);
    }
});
