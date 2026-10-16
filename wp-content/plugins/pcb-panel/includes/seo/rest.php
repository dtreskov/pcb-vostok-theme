<?php
/**
 * REST вкладки SEO: /wp-json/pcb-panel/v1/seo/…  Любые изменения — pcb_seo_edit (администратор).
 * Каждое изменение пишется в общий журнал (entity = seo:<id страницы> | seo:site | seo:robots | seo:maint).
 */

defined( 'ABSPATH' ) || exit;

/** Запись «было → стало» о SEO в общий журнал. */
function pcb_crm_log_seo( $what, $label, $old, $new ) {
	pcb_cl_log( 'seo:' . $what, 'cl_field', '', wp_json_encode( array( 'label' => $label, 'old' => (string) $old, 'new' => (string) $new ), JSON_UNESCAPED_UNICODE ) );
}

function pcb_seo_err( $msg, $fields = array(), $status = 400 ) {
	return new WP_Error( 'pcb_seo', $msg, array( 'status' => $status, 'fields' => $fields ) );
}

/** Короткое значение для журнала. */
function pcb_seo_log_val( $v ) {
	$v = (string) $v;
	return mb_strlen( $v ) > 120 ? mb_substr( $v, 0, 117 ) . '…' : $v;
}

function pcb_seo_text( $v, $max ) {
	$v = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
	return mb_substr( $v, 0, $max );
}

function pcb_seo_check_image( $id ) {
	$id = (int) $id;
	return 0 === $id || ( wp_attachment_is_image( $id ) );
}

