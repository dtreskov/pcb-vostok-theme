<?php
/**
 * Общий журнал действий по заявкам: /panel/crm/journal/ (право pcb_panel_log_view).
 * Записи удалённых заявок остаются — по номеру и коду видно, кто и когда удалил.
 */
defined( 'ABSPATH' ) || exit;

$per  = 100;
$pg   = max( 1, isset( $_GET['pg'] ) ? (int) $_GET['pg'] : 1 );
$rows = pcb_crm_log_get( 0, $per + 1, ( $pg - 1 ) * $per );
$more = count( $rows ) > $per;
$rows = array_slice( $rows, 0, $per );
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">

<a class="crm-back" href="<?php echo esc_url( pcb_panel_url( 'crm' ) ); ?>">← Все заявки</a>

<div class="pp-page-head">
	<div>
		<h1>Журнал действий</h1>
		<p>Кто и что менял в заявках. Контактов клиентов здесь нет, заметки удалённых заявок стёрты.</p>
	</div>
</div>

<?php if ( ! $rows ) : ?>
	<section class="pp-empty"><h2>Записей пока нет</h2></section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table">
			<thead><tr><th>Когда</th><th>Кто</th><th>Заявка</th><th>Действие</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) :
				$alive = $r['request_id'] && pcb_crm_is_request( $r['request_id'] );
				$label = trim( $r['number'] . ( '' !== $r['code'] ? ' · ' . $r['code'] : '' ) );
				?>
				<tr>
					<td class="crm-nowrap"><?php echo esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); ?></td>
					<td><?php echo esc_html( pcb_crm_log_user( $r['user_id'] ) ); ?></td>
					<td class="crm-nowrap">
						<?php if ( $alive ) : ?>
							<a href="<?php echo esc_url( pcb_rq_panel_url( (int) $r['request_id'] ) ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php else : ?>
							<?php echo esc_html( '' !== $label ? $label : '—' ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<nav class="crm-pages" aria-label="Страницы">
		<?php if ( $pg > 1 ) : ?><a class="pp-filter" href="<?php echo esc_url( add_query_arg( 'pg', $pg - 1 ) ); ?>">← Новее</a><?php endif; ?>
		<?php if ( $more ) : ?><a class="pp-filter" href="<?php echo esc_url( add_query_arg( 'pg', $pg + 1 ) ); ?>">Старее →</a><?php endif; ?>
	</nav>
<?php endif; ?>
