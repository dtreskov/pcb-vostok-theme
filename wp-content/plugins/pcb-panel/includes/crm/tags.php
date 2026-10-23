<?php
/**
 * Метки (0.9.0): свободные короткие ярлыки у заявок, компаний и контактов — «повтор», «срочно», «дилер».
 *
 *   {prefix}pcb_tags  type (request | company | contact), object_id, tag
 *
 * Наборы раздельные: у заявок свои метки, у клиентов (компаний и контактов) — свои. Одна таблица связей —
 * общий код автодополнения, фильтра и поиска. Ставит менеджер (pcb_crm_edit); изменения — в журнал (действие tags).
 * Написание: регистр — как у первой такой метки этого набора («ВЧ» не превратится во «вч» и не задвоится).
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_TAGS_DB_VERSION', '1' );
const PCB_TAG_MAX_LEN = 30; // знаков на метку
const PCB_TAG_MAX_PER = 10; // меток у одной записи

function pcb_tags_table() {
	global $wpdb;
	return $wpdb->prefix . 'pcb_tags';
}

function pcb_tags_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . pcb_tags_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		type varchar(10) NOT NULL,
		object_id bigint(20) unsigned NOT NULL,
		tag varchar(60) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY obj_tag (type,object_id,tag),
		KEY tag (type,tag)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'pcb_tags_db_version', PCB_TAGS_DB_VERSION, false );
}

add_action( 'init', function () {
	if ( get_option( 'pcb_tags_db_version' ) !== PCB_TAGS_DB_VERSION ) {
		pcb_tags_install();
	}
}, 6 );

/** Тип записи → набор меток: заявки — свой, компании и контакты — общий клиентский. */
function pcb_tags_types() {
	return array( 'request', 'company', 'contact' );
}

function pcb_tags_set_of( $type ) {
	return 'request' === $type ? array( 'request' ) : array( 'company', 'contact' );
}

/** Одна метка: без разметки и лишних пробелов, до PCB_TAG_MAX_LEN знаков; запятая — разделитель, внутри метки её нет. */
function pcb_tag_clean( $raw ) {
	$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( ',', ' ', (string) $raw ) ) ) );
	return mb_substr( $t, 0, PCB_TAG_MAX_LEN );
}

/** Метки одной записи. */
function pcb_tags_get( $type, $id ) {
	$all = pcb_tags_for( $type, array( $id ) );
	return $all[ (int) $id ] ?? array();
}

/** Метки многих записей одним запросом: array( id => array( метки ) ). */
function pcb_tags_for( $type, array $ids ) {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', $ids ) );
	if ( ! $ids || ! in_array( $type, pcb_tags_types(), true ) ) {
		return array();
	}
	$out = array();
	$t   = pcb_tags_table();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, tag FROM {$t} WHERE type = %s AND object_id IN (" . implode( ',', $ids ) . ') ORDER BY id', $type ), ARRAY_A ) as $r ) {
		$out[ (int) $r['object_id'] ][] = $r['tag'];
	}
	return $out;
}

/** Все метки набора с числом записей — для автодополнения и фильтра: array( метка => n ), частые сверху. */
function pcb_tags_all( $type ) {
	global $wpdb;
	$types = pcb_tags_set_of( $type );
	$t     = pcb_tags_table();
	$rows  = (array) $wpdb->get_results( "SELECT tag, COUNT(*) AS n FROM {$t} WHERE type IN ('" . implode( "','", $types ) . "') GROUP BY tag ORDER BY n DESC, tag ASC LIMIT 300", ARRAY_A );
	return wp_list_pluck( $rows, 'n', 'tag' );
}

/** Написание метки, если такая уже есть в наборе (без учёта регистра); иначе — как ввели. */
function pcb_tag_canonical( $type, $tag ) {
	foreach ( array_keys( pcb_tags_all( $type ) ) as $have ) {
		if ( mb_strtolower( (string) $have ) === mb_strtolower( $tag ) ) {
			return (string) $have;
		}
	}
	return $tag;
}

/**
 * Записать метки записи целиком. Возвращает итоговый список или WP_Error.
 * В журнал — что добавлено и что убрано (у заявки — её история, у клиента — его).
 */
