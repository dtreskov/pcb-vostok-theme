<?php
/**
 * База клиентов: /panel/crm/clients/?type=company|contact — общая таблица (README §6.2): поиск ?q=, фильтры, ?sort=&dir=, ?pg=.
 */
defined( 'ABSPATH' ) || exit;

$type = isset( $_GET['type'] ) && 'contact' === $_GET['type'] ? 'contact' : 'company'; // phpcs:ignore WordPress.Security.NonceVerification
$base = pcb_panel_url( 'crm' ) . 'clients/';
$l    = new PCB_List(
	$base,
	array( 'updated' => 'desc', 'name' => 'asc', 'created' => 'desc', 'requests' => 'desc' ),
	'company' === $type
		? array(
			'tag'      => array( 'type' => 'select', 'label' => 'Метка', 'options' => pcb_tags_options( 'company' ), 'chip_value' => true ),
			'period'   => array( 'type' => 'period', 'label' => 'Добавлена' ),
			'verified' => array( 'type' => 'select', 'label' => 'Реквизиты', 'options' => array( 'yes' => 'Проверены', 'no' => 'Не проверены' ) ),
		)
		: array(
			'tag'       => array( 'type' => 'select', 'label' => 'Метка', 'options' => pcb_tags_options( 'contact' ), 'chip_value' => true ),
			'period'    => array( 'type' => 'period', 'label' => 'Добавлен' ),
			'nocompany' => array( 'type' => 'check', 'label' => 'Без компании' ),
		),
	array( 'type' => array( 'company', 'contact' ) )
);
$res  = pcb_cl_list_query( $type, $l );
$rows    = $res['rows'];
$tags    = pcb_tags_for( $type, wp_list_pluck( $rows, 'id' ) );
$can_tag = current_user_can( 'pcb_crm_edit' );
$crm_nav = 'clients';
include __DIR__ . '/_crm-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Клиенты</h1>
		<p>Компании и контактные лица. Заявки привязываются сами по почте и телефону; сомнительные совпадения — в разделе «Проверить».</p>
	</div>
	<?php if ( current_user_can( 'pcb_crm_edit' ) ) : ?>
		<div class="crm-head-actions">
			<a class="pp-btn pp-btn--ghost" href="<?php echo esc_url( pcb_panel_url( 'crm' ) . 'contact/new/' ); ?>">Новый контакт</a>
			<a class="pp-btn" href="<?php echo esc_url( pcb_panel_url( 'crm' ) . 'company/new/' ); ?>">Новая компания</a>
		</div>
	<?php endif; ?>
</div>

<?php
echo $l->toolbar( // phpcs:ignore -- разметка собрана с экранированием
	'company' === $type ? 'Название или ИНН' : 'ФИО, почта или телефон',
	'<nav class="crm-filters" aria-label="Тип">'
		. '<a class="pp-filter' . ( 'company' === $type ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( array_filter( array( 'q' => rawurlencode( $l->q ) ) ), $base ) ) . '">Компании</a>'
		. '<a class="pp-filter' . ( 'contact' === $type ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( array_filter( array( 'type' => 'contact', 'q' => rawurlencode( $l->q ) ) ), $base ) ) . '">Контактные лица</a>'
		. '</nav>'
);
?>

