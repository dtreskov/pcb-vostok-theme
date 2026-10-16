<?php
/**
 * Карточка заявки: /panel/crm/<номер>/
 * Слева — что прислал клиент (контакты с правками менеджера, описание, калькулятор, файлы, переписка),
 * справа — рабочая часть (статус, услуга, заметки, история, Метрика).
 * Правки отправляет assets/crm.js в REST pcb-panel/v1/requests/<id>/… (права проверяются там же).
 */
defined( 'ABSPATH' ) || exit;

$id       = (int) $GLOBALS['pcb_panel_request_id'];
$post     = get_post( $id );
$m        = function ( $k ) use ( $id ) { return get_post_meta( $id, '_pcb_' . $k, true ); };
$status   = (string) $m( 'status' );
$service  = (string) $m( 'service' );
$client   = (string) $m( 'service_client' );
$files    = (array) $m( 'files' );
$mail     = (array) $m( 'mail' );
$orig     = pcb_crm_original( $id );
$can_edit = current_user_can( 'pcb_crm_edit' );
$can_del  = current_user_can( 'pcb_crm_delete' );
$history  = pcb_crm_log_get( $id );
$notes    = array_values( array_filter( $history, function ( $r ) { return 'note' === $r['action']; } ) );
$emails   = array_values( array_filter( $history, function ( $r ) { return 'email' === $r['action']; } ) );
// важные нерешённые — сверху
usort( $notes, function ( $a, $b ) {
	$ia = 'important' === $a['old_value'];
	$ib = 'important' === $b['old_value'];
	return $ia === $ib ? (int) $b['id'] - (int) $a['id'] : ( $ia ? -1 : 1 );
} );

$services = PCB_RQ_SERVICES;
foreach ( array( $service, $client ) as $v ) {
	if ( '' !== $v && ! isset( $services[ $v ] ) ) {
		$services[ $v ] = $v;
	}
}

$related_q = array( 'relation' => 'OR', array( 'key' => '_pcb_email', 'value' => (string) $m( 'email' ) ) );
if ( '' !== (string) $m( 'company' ) ) {
	$related_q[] = array( 'key' => '_pcb_company', 'value' => (string) $m( 'company' ) );
}
$related = get_posts( array(
	'post_type'      => 'pcb_request',
	'post_status'    => 'any',
	'posts_per_page' => 10,
	'post__not_in'   => array( $id ),
	'meta_query'     => $related_q,
) );

$calc_rows = pcb_crm_calc_rows( (array) $m( 'calc' ) );
$desc      = (string) $post->post_content;
$reply_url = pcb_rq_panel_url( $id ) . 'reply/';
$ym_id     = (string) $m( 'ym_client' );
$counter   = function_exists( 'pcb_ya_counter' ) ? (int) pcb_ya_counter() : ( defined( 'PCB_METRIKA_ID' ) ? (int) PCB_METRIKA_ID : 0 );
$who       = function ( $uid, $time ) { return pcb_crm_log_user( $uid ) . ' · ' . mysql2date( 'd.m.Y H:i', $time ); };
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">

<a class="crm-back" href="<?php echo esc_url( pcb_panel_url( 'crm' ) ); ?>">← Все заявки</a>

