<?php
/**
 * REST базы клиентов: /wp-json/pcb-panel/v1/clients/…
 * Просмотр и поиск — pcb_crm_view, любые изменения — pcb_crm_edit.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$ns   = 'pcb-panel/v1';
	$can  = function ( $cap ) {
		return function () use ( $cap ) {
			return current_user_can( $cap );
		};
	};
	$ok   = function ( $res, $extra = array() ) {
		return is_wp_error( $res ) ? $res : array_merge( array( 'ok' => true ), $extra );
	};

	// новая компания (вручную) → адрес её карточки
	register_rest_route( $ns, '/clients/company', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) {
			$clean = pcb_cl_clean_company( (array) $r->get_params() );
			if ( $clean['errors'] ) {
				return new WP_Error( 'pcb_cl', implode( ' ', $clean['errors'] ), array( 'status' => 400, 'fields' => $clean['errors'] ) );
			}
			$inn = $clean['values']['inn'] ?? '';
			if ( '' !== $inn && ( $other = pcb_cl_find( 'inn', $inn, 'company' ) ) ) {
				return new WP_Error( 'pcb_cl', 'Компания с таким ИНН уже есть.', array( 'status' => 409, 'redirect' => pcb_cl_url( 'company', $other ) ) );
			}
			$id = pcb_cl_company_create( $clean['values'], 0, true );
			return array( 'ok' => true, 'redirect' => pcb_cl_url( 'company', $id ) );
		},
	) );

	register_rest_route( $ns, '/clients/company/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) use ( $ok ) {
			$p = (array) $r->get_params();
			if ( ! empty( $p['verified_present'] ) && ! isset( $p['verified'] ) ) {
				$p['verified'] = 0; // галочку сняли — браузер её не присылает
			}
			return $ok( pcb_cl_company_update( (int) $r['id'], $p ) );
		},
	) );

	register_rest_route( $ns, '/clients/(?P<type>company|contact)/(?P<id>\d+)/merge', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) {
			$type = (string) $r['type'];
			$res  = 'company' === $type ? pcb_cl_merge_company( (int) $r['id'], (int) $r['into'] ) : pcb_cl_merge_contact( (int) $r['id'], (int) $r['into'] );
			return is_wp_error( $res ) ? $res : array( 'ok' => true, 'redirect' => pcb_cl_url( $type, $res ) );
		},
	) );

	// новый контакт вручную: { name, post, company_id, email, phone }
	register_rest_route( $ns, '/clients/contact', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) {
			$id = pcb_cl_contact_create_manual( (array) $r->get_params() );
			return is_wp_error( $id ) ? $id : array( 'ok' => true, 'redirect' => pcb_cl_url( 'contact', $id ) );
		},
	) );

	register_rest_route( $ns, '/clients/contact/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) use ( $ok ) {
			$in = array_intersect_key( (array) $r->get_params(), array_flip( array( 'name', 'post', 'note', 'company_id' ) ) );
			return $ok( pcb_cl_contact_update( (int) $r['id'], $in ) );
		},
	) );

	// почта или телефон контакта: { kind: email|phone, value, remove }
	register_rest_route( $ns, '/clients/contact/(?P<id>\d+)/channel', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) use ( $ok ) {
			return $ok( pcb_cl_contact_channel( (int) $r['id'], (string) $r['kind'], (string) $r['value'], ! empty( $r['remove'] ) ) );
		},
	) );

	// очередь «Проверить»: { pair, action: apply|skip }
	register_rest_route( $ns, '/clients/queue', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) use ( $ok ) {
			$pair = (string) $r['pair'];
			if ( 'skip' === $r['act'] ) {
				return $ok( pcb_cl_skip( $pair ) );
			}
			if ( ! preg_match( '/^(link|cmerge|pmerge):(\d+):(\d+)$/', $pair, $m ) ) {
				return new WP_Error( 'pcb_cl', 'Неизвестное предложение.', array( 'status' => 400 ) );
			}
			if ( 'link' === $m[1] ) {
				return $ok( pcb_cl_contact_update( (int) $m[2], array( 'company_id' => (int) $m[3] ) ) );
			}
			// объединяем в более раннюю запись: она уже в заявках и письмах
			$res = 'cmerge' === $m[1] ? pcb_cl_merge_company( (int) $m[3], (int) $m[2] ) : pcb_cl_merge_contact( (int) $m[3], (int) $m[2] );
			return $ok( $res );
		},
	) );

	// перепривязать заявку вручную: { contact_id, company_id }
	register_rest_route( $ns, '/requests/(?P<id>\d+)/client', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) {
			$rid = (int) $r['id'];
			if ( ! pcb_crm_is_request( $rid ) ) {
				return new WP_Error( 'pcb_cl', 'Заявка не найдена.', array( 'status' => 404 ) );
			}
			$contact = (int) $r['contact_id'];
			$company = (int) $r['company_id'];
			if ( $contact && ! pcb_cl_contact( $contact ) ) {
				return new WP_Error( 'pcb_cl', 'Контакт не найден.', array( 'status' => 404 ) );
			}
			if ( $company && ! pcb_cl_company( $company ) ) {
				return new WP_Error( 'pcb_cl', 'Компания не найдена.', array( 'status' => 404 ) );
			}
			if ( $contact && ! $company ) {
				$company = (int) pcb_cl_contact( $contact )['company_id'];
			}
			pcb_cl_link_request( $rid, $contact, $company, 'manual' );
			return array( 'ok' => true );
		},
	) );

	// поиск для выбора компании/контакта: ?type=company|contact&q=
	register_rest_route( $ns, '/clients/search', array(
		'methods'             => 'GET',
		'permission_callback' => $can( 'pcb_crm_view' ),
		'callback'            => function ( WP_REST_Request $r ) {
			$type = 'contact' === $r['type'] ? 'contact' : 'company';
			$out  = array();
			foreach ( pcb_cl_list( $type, trim( (string) $r['q'] ), 15 ) as $row ) {
				$label = 'company' === $type ? $row['name'] . ( $row['inn'] ? ' · ИНН ' . $row['inn'] : '' ) : ( $row['name'] ?: 'без имени' ) . ' · ' . implode( ', ', pcb_cl_ids_of( 'contact', $row['id'] )['email'] ?? array() );
				$out[] = array( 'id' => (int) $row['id'], 'label' => $label );
			}
			return $out;
		},
	) );

	// реквизиты по ИНН организации (DaData)
	register_rest_route( $ns, '/clients/dadata', array(
		'methods'             => 'GET',
		'permission_callback' => $can( 'pcb_crm_edit' ),
		'callback'            => function ( WP_REST_Request $r ) {
			return pcb_cl_dadata_party( (string) $r['inn'] );
		},
	) );
} );
