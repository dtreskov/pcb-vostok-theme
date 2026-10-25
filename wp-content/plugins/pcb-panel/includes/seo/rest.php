<?php
/**
 * REST вкладки SEO: /wp-json/pcb-panel/v1/seo/…  Любые изменения — pcb_seo_edit (администратор).
 * Каждое изменение пишется в общий журнал
 * (entity = seo:<id страницы> | seo:site | seo:robots | seo:maint | seo:markup | seo:semantic | seo:cards).
 */

defined( 'ABSPATH' ) || exit;

/** Запись «было → стало» о SEO в общий журнал. */
function pcb_crm_log_seo( $what, $label, $old, $new ) {
	pcb_cl_log( 'seo:' . $what, 'cl_field', '', wp_json_encode( array( 'label' => $label, 'old' => (string) $old, 'new' => (string) $new ), JSON_UNESCAPED_UNICODE ) );
}

/** Запись о SEO в журнал готовой строкой (без «было → стало»). */
function pcb_crm_log_seo_text( $what, $text ) {
	pcb_cl_log( 'seo:' . $what, 'cl_field', '', wp_json_encode( array( 'text' => (string) $text ), JSON_UNESCAPED_UNICODE ) );
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
			// название страницы (post_title): адрес страницы (slug) не меняется
			if ( array_key_exists( 'page_title', $p ) ) {
				$pt = pcb_seo_text( $p['page_title'], 120 );
				if ( '' === $pt ) {
					return pcb_seo_err( 'Название страницы не может быть пустым.', array( 'page_title' => 'Заполните название.' ) );
				}
				$was = get_post_field( 'post_title', $id );
				if ( $pt !== $was ) {
					$res = wp_update_post( array( 'ID' => $id, 'post_title' => $pt ), true );
					if ( is_wp_error( $res ) ) {
						return pcb_seo_err( $res->get_error_message() );
					}
					pcb_crm_log_seo( $id, 'Название страницы', $was, $pt );
				}
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
			$upd = array( 'title' => $title, 'text' => $text );
			if ( array_key_exists( 'sign', $p ) ) {
				$sign = trim( mb_substr( sanitize_textarea_field( (string) $p['sign'] ), 0, 500 ) );
				if ( $sign !== pcb_maint_sign() ) {
					pcb_crm_log_seo( 'maint', 'Подпись под текстом', pcb_seo_log_val( str_replace( "\n", ' · ', pcb_maint_sign() ) ), pcb_seo_log_val( str_replace( "\n", ' · ', $sign ) ) );
					$upd['sign'] = $sign;
				}
			}
			pcb_maint_update( $upd );
			if ( ! empty( $p['on_present'] ) ) {
				pcb_maint_set( ! empty( $p['on'] ) );
			}
			return array( 'ok' => true );
		},
	) );

	// анкета разметки: org_description, svc{1..3}_name|desc, faq_on (+faq_present), webapp_on (+webapp_present), webapp_page, webapp_name, webapp_desc
	register_rest_route( $ns, '/seo/markup', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$p    = (array) $r->get_params();
			$L    = PCB_SEO_MARKUP_LEN;
			$old  = pcb_seo_markup_get();
			$new  = $old;
			$errs = array();
			if ( array_key_exists( 'org_description', $p ) ) {
				$new['org_description'] = pcb_seo_text( $p['org_description'], $L['org_description'] );
			}
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( array_key_exists( "svc{$i}_name", $p ) ) {
					$new['services'][ $i - 1 ]['name'] = pcb_seo_text( $p[ "svc{$i}_name" ], $L['svc_name'] );
				}
				if ( array_key_exists( "svc{$i}_desc", $p ) ) {
					$new['services'][ $i - 1 ]['description'] = pcb_seo_text( $p[ "svc{$i}_desc" ], $L['svc_desc'] );
				}
				if ( '' === $new['services'][ $i - 1 ]['name'] && '' !== $new['services'][ $i - 1 ]['description'] ) {
					$errs[ "svc{$i}_name" ] = 'У услуги с описанием нужно название.';
				}
			}
			if ( ! empty( $p['webapp_present'] ) ) {
				$new['webapp_on'] = empty( $p['webapp_on'] ) ? 0 : 1;
			}
			if ( ! empty( $p['faq_present'] ) ) {
				$new['faq_on'] = empty( $p['faq_on'] ) ? 0 : 1;
			}
			if ( array_key_exists( 'webapp_page', $p ) ) {
				$pg = (int) $p['webapp_page'];
				if ( $pg && ! pcb_seo_is_page( $pg ) ) {
					$errs['webapp_page'] = 'Страница не найдена.';
				}
				$new['webapp_page'] = $pg;
			}
			foreach ( array( 'webapp_name', 'webapp_desc' ) as $k ) {
				if ( array_key_exists( $k, $p ) ) {
					$new[ $k ] = pcb_seo_text( $p[ $k ], $L[ $k ] );
				}
			}
			if ( $new['webapp_on'] && '' === $new['webapp_name'] ) {
				$errs['webapp_name'] = 'Нужно название калькулятора.';
			}
			if ( $errs ) {
				return pcb_seo_err( implode( ' ', $errs ), $errs );
			}
			// журнал: «было → стало» по каждому изменённому полю
			$log = function ( $label, $a, $b ) {
				if ( (string) $a !== (string) $b ) {
					pcb_crm_log_seo( 'markup', $label, pcb_seo_log_val( $a ), pcb_seo_log_val( $b ) );
				}
			};
			$log( 'Описание компании', $old['org_description'], $new['org_description'] );
			foreach ( $new['services'] as $i => $s ) {
				$log( 'Услуга ' . ( $i + 1 ) . ': название', $old['services'][ $i ]['name'], $s['name'] );
				$log( 'Услуга ' . ( $i + 1 ) . ': описание', $old['services'][ $i ]['description'], $s['description'] );
			}
			$log( 'Вопросы из FAQ в разметке', $old['faq_on'] ? 'включены' : 'выключены', $new['faq_on'] ? 'включены' : 'выключены' );
			$log( 'Калькулятор в разметке', $old['webapp_on'] ? 'включён' : 'выключен', $new['webapp_on'] ? 'включён' : 'выключен' );
			$log( 'Калькулятор: страница', $old['webapp_page'] ? get_the_title( $old['webapp_page'] ) : 'по адресу /calculator/', $new['webapp_page'] ? get_the_title( $new['webapp_page'] ) : 'по адресу /calculator/' );
			$log( 'Калькулятор: название', $old['webapp_name'], $new['webapp_name'] );
			$log( 'Калькулятор: описание', $old['webapp_desc'], $new['webapp_desc'] );
			pcb_seo_markup_update( $new );
			pcb_seo_purge();
			return array( 'ok' => true );
		},
	) );

	// семантика: phrases, g_<n>_name, g_<n>_words (группы минус-слов; пустая группа удаляется)
	register_rest_route( $ns, '/seo/semantic', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$p      = (array) $r->get_params();
			$groups = array();
			$keys   = array();
			foreach ( $p as $k => $v ) {
				if ( preg_match( '/^g_(\d+)_name$/', $k, $m ) ) {
					$keys[] = (int) $m[1];
				}
			}
			sort( $keys );
			$total = 0;
			foreach ( $keys as $n ) {
				$words = pcb_seo_sem_lines( $p[ "g_{$n}_words" ] ?? '' );
				$name  = pcb_seo_text( $p[ "g_{$n}_name" ] ?? '', 60 );
				if ( ! $words && '' === $name ) {
					continue;
				}
				$groups[] = array( 'name' => '' !== $name ? $name : 'Без названия', 'words' => implode( "\n", $words ) );
				$total   += count( $words );
			}
			$phrases = pcb_seo_sem_lines( $p['phrases'] ?? '' );
			if ( count( $groups ) > PCB_SEO_SEM_GROUPS ) {
				return pcb_seo_err( 'Групп минус-слов не больше ' . PCB_SEO_SEM_GROUPS . '.' );
			}
			if ( $total + count( $phrases ) > PCB_SEO_SEM_WORDS ) {
				return pcb_seo_err( 'Слишком длинные списки: не больше ' . PCB_SEO_SEM_WORDS . ' строк вместе.' );
			}
			$old    = pcb_seo_semantic_get();
			$new    = array( 'phrases' => implode( "\n", $phrases ), 'groups' => $groups );
			$o_ph   = pcb_seo_sem_lines( $old['phrases'] );
			$o_mw   = pcb_seo_minus_words( $old );
			$n_mw   = pcb_seo_minus_words( $new );
			$diff   = function ( $a, $b ) {
				$add = array_values( array_diff( $b, $a ) );
				$del = array_values( array_diff( $a, $b ) );
				$s   = array();
				if ( $add ) {
					$s[] = 'добавлены: ' . implode( ', ', $add );
				}
				if ( $del ) {
					$s[] = 'убраны: ' . implode( ', ', $del );
				}
				return implode( '; ', $s );
			};
			if ( $d = $diff( $o_ph, $phrases ) ) {
				pcb_crm_log_seo_text( 'semantic', pcb_seo_log_val( 'Ключевые фразы: ' . $d ) );
			}
			if ( $d = $diff( $o_mw, $n_mw ) ) {
				pcb_crm_log_seo_text( 'semantic', pcb_seo_log_val( 'Минус-слова: ' . $d ) );
			}
			if ( wp_list_pluck( $old['groups'], 'name' ) !== wp_list_pluck( $groups, 'name' ) ) {
				pcb_crm_log_seo( 'semantic', 'Группы минус-слов', pcb_seo_log_val( implode( ', ', wp_list_pluck( $old['groups'], 'name' ) ) ), pcb_seo_log_val( implode( ', ', wp_list_pluck( $groups, 'name' ) ) ) );
			}
			pcb_seo_semantic_update( $new );
			return array( 'ok' => true );
		},
	) );

	// карточки организаций: <сервис>_url
	register_rest_route( $ns, '/seo/cards', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$p     = (array) $r->get_params();
			$cards = pcb_seo_cards_get();
			$svc   = pcb_seo_card_services();
			$errs  = array();
			foreach ( $svc as $k => $info ) {
				if ( ! array_key_exists( $k . '_url', $p ) ) {
					continue;
				}
				$url = pcb_seo_card_url( $k, $p[ $k . '_url' ] );
				if ( false === $url ) {
					$errs[ $k . '_url' ] = 'Нужна ссылка на карточку в ' . $info['label'] . '.';
					continue;
				}
				if ( $url !== $cards[ $k ]['url'] ) {
					pcb_crm_log_seo( 'cards', $info['label'] . ': ссылка на карточку', $cards[ $k ]['url'], $url );
					$cards[ $k ]['url'] = $url;
				}
			}
			if ( $errs ) {
				return pcb_seo_err( implode( ' ', $errs ), $errs );
			}
			pcb_seo_cards_update( $cards );
			pcb_seo_purge(); // ссылки идут в разметку главной (sameAs)
			return array( 'ok' => true );
		},
	) );

	// отметка сверки карточки: { card: yandex | gis }
	register_rest_route( $ns, '/seo/cards/check', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $r ) {
			$k     = (string) $r['card'];
			$svc   = pcb_seo_card_services();
			$cards = pcb_seo_cards_get();
			if ( ! isset( $svc[ $k ] ) ) {
				return pcb_seo_err( 'Неизвестная карточка.' );
			}
			$cards[ $k ]['checked'] = current_time( 'mysql' );
			$cards[ $k ]['by']      = get_current_user_id();
			pcb_seo_cards_update( $cards );
			pcb_crm_log_seo_text( 'cards', $svc[ $k ]['label'] . ': сверено — данные совпадают с сайтом' );
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
