<?php
/**
 * Карточка компании: /panel/crm/company/<id>/ (и /company/new/ — новая).
 * Слева — реквизиты (проверка контрольных сумм, автозаполнение по ИНН организации), справа — контакты, заявки,
 * объединение, история. Сохранение — REST pcb-panel/v1/clients/company[/<id>].
 */
defined( 'ABSPATH' ) || exit;

$row      = $GLOBALS['pcb_panel_client'];
$is_new   = null === $row;
$id       = $is_new ? 0 : (int) $row['id'];
$can_edit = current_user_can( 'pcb_crm_edit' );
$fields   = pcb_cl_company_fields();
$groups   = array();
foreach ( $fields as $k => $f ) {
	$groups[ $f[1] ][ $k ] = $f;
}
$crm_nav = 'clients';
include __DIR__ . '/_crm-nav.php';
?>
<a class="crm-back" href="<?php echo esc_url( pcb_panel_url( 'crm' ) . 'clients/' ); ?>">← Все клиенты</a>

<div class="pp-page-head">
	<div>
		<h1><?php echo esc_html( $is_new ? 'Новая компания' : ( $row['name'] ?: 'Компания без названия' ) ); ?></h1>
		<?php if ( ! $is_new ) : ?>
			<p><?php echo esc_html( trim( ( $row['inn'] ? 'ИНН ' . $row['inn'] : 'ИНН не указан' ) . ( $row['kpp'] ? ' · КПП ' . $row['kpp'] : '' ) ) ); ?>
				<?php echo $row['verified'] ? '<span class="crm-tag crm-tag--sent">реквизиты проверены</span>' : '<span class="crm-tag">не проверено</span>'; ?></p>
		<?php endif; ?>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form crm-requisites" data-api="<?php echo esc_attr( $is_new ? 'clients/company' : 'clients/company/' . $id ); ?>"<?php echo $can_edit ? '' : ' inert'; ?>>
			<?php foreach ( $groups as $g => $list ) : ?>
				<fieldset>
					<legend><?php echo esc_html( $g ); ?></legend>
					<div class="crm-grid">
						<?php foreach ( $list as $k => $f ) : ?>
							<label class="<?php echo 'area' === $f[2] ? 'crm-grid__wide' : ''; ?>" data-field="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?>
								<?php if ( 'area' === $f[2] ) : ?>
									<textarea name="<?php echo esc_attr( $k ); ?>" rows="2"><?php echo esc_textarea( $row[ $k ] ?? '' ); ?></textarea>
								<?php else : ?>
									<input type="text" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $row[ $k ] ?? '' ); ?>"<?php echo 'digits' === $f[2] ? ' inputmode="numeric"' : ''; ?><?php echo 'name' === $k ? ' required' : ''; ?>>
								<?php endif; ?>
								<?php if ( 'inn' === $k && $can_edit && pcb_cl_dadata_enabled() ) : ?>
									<button class="crm-link" type="button" data-dadata>Заполнить по ИНН</button>
								<?php endif; ?>
								<span class="crm-err" hidden></span>
							</label>
						<?php endforeach; ?>
					</div>
					<?php if ( 'Адреса' === $g && $can_edit ) : ?>
						<button class="crm-link" type="button" data-copy-addr>Фактический и почтовый — как юридический</button>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts">
					<?php if ( ! $is_new ) : ?>
						<label class="crm-check"><input type="checkbox" name="verified" value="1"<?php checked( (int) $row['verified'], 1 ); ?>> Реквизиты проверены</label>
						<input type="hidden" name="verified_present" value="1">
					<?php endif; ?>
					<button class="pp-btn" type="submit"><?php echo $is_new ? 'Создать компанию' : 'Сохранить реквизиты'; ?></button>
				</div>
				<p class="crm-sub">ИНН, ОГРН, БИК и счета проверяются по контрольным числам. <?php echo pcb_cl_dadata_enabled() ? 'Автозаполнение по ИНН — только для организаций (10 цифр); реквизиты ИП вводятся вручную.' : 'Автозаполнение по ИНН не подключено (нет ключа DaData).'; ?></p>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( ! $is_new ) :
		global $wpdb;
		$contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pcb_cl_table( 'contacts' ) . ' WHERE company_id = %d AND merged_into = 0 ORDER BY name', $id ), ARRAY_A );
		$requests = pcb_cl_requests( 'company', $id );
		$domains  = pcb_cl_ids_of( 'company', $id )['domain'] ?? array();
		$history  = pcb_cl_log_get( 'company:' . $id );
		?>
		<div class="crm-col crm-col--side">
			<section class="crm-box">
				<h2>Контактные лица</h2>
				<?php if ( ! $contacts ) : ?>
					<p class="pp-muted">Пока нет.</p>
				<?php else : ?>
					<ul class="crm-related">
						<?php foreach ( $contacts as $c ) : ?>
							<li><a href="<?php echo esc_url( pcb_cl_url( 'contact', $c['id'] ) ); ?>"><?php echo esc_html( $c['name'] ?: 'без имени' ); ?></a>
								<span class="crm-sub"><?php echo esc_html( trim( $c['post'] . ' · ' . implode( ', ', pcb_cl_ids_of( 'contact', $c['id'] )['email'] ?? array() ), ' ·' ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?><p><a class="crm-link" href="<?php echo esc_url( add_query_arg( 'company', $id, pcb_panel_url( 'crm' ) . 'contact/new/' ) ); ?>">+ Добавить контактное лицо</a></p><?php endif; ?>
				<?php if ( $domains ) : ?><p class="crm-sub">Домены почты: <?php echo esc_html( implode( ', ', $domains ) ); ?> — новые письма с них предлагаются этой компании.</p><?php endif; ?>
			</section>

			<section class="crm-box">
				<h2>Заявки</h2>
				<?php if ( ! $requests ) : ?>
					<p class="pp-muted">Заявок нет.</p>
				<?php else : ?>
					<ul class="crm-related crm-scroll">
						<?php foreach ( $requests as $rid ) : ?>
							<li><a href="<?php echo esc_url( pcb_rq_panel_url( $rid ) ); ?>"><?php echo esc_html( pcb_rq_label( $rid ) ); ?></a>
								<span class="crm-sub"><?php echo esc_html( get_the_date( 'd.m.Y', $rid ) . ' · ' . pcb_rq_status_label( get_post_meta( $rid, '_pcb_status', true ) ) . ' · ' . pcb_rq_service_label( get_post_meta( $rid, '_pcb_service', true ) ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<?php if ( $can_edit ) : ?>
				<details class="crm-box crm-fold">
					<summary><h2>Объединить с другой компанией</h2></summary>
					<form class="crm-form" data-api="clients/company/<?php echo (int) $id; ?>/merge" data-confirm="Влить эту компанию в выбранную? Её контакты, заявки и недостающие реквизиты перейдут туда.">
						<div class="crm-pick" data-pick="company" data-exclude="<?php echo (int) $id; ?>">
							<input type="search" placeholder="Название или ИНН" autocomplete="off">
							<input type="hidden" name="into" required>
							<ul class="crm-pick__list" hidden></ul>
						</div>
						<p class="crm-sub">Эта компания вольётся в выбранную: пустые реквизиты выбранной заполнятся отсюда, контакты и заявки перейдут к ней.</p>
						<button class="pp-btn pp-btn--sm" type="submit">Объединить</button>
					</form>
				</details>
			<?php endif; ?>

			<details class="crm-box crm-fold" open>
				<summary><h2>История</h2><span class="crm-sub"><?php echo (int) count( $history ); ?></span></summary>
				<ul class="crm-history crm-scroll">
					<?php foreach ( $history as $r ) : ?>
						<li><span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
							<span class="crm-sub"><?php echo pcb_crm_user_html( $r['user_id'] ) . ' · ' . esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); // phpcs:ignore ?></span></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
	<?php endif; ?>
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
