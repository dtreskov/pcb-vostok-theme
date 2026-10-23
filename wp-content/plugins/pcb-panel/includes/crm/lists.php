<?php
/**
 * Выборки для списков панели (0.9.0): заявки, клиенты, журнал — с сортировкой и фильтрами на сервере.
 * Разметка — includes/crm/table.php (PCB_List) и views/crm.php, crm-clients.php, crm-journal.php.
 */

defined( 'ABSPATH' ) || exit;

/* =================================
   Заявки
   ================================= */

/**
 * Действия, которые не считаются изменением заявки (столбец «Изменена»): скачивание, выгрузка,
 * служебное обновление данных; записи системы и сайта (user_id = 0) — тоже нет.
 */
function pcb_crm_change_ignored() {
	return array( 'file_download', 'export', 'migrated', 'created' );
}

/** Варианты услуги для фильтра: известные коды + то, что реально встречается в заявках. */
function pcb_crm_service_options() {
	global $wpdb;
	$out = array();
	foreach ( PCB_RQ_SERVICES as $k => $label ) {
		$out[ $k ] = $label;
	}
	$used = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_pcb_service' AND meta_value <> '' LIMIT 50" );
	foreach ( (array) $used as $v ) {
		if ( ! isset( $out[ $v ] ) ) {
			$out[ $v ] = pcb_rq_service_label( $v );
		}
	}
	return $out;
}

/**
 * Список заявок. $l — PCB_List с фильтрами service, tag, period, source, important, demo и вкладкой status.
 * Возвращает array( 'ids' => [...], 'total' => n, 'counts' => [status => n], 'changed' => [id => строка журнала] ).
 */
