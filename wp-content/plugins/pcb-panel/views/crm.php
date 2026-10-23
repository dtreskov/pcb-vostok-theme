<?php
/**
 * Вкладка CRM — список заявок (общая таблица, README §6.2): вкладки ?status=, поиск ?q=, фильтры, ?sort=&dir=, страницы ?pg=.
 * Права на вкладку проверены в access.php; кнопки администратора — по pcb_crm_delete / pcb_panel_log_view.
 */
defined( 'ABSPATH' ) || exit;

$l = new PCB_List(
	pcb_panel_url( 'crm' ),
	array( 'date' => 'desc', 'number' => 'desc', 'changed' => 'desc', 'status' => 'asc' ),
	array(
		'service'   => array( 'type' => 'select', 'label' => 'Услуга', 'options' => pcb_crm_service_options() ),
		'tag'       => array( 'type' => 'select', 'label' => 'Метка', 'options' => pcb_tags_options( 'request' ), 'chip_value' => true ),
		'period'    => array( 'type' => 'period', 'label' => 'Поступила' ),
		'source'    => array( 'type' => 'select', 'label' => 'Откуда', 'options' => array( 'home' => 'Главная', 'calc' => 'Калькулятор' ) ),
		'demo'      => array( 'type' => 'select', 'label' => 'Демо-проект', 'options' => array( 'yes' => 'Только демо', 'no' => 'Без демо' ) ),
		'important' => array( 'type' => 'check', 'label' => 'Есть важные заметки' ),
	),
	array( 'status' => array_merge( array( '' ), array_keys( PCB_RQ_STATUSES ) ) )
);
$res       = pcb_crm_requests_query( $l );
$important = pcb_crm_important_counts( $res['ids'] );
$tags      = pcb_tags_for( 'request', $res['ids'] );
$can_tag   = current_user_can( 'pcb_crm_edit' );
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

<?php
echo $l->toolbar( // phpcs:ignore -- разметка собрана с экранированием
	'Номер, код, почта, компания',
	$l->tabs( 'status', array_merge( array( '' => 'Все' ), PCB_RQ_STATUSES ), $res['counts'], 'Статус' )
);
?>

<?php if ( ! $res['ids'] ) : ?>
	<section class="pp-empty">
		<h2><?php echo ( $l->filtered() || '' !== $l->val( 'status' ) ) ? 'Ничего не найдено' : 'Заявок пока нет'; ?></h2>
		<p><?php echo ( $l->filtered() || '' !== $l->val( 'status' ) ) ? 'Попробуйте другой запрос или сбросьте фильтры.' : 'Заявки с формы на сайте появятся здесь автоматически.'; ?></p>
	</section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table crm-table">
			<thead>
				<tr>
					<?php
					echo $l->th( 'number', 'Заявка' ) . $l->th( '', 'Метки', 'crm-th-tags' ) . $l->th( '', 'Клиент' ) . $l->th( '', 'Услуга' ) . $l->th( 'status', 'Статус' ) // phpcs:ignore
						. $l->th( '', 'Файлы' ) . $l->th( 'date', 'Поступила' ) . $l->th( 'changed', 'Изменена' ); // phpcs:ignore
					?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $res['ids'] as $id ) :
				$m       = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
				$st      = (string) $m( 'status' );
				$unseen  = ! $m( 'seen' );
				$company = (string) $m( 'company' );
				$ch      = $res['changed'][ $id ] ?? null;
				?>
				<tr<?php echo PCB_List::row( pcb_rq_panel_url( $id ), $unseen ? 'is-unseen' : '' ); // phpcs:ignore ?>>
					<td>
						<a class="crm-num" href="<?php echo esc_url( pcb_rq_panel_url( $id ) ); ?>"><?php echo esc_html( $m( 'number' ) ); ?></a>
						<?php if ( ! empty( $important[ $id ] ) ) : ?><span class="crm-flag" title="Важных заметок: <?php echo (int) $important[ $id ]; ?>">!</span><?php endif; ?>
						<span class="crm-code"><?php echo esc_html( $m( 'code' ) ); ?><?php if ( pcb_crm_is_demo( $id ) ) : ?> <span class="crm-tag crm-tag--demo" title="Заявка с демо-проектом калькулятора">демо</span><?php endif; ?></span>
					</td>
					<td class="crm-td-tags"<?php echo $can_tag ? ' data-no-row' : ''; ?>><?php echo pcb_tags_html( 'request', $id, $tags[ $id ] ?? array(), $can_tag ); // phpcs:ignore -- экранировано ?></td>
					<td>
						<?php $cl_co = (int) $m( 'company_id' ) ? pcb_cl_company( (int) $m( 'company_id' ) ) : null; ?>
						<?php if ( $cl_co ) : ?><a href="<?php echo esc_url( pcb_cl_url( 'company', $cl_co['id'] ) ); ?>"><?php echo esc_html( $cl_co['name'] ); ?></a><?php else : ?><?php echo esc_html( '' !== $company ? $company : ( $m( 'name' ) ?: '—' ) ); ?><?php endif; ?>
						<span class="crm-sub"><?php echo esc_html( $m( 'email' ) ); ?></span>
					</td>
					<td><?php echo esc_html( pcb_rq_service_label( $m( 'service' ) ) ); ?><?php if ( 'calc' === $m( 'source' ) ) : ?><span class="crm-sub">с калькулятора</span><?php endif; ?></td>
					<td><span class="crm-status crm-status--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( pcb_rq_status_label( $st ) ); ?></span></td>
					<td><?php echo (int) count( (array) $m( 'files' ) ); ?></td>
					<td><?php echo PCB_List::date( get_post_field( 'post_date', $id ) ); // phpcs:ignore ?></td>
					<td<?php echo $ch ? ' title="' . esc_attr( mb_strimwidth( pcb_crm_log_text( $ch ), 0, 140, '…' ) ) . '"' : ''; ?>><?php echo $ch ? PCB_List::date( $ch['time'], pcb_crm_user_html( $ch['user_id'] ) ) : '<span class="pp-muted">—</span>'; // phpcs:ignore ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php echo $l->pages( $res['total'] ); // phpcs:ignore ?>
<?php endif; ?>
<?php echo PCB_List::script() . ( $can_tag ? pcb_tags_script( array( 'request' ) ) : '' ); // phpcs:ignore ?>
