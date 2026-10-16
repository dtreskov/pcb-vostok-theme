<?php
/**
 * Вкладка CRM — список заявок. Фильтр ?status=, поиск ?q= (номер, код, почта, компания, имя), страницы ?pg=.
 * Права на вкладку проверены в access.php; кнопки администратора — по pcb_crm_delete / pcb_panel_log_view.
 */
defined( 'ABSPATH' ) || exit;

$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$status = isset( PCB_RQ_STATUSES[ $status ] ) ? $status : '';
$q      = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
$pg     = max( 1, isset( $_GET['pg'] ) ? (int) $_GET['pg'] : 1 );
$per    = 50;

$args = array(
	'post_type'      => 'pcb_request',
	'post_status'    => 'any',
	'posts_per_page' => $per,
	'paged'          => $pg,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_query'     => array(),
);
if ( '' !== $status ) {
	$args['meta_query'][] = array( 'key' => '_pcb_status', 'value' => $status );
}
if ( '' !== $q ) {
	$exact = pcb_rq_find( $q );
	if ( $exact ) {
		$args['post__in'] = array( $exact );
	} else {
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array( 'key' => '_pcb_email', 'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_pcb_company', 'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_pcb_name', 'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_pcb_phone', 'value' => $q, 'compare' => 'LIKE' ),
		);
	}
}
$query     = new WP_Query( $args );
$important = pcb_crm_important_counts( wp_list_pluck( $query->posts, 'ID' ) );

$link = function ( $over = array() ) use ( $status, $q ) {
	$params = array_filter( array_merge( array( 'status' => $status, 'q' => $q ), $over ), 'strlen' );
	return add_query_arg( $params, pcb_panel_url( 'crm' ) );
};
?>
<?php $crm_nav = 'requests'; include __DIR__ . '/_crm-nav.php'; ?>

<div class="pp-page-head">
	<div>
		<h1>Заявки</h1>
		<p>Заявки с формы на сайте и из калькулятора. Клиент знает только код заявки, внутренний номер — для вас.</p>
	</div>
	<div class="crm-head-actions">
		<?php if ( current_user_can( 'pcb_crm_delete' ) ) : ?>
			<a class="pp-btn pp-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pcb_crm_export' ), 'pcb_crm_export' ) ); ?>">Выгрузить в CSV</a>
		<?php endif; ?>
	</div>
</div>

<?php if ( current_user_can( 'manage_options' ) && pcb_rq_storage_is_public() ) : ?>
	<p class="crm-warn">Файлы заявок лежат внутри веб-корня (<code><?php echo esc_html( pcb_rq_storage_root() ); ?></code>) и защищены только правилом .htaccess. Надёжнее — папка выше public_html: задайте путь константой <code>PCB_REQUEST_STORAGE</code> в wp-config.php.</p>
<?php endif; ?>

<form class="crm-toolbar" method="get" action="<?php echo esc_url( pcb_panel_url( 'crm' ) ); ?>">
	<nav class="crm-filters" aria-label="Статус">
		<a class="pp-filter<?php echo '' === $status ? ' is-on' : ''; ?>" href="<?php echo esc_url( $link( array( 'status' => '' ) ) ); ?>">Все</a>
		<?php foreach ( PCB_RQ_STATUSES as $k => $label ) : ?>
			<a class="pp-filter<?php echo $k === $status ? ' is-on' : ''; ?>" href="<?php echo esc_url( $link( array( 'status' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php if ( '' !== $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
	<label class="crm-search">
		<span class="pp-sr">Поиск</span>
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Номер, код, почта, компания">
	</label>
</form>

<?php if ( ! $query->have_posts() ) : ?>
	<section class="pp-empty">
		<h2><?php echo ( '' !== $q || '' !== $status ) ? 'Ничего не найдено' : 'Заявок пока нет'; ?></h2>
		<p><?php echo ( '' !== $q || '' !== $status ) ? 'Попробуйте другой запрос или сбросьте фильтр.' : 'Заявки с формы на сайте появятся здесь автоматически.'; ?></p>
	</section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table crm-table">
			<thead>
				<tr><th>Заявка</th><th>Дата</th><th>Клиент</th><th>Услуга</th><th>Статус</th><th>Файлы</th><th>Откуда</th></tr>
			</thead>
			<tbody>
			<?php foreach ( $query->posts as $p ) :
				$id      = $p->ID;
				$m       = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
				$st      = (string) $m( 'status' );
				$unseen  = ! $m( 'seen' );
				$company = (string) $m( 'company' );
				?>
				<tr class="<?php echo $unseen ? 'is-unseen' : ''; ?>">
					<td>
						<a class="crm-num" href="<?php echo esc_url( pcb_rq_panel_url( $id ) ); ?>"><?php echo esc_html( $m( 'number' ) ); ?></a>
						<?php if ( ! empty( $important[ $id ] ) ) : ?><span class="crm-flag" title="Важных заметок: <?php echo (int) $important[ $id ]; ?>">!</span><?php endif; ?>
						<span class="crm-code"><?php echo esc_html( $m( 'code' ) ); ?></span>
					</td>
					<td class="crm-nowrap"><?php echo esc_html( get_the_date( 'd.m.Y H:i', $id ) ); ?></td>
					<td>
						<?php $cl_co = (int) $m( 'company_id' ) ? pcb_cl_company( (int) $m( 'company_id' ) ) : null; ?>
						<?php if ( $cl_co ) : ?><a href="<?php echo esc_url( pcb_cl_url( 'company', $cl_co['id'] ) ); ?>"><?php echo esc_html( $cl_co['name'] ); ?></a><?php else : ?><?php echo esc_html( '' !== $company ? $company : ( $m( 'name' ) ?: '—' ) ); ?><?php endif; ?>
						<span class="crm-sub"><?php echo esc_html( $m( 'email' ) ); ?></span>
					</td>
					<td><?php echo esc_html( pcb_rq_service_label( $m( 'service' ) ) ); ?></td>
					<td><span class="crm-status crm-status--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( pcb_rq_status_label( $st ) ); ?></span></td>
					<td><?php echo (int) count( (array) $m( 'files' ) ); ?></td>
					<td><?php echo 'calc' === $m( 'source' ) ? 'Калькулятор' : 'Главная'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $query->max_num_pages > 1 ) : ?>
		<nav class="crm-pages" aria-label="Страницы">
			<?php for ( $i = 1; $i <= $query->max_num_pages; $i++ ) : ?>
				<a class="pp-filter<?php echo $i === $pg ? ' is-on' : ''; ?>" href="<?php echo esc_url( $link( array( 'pg' => $i > 1 ? (string) $i : '' ) ) ); ?>"><?php echo (int) $i; ?></a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
