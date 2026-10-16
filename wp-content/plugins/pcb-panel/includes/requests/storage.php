<?php
/**
 * Файлы заявок: где лежат, как сохраняются, скачиваются, удаляются и уходят в архив.
 *
 * Корень хранения (по умолчанию — вне веб-корня):
 *   <папка сайта>/pcb-data/requests/
 *     active/<номер>/<ключ>.<расш>.bin   файлы действующих заявок
 *     archive/<номер>_<код>/             файлы удалённых заявок + info.txt
 * Корень можно задать константой PCB_REQUEST_STORAGE в wp-config.php.
 * Если выше public_html писать нельзя — запасной вариант uploads/pcb-requests (как было раньше),
 * панель тогда показывает предупреждение.
 *
 * В метаполе _pcb_files путь файла хранится относительно active/: «<номер>/<имя на диске>».
 */

defined( 'ABSPATH' ) || exit;

/** Старое расположение (до 0.3.0): uploads/pcb-requests/<номер>/… */
function pcb_rq_legacy_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'pcb-requests';
}

/** Корень, который выбрал бы плагин: константа или <папка сайта>/pcb-data/requests. */
function pcb_rq_preferred_root() {
	$dir = pcb_rq_const( 'PCB_REQUEST_STORAGE' );
	if ( '' === $dir ) {
		$dir = dirname( untrailingslashit( ABSPATH ) ) . '/pcb-data/requests';
	}
	return untrailingslashit( $dir );
}

/**
 * Корень хранения. Выбирается один раз и запоминается в опции pcb_rq_storage_root,
 * чтобы файлы не «потерялись», если права на папку поменяются.
 */
function pcb_rq_storage_root() {
	$saved = (string) get_option( 'pcb_rq_storage_root', '' );
	if ( '' !== $saved && is_dir( $saved ) && ( '' === pcb_rq_const( 'PCB_REQUEST_STORAGE' ) || $saved === pcb_rq_preferred_root() ) ) {
		return $saved;
	}
	$root = pcb_rq_preferred_root();
	if ( ! is_dir( $root ) ) {
		wp_mkdir_p( $root );
	}
	if ( ! is_dir( $root ) || ! wp_is_writable( $root ) ) {
		$root = pcb_rq_legacy_dir(); // запасной вариант внутри uploads, как было до 0.3.0
		wp_mkdir_p( $root );
	}
	pcb_rq_protect( $root );
	update_option( 'pcb_rq_storage_root', $root, false );
	return $root;
}

/** Лежит ли хранилище внутри веб-корня (тогда защищает только .htaccess). */
function pcb_rq_storage_is_public() {
	$root = wp_normalize_path( pcb_rq_storage_root() );
	return 0 === strpos( $root, wp_normalize_path( ABSPATH ) );
}

function pcb_rq_storage_active() {
	$dir = pcb_rq_storage_root() . '/active';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		pcb_rq_protect( $dir );
	}
	return $dir;
}

function pcb_rq_storage_archive() {
	$dir = pcb_rq_storage_root() . '/archive';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		pcb_rq_protect( $dir );
	}
	return $dir;
}

/** Запрет прямого доступа на случай, если папка окажется в веб-корне. */
function pcb_rq_protect( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}
}

/* ---------- сохранение ---------- */

function pcb_rq_store_files( array $files, $number ) {
	if ( ! $files ) {
		return array();
	}
	$dir = pcb_rq_storage_active() . '/' . $number;
	wp_mkdir_p( $dir );
	$stored = array();
	foreach ( $files as $file ) {
		$key  = strtolower( wp_generate_password( 24, false, false ) );
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$ext  = preg_replace( '/[^a-z0-9]/', '', $ext );
		$disk = $key . ( '' !== $ext ? '.' . $ext : '' ) . '.bin';
		if ( @move_uploaded_file( $file['tmp'], $dir . '/' . $disk ) ) {
			@chmod( $dir . '/' . $disk, 0640 );
			$stored[] = array( 'key' => $key, 'name' => $file['name'], 'size' => $file['size'], 'path' => $number . '/' . $disk );
		}
	}
	return $stored;
}