<div class="pp-page-head crm-card-head">
	<div>
		<h1>Заявка <?php echo esc_html( $m( 'number' ) ); ?></h1>
		<p>
			Код для клиента <b class="crm-code crm-code--lg"><?php echo esc_html( $m( 'code' ) ); ?></b>
			· <?php echo esc_html( get_the_date( 'd.m.Y H:i', $id ) ); ?>
			· <?php echo 'calc' === $m( 'source' ) ? 'с калькулятора' : 'с главной'; ?>
		</p>
	</div>
	<div class="crm-head-actions">
		<span class="crm-status crm-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( pcb_rq_status_label( $status ) ); ?></span>
		<?php if ( $can_edit ) : ?>
			<a class="pp-btn" href="<?php echo esc_url( $reply_url ); ?>" data-crm-window>Ответить</a>
		<?php endif; ?>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">

		<section class="crm-box">
			<div class="crm-box__head">
				<h2>Клиент</h2>
				<?php if ( $can_edit ) : ?><button class="crm-link" type="button" data-crm-toggle="crm-contacts-form">Изменить</button><?php endif; ?>
			</div>
			<dl class="crm-dl">
				<?php foreach ( pcb_crm_contact_fields() as $k => $label ) :
					$cur     = (string) $m( $k );
					$was     = (string) ( $orig[ $k ] ?? $cur );
					$edited  = $was !== $cur;
					?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd>
						<span class="<?php echo $edited ? 'crm-edited' : ''; ?>">
							<?php if ( 'email' === $k && '' !== $cur ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $cur ); ?>"><?php echo esc_html( $cur ); ?></a>
							<?php elseif ( 'phone' === $k && '' !== $cur ) : ?>
								<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $cur ) ); ?>"><?php echo esc_html( $cur ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $cur ? $cur : '—' ); ?>
							<?php endif; ?>
						</span>
						<?php if ( $edited ) : ?>
							<span class="crm-sub">В заявке: <?php echo esc_html( '' !== $was ? $was : 'не указано' ); ?></span>
						<?php endif; ?>
					</dd>
				<?php endforeach; ?>
			</dl>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form crm-form--inline" id="crm-contacts-form" data-crm="contacts" data-id="<?php echo (int) $id; ?>" hidden>
					<?php foreach ( pcb_crm_contact_fields() as $k => $label ) : ?>
						<label><?php echo esc_html( $label ); ?>
							<input type="<?php echo 'email' === $k ? 'email' : ( 'phone' === $k ? 'tel' : 'text' ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $m( $k ) ); ?>">
						</label>
					<?php endforeach; ?>
					<p class="crm-sub">Исходные значения из заявки сохраняются — исправленное поле будет видно рядом с ними.</p>
					<div class="crm-form__acts">
						<button class="pp-btn pp-btn--sm" type="submit">Сохранить</button>
						<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-crm-toggle="crm-contacts-form">Отмена</button>
					</div>
				</form>
			<?php endif; ?>
		</section>

		<details class="crm-box crm-fold"<?php echo mb_strlen( $desc ) <= 600 ? ' open' : ''; ?>>
			<summary><h2>Описание задачи</h2><?php if ( mb_strlen( $desc ) > 600 ) : ?><span class="crm-sub"><?php echo (int) mb_strlen( $desc ); ?> знаков</span><?php endif; ?></summary>
			<?php if ( '' !== trim( $desc ) ) : ?>
				<div class="crm-text"><?php echo esc_html( $desc ); ?></div>
			<?php else : ?>
				<p class="pp-muted">Клиент не написал описание.</p>
			<?php endif; ?>
		</details>

		<?php if ( $calc_rows ) : ?>
			<details class="crm-box crm-fold"<?php echo count( $calc_rows ) <= 8 ? ' open' : ''; ?>>
				<summary><h2>Данные калькулятора</h2><span class="crm-sub"><?php echo (int) count( $calc_rows ); ?> параметров</span></summary>
				<dl class="crm-dl crm-dl--wide">
					<?php foreach ( $calc_rows as $row ) : ?>
						<dt<?php echo 0 === strpos( $row[0], '· ' ) ? ' class="crm-dl__sub"' : ''; ?>><?php echo esc_html( ltrim( $row[0], '· ' ) ); ?></dt><dd><?php echo esc_html( $row[1] ); ?></dd>
					<?php endforeach; ?>
				</dl>
			</details>
		<?php endif; ?>

		<section class="crm-box">
			<div class="crm-box__head">
				<h2>Файлы</h2>
				<?php if ( $can_edit ) : ?><button class="crm-link" type="button" data-crm-toggle="crm-files-form">Добавить файлы</button><?php endif; ?>
			</div>
			<?php if ( ! $files ) : ?>
				<p class="pp-muted">Не приложены.</p>
			<?php else : ?>
				<ul class="crm-files">
					<?php foreach ( $files as $f ) :
						$kind = $f['kind'] ?? 'client';
						?>
						<li>
							<?php if ( pcb_rq_file_path( $f ) ) : ?>
								<a href="<?php echo esc_url( pcb_rq_file_url( $id, $f['key'] ) ); ?>"><?php echo esc_html( $f['name'] ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( $f['name'] ); ?></span> <span class="crm-sub">файл не найден на диске</span>
							<?php endif; ?>
							<span class="crm-sub"><?php echo esc_html( size_format( $f['size'] ) ); ?></span>
							<?php if ( 'manager' === $kind ) : ?>
								<span class="crm-tag">добавил <?php echo esc_html( $who( $f['by'] ?? 0, $f['added'] ?? '' ) ); ?></span>
							<?php elseif ( 'sent' === $kind ) : ?>
								<span class="crm-tag crm-tag--sent">отправлено клиенту · <?php echo esc_html( $who( $f['by'] ?? 0, $f['added'] ?? '' ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form crm-upload" id="crm-files-form" data-crm-upload="files" data-id="<?php echo (int) $id; ?>" hidden>
					<label class="crm-drop">
						<input type="file" name="files[]" multiple>
						<span>Перетащите файлы сюда или выберите на компьютере</span>
						<span class="crm-sub">Например, файлы, которые клиент дослал на почту. До 10 файлов и 50 МБ за раз, без исполняемых файлов.</span>
					</label>
					<ul class="crm-picked"></ul>
					<div class="crm-form__acts">
						<button class="pp-btn pp-btn--sm" type="submit">Загрузить</button>
						<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-crm-toggle="crm-files-form">Отмена</button>
					</div>
				</form>
			<?php endif; ?>
		</section>

		<?php if ( $emails ) : ?>
			<section class="crm-box">
				<h2>Переписка</h2>
				<ul class="crm-mails">
					<?php foreach ( $emails as $r ) :
						$e = json_decode( $r['new_value'], true );
						?>
						<li>
							<details>
								<summary>
									<b><?php echo esc_html( is_array( $e ) ? $e['subject'] : 'Письмо' ); ?></b>
									<span class="crm-sub"><?php echo esc_html( $who( $r['user_id'], $r['time'] ) . ( ! empty( $e['files'] ) ? ' · вложения: ' . implode( ', ', $e['files'] ) : '' ) ); ?></span>
								</summary>
								<div class="crm-text"><?php echo esc_html( is_array( $e ) ? $e['body'] : $r['new_value'] ); ?></div>
							</details>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="crm-sub">Ответы клиента приходят в почтовый ящик <?php echo esc_html( pcb_crm_mailbox() ); ?> — в CRM они не попадают.</p>
			</section>
		<?php endif; ?>

		<details class="crm-box crm-box--muted crm-fold">
			<summary><h2>Служебное</h2></summary>
			<dl class="crm-dl">
				<dt>Письмо менеджерам</dt><dd><?php echo esc_html( $mail['notify'] ?? '—' ); ?></dd>
				<dt>Письмо клиенту</dt><dd><?php echo esc_html( $mail['client'] ?? '—' ); ?></dd>
				<dt>Капча</dt><dd><?php echo esc_html( $m( 'captcha' ) ?: '—' ); ?></dd>
				<dt>Страница</dt><dd><?php echo esc_html( $m( 'page' ) ?: '—' ); ?></dd>
				<dt>Переход с</dt><dd><?php echo esc_html( $m( 'referrer' ) ?: '—' ); ?></dd>
				<?php $utm = (array) $m( 'utm' ); if ( $utm ) : ?>
					<dt>UTM</dt><dd><?php echo esc_html( implode( ', ', array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $utm ), $utm ) ) ); ?></dd>
				<?php endif; ?>
				<?php $consent = (array) $m( 'consent' ); if ( $consent ) : ?>
					<dt>Согласие на ПДн</dt><dd><?php echo esc_html( ( $consent['time'] ?? '' ) . ', политика ' . ( $consent['version'] ?? '' ) ); ?></dd>
				<?php endif; ?>
			</dl>
		</details>
	</div>

	<div class="crm-col crm-col--side">
		<section class="crm-box">
			<h2>Работа с заявкой</h2>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form" data-crm="status" data-id="<?php echo (int) $id; ?>">
					<label>Статус
						<select name="status">
							<?php foreach ( PCB_RQ_STATUSES as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="crm-reason"<?php echo 'lost' === $status ? '' : ' hidden'; ?>>Причина отказа <span class="crm-sub">необязательно</span>
						<select name="reason">
							<option value="">Не указана</option>
							<?php foreach ( PCB_RQ_LOST_REASONS as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"<?php selected( (string) $m( 'lost_reason' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="pp-btn pp-btn--sm" type="submit">Сохранить статус</button>
				</form>

				<form class="crm-form" data-crm="service" data-id="<?php echo (int) $id; ?>">
					<label>Услуга
						<select name="service">
							<?php foreach ( $services as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $service, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php if ( '' !== $client && $client !== $service ) : ?>
						<p class="crm-sub">Клиент выбрал: <?php echo esc_html( pcb_rq_service_label( $client ) ); ?></p>
					<?php endif; ?>
					<button class="pp-btn pp-btn--sm" type="submit">Сохранить услугу</button>
				</form>
			<?php else : ?>
				<dl class="crm-dl">
					<dt>Статус</dt><dd><?php echo esc_html( pcb_rq_status_label( $status ) ); ?></dd>
					<dt>Услуга</dt><dd><?php echo esc_html( pcb_rq_service_label( $service ) ); ?></dd>
				</dl>
			<?php endif; ?>
		</section>

		<section class="crm-box">
			<h2>Заметки</h2>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form" data-crm="note" data-id="<?php echo (int) $id; ?>">
					<label><span class="pp-sr">Новая заметка</span>
						<textarea name="text" rows="3" placeholder="Например: запросили stackup, ждём ответа"></textarea>
					</label>
					<div class="crm-form__acts">
						<label class="crm-check"><input type="checkbox" name="important" value="1"> Важно</label>
						<button class="pp-btn pp-btn--sm" type="submit">Добавить заметку</button>
					</div>
				</form>
			<?php endif; ?>
			<?php if ( ! $notes ) : ?>
				<p class="pp-muted">Заметок нет.</p>
			<?php else : ?>
				<ul class="crm-notes">
					<?php foreach ( $notes as $r ) :
						$flag = (string) $r['old_value'];
						?>
						<li class="<?php echo 'important' === $flag ? 'is-important' : ''; ?>">
							<?php if ( 'important' === $flag ) : ?><span class="crm-tag crm-tag--important">Важно</span><?php endif; ?>
							<div class="crm-text"><?php echo esc_html( $r['new_value'] ); ?></div>
							<span class="crm-sub">
								<?php echo esc_html( $who( $r['user_id'], $r['time'] ) ); ?>
								<?php if ( 'resolved' === $flag ) : ?> · было важно<?php endif; ?>
							</span>
							<?php if ( $can_edit ) : ?>
								<button class="crm-link" type="button" data-crm-flag="<?php echo 'important' === $flag ? 'resolved' : 'important'; ?>" data-note="<?php echo (int) $r['id']; ?>" data-id="<?php echo (int) $id; ?>">
									<?php echo 'important' === $flag ? 'Снять «Важно»' : 'Отметить как важное'; ?>
								</button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<details class="crm-box crm-fold"<?php echo count( $history ) <= 8 ? ' open' : ''; ?>>
			<summary><h2>История</h2></summary>
			<ul class="crm-history">
				<?php foreach ( $history as $r ) : if ( in_array( $r['action'], array( 'note', 'email' ), true ) ) { continue; } ?>
					<li>
						<span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
						<span class="crm-sub"><?php echo esc_html( $who( $r['user_id'], $r['time'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>

		<section class="crm-box">
			<h2>Яндекс Метрика</h2>
			<?php if ( '' !== $ym_id ) : ?>
				<p class="crm-sub">Посетитель согласился на аналитику — заявку можно связать с его визитами.</p>
				<dl class="crm-dl">
					<dt>ClientID</dt><dd><code class="crm-copy" title="Скопировать"><?php echo esc_html( $ym_id ); ?></code></dd>
					<dt>Код в Метрике</dt><dd>параметр посетителя <code>pcb_request</code> = <code class="crm-copy"><?php echo esc_html( $m( 'code' ) ); ?></code></dd>
				</dl>
				<?php if ( $counter ) : ?>
					<p class="crm-metrika-links">
						<a href="<?php echo esc_url( 'https://metrika.yandex.ru/stat/visitors?id=' . $counter ); ?>" target="_blank" rel="noopener">Посетители</a>
						<a href="<?php echo esc_url( 'https://metrika.yandex.ru/stat/visor?id=' . $counter ); ?>" target="_blank" rel="noopener">Вебвизор</a>
						<a href="<?php echo esc_url( 'https://metrika.yandex.ru/stat/user_vars?id=' . $counter ); ?>" target="_blank" rel="noopener">Параметры посетителей</a>
					</p>
					<p class="crm-sub">В отчёте добавьте сегмент «ClientID» или найдите код заявки в параметре pcb_request.</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="pp-muted">Связи нет: посетитель не давал согласия на аналитические cookie или заявка отправлена до подключения связи.</p>
			<?php endif; ?>
		</section>

		<?php if ( $related ) : ?>
			<section class="crm-box">
				<h2>Другие заявки клиента</h2>
				<ul class="crm-related">
					<?php foreach ( $related as $rp ) : ?>
						<li>
							<a href="<?php echo esc_url( pcb_rq_panel_url( $rp->ID ) ); ?>"><?php echo esc_html( pcb_rq_label( $rp->ID ) ); ?></a>
							<span class="crm-sub"><?php echo esc_html( get_the_date( 'd.m.Y', $rp ) . ' · ' . pcb_rq_status_label( get_post_meta( $rp->ID, '_pcb_status', true ) ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( $can_del ) : ?>
			<button class="crm-delete" type="button" data-crm-delete>Удалить заявку…</button>
		<?php endif; ?>
	</div>
</div>

<?php if ( $can_del ) : ?>
	<dialog class="crm-dialog" id="crm-delete-dialog">
		<form method="dialog" class="crm-dialog__in" data-id="<?php echo (int) $id; ?>">
			<h2>Удалить заявку <?php echo esc_html( $m( 'number' ) ); ?>?</h2>
			<p>Запись удалится из базы. Что сделать с файлами<?php echo $files ? ' (' . (int) count( $files ) . ')' : ''; ?>?</p>
			<div class="crm-dialog__actions">
				<button class="pp-btn" value="archive">Удалить, файлы — в архив</button>
				<button class="pp-btn pp-btn--danger" value="purge">Удалить вместе с файлами</button>
				<button class="pp-btn pp-btn--ghost" value="cancel">Отмена</button>
			</div>
			<p class="crm-sub">В архиве файлы остаются на сервере; проверьте их на персональные данные вручную.</p>
		</form>
	</dialog>
<?php endif; ?>

<p class="crm-msg" role="status" hidden></p>

<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
