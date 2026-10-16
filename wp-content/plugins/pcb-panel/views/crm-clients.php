<?php
/**
 * База клиентов: /panel/crm/clients/?type=company|contact&q=
 */
defined( 'ABSPATH' ) || exit;

$type = isset( $_GET['type'] ) && 'contact' === $_GET['type'] ? 'contact' : 'company';
$q    = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
$rows = pcb_cl_list( $type, $q, 200 );
$base = pcb_panel_url( 'crm' ) . 'clients/';
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

<form class="crm-toolbar" method="get" action="<?php echo esc_url( $base ); ?>">
	<nav class="crm-filters" aria-label="Тип">
		<a class="pp-filter<?php echo 'company' === $type ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'q' => $q ) ), $base ) ); ?>">Компании</a>
		<a class="pp-filter<?php echo 'contact' === $type ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'type' => 'contact', 'q' => $q ) ), $base ) ); ?>">Контактные лица</a>
	</nav>
	<?php if ( 'contact' === $type ) : ?><input type="hidden" name="type" value="contact"><?php endif; ?>
	<label class="crm-search"><span class="pp-sr">Поиск</span>
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo 'company' === $type ? 'Название или ИНН' : 'ФИО, почта или телефон'; ?>">
	</label>
</form>

<?php if ( ! $rows ) : ?>
	<section class="pp-empty"><h2><?php echo '' !== $q ? 'Ничего не найдено' : 'Пока пусто'; ?></h2><p>Клиенты появляются автоматически из заявок; компанию или контакт можно добавить и вручную.</p></section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table">
			<?php if ( 'company' === $type ) : ?>
				<thead><tr><th>Компания</th><th>ИНН</th><th>Контакты</th><th>Заявки</th><th>Обновлена</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					global $wpdb;
					$nc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . pcb_cl_table( 'contacts' ) . ' WHERE company_id = %d AND merged_into = 0', $r['id'] ) );
					?>
					<tr>
						<td><a class="crm-num" href="<?php echo esc_url( pcb_cl_url( 'company', $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ?: 'без названия' ); ?></a>
							<?php if ( $r['verified'] ) : ?><span class="crm-tag crm-tag--sent">проверено</span><?php endif; ?>
							<?php if ( $r['full_name'] ) : ?><span class="crm-sub"><?php echo esc_html( $r['full_name'] ); ?></span><?php endif; ?></td>
						<td class="crm-nowrap"><?php echo esc_html( $r['inn'] ?: '—' ); ?></td>
						<td><?php echo (int) $nc; ?></td>
						<td><?php echo (int) count( pcb_cl_requests( 'company', $r['id'] ) ); ?></td>
						<td class="crm-nowrap"><?php echo esc_html( mysql2date( 'd.m.Y', $r['updated'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php else : ?>
				<thead><tr><th>Контакт</th><th>Компания</th><th>Почта и телефон</th><th>Заявки</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$ids = pcb_cl_ids_of( 'contact', $r['id'] );
					$co  = $r['company_id'] ? pcb_cl_company( $r['company_id'] ) : null;
					?>
					<tr>
						<td><a class="crm-num" href="<?php echo esc_url( pcb_cl_url( 'contact', $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ?: 'без имени' ); ?></a>
							<?php if ( $r['post'] ) : ?><span class="crm-sub"><?php echo esc_html( $r['post'] ); ?></span><?php endif; ?></td>
						<td><?php echo $co ? '<a href="' . esc_url( pcb_cl_url( 'company', $co['id'] ) ) . '">' . esc_html( $co['name'] ) . '</a>' : '<span class="pp-muted">без компании</span>'; ?></td>
						<td><?php echo esc_html( implode( ', ', array_merge( $ids['email'] ?? array(), array_map( function ( $p ) { return pcb_cl_show_id( 'phone', $p ); }, $ids['phone'] ?? array() ) ) ) ); ?></td>
						<td><?php echo (int) count( pcb_cl_requests( 'contact', $r['id'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php endif; ?>
		</table>
	</div>
<?php endif; ?>
