<?php
/**
 * Новый контакт вручную: /panel/crm/contact/new/[?company=<id>] — например, клиент позвонил или написал напрямую.
 * REST pcb-panel/v1/clients/contact. Дубли по почте и мобильному не создаются.
 */
defined( 'ABSPATH' ) || exit;

$pre     = isset( $_GET['company'] ) ? pcb_cl_company( (int) $_GET['company'] ) : null;
$crm_nav = 'clients';
include __DIR__ . '/_crm-nav.php';
?>
<a class="crm-back" href="<?php echo esc_url( $pre ? pcb_cl_url( 'company', $pre['id'] ) : add_query_arg( 'type', 'contact', pcb_panel_url( 'crm' ) . 'clients/' ) ); ?>">← <?php echo $pre ? esc_html( $pre['name'] ) : 'Все контакты'; ?></a>

<div class="pp-page-head">
	<div>
		<h1>Новый контакт</h1>
		<p>Без заявки — например, клиент позвонил или написал на почту. Будущие заявки с этой почты или мобильного привяжутся к нему сами.</p>
	</div>
</div>

<div class="crm-reply">
	<form class="crm-box crm-form" data-api="clients/contact">
		<div class="crm-grid">
			<label data-field="name">ФИО <input type="text" name="name" required minlength="2"><span class="crm-err" hidden></span></label>
			<label data-field="post">Должность <input type="text" name="post"><span class="crm-err" hidden></span></label>
			<label data-field="email">Почта <input type="email" name="email" placeholder="ivan@company.ru"><span class="crm-err" hidden></span></label>
			<label data-field="phone">Телефон <input type="tel" name="phone" placeholder="+7 912 345-67-89" pattern="[+0-9\s\(\)\-\.]{5,25}" title="Цифры, пробелы, скобки, дефисы и «+»"><span class="crm-err" hidden></span></label>
			<label class="crm-grid__wide" data-field="company_id">Компания <span class="crm-sub">необязательно</span>
				<div class="crm-pick" data-pick="company">
					<input type="search" value="<?php echo esc_attr( $pre ? $pre['name'] : '' ); ?>" placeholder="Название или ИНН" autocomplete="off">
					<input type="hidden" name="company_id" value="<?php echo (int) ( $pre['id'] ?? 0 ); ?>">
					<ul class="crm-pick__list" hidden></ul>
				</div>
				<span class="crm-err" hidden></span>
			</label>
		</div>
		<p class="crm-sub">Нужна почта или телефон. Если такая почта или мобильный уже есть у другого контакта, откроем его вместо создания дубля. Нет компании в списке — сначала <a href="<?php echo esc_url( pcb_panel_url( 'crm' ) . 'company/new/' ); ?>">создайте её</a>.</p>
		<div class="crm-form__acts"><button class="pp-btn" type="submit">Создать контакт</button></div>
	</form>
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