function pcb_crm_requests_query( PCB_List $l ) {
	global $wpdb;
	$pm  = $wpdb->postmeta;
	$log = pcb_crm_log_table();
	$ign = "'" . implode( "','", pcb_crm_change_ignored() ) . "'";

	$where = array( "p.post_type = 'pcb_request'", "p.post_status NOT IN ('trash','auto-draft','inherit')" );
	$args  = array();
	$meta  = function ( $key, $op, $val ) use ( $pm, $wpdb ) {
		return $wpdb->prepare( "EXISTS (SELECT 1 FROM {$pm} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value {$op} %s)", $key, $val );
	};

	if ( '' !== $l->val( 'service' ) ) {
		$where[] = $meta( '_pcb_service', '=', $l->val( 'service' ) );
	}
	if ( '' !== $l->val( 'source' ) ) {
		$where[] = 'calc' === $l->val( 'source' ) ? $meta( '_pcb_source', '=', 'calc' ) : 'NOT ' . $meta( '_pcb_source', '=', 'calc' );
	}
	list( $from, $to ) = $l->period( 'period' );
	if ( '' !== $from ) {
		$where[] = $wpdb->prepare( 'p.post_date >= %s', $from );
	}
	if ( '' !== $to ) {
		$where[] = $wpdb->prepare( 'p.post_date <= %s', $to );
	}
	if ( '' !== $l->val( 'tag' ) ) {
		$where[] = pcb_tags_filter_sql( 'request', 'p.ID', $l->val( 'tag' ) );
	}
	if ( '1' === $l->val( 'important' ) ) {
		$where[] = "EXISTS (SELECT 1 FROM {$log} n WHERE n.request_id = p.ID AND n.action = 'note' AND n.old_value = 'important')";
	}
	if ( '' !== $l->val( 'demo' ) ) {
		$demo    = $meta( '_pcb_calc', 'LIKE', '%' . $wpdb->esc_like( 's:4:"demo";b:1;' ) . '%' );
		$where[] = 'yes' === $l->val( 'demo' ) ? $demo : 'NOT ' . $demo;
	}
	if ( '' !== $l->q ) {
		$exact = pcb_rq_find( $l->q );
		if ( $exact ) {
			$where[] = 'p.ID = ' . (int) $exact;
		} else {
			$like    = '%' . $wpdb->esc_like( $l->q ) . '%';
			$digits  = preg_replace( '/\D/', '', $l->q );
			$where[] = '(' . $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$pm} m WHERE m.post_id = p.ID AND m.meta_key IN ('_pcb_email','_pcb_company','_pcb_name','_pcb_phone','_pcb_number','_pcb_code') AND m.meta_value LIKE %s)",
				$like
			) . ( strlen( $digits ) >= 4 ? $wpdb->prepare( " OR EXISTS (SELECT 1 FROM {$pm} m WHERE m.post_id = p.ID AND m.meta_key = '_pcb_k_phone' AND m.meta_value LIKE %s)", '%' . $digits . '%' ) : '' )
				. pcb_tags_search_sql( 'request', 'p.ID', $l->q ) . ')';
		}
	}

	$join_st = "LEFT JOIN {$pm} st ON st.post_id = p.ID AND st.meta_key = '_pcb_status'";
	$join_ch = "LEFT JOIN (SELECT request_id, MAX(id) AS lid FROM {$log} WHERE request_id > 0 AND user_id > 0 AND action NOT IN ({$ign}) GROUP BY request_id) ch ON ch.request_id = p.ID
		LEFT JOIN {$log} lg ON lg.id = ch.lid";

	// счётчики вкладок — с теми же фильтрами, без фильтра статуса
	$base_where = implode( ' AND ', $where );
	$counts     = array_fill_keys( array_keys( PCB_RQ_STATUSES ), 0 );
	foreach ( (array) $wpdb->get_results( "SELECT st.meta_value AS s, COUNT(*) AS n FROM {$wpdb->posts} p {$join_st} WHERE {$base_where} GROUP BY st.meta_value", ARRAY_A ) as $r ) {
		$counts[ (string) $r['s'] ] = (int) $r['n'];
	}
	$counts[''] = array_sum( $counts );

	if ( '' !== $l->val( 'status' ) ) {
		$where[] = $wpdb->prepare( 'st.meta_value = %s', $l->val( 'status' ) );
	}
	$w     = implode( ' AND ', $where );
	$dir   = 'asc' === $l->dir ? 'ASC' : 'DESC';
	$order = array(
		'date'    => "p.post_date {$dir}",
		'number'  => "nm.meta_value {$dir}",
		'changed' => "lg.time {$dir}",
		'status'  => "CASE st.meta_value WHEN 'new' THEN 1 WHEN 'work' THEN 2 WHEN 'done' THEN 3 WHEN 'lost' THEN 4 ELSE 5 END {$dir}, p.post_date DESC",
	)[ $l->sort ] ?? "p.post_date {$dir}";
	$join_nm = 'number' === $l->sort ? "LEFT JOIN {$pm} nm ON nm.post_id = p.ID AND nm.meta_key = '_pcb_number'" : '';

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$join_st} WHERE {$w}" );
	$rows  = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID AS id, lg.id AS lid FROM {$wpdb->posts} p {$join_st} {$join_ch} {$join_nm} WHERE {$w} ORDER BY {$order}, p.ID DESC LIMIT %d OFFSET %d",
		$l->per, ( $l->pg - 1 ) * $l->per
	), ARRAY_A );

	$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	$lids    = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'lid' ) ) );
	$changed = array();
	if ( $lids ) {
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$log} WHERE id IN (" . implode( ',', $lids ) . ')', ARRAY_A ) as $r ) {
			$changed[ (int) $r['request_id'] ] = $r;
		}
	}
	if ( $ids ) {
		_prime_post_caches( $ids, false, true );
	}
	return array( 'ids' => $ids, 'total' => $total, 'counts' => $counts, 'changed' => $changed );
}

/* =================================
   Клиенты
   ================================= */

/**
 * Компании или контакты. Сортировка: name, created, updated, requests (число заявок); фильтры:
 * tag (метка); компании — period (добавлена), verified (yes|no); контакты — period, nocompany.
 * Возвращает array( 'rows' => [...], 'total' => n ), в строках — n_requests (и n_contacts у компаний).
 */
