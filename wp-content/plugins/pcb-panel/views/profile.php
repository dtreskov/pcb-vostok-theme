<?php
/**
 * «Мой профиль»: /panel/profile/ — подпись в письмах клиентам. Доступен всем пользователям панели.
 */
defined( 'ABSPATH' ) || exit;

$user = wp_get_current_user();
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">

<div class="pp-page-head">
	<div>
		<h1>Мой профиль</h1>
		<p><?php echo esc_html( $user->display_name . ' · ' . $user->user_email . ' · ' . pcb_panel_role_label() ); ?></p>
	</div>
</div>

<div class="crm-profile">
	<section class="crm-box">
		<h2>Отображаемое имя</h2>
		<form class="crm-form" data-crm-me="name">
			<label><span class="pp-sr">Отображаемое имя</span>
				<input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" maxlength="60" required>
			</label>
			<p class="crm-sub">Так вас видят в панели: в истории, заметках, журнале. Логин <b><?php echo esc_html( $user->user_login ); ?></b> не меняется — он показывается при наведении на имя, по нему всегда видно, кто что сделал.</p>
			<button class="pp-btn pp-btn--sm" type="submit">Сохранить имя</button>
		</form>
	</section>

	<section class="crm-box">
		<h2>Подпись в письмах клиентам</h2>
		<form class="crm-form" data-crm-me="signature">
			<label><span class="pp-sr">Подпись</span>
				<textarea name="signature" rows="6"><?php echo esc_textarea( pcb_crm_signature( $user->ID ) ); ?></textarea>
			</label>
			<p class="crm-sub">Добавляется в конце каждого письма из панели после строки «-- ». До 1000 знаков, без оформления.</p>
			<button class="pp-btn pp-btn--sm" type="submit">Сохранить подпись</button>
		</form>
	</section>

	<section class="crm-box crm-box--muted">
		<h2>Вход и безопасность</h2>
		<p>Пароль и двухфакторный вход настраиваются в <a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">профиле WordPress</a>.</p>
	</section>
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'me' => esc_url_raw( rest_url( 'pcb-panel/v1/me/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