/** Абсолютный путь к файлу заявки или '' если его нет. */
function pcb_rq_file_path( $file ) {
	if ( empty( $file['path'] ) || false !== strpos( $file['path'], '..' ) ) {
		return '';
	}
	$path = pcb_rq_storage_active() . '/' . $file['path'];
	return is_file( $path ) ? $path : '';
}

/* ---------- скачивание: admin-post.php?action=pcb_request_file&id=…&f=… ---------- */

function pcb_rq_file_url( $post_id, $key ) {
	return add_query_arg( array( 'action' => 'pcb_request_file', 'id' => (int) $post_id, 'f' => $key ), admin_url( 'admin-post.php' ) );
}

add_action( 'admin_post_pcb_request_file', function () {
	if ( ! current_user_can( 'pcb_crm_view' ) ) {
		wp_die( 'Нет доступа к файлам заявок.', 403 );
	}
	$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$key = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : '';
	if ( 'pcb_request' !== get_post_type( $id ) ) {
		wp_die( 'Заявка не найдена.', 404 );
	}
	foreach ( (array) get_post_meta( $id, '_pcb_files', true ) as $file ) {
		if ( isset( $file['key'] ) && strtolower( $file['key'] ) === $key ) {
			$path = pcb_rq_file_path( $file );
			if ( '' === $path ) {
				break;
			}
			pcb_crm_log( $id, 'file_download', '', $file['name'] );
			nocache_headers();
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: ' . filesize( $path ) );
			header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( $file['name'] ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $path );
			exit;
		}
	}
	wp_die( 'Файл не найден.', 404 );
} );

add_action( 'admin_post_nopriv_pcb_request_file', function () {
	auth_redirect();
} );

/* ---------- удаление и архив ---------- */

function pcb_rq_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) ? pcb_rq_rmdir( $p ) : @unlink( $p );
	}
	@rmdir( $dir );
}

/** Удалить файлы заявки безвозвратно. */
function pcb_rq_files_purge( $post_id ) {
	$number = (string) get_post_meta( $post_id, '_pcb_number', true );
	if ( '' !== $number && false === strpos( $number, '/' ) && false === strpos( $number, '.' ) ) {
		pcb_rq_rmdir( pcb_rq_storage_active() . '/' . $number );
	}
}

/**
 * Перенести файлы заявки в архив и записать info.txt (без контактов клиента).
 * Возвращает имя папки в архиве или '' если файлов не было.
 */
function pcb_rq_files_archive( $post_id ) {
	$number = (string) get_post_meta( $post_id, '_pcb_number', true );
	$code   = (string) get_post_meta( $post_id, '_pcb_code', true );
	$files  = (array) get_post_meta( $post_id, '_pcb_files', true );
	$src    = pcb_rq_storage_active() . '/' . $number;
	if ( '' === $number || ! is_dir( $src ) ) {
		return '';
	}
	$name = $number . ( '' !== $code ? '_' . $code : '' );
	$dst  = pcb_rq_storage_archive() . '/' . $name;
	if ( file_exists( $dst ) ) {
		$dst .= '-' . time();
	}
	if ( ! @rename( $src, $dst ) ) {
		return '';
	}
	$user  = wp_get_current_user();
	$lines = array(
		'Заявка:        ' . $number,
		'Код клиента:   ' . ( '' !== $code ? $code : '—' ),
		'Дата заявки:   ' . get_the_date( 'Y-m-d H:i', $post_id ),
		'Услуга:        ' . pcb_rq_service_label( get_post_meta( $post_id, '_pcb_service', true ) ),
		'Удалил:        ' . ( $user->exists() ? $user->display_name . ' (' . $user->user_login . ')' : 'система' ),
		'Когда удалена: ' . current_time( 'Y-m-d H:i' ),
		'',
		'Файлы (оригинальное имя → имя на диске):',
	);
	foreach ( $files as $f ) {
		$lines[] = '  ' . $f['name'] . ' (' . size_format( $f['size'] ) . ') → ' . basename( (string) $f['path'] );
	}
	$lines[] = '';
	$lines[] = 'Файлы могут содержать персональные данные — проверьте вручную перед хранением или удалением.';
	@file_put_contents( $dst . '/info.txt', implode( "\n", $lines ) . "\n" );
	return basename( $dst );
}