<?php if ( ! $rows ) : ?>
	<section class="pp-empty"><h2><?php echo $l->filtered() ? 'Ничего не найдено' : 'Пока пусто'; ?></h2><p><?php echo $l->filtered() ? 'Попробуйте другой запрос или сбросьте фильтры.' : 'Клиенты появляются автоматически из заявок; компанию или контакт можно добавить и вручную.'; ?></p></section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table">
			<?php if ( 'company' === $type ) : ?>
				<thead><tr><?php echo $l->th( 'name', 'Компания' ) . $l->th( '', 'Метки', 'crm-th-tags' ) . $l->th( '', 'ИНН' ) . $l->th( '', 'Контакты' ) . $l->th( 'requests', 'Заявки' ) . $l->th( 'created', 'Добавлена' ) . $l->th( 'updated', 'Обновлена' ); // phpcs:ignore ?></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr<?php echo PCB_List::row( pcb_cl_url( 'company', $r['id'] ) ); // phpcs:ignore ?>>
						<td><a class="crm-num" href="<?php echo esc_url( pcb_cl_url( 'company', $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ?: 'без названия' ); ?></a>
							<?php if ( $r['verified'] ) : ?><span class="crm-tag crm-tag--sent">проверено</span><?php endif; ?>
							<?php if ( $r['full_name'] ) : ?><span class="crm-sub"><?php echo esc_html( $r['full_name'] ); ?></span><?php endif; ?></td>
						<td class="crm-td-tags"<?php echo $can_tag ? ' data-no-row' : ''; ?>><?php echo pcb_tags_html( 'company', $r['id'], $tags[ (int) $r['id'] ] ?? array(), $can_tag ); // phpcs:ignore ?></td>
						<td class="crm-nowrap"><?php echo esc_html( $r['inn'] ?: '—' ); ?></td>
						<td><?php echo (int) $r['n_contacts']; ?></td>
						<td><?php echo (int) $r['n_requests']; ?></td>
						<td><?php echo PCB_List::date( $r['created'] ); // phpcs:ignore ?></td>
						<td><?php echo PCB_List::date( $r['updated'] ); // phpcs:ignore ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php else : ?>
				<thead><tr><?php echo $l->th( 'name', 'Контакт' ) . $l->th( '', 'Метки', 'crm-th-tags' ) . $l->th( '', 'Компания' ) . $l->th( '', 'Почта и телефон' ) . $l->th( 'requests', 'Заявки' ) . $l->th( 'created', 'Добавлен' ) . $l->th( 'updated', 'Обновлён' ); // phpcs:ignore ?></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$ids = pcb_cl_ids_of( 'contact', $r['id'] );
					$co  = $r['company_id'] ? pcb_cl_company( $r['company_id'] ) : null;
					?>
					<tr<?php echo PCB_List::row( pcb_cl_url( 'contact', $r['id'] ) ); // phpcs:ignore ?>>
						<td><a class="crm-num" href="<?php echo esc_url( pcb_cl_url( 'contact', $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ?: 'без имени' ); ?></a>
							<?php if ( $r['post'] ) : ?><span class="crm-sub"><?php echo esc_html( $r['post'] ); ?></span><?php endif; ?></td>
						<td class="crm-td-tags"<?php echo $can_tag ? ' data-no-row' : ''; ?>><?php echo pcb_tags_html( 'contact', $r['id'], $tags[ (int) $r['id'] ] ?? array(), $can_tag ); // phpcs:ignore ?></td>
						<td><?php echo $co ? '<a href="' . esc_url( pcb_cl_url( 'company', $co['id'] ) ) . '">' . esc_html( $co['name'] ) . '</a>' : '<span class="pp-muted">без компании</span>'; ?></td>
						<td><?php echo esc_html( implode( ', ', array_merge( $ids['email'] ?? array(), array_map( function ( $p ) { return pcb_cl_show_id( 'phone', $p ); }, $ids['phone'] ?? array() ) ) ) ); ?></td>
						<td><?php echo (int) $r['n_requests']; ?></td>
						<td><?php echo PCB_List::date( $r['created'] ); // phpcs:ignore ?></td>
						<td><?php echo PCB_List::date( $r['updated'] ); // phpcs:ignore ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php endif; ?>
		</table>
	</div>
	<?php echo $l->pages( $res['total'] ); // phpcs:ignore ?>
<?php endif; ?>
<?php echo PCB_List::script() . ( $can_tag ? pcb_tags_script( array( $type ) ) : '' ); // phpcs:ignore ?>
