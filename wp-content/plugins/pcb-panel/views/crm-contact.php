<?php
/**
 * Карточка контактного лица: /panel/crm/contact/<id>/
 * ФИО, должность, компания, все почты и телефоны (по ним новые заявки привязываются сами), заявки, объединение.
 */
defined( 'ABSPATH' ) || exit;

$row      = $GLOBALS['pcb_panel_client'];
$id       = (int) $row['id'];
$can_edit = current_user_can( 'pcb_crm_edit' );
$ids      = pcb_cl_ids_of( 'contact', $id );
$company  = $row['company_id'] ? pcb_cl_company( $row['company_id'] ) : null;
$requests = pcb_cl_requests( 'contact', $id );
$history  = pcb_cl_log_get( 'contact:' . $id );
$crm_nav  = 'clients';
include __DIR__ . '/_crm-nav.php';
?>
<a class="crm-back" href="<?php echo esc_url( add_query_arg( 'type', 'contact', pcb_panel_url( 'crm' ) . 'clients/' ) ); ?>">← Все контакты</a>

<div class="pp-page-head">
	<div>
		<h1><?php echo esc_html( $row['name'] ?: 'Контакт без имени' ); ?></h1>
		<p><?php echo esc_html( $row['post'] ?: 'Должность не указана' ); ?> ·
			<?php echo $company ? '<a href="' . esc_url( pcb_cl_url( 'company', $company['id'] ) ) . '">' . esc_html( $company['name'] ) . '</a>' : 'без компании'; ?></p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<form class="crm-box crm-form" data-api="clients/contact/<?php echo (int) $id; ?>"<?php echo $can_edit ? '' : ' inert'; ?>>
			<h2>Контактное лицо</h2>
			<div class="crm-grid">
				<label>ФИО <input type="text" name="name" value="<?php echo esc_attr( $row['name'] ); ?>"></label>
				<label>Должность <input type="text" name="post" value="<?php echo esc_attr( $row['post'] ); ?>"></label>
				<label class="crm-grid__wide">Компания
					<div class="crm-pick" data-pick="company">
						<input type="search" value="<?php echo esc_attr( $company ? $company['name'] : '' ); ?>" placeholder="Начните вводить название или ИНН" autocomplete="off">
						<input type="hidden" name="company_id" value="<?php echo (int) $row['company_id']; ?>">
						<ul class="crm-pick__list" hidden></ul>
					</div>
				</label>
				<label class="crm-grid__wide">Комментарий <textarea name="note" rows="2"><?php echo esc_textarea( $row['note'] ); ?></textarea></label>
			</div>
			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts">
					<button class="pp-btn pp-btn--sm" type="submit">Сохранить</button>
					<span class="crm-sub">Сменили компанию — заявки контакта перейдут вместе с ним. Очистите поле, чтобы отвязать.</span>
				</div>
			<?php endif; ?>
		</form>

		<section class="crm-box">
			<h2>Почта и телефоны</h2>
			<p class="crm-sub">Новые заявки с этих адресов и номеров привяжутся к контакту автоматически.</p>
			<ul class="crm-files">
				<?php foreach ( array( 'email' => 'Почта', 'phone' => 'Телефон' ) as $kind => $label ) : foreach ( $ids[ $kind ] ?? array() as $v ) : ?>
					<li><span class="crm-tag"><?php echo esc_html( $label ); ?></span> <?php echo esc_html( pcb_cl_show_id( $kind, $v ) ); ?>
						<?php if ( $can_edit ) : ?><button class="crm-link" type="button" data-api-click="clients/contact/<?php echo (int) $id; ?>/channel" data-body="<?php echo esc_attr( wp_json_encode( array( 'kind' => $kind, 'value' => 'phone' === $kind ? '+' . $v : $v, 'remove' => 1 ) ) ); ?>" data-confirm="Убрать <?php echo esc_attr( mb_strtolower( $label ) ); ?> у контакта?">убрать</button><?php endif; ?>
					</li>
				<?php endforeach; endforeach; ?>
			</ul>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form crm-channel" data-api="clients/contact/<?php echo (int) $id; ?>/channel">
					<div class="crm-work__row">
						<label><span class="pp-sr">Тип</span><select name="kind"><option value="email">Почта</option><option value="phone">Телефон</option></select></label>
						<label><span class="pp-sr">Значение</span><input type="text" name="value" placeholder="ivan@company.ru или +7 912 345-67-89" required></label>
					</div>
					<button class="pp-btn pp-btn--sm" type="submit">Добавить</button>
				</form>
			<?php endif; ?>
		</section>
	</div>

	<div class="crm-col crm-col--side">
		<section class="crm-box">
			<h2>Заявки</h2>
			<?php if ( ! $requests ) : ?>
				<p class="pp-muted">Заявок нет.</p>
			<?php else : ?>
				<ul class="crm-related crm-scroll">
					<?php foreach ( $requests as $rid ) : ?>
						<li><a href="<?php echo esc_url( pcb_rq_panel_url( $rid ) ); ?>"><?php echo esc_html( pcb_rq_label( $rid ) ); ?></a>
							<span class="crm-sub"><?php echo esc_html( get_the_date( 'd.m.Y', $rid ) . ' · ' . pcb_rq_status_label( get_post_meta( $rid, '_pcb_status', true ) ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( $can_edit ) : ?>
			<details class="crm-box crm-fold">
				<summary><h2>Объединить с другим контактом</h2></summary>
				<form class="crm-form" data-api="clients/contact/<?php echo (int) $id; ?>/merge" data-confirm="Влить этот контакт в выбранный? Почты, телефоны и заявки перейдут туда.">
					<div class="crm-pick" data-pick="contact" data-exclude="<?php echo (int) $id; ?>">
						<input type="search" placeholder="ФИО, почта или телефон" autocomplete="off">
						<input type="hidden" name="into" required>
						<ul class="crm-pick__list" hidden></ul>
					</div>
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
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
