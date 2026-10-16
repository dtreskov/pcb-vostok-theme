<?php
/** Подразделы CRM: заявки, клиенты, очередь «Проверить», журнал. $crm_nav — текущий. */
defined( 'ABSPATH' ) || exit;
$crm_nav = $crm_nav ?? 'requests';
$queue_n = pcb_cl_queue_count();
$items   = array(
	'requests' => array( 'Заявки', pcb_panel_url( 'crm' ) ),
	'clients'  => array( 'Клиенты', pcb_panel_url( 'crm' ) . 'clients/' ),
	'queue'    => array( 'Проверить', pcb_panel_url( 'crm' ) . 'clients/queue/' ),
);
if ( current_user_can( 'pcb_panel_log_view' ) ) {
	$items['journal'] = array( 'Журнал', pcb_panel_url( 'crm' ) . 'journal/' );
}
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">
<nav class="crm-subnav" aria-label="Разделы CRM">
	<?php foreach ( $items as $k => $it ) : ?>
		<a class="<?php echo $k === $crm_nav ? 'is-on' : ''; ?>" href="<?php echo esc_url( $it[1] ); ?>"<?php echo $k === $crm_nav ? ' aria-current="page"' : ''; ?>>
			<?php echo esc_html( $it[0] ); ?><?php if ( 'queue' === $k && $queue_n ) : ?><span class="pp-badge"><?php echo (int) $queue_n; ?></span><?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