function pcb_cl_list_query( $type, PCB_List $l ) {
	global $wpdb;
	$type  = 'contact' === $type ? 'contact' : 'company';
	$t     = pcb_cl_table( 'company' === $type ? 'companies' : 'contacts' );
	$where = array( 'c.merged_into = 0' );

	if ( '' !== $l->q ) {
		$like = '%' . $wpdb->esc_like( $l->q ) . '%';
		if ( 'company' === $type ) {
			$where[] = $wpdb->prepare( '(c.name LIKE %s OR c.full_name LIKE %s OR c.inn LIKE %s', $like, $like, $like )
				. pcb_tags_search_sql( 'company', 'c.id', $l->q ) . ')';
		} else {
			$val     = pcb_crm_phone_digits( $l->q );
			$val     = strlen( (string) $val ) >= 4 ? $val : strtolower( $l->q );
			$where[] = $wpdb->prepare( '(c.name LIKE %s OR c.id IN (SELECT contact_id FROM ' . pcb_cl_table( 'client_ids' ) . ' WHERE value LIKE %s)', $like, '%' . $wpdb->esc_like( $val ) . '%' )
				. pcb_tags_search_sql( 'contact', 'c.id', $l->q ) . ')';
		}
	}
	list( $from, $to ) = $l->period( 'period' );
	if ( '' !== $from ) {
		$where[] = $wpdb->prepare( 'c.created >= %s', $from );
	}
	if ( '' !== $to ) {
		$where[] = $wpdb->prepare( 'c.created <= %s', $to );
	}
	if ( '' !== $l->val( 'tag' ) ) {
		$where[] = pcb_tags_filter_sql( $type, 'c.id', $l->val( 'tag' ) );
	}
	if ( 'company' === $type && '' !== $l->val( 'verified' ) ) {
		$where[] = 'yes' === $l->val( 'verified' ) ? 'c.verified = 1' : 'c.verified = 0';
	}
	if ( 'contact' === $type && '1' === $l->val( 'nocompany' ) ) {
		$where[] = 'c.company_id = 0';
	}

	$mk    = 'company' === $type ? '_pcb_company_id' : '_pcb_contact_id';
	$n_req = "(SELECT COUNT(*) FROM {$wpdb->postmeta} r JOIN {$wpdb->posts} rp ON rp.ID = r.post_id AND rp.post_status NOT IN ('trash','auto-draft') WHERE r.meta_key = '{$mk}' AND r.meta_value = CAST(c.id AS CHAR))";
	$extra = 'company' === $type ? ', (SELECT COUNT(*) FROM ' . pcb_cl_table( 'contacts' ) . ' k WHERE k.company_id = c.id AND k.merged_into = 0) AS n_contacts' : '';
	$dir   = 'asc' === $l->dir ? 'ASC' : 'DESC';
	$order = array(
		'name'     => "c.name {$dir}",
		'created'  => "c.created {$dir}",
		'updated'  => "c.updated {$dir}",
		'requests' => "n_requests {$dir}",
	)[ $l->sort ] ?? "c.updated {$dir}";
	$w     = implode( ' AND ', $where );
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} c WHERE {$w}" );
	$rows  = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT c.*, {$n_req} AS n_requests{$extra} FROM {$t} c WHERE {$w} ORDER BY {$order}, c.id DESC LIMIT %d OFFSET %d",
		$l->per, ( $l->pg - 1 ) * $l->per
	), ARRAY_A );
	return array( 'rows' => $rows, 'total' => $total );
}

/* =================================
   Журнал действий
   ================================= */

/** Категории действий журнала: ключ => array( подпись, действия ). Действие без категории — «Служебное». */
function pcb_crm_log_categories() {
	return array(
		'created' => array( 'Создано', array( 'created', 'cl_created' ) ),
		'changed' => array( 'Изменено', array( 'status', 'service', 'lost_reason', 'contact', 'cl_field', 'user_name', 'tags' ) ),
		'added'   => array( 'Добавлено', array( 'note', 'file_added', 'client_link', 'cl_link' ) ),
		'deleted' => array( 'Удалено', array( 'deleted', 'deleted_archived', 'note_deleted' ) ),
		'got'     => array( 'Скачано', array( 'file_download', 'export' ) ),
		'sent'    => array( 'Отправлено', array( 'email' ) ),
		'marks'   => array( 'Отметки', array( 'note_important', 'note_resolved' ) ),
		'calc'    => array( 'Калькулятор', array( 'calc_publish', 'calc_restore', 'calc_csv' ) ),
		'service' => array( 'Служебное', array( 'cl_merge', 'cl_skip', 'migrated' ) ),
	);
}