function pcb_tags_set( $type, $id, array $tags ) {
	global $wpdb;
	$id = (int) $id;
	if ( ! in_array( $type, pcb_tags_types(), true ) ) {
		return new WP_Error( 'pcb_tags', 'Неизвестный тип записи.', array( 'status' => 400 ) );
	}
	$exists = 'request' === $type ? pcb_crm_is_request( $id ) : (bool) pcb_cl_get( $type, $id );
	if ( ! $exists ) {
		return new WP_Error( 'pcb_tags', 'Запись не найдена.', array( 'status' => 404 ) );
	}
	$new = array();
	foreach ( $tags as $raw ) {
		$tag = pcb_tag_clean( $raw );
		if ( '' === $tag ) {
			continue;
		}
		$tag = pcb_tag_canonical( $type, $tag );
		$key = mb_strtolower( $tag );
		if ( ! isset( $new[ $key ] ) ) {
			$new[ $key ] = $tag;
		}
	}
	if ( count( $new ) > PCB_TAG_MAX_PER ) {
		return new WP_Error( 'pcb_tags', 'Не больше ' . PCB_TAG_MAX_PER . ' меток у одной записи.', array( 'status' => 400 ) );
	}
	$old     = pcb_tags_get( $type, $id );
	$old_map = array();
	foreach ( $old as $tag ) {
		$old_map[ mb_strtolower( $tag ) ] = $tag;
	}
	$add = array_diff_key( $new, $old_map );
	$del = array_diff_key( $old_map, $new );
	if ( ! $add && ! $del ) {
		return array_values( $old_map );
	}
	$t = pcb_tags_table();
	foreach ( $del as $tag ) {
		$wpdb->delete( $t, array( 'type' => $type, 'object_id' => $id, 'tag' => $tag ) );
	}
	foreach ( $add as $tag ) {
		$wpdb->insert( $t, array( 'type' => $type, 'object_id' => $id, 'tag' => $tag ) );
	}
	$diff = wp_json_encode( array( 'add' => array_values( $add ), 'del' => array_values( $del ) ), JSON_UNESCAPED_UNICODE );
	if ( 'request' === $type ) {
		pcb_crm_log( $id, 'tags', '', $diff );
	} else {
		pcb_cl_log( $type . ':' . $id, 'tags', '', $diff );
		$wpdb->update( pcb_cl_table( 'company' === $type ? 'companies' : 'contacts' ), array( 'updated' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}
	return pcb_tags_get( $type, $id );
}

/** Строка журнала для действия tags. */
function pcb_tags_log_text( $new ) {
	$c = json_decode( (string) $new, true );
	if ( ! is_array( $c ) ) {
		return 'Метки';
	}
	$q     = function ( $list ) { return implode( ', ', array_map( function ( $t ) { return '«' . $t . '»'; }, (array) $list ) ); };
	$parts = array();
	if ( ! empty( $c['add'] ) ) {
		$parts[] = 'добавлены ' . $q( $c['add'] );
	}
	if ( ! empty( $c['del'] ) ) {
		$parts[] = 'убраны ' . $q( $c['del'] );
	}
	return 'Метки: ' . implode( '; ', $parts );
}

/** Перенести метки при объединении клиентов (src влит в dst). */
function pcb_tags_merge( $type, $src, $dst ) {
	$have = pcb_tags_get( $type, $dst );
	$from = pcb_tags_get( $type, $src );
	if ( ! $from ) {
		return;
	}
	global $wpdb;
	$wpdb->delete( pcb_tags_table(), array( 'type' => $type, 'object_id' => (int) $src ) );
	$keys = array_map( 'mb_strtolower', $have );
	foreach ( $from as $tag ) {
		if ( count( $have ) < PCB_TAG_MAX_PER && ! in_array( mb_strtolower( $tag ), $keys, true ) ) {
			$wpdb->insert( pcb_tags_table(), array( 'type' => $type, 'object_id' => (int) $dst, 'tag' => $tag ) );
			$have[] = $tag;
			$keys[] = mb_strtolower( $tag );
		}
	}
}

/** Удалить метки записи (заявка удалена). */
function pcb_tags_drop( $type, $id ) {
	global $wpdb;
	$wpdb->delete( pcb_tags_table(), array( 'type' => $type, 'object_id' => (int) $id ) );
}

add_action( 'deleted_post', function ( $id, $post = null ) {
	if ( $post && 'pcb_request' === $post->post_type ) {
		pcb_tags_drop( 'request', $id );
	}
}, 10, 2 );

/** Условие для поиска: « OR EXISTS (метка содержит запрос)». $col — столбец с ID записи в основном запросе. */
function pcb_tags_search_sql( $type, $col, $q ) {
	global $wpdb;
	return $wpdb->prepare( ' OR EXISTS (SELECT 1 FROM ' . pcb_tags_table() . " tg WHERE tg.type = %s AND tg.object_id = {$col} AND tg.tag LIKE %s)", $type, '%' . $wpdb->esc_like( $q ) . '%' );
}

/** Условие для фильтра: у записи есть метка $tag. */
function pcb_tags_filter_sql( $type, $col, $tag ) {
	global $wpdb;
	return $wpdb->prepare( 'EXISTS (SELECT 1 FROM ' . pcb_tags_table() . " tf WHERE tf.type = %s AND tf.object_id = {$col} AND tf.tag = %s)", $type, $tag );
}

/** Варианты фильтра «Метка» для набора: array( метка => «метка (n)» ). */
function pcb_tags_options( $type ) {
	global $wpdb;
	$t    = pcb_tags_table();
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tag, COUNT(*) AS n FROM {$t} WHERE type = %s GROUP BY tag ORDER BY tag ASC LIMIT 300", $type ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ $r['tag'] ] = $r['tag'] . ' (' . (int) $r['n'] . ')';
	}
	return $out;
}

/**
 * Метки для вывода. $edit — можно править: в карточке поле «+ метка» видно сразу, в списке редактор
 * открывается по клику на ячейку (assets/tags.js). Значения экранированы.
 */
function pcb_tags_html( $type, $id, array $tags, $edit = false, $mode = 'list' ) {
	$h = '<div class="pp-tags pp-tags--' . esc_attr( $mode ) . '"'
		. ( $edit ? ' data-tags-type="' . esc_attr( $type ) . '" data-tags-id="' . (int) $id . '" data-tags="' . esc_attr( wp_json_encode( array_values( $tags ) ) ) . '"' : '' ) . '>';
	foreach ( $tags as $tag ) {
		$h .= '<span class="pp-tag">' . esc_html( $tag ) . '</span>';
	}
	if ( $edit ) {
		$h .= '<button type="button" class="pp-tags__add" aria-label="Изменить метки" title="Изменить метки">' . ( $tags || 'list' === $mode ? '+' : '+ метка' ) . '</button>';
	}
	return $h . '</div>';
}

/** Подключение редактора меток: подсказки обоих наборов и адрес REST. Один раз на странице. */
function pcb_tags_script( array $types ) {
	$sug = array();
	foreach ( $types as $type ) {
		$sug[ $type ] = array_map( 'strval', array_keys( pcb_tags_all( $type ) ) );
	}
	return '<script>window.PCBTags = ' . wp_json_encode( array(
		'api'   => esc_url_raw( rest_url( 'pcb-panel/v1/tags' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'max'   => PCB_TAG_MAX_LEN,
		'per'   => PCB_TAG_MAX_PER,
		'sug'   => $sug,
	) ) . ';</script><script src="' . esc_url( PCB_PANEL_URL . 'assets/tags.js?ver=' . PCB_PANEL_VERSION ) . '" defer></script>';
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pcb-panel/v1', '/tags', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'pcb_crm_edit' );
		},
		'args'                => array(
			'type' => array( 'required' => true, 'enum' => pcb_tags_types() ),
			'id'   => array( 'required' => true ),
			'tags' => array( 'required' => true, 'type' => 'array' ),
		),
		'callback'            => function ( WP_REST_Request $r ) {
			$res = pcb_tags_set( (string) $r['type'], (int) $r['id'], array_map( 'strval', (array) $r['tags'] ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'ok' => true, 'tags' => $res, 'sug' => array_map( 'strval', array_keys( pcb_tags_all( (string) $r['type'] ) ) ) );
		},
	) );
} );
