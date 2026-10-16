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
<?php $crm_nav = 'journal'; include __DIR__ . '/_crm-nav.php'; ?>

<div class="pp-page-head">
	<div>
		<h1>Журнал действий</h1>
		<p>Кто и что менял: заявки, клиенты, SEO и техработы. Наведите на имя — увидите логин. У удалённых заявок тексты заметок, писем и значения контактов стёрты.</p>
	</div>
</div>

<?php if ( ! $rows ) : ?>
	<section class="pp-empty"><h2>Записей пока нет</h2></section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table">
			<thead><tr><th>Когда</th><th>Кто</th><th>Объект</th><th>Действие</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) :
				$alive = $r['request_id'] && pcb_crm_is_request( $r['request_id'] );
				$label = trim( $r['number'] . ( '' !== $r['code'] ? ' · ' . $r['code'] : '' ) );
				?>
				<tr>
					<td class="crm-nowrap"><?php echo esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); ?></td>
					<td><?php echo pcb_crm_user_html( $r['user_id'] ); // phpcs:ignore -- экранировано ?></td>
					<td class="crm-nowrap">
						<?php if ( 0 === strpos( (string) $r['entity'], 'seo:' ) ) :
							$sk = substr( $r['entity'], 4 );
							$sl = ctype_digit( $sk ) ? 'SEO: ' . ( get_the_title( (int) $sk ) ?: '#' . $sk ) : 'SEO: ' . ( array( 'site' => 'данные сайта', 'robots' => 'robots.txt', 'maint' => 'техработы' )[ $sk ] ?? $sk );
							$su = pcb_panel_url( 'seo' ) . ( ctype_digit( $sk ) ? $sk : ( 'maint' === $sk ? 'maintenance' : $sk ) ) . '/'; ?>
							<a href="<?php echo esc_url( $su ); ?>"><?php echo esc_html( $sl ); ?></a>
						<?php elseif ( ! $r['request_id'] && '' !== $r['entity'] ) :
							list( $et, $eid ) = array_pad( explode( ':', $r['entity'] ), 2, 0 );
							$er = pcb_cl_get( $et, (int) $eid ); ?>
							<a href="<?php echo esc_url( pcb_cl_url( $et, $eid ) ); ?>"><?php echo esc_html( ( 'company' === $et ? 'Компания ' : 'Контакт ' ) . ( $er ? ( $er['name'] ?: '#' . $eid ) : '#' . $eid ) ); ?></a>
						<?php elseif ( $alive ) : ?>
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
