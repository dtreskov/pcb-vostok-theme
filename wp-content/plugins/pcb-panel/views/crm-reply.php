<?php
/**
 * Письмо клиенту: /panel/crm/<номер>/reply/ — открывается отдельным окном из карточки.
 * Отправка — REST pcb-panel/v1/requests/<id>/email (assets/crm.js), через SMTP сайта.
 */
defined( 'ABSPATH' ) || exit;

$id    = (int) $GLOBALS['pcb_panel_request_id'];
$m     = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
$files = (array) $m( 'files' );
$name  = (string) $m( 'name' );
$box   = pcb_crm_mailbox();
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">

<div class="crm-reply">
	<div class="pp-page-head">
		<div>
			<h1>Письмо клиенту</h1>
			<p>Заявка <?php echo esc_html( pcb_rq_label( $id ) ); ?> · уйдёт с <?php echo esc_html( $box ); ?>, копия — туда же</p>
		</div>
	</div>

	<form class="crm-form crm-mailform" data-crm-upload="email" data-id="<?php echo (int) $id; ?>">
		<label>Кому
			<input type="email" value="<?php echo esc_attr( $m( 'email' ) ); ?>" readonly>
		</label>
		<label>Тема
			<input type="text" name="subject" value="<?php echo esc_attr( 'Заявка № ' . $m( 'code' ) . ' — PCB Восток' ); ?>" required>
		</label>
		<label>Текст
			<textarea name="body" rows="12" required><?php echo esc_textarea( ( '' !== $name ? 'Здравствуйте, ' . $name . '!' : 'Здравствуйте!' ) . "\n\n" ); ?></textarea>
		</label>
		<div class="crm-signature">
			<span class="crm-sub">Подпись — добавится в конце письма · <a href="<?php echo esc_url( pcb_panel_url( 'profile' ) ); ?>" target="_blank" rel="noopener">изменить</a></span>
			<div class="crm-text"><?php echo esc_html( pcb_crm_signature() ); ?></div>
		</div>

		<?php if ( $files ) : ?>
			<fieldset class="crm-attach">
				<legend>Приложить файлы заявки</legend>
				<?php foreach ( $files as $f ) : if ( ! pcb_rq_file_path( $f ) ) { continue; } ?>
					<label class="crm-check"><input type="checkbox" name="attach[]" value="<?php echo esc_attr( $f['key'] ); ?>"> <?php echo esc_html( $f['name'] ); ?> <span class="crm-sub"><?php echo esc_html( size_format( $f['size'] ) ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<label class="crm-drop">
			<input type="file" name="files[]" multiple>
			<span>Новые вложения — например, КП в PDF</span>
			<span class="crm-sub">Сохранятся в файлах заявки с пометкой «отправлено клиенту». Всё вместе — до 20 МБ.</span>
		</label>
		<ul class="crm-picked"></ul>

		<div class="crm-form__acts">
			<button class="pp-btn" type="submit">Отправить</button>
			<button class="pp-btn pp-btn--ghost" type="button" data-crm-close>Закрыть</button>
		</div>
	</form>

	<section class="crm-box crm-sent" hidden>
		<h2>Письмо отправлено</h2>
		<p>Оно появится в разделе «Переписка» карточки заявки, копия — в ящике <?php echo esc_html( $box ); ?>.</p>
		<button class="pp-btn" type="button" data-crm-close>Закрыть окно</button>
	</section>
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