add_action( 'rest_api_init', function () {
	$ns   = 'pcb-panel/v1';
	$edit = function () {
		return current_user_can( 'pcb_seo_edit' );
	};

	// страница: { title, description, og, noindex, noindex_present }
	register_rest_route( $ns, '/seo/page/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$id = (int) $r['id'];
			if ( ! pcb_seo_is_page( $id ) ) {
				return pcb_seo_err( 'Страница не найдена.', array(), 404 );
			}
			$p   = (array) $r->get_params();
			$new = array(
				'title'       => pcb_seo_text( $p['title'] ?? '', 120 ),
				'description' => pcb_seo_text( $p['description'] ?? '', 300 ),
				'og'          => (int) ( $p['og'] ?? 0 ),
				'noindex'     => ! empty( $p['noindex'] ),
			);
			if ( ! pcb_seo_check_image( $new['og'] ) ) {
				return pcb_seo_err( 'Картинка не найдена в медиатеке.', array( 'og' => 'Загрузите картинку заново.' ) );
			}
			if ( (int) get_option( 'page_on_front' ) === $id && $new['noindex'] ) {
				return pcb_seo_err( 'Главную нельзя закрыть от индексации — для этого есть режим техработ.', array( 'noindex' => 'Главная всегда в индексе.' ) );
			}
			$old    = pcb_seo_meta( $id );
			$labels = array( 'title' => 'Title', 'description' => 'Description', 'og' => 'Картинка для мессенджеров', 'noindex' => 'Индексация' );
			foreach ( $new as $k => $v ) {
				// меняются только присланные поля; галочка — по признаку noindex_present (снятую браузер не шлёт)
				if ( 'noindex' === $k ? empty( $p['noindex_present'] ) : ! array_key_exists( $k, $p ) ) {
					continue;
				}
				if ( (string) $old[ $k ] === (string) $v ) {
					continue;
				}
				if ( 'noindex' === $k ) {
					$v ? update_post_meta( $id, PCB_SEO_META[ $k ], '1' ) : delete_post_meta( $id, PCB_SEO_META[ $k ] );
					pcb_crm_log_seo( $id, $labels[ $k ], $old[ $k ] ? 'закрыта' : 'открыта', $v ? 'закрыта' : 'открыта' );
					continue;
				}
				( '' === (string) $v || 0 === $v ) ? delete_post_meta( $id, PCB_SEO_META[ $k ] ) : update_post_meta( $id, PCB_SEO_META[ $k ], $v );
				if ( 'og' === $k ) {
					pcb_crm_log_seo( $id, $labels[ $k ], $old[ $k ] ? 'своя' : 'общая', $v ? 'своя' : 'общая' );
				} else {
					pcb_crm_log_seo( $id, $labels[ $k ], pcb_seo_log_val( $old[ $k ] ), pcb_seo_log_val( $v ) );
				}
			}
			pcb_seo_purge( $id );
			return array( 'ok' => true );
		},
	) );

	// данные сайта
	register_rest_route( $ns, '/seo/site', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$p      = (array) $r->get_params();
			$fields = pcb_seo_site_fields();
			$vals   = array();
			$errs   = array();
			foreach ( $fields as $k => $label ) {
				if ( ! array_key_exists( $k, $p ) ) {
					continue;
				}
				$vals[ $k ] = in_array( $k, array( 'logo_id', 'og_id' ), true ) ? (int) $p[ $k ] : pcb_seo_text( $p[ $k ], 200 );
			}
			if ( isset( $vals['brand'] ) && '' === $vals['brand'] ) {
				$errs['brand'] = 'Название нужно для заголовков и разметки.';
			}
			if ( isset( $vals['email'] ) && '' !== $vals['email'] && ! is_email( $vals['email'] ) ) {
				$errs['email'] = 'Проверьте адрес почты.';
			}
			if ( isset( $vals['phone'] ) ) {
				$ph = pcb_crm_norm_phone( $vals['phone'] );
				if ( is_wp_error( $ph ) ) {
					$errs['phone'] = $ph->get_error_message();
				} else {
					$vals['phone'] = $ph;
				}
			}
			if ( isset( $vals['postal_code'] ) && '' !== $vals['postal_code'] && ! preg_match( '/^\d{6}$/', $vals['postal_code'] ) ) {
				$errs['postal_code'] = 'Индекс — 6 цифр.';
			}
			foreach ( array( 'logo_id', 'og_id' ) as $k ) {
				if ( isset( $vals[ $k ] ) && ! pcb_seo_check_image( $vals[ $k ] ) ) {
					$errs[ $k ] = 'Картинка не найдена в медиатеке.';
				}
			}
			if ( $errs ) {
				return pcb_seo_err( implode( ' ', $errs ), $errs );
			}
			$old = pcb_seo_get();
			foreach ( $vals as $k => $v ) {
				if ( (string) $old[ $k ] === (string) $v ) {
					continue;
				}
				$is_img = in_array( $k, array( 'logo_id', 'og_id' ), true );
				pcb_crm_log_seo( 'site', $fields[ $k ], $is_img ? ( $old[ $k ] ? 'своя' : 'по умолчанию' ) : $old[ $k ], $is_img ? ( $v ? 'своя' : 'по умолчанию' ) : $v );
			}
			pcb_seo_update( $vals );
			pcb_seo_purge();
			return array( 'ok' => true );
		},
	) );

	// дополнительные строки robots.txt
	register_rest_route( $ns, '/seo/robots', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$lines = preg_split( '/\R/', (string) $r['robots_extra'] );
			$out   = array();
			foreach ( $lines as $i => $l ) {
				$l = trim( wp_strip_all_tags( $l ) );
				if ( '' === $l ) {
					continue;
				}
				if ( '#' !== $l[0] && ! preg_match( '/^(User-agent|Disallow|Allow|Clean-param|Crawl-delay|Sitemap)\s*:/i', $l ) ) {
					return pcb_seo_err( 'Строка ' . ( $i + 1 ) . ': неизвестная директива «' . mb_substr( $l, 0, 40 ) . '». Допустимы User-agent, Disallow, Allow, Clean-param, Crawl-delay, Sitemap и комментарии «#».', array( 'robots_extra' => 'Проверьте строку ' . ( $i + 1 ) . '.' ) );
				}
				if ( preg_match( '/^Disallow\s*:\s*\/\s*$/i', $l ) ) {
					return pcb_seo_err( '«Disallow: /» закроет весь сайт. Для этого есть режим техработ.', array( 'robots_extra' => 'Уберите «Disallow: /».' ) );
				}
				$out[] = $l;
			}
			$new = implode( "\n", $out );
			$old = (string) pcb_seo_get( 'robots_extra' );
			if ( $new !== $old ) {
				pcb_crm_log_seo( 'robots', 'Свои строки robots.txt', pcb_seo_log_val( str_replace( "\n", ' · ', $old ) ), pcb_seo_log_val( str_replace( "\n", ' · ', $new ) ) );
				pcb_seo_update( array( 'robots_extra' => $new ) );
			}
			return array( 'ok' => true );
		},
	) );

	// техработы: { on, on_present, title, text }
	register_rest_route( $ns, '/seo/maintenance', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$p     = (array) $r->get_params();
			$title = pcb_seo_text( $p['title'] ?? '', 120 );
			$text  = trim( mb_substr( sanitize_textarea_field( (string) ( $p['text'] ?? '' ) ), 0, 1000 ) );
			if ( '' === $title ) {
				return pcb_seo_err( 'Нужен заголовок заглушки.', array( 'title' => 'Заполните заголовок.' ) );
			}
			$old = pcb_maint_get();
			if ( $title !== $old['title'] ) {
				pcb_crm_log_seo( 'maint', 'Заголовок заглушки', $old['title'], $title );
			}
			if ( $text !== $old['text'] ) {
				pcb_crm_log_seo( 'maint', 'Текст заглушки', pcb_seo_log_val( $old['text'] ), pcb_seo_log_val( $text ) );
			}
			pcb_maint_update( array( 'title' => $title, 'text' => $text ) );
			if ( ! empty( $p['on_present'] ) ) {
				pcb_maint_set( ! empty( $p['on'] ) );
			}
			return array( 'ok' => true );
		},
	) );

	// новая ссылка предпросмотра — старые перестают работать
	register_rest_route( $ns, '/seo/maintenance/key', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function () {
			pcb_maint_key( true );
			pcb_crm_log_seo( 'maint', 'Ссылка предпросмотра', 'прежняя', 'выпущена новая' );
			return array( 'ok' => true );
		},
	) );

	// загрузка картинки (multipart, поле file) → медиатека
	register_rest_route( $ns, '/seo/image', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$files = $r->get_file_params();
			if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== (int) $files['file']['error'] ) {
				return pcb_seo_err( 'Файл не получен.' );
			}
			if ( $files['file']['size'] > 5 * MB_IN_BYTES ) {
				return pcb_seo_err( 'Картинка больше 5 МБ — уменьшите её.' );
			}
			$type = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
			if ( ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return pcb_seo_err( 'Подойдёт JPG, PNG или WebP: SVG мессенджеры и поисковики не показывают.' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$_FILES['file'] = $files['file'];
			$id = media_handle_upload( 'file', 0 );
			if ( is_wp_error( $id ) ) {
				return pcb_seo_err( $id->get_error_message() );
			}
			$src = wp_get_attachment_image_src( $id, 'full' );
			return array( 'ok' => true, 'id' => $id, 'url' => $src[0], 'w' => (int) $src[1], 'h' => (int) $src[2] );
		},
	) );
} );