/** Категория действия: ключ. */
function pcb_crm_log_category( $action ) {
	foreach ( pcb_crm_log_categories() as $k => $c ) {
		if ( in_array( $action, $c[1], true ) ) {
			return $k;
		}
	}
	return 'service';
}

/** Объект записи журнала: request | client | settings | seo | calc | user | ''. */
function pcb_crm_log_object( array $r ) {
	$e = (string) $r['entity'];
	if ( 0 === strpos( $e, 'seo:' ) ) {
		return 'seo';
	}
	if ( 'calc' === $e || 0 === strpos( (string) $r['action'], 'calc_' ) ) {
		return 'calc';
	}
	if ( 0 === strpos( $e, 'company:' ) || 0 === strpos( $e, 'contact:' ) ) {
		return 'client';
	}
	if ( 'user_name' === $r['action'] ) {
		return 'user';
	}
	if ( 'crm:settings' === $e ) {
		return 'settings';
	}
	return $r['request_id'] || 'export' === $r['action'] ? 'request' : '';
}

function pcb_crm_log_objects() {
	return array( 'request' => 'Заявки', 'client' => 'Клиенты', 'settings' => 'Реакция на заявку', 'seo' => 'SEO и техработы', 'calc' => 'Калькулятор', 'user' => 'Пользователи' );
}

/** Сотрудники, встречающиеся в журнале: id => имя (0 — «сайт»). */
function pcb_crm_log_users() {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . pcb_crm_log_table() ) as $uid ) {
		$out[ (string) (int) $uid ] = pcb_crm_log_user( (int) $uid );
	}
	asort( $out );
	return $out;
}

/** Журнал: фильтры cat, user, obj, period; сортировка time. Возвращает array( 'rows', 'total' ). */
function pcb_crm_journal_query( PCB_List $l ) {
	global $wpdb;
	$t     = pcb_crm_log_table();
	$where = array( '1=1' );
	$in    = function ( array $vals ) {
		return "('" . implode( "','", array_map( 'esc_sql', $vals ) ) . "')";
	};

	$cat = $l->val( 'cat' );
	if ( '' !== $cat ) {
		$cats = pcb_crm_log_categories();
		if ( 'service' === $cat ) {
			$known = array();
			foreach ( $cats as $k => $c ) {
				if ( 'service' !== $k ) {
					$known = array_merge( $known, $c[1] );
				}
			}
			$where[] = 'action NOT IN ' . $in( $known );
		} else {
			$where[] = 'action IN ' . $in( $cats[ $cat ][1] );
		}
	}
	if ( '' !== $l->val( 'user' ) ) {
		$where[] = 'user_id = ' . (int) $l->val( 'user' );
	}
	switch ( $l->val( 'obj' ) ) {
		case 'seo':
			$where[] = "entity LIKE 'seo:%'";
			break;
		case 'calc':
			$where[] = "(entity = 'calc' OR action LIKE 'calc\\_%')";
			break;
		case 'client':
			$where[] = "(entity LIKE 'company:%' OR entity LIKE 'contact:%')";
			break;
		case 'user':
			$where[] = "action = 'user_name'";
			break;
		case 'settings':
			$where[] = "entity = 'crm:settings'";
			break;
		case 'request':
			$where[] = "entity = '' AND action NOT LIKE 'calc\\_%' AND action <> 'user_name' AND (request_id > 0 OR action = 'export')";
			break;
	}
	list( $from, $to ) = $l->period( 'period' );
	if ( '' !== $from ) {
		$where[] = $wpdb->prepare( 'time >= %s', $from );
	}
	if ( '' !== $to ) {
		$where[] = $wpdb->prepare( 'time <= %s', $to );
	}
	if ( '' !== $l->q ) {
		$like    = '%' . $wpdb->esc_like( $l->q ) . '%';
		$where[] = $wpdb->prepare( '(number LIKE %s OR code LIKE %s)', $like, $like );
	}
	$w     = implode( ' AND ', $where );
	$dir   = 'asc' === $l->dir ? 'ASC' : 'DESC';
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$w}" );
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY time {$dir}, id {$dir} LIMIT %d OFFSET %d", $l->per, ( $l->pg - 1 ) * $l->per ), ARRAY_A );
	return array( 'rows' => $rows, 'total' => $total );
}
