<?php
/**
 * Обновление данных заявок при смене версии структуры (опция pcb_rq_data_version).
 *
 * v1 (плагин 0.3.0):
 *   — старые статусы: quoted → work, closed → done;
 *   — заявкам без кода клиента присваивается код, без «выбора клиента» — текущая услуга;
 *   — файлы из uploads/pcb-requests/<номер>/ переносятся в <корень хранения>/active/<номер>/.
 */

defined( 'ABSPATH' ) || exit;

define( 'PCB_RQ_DATA_VERSION', '1' );

add_action( 'init', function () {
	if ( get_option( 'pcb_rq_data_version' ) === PCB_RQ_DATA_VERSION ) {
		return;
	}
	// не запускать параллельно из двух запросов
	if ( get_transient( 'pcb_rq_migrating' ) ) {
		return;
	}
	set_transient( 'pcb_rq_migrating', 1, 5 * MINUTE_IN_SECONDS );
	pcb_rq_migrate_v1();
	update_option( 'pcb_rq_data_version', PCB_RQ_DATA_VERSION, false );
	delete_transient( 'pcb_rq_migrating' );
}, 30 );

function pcb_rq_migrate_v1() {
	$map = array( 'quoted' => 'work', 'closed' => 'done' );
	$ids = get_posts( array(
		'post_type'      => 'pcb_request',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	foreach ( $ids as $id ) {
		$status = (string) get_post_meta( $id, '_pcb_status', true );
		if ( isset( $map[ $status ] ) ) {
			update_post_meta( $id, '_pcb_status', $map[ $status ] );
			pcb_crm_log( $id, 'migrated', $status, 'статус → ' . pcb_rq_status_label( $map[ $status ] ), 0 );
		} elseif ( ! isset( PCB_RQ_STATUSES[ $status ] ) ) {
			update_post_meta( $id, '_pcb_status', 'new' );
		}
		if ( '' === (string) get_post_meta( $id, '_pcb_code', true ) ) {
			update_post_meta( $id, '_pcb_code', pcb_rq_new_code() );
		}
		if ( '' === (string) get_post_meta( $id, '_pcb_service_client', true ) ) {
			update_post_meta( $id, '_pcb_service_client', (string) get_post_meta( $id, '_pcb_service', true ) );
		}
	}
	pcb_rq_migrate_files();
}

/** Перенос папок заявок из старого места в active/. Пути в _pcb_files не меняются: «<номер>/<файл>». */
function pcb_rq_migrate_files() {
	$old    = pcb_rq_legacy_dir();
	$active = pcb_rq_storage_active();
	if ( ! is_dir( $old ) ) {
		return;
	}
	$same = wp_normalize_path( $old ) === wp_normalize_path( dirname( $active ) );
	foreach ( (array) scandir( $old ) as $name ) {
		if ( ! preg_match( '/^\d{4}-\d+$/', $name ) || ! is_dir( $old . '/' . $name ) ) {
			continue; // только папки заявок; active/, archive/, .htaccess не трогаем
		}
		$dst = $active . '/' . $name;
		if ( file_exists( $dst ) ) {
			continue;
		}
		if ( ! @rename( $old . '/' . $name, $dst ) ) {
			error_log( 'PCB requests: не удалось перенести ' . $old . '/' . $name . ' → ' . $dst );
		}
	}
	// старая папка опустела и это не текущий корень — убираем её вместе со служебными файлами
	if ( ! $same ) {
		$left = array_diff( (array) scandir( $old ), array( '.', '..', '.htaccess', 'index.php' ) );
		if ( ! $left ) {
			@unlink( $old . '/.htaccess' );
			@unlink( $old . '/index.php' );
			@rmdir( $old );
		}
	}
}
