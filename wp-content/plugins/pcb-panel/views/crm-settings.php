<?php
/**
 * «Реакция на заявку»: /panel/crm/settings/ — автоответ клиенту, кому уходят уведомления, состояние почты, история.
 * Правят администратор и менеджер (pcb_crm_edit); сохранение — REST pcb-panel/v1/crm/reaction (assets/reaction.js).
 * Логика и хранение — includes/requests/reaction.php, README §3.6.
 */
defined( 'ABSPATH' ) || exit;

$o        = pcb_rq_reaction();
$can_edit = current_user_can( 'pcb_crm_edit' );
$state    = pcb_rq_mail_state();
$history  = pcb_cl_log_get( 'crm:settings', 50 );
$domains  = pcb_rq_company_domains();
$fallback = pcb_rq_emails( pcb_rq_const( 'PCB_REQUEST_NOTIFY', 'info@pcb-vostok.ru' ) );
$me       = wp_get_current_user()->user_email;
$crm_nav  = 'settings';
include __DIR__ . '/_crm-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Реакция на заявку</h1>
		<p>Что получает клиент сразу после отправки заявки и кому из сотрудников приходит уведомление. Секреты почты (SMTP) — только в wp-config.php.</p>
	</div>
</div>

<div class="crm-card crm-react">
	<div class="crm-col">
		<form class="crm-box crm-form" id="rq-form"<?php echo $can_edit ? '' : ' inert'; ?>>
			<div class="crm-box__head"><h2>Автоответ клиенту</h2>
				<label class="crm-switch"><input type="checkbox" name="auto_on" value="1"<?php checked( $o['auto_on'] ); ?>> Отправлять</label></div>
			<div class="rq-auto">
				<label class="crm-field"><span>Тема</span><input type="text" name="auto_subject" maxlength="200" value="<?php echo esc_attr( $o['auto_subject'] ); ?>"></label>
				<label class="crm-field"><span>Текст</span><textarea name="auto_body" rows="9" maxlength="5000"><?php echo esc_textarea( $o['auto_body'] ); ?></textarea></label>
				<p class="crm-sub">Подстановки — нажмите, чтобы вставить:
					<?php foreach ( array( 'имя' => 'имя клиента; если не указано, «Здравствуйте, {имя}!» станет «Здравствуйте!»', 'код' => 'код заявки для клиента', 'услуга' => 'выбранная услуга', 'сайт' => 'адрес сайта' ) as $k => $t ) : ?>
						<button type="button" class="rq-var" data-var="{<?php echo esc_attr( $k ); ?>}" title="<?php echo esc_attr( $t ); ?>">{<?php echo esc_html( $k ); ?>}</button>
					<?php endforeach; ?>
				</p>
				<p class="rq-warn crm-sub" id="rq-nocode" hidden>В теме и тексте нет {код} — клиенту будет не на что сослаться в ответе.</p>
				<?php if ( $can_edit ) : ?>
					<div class="rq-row">
						<button type="button" class="crm-link" id="rq-defaults">Вернуть текст по умолчанию</button>
						<span class="rq-test">
							<input type="email" id="rq-test-to" value="<?php echo esc_attr( $me ); ?>" aria-label="Адрес для пробного письма">
							<button type="button" class="pp-btn pp-btn--sm pp-btn--ghost" data-test="auto">Пробное письмо</button>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<div class="crm-box__head rq-sep"><h2>Кому уведомления</h2></div>
			<label class="crm-field"><span>Основной ящик — полное письмо</span>
				<input type="text" name="main" value="<?php echo esc_attr( $o['main'] ); ?>" placeholder="<?php echo esc_attr( implode( ', ', $fallback ) ); ?>"></label>
			<p class="crm-sub">Контакты клиента, описание, расчёт, ссылки на файлы, «Открыть заявку»; ответ на письмо уходит клиенту.
				<?php echo '' === $o['main'] ? 'Пусто — берётся из wp-config.php (' . esc_html( implode( ', ', $fallback ) ) . ').' : 'Несколько адресов — через запятую.'; ?></p>

			<div class="rq-copies">
				<span class="rq-copies__h">Копии менеджерам — без данных клиента</span>
				<p class="crm-sub">Номер, код, услуга, откуда и ссылка на заявку — по ней нужен вход в панель. Контакты, описание и файлы в копию не попадают, поэтому можно указать и личную почту.
					«Полное письмо» — только для адресов домена компании: <?php echo esc_html( implode( ', ', $domains ) ); ?>.</p>
				<ul class="rq-list" id="rq-list">
					<?php foreach ( $o['copies'] as $c ) : ?>
						<li class="rq-item">
							<input type="email" value="<?php echo esc_attr( $c['email'] ); ?>" aria-label="Адрес копии">
							<label class="rq-full"><input type="checkbox"<?php checked( ! empty( $c['full'] ) ); ?>> полное письмо</label>
							<?php if ( $can_edit ) : ?><button type="button" class="crm-link" data-test="copy">пробное</button><button type="button" class="rq-del" aria-label="Убрать адрес">×</button><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $can_edit ) : ?><button type="button" class="crm-link" id="rq-add">+ Добавить адрес</button><?php endif; ?>
			</div>

			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить</button></div>
			<?php endif; ?>
		</form>
	</div>

	<div class="crm-col">
		<section class="crm-box">
			<h2>Как письмо увидит клиент</h2>
			<div class="rq-mail" id="rq-preview">
				<div class="rq-mail__head"><span>Тема:</span> <b id="rq-pv-subject"></b><span class="crm-sub">От: PCB Восток &lt;<?php echo esc_html( $state['from'] ); ?>&gt; · ответ — на <?php echo esc_html( pcb_rq_notify_main()[0] ?? $state['from'] ); ?></span></div>
				<pre class="rq-mail__body" id="rq-pv-body"></pre>
			</div>
			<p class="crm-sub" id="rq-pv-off" hidden>Автоответ выключен — клиент увидит только сообщение на сайте с кодом заявки.</p>
			<p class="crm-sub">Пример: Алексей, заявка 7F3A9C, «Платы под ключ».</p>
		</section>

		<section class="crm-box">
			<h2>Копия менеджеру</h2>
			<?php $copy = pcb_rq_copy_text( array( 'number' => wp_date( 'Y' ) . '-0012', 'code' => '7F3A9C', 'service' => 'Платы под ключ', 'source' => 'с калькулятора', 'url' => pcb_panel_url( 'crm' ) . wp_date( 'Y' ) . '-0012/' ) ); ?>
			<div class="rq-mail"><div class="rq-mail__head"><span>Тема:</span> <b><?php echo esc_html( $copy['subject'] ); ?></b></div><pre class="rq-mail__body"><?php echo esc_html( $copy['body'] ); ?></pre></div>
		</section>

		<section class="crm-box">
			<h2>Состояние почты</h2>
			<dl class="crm-dl">
				<dt>Отправка</dt><dd><?php echo $state['smtp'] ? 'SMTP, ' . esc_html( $state['host'] ) . ( $state['pass'] ? '' : ' — <b>пароль не задан</b>' ) : '<b>PHP mail()</b> — SMTP не настроен (PCB_SMTP_HOST в wp-config.php), письма могут попадать в «Спам»'; ?></dd>
				<dt>Отправитель</dt><dd><?php echo esc_html( $state['from'] ); ?></dd>
				<dt>Основной ящик</dt><dd><?php echo esc_html( implode( ', ', pcb_rq_notify_main() ) ); ?> <span class="crm-sub">задан: <?php echo esc_html( $state['main_from'] ); ?></span></dd>
				<dt>Последние заявки</dt><dd><?php echo $state['fails'] ? 'есть ошибки отправки:' : ( $state['checked'] ? 'все письма ушли (проверено ' . (int) $state['checked'] . ')' : 'заявок с отметкой об отправке пока нет' ); ?></dd>
			</dl>
			<?php if ( $state['fails'] ) : ?>
				<ul class="rq-fails">
					<?php foreach ( $state['fails'] as $f ) : ?>
						<li><a href="<?php echo esc_url( pcb_rq_panel_url( $f['id'] ) ); ?>"><?php echo esc_html( $f['number'] ); ?></a> · <?php echo esc_html( mysql2date( 'd.m.Y H:i', $f['date'] ) ); ?><span class="crm-sub"><?php echo esc_html( $f['text'] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( current_user_can( 'edit_pcb_requests' ) ) : ?>
				<p class="crm-sub"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=pcb_request&page=pcb-request-mail' ) ); ?>">Проверка почты</a> — настройки SMTP и тестовое письмо с журналом (консоль WordPress).</p>
			<?php endif; ?>
		</section>

		<details class="crm-box crm-fold">
			<summary><h2>История</h2><span class="crm-sub"><?php echo (int) count( $history ); ?></span></summary>
			<ul class="crm-history crm-scroll">
				<?php foreach ( $history as $r ) : ?>
					<li><?php echo esc_html( pcb_crm_log_text( $r ) ); ?><span class="crm-sub"><?php echo pcb_crm_user_html( $r['user_id'] ); // phpcs:ignore ?> · <?php echo esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( ! $history ) : ?><li class="pp-muted">Изменений ещё не было.</li><?php endif; ?>
			</ul>
		</details>
	</div>
</div>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBReaction = <?php echo wp_json_encode( array(
	'api'      => esc_url_raw( rest_url( 'pcb-panel/v1/crm/reaction' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'defaults' => pcb_rq_reaction_defaults(),
	'sample'   => pcb_rq_sample_vars(),
	'domains'  => $domains,
	'canEdit'  => $can_edit,
), JSON_UNESCAPED_UNICODE ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/reaction.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
