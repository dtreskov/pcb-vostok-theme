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

// клиент заявки (база клиентов, 0.6.0)
$cl_contact = (int) $m( 'contact_id' ) ? pcb_cl_contact( (int) $m( 'contact_id' ) ) : null;
$cl_company = (int) $m( 'company_id' ) ? pcb_cl_company( (int) $m( 'company_id' ) ) : null;
// связанные: заявки того же контакта и компании, затем совпадения по полям (ещё не разобранные клиенты)
$related = array();
if ( $cl_contact ) {
	foreach ( pcb_cl_requests( 'contact', $cl_contact['id'] ) as $rid ) {
		$related[ $rid ][] = 'тот же контакт';
	}
}
if ( $cl_company ) {
	foreach ( pcb_cl_requests( 'company', $cl_company['id'] ) as $rid ) {
		if ( ! isset( $related[ $rid ] ) ) {
			$related[ $rid ][] = 'та же компания';
		}
	}
}
foreach ( pcb_crm_related( $id ) as $rid => $why ) {
	if ( ! isset( $related[ $rid ] ) ) {
		$related[ $rid ] = $why;
	}
}
unset( $related[ $id ] );
krsort( $related );
$open_important = count( array_filter( $notes, function ( $r ) { return 'important' === $r['old_value']; } ) );

$calc_rows = pcb_crm_calc_rows( (array) $m( 'calc' ) );
$desc      = (string) $post->post_content;
// до 0.8.1 форма дописывала расчёт калькулятора текстом в описание — показываем его отдельно, свёрнутым
$desc_calc = '';
$mark_pos  = mb_strpos( $desc, '— Данные из онлайн-калькулятора —' );
if ( false !== $mark_pos ) {
	$desc_calc = trim( mb_substr( $desc, $mark_pos ) );
	$desc      = rtrim( mb_substr( $desc, 0, $mark_pos ) );
}
$reply_url = pcb_rq_panel_url( $id ) . 'reply/';
$ym_id     = (string) $m( 'ym_client' );
$counter   = function_exists( 'pcb_ya_counter' ) ? (int) pcb_ya_counter() : ( defined( 'PCB_METRIKA_ID' ) ? (int) PCB_METRIKA_ID : 0 );
// «кто · когда» — уже экранированный HTML: имя с логином в подсказке
$who       = function ( $uid, $time ) { return pcb_crm_user_html( $uid ) . ' · ' . esc_html( mysql2date( 'd.m.Y H:i', $time ) ); };
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">

<?php $crm_nav = 'requests'; include __DIR__ . '/_crm-nav.php'; ?>
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
				<?php if ( $can_edit ) : ?><button class="crm-link" type="button" data-crm-toggle="crm-contacts-form">Исправить контакты</button><?php endif; ?>
			</div>
			<p class="crm-clientline">
				<?php if ( $cl_contact ) : ?>Контакт <a href="<?php echo esc_url( pcb_cl_url( 'contact', $cl_contact['id'] ) ); ?>"><?php echo esc_html( $cl_contact['name'] ?: 'без имени' ); ?></a><?php else : ?><span class="pp-muted">Контакт не определён</span><?php endif; ?>
				·
				<?php if ( $cl_company ) : ?>компания <a href="<?php echo esc_url( pcb_cl_url( 'company', $cl_company['id'] ) ); ?>"><?php echo esc_html( $cl_company['name'] ); ?></a><?php if ( $cl_company['verified'] ) : ?> <span class="crm-tag crm-tag--sent">проверено</span><?php endif; ?><?php else : ?><span class="pp-muted">без компании</span><?php endif; ?>
				<?php if ( $can_edit ) : ?> <button class="crm-link" type="button" data-crm-toggle="crm-client-form">изменить привязку</button><?php endif; ?>
			</p>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form crm-form--inline" id="crm-client-form" data-api="requests/<?php echo (int) $id; ?>/client" hidden>
					<label>Контакт
						<div class="crm-pick" data-pick="contact">
							<input type="search" value="<?php echo esc_attr( $cl_contact ? $cl_contact['name'] : '' ); ?>" placeholder="ФИО, почта или телефон" autocomplete="off">
							<input type="hidden" name="contact_id" value="<?php echo (int) ( $cl_contact['id'] ?? 0 ); ?>">
							<ul class="crm-pick__list" hidden></ul>
						</div>
					</label>
					<label>Компания <span class="crm-sub">пусто — компания контакта</span>
						<div class="crm-pick" data-pick="company">
							<input type="search" value="<?php echo esc_attr( $cl_company ? $cl_company['name'] : '' ); ?>" placeholder="Название или ИНН" autocomplete="off">
							<input type="hidden" name="company_id" value="<?php echo (int) ( $cl_company['id'] ?? 0 ); ?>">
							<ul class="crm-pick__list" hidden></ul>
						</div>
					</label>
					<div class="crm-form__acts">
						<button class="pp-btn pp-btn--sm" type="submit">Сохранить привязку</button>
						<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-crm-toggle="crm-client-form">Отмена</button>
					</div>
				</form>
			<?php endif; ?>
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
							<input type="<?php echo 'email' === $k ? 'email' : ( 'phone' === $k ? 'tel' : 'text' ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $m( $k ) ); ?>"<?php
								if ( 'email' === $k ) { echo ' required'; }
								if ( 'phone' === $k ) { echo ' pattern="[+0-9\s\(\)\-\.]{5,25}" title="Цифры, пробелы, скобки, дефисы и «+»; например +7 912 345-67-89"'; }
							?>>
						</label>
					<?php endforeach; ?>
					<p class="crm-sub">Телефон приводится к виду +7 912 345-67-89, почта — к нижнему регистру. Исходные значения из заявки сохраняются и видны рядом с исправленными.</p>
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
			<?php if ( '' !== $desc_calc ) : ?>
				<details class="crm-legacy-calc">
					<summary>Текст расчёта, приложенный клиентом</summary>
					<div class="crm-text"><?php echo esc_html( $desc_calc ); ?></div>
				</details>
			<?php endif; ?>
		</details>

		<?php if ( $calc_rows ) : ?>
			<details class="crm-box crm-fold"<?php echo count( $calc_rows ) <= 8 ? ' open' : ''; ?>>
				<?php $restore = pcb_crm_calc_restore( $id ); ?>
				<summary><h2>Данные калькулятора</h2><span class="crm-sub"><?php echo (int) count( $calc_rows ); ?> параметров</span>
					<?php if ( $restore ) : ?>
						<button class="pp-btn pp-btn--sm pp-btn--ghost crm-calc-open" type="button" data-calc-open="<?php echo esc_url( pcb_crm_calc_page_url() ); ?>" data-payload="<?php echo esc_attr( wp_json_encode( $restore, JSON_UNESCAPED_UNICODE ) ); ?>"
							title="<?php echo esc_attr( 'Откроется в новой вкладке с параметрами' . ( $restore['files'] ? ' и файлами (' . count( $restore['files'] ) . ')' : '' ) . ' из заявки. Правки там в заявку не сохраняются.' ); ?>">Показать в калькуляторе</button>
					<?php endif; ?>
				</summary>
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
								<span class="crm-tag">добавил <?php echo $who( $f['by'] ?? 0, $f['added'] ?? '' ); // phpcs:ignore -- экранировано в $who ?></span>
							<?php elseif ( 'sent' === $kind ) : ?>
								<span class="crm-tag crm-tag--sent">отправлено клиенту · <?php echo $who( $f['by'] ?? 0, $f['added'] ?? '' ); // phpcs:ignore -- экранировано в $who ?></span>
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
						<span class="crm-sub">Например, файлы, которые клиент дослал на почту. До 20 файлов и 100 МБ за раз, без исполняемых файлов.</span>
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
									<span class="crm-sub"><?php echo $who( $r['user_id'], $r['time'] ) . esc_html( ! empty( $e['files'] ) ? ' · вложения: ' . implode( ', ', $e['files'] ) : '' ); // phpcs:ignore ?></span>
								</summary>
								<div class="crm-text"><?php echo esc_html( is_array( $e ) ? $e['body'] : $r['new_value'] ); ?></div>
							</details>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="crm-sub">Ответы клиента приходят в почтовый ящик <?php echo esc_html( pcb_crm_mailbox() ); ?> — в CRM они не попадают.</p>
			</section>
		<?php endif; ?>

		<section class="crm-box">
			<h2>Связанные заявки</h2>
			<?php if ( ! $related ) : ?>
				<p class="pp-muted">Связанных заявок нет — с такой почтой, телефоном или компанией других заявок не было.</p>
			<?php else : ?>
				<ul class="crm-related">
					<?php foreach ( $related as $rid => $why ) : ?>
						<li>
							<a href="<?php echo esc_url( pcb_rq_panel_url( $rid ) ); ?>"><?php echo esc_html( pcb_rq_label( $rid ) ); ?></a>
							<span class="crm-sub"><?php echo esc_html( get_the_date( 'd.m.Y', $rid ) . ' · ' . pcb_rq_status_label( get_post_meta( $rid, '_pcb_status', true ) ) ); ?></span>
							<span class="crm-tag">совпадает: <?php echo esc_html( implode( ', ', $why ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

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
				<form class="crm-form crm-work" data-crm="work" data-id="<?php echo (int) $id; ?>">
					<div class="crm-work__row">
						<label>Статус
							<select name="status">
								<?php foreach ( PCB_RQ_STATUSES as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>Услуга
							<select name="service">
								<?php foreach ( $services as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $service, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<div class="crm-work__row">
						<label class="crm-reason"<?php echo 'lost' === $status ? '' : ' hidden'; ?>><span class="pp-sr">Причина отказа</span>
							<select name="reason">
								<option value="">Причина отказа — не указана</option>
								<?php foreach ( PCB_RQ_LOST_REASONS as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( (string) $m( 'lost_reason' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php if ( '' !== $client && $client !== $service ) : ?>
							<span class="crm-sub crm-work__note">Клиент выбрал: <?php echo esc_html( pcb_rq_service_label( $client ) ); ?></span>
						<?php endif; ?>
						<button class="pp-btn pp-btn--sm" type="submit">Сохранить</button>
					</div>
				</form>
			<?php else : ?>
				<dl class="crm-dl">
					<dt>Статус</dt><dd><?php echo esc_html( pcb_rq_status_label( $status ) ); ?></dd>
					<dt>Услуга</dt><dd><?php echo esc_html( pcb_rq_service_label( $service ) ); ?></dd>
				</dl>
			<?php endif; ?>
		</section>

		<details class="crm-box crm-fold crm-notes-box<?php echo $open_important ? ' is-alert' : ''; ?>" open>
			<summary>
				<h2>Заметки</h2>
				<span class="crm-sub"><?php echo (int) count( $notes ); ?><?php echo $open_important ? ' · важных: ' . (int) $open_important : ''; ?></span>
			</summary>
			<?php if ( $can_edit ) : ?>
				<form class="crm-form" data-crm="note" data-id="<?php echo (int) $id; ?>">
					<label><span class="pp-sr">Новая заметка</span>
						<textarea name="text" rows="2" placeholder="Например: запросили stackup, ждём ответа"></textarea>
					</label>
					<div class="crm-form__acts">
						<label class="crm-check"><input type="checkbox" name="important" value="1"> Важно</label>
						<button class="pp-btn pp-btn--sm" type="submit">Добавить заметку</button>
					</div>
				</form>
			<?php endif; ?>
			<?php if ( ! $notes ) : ?>
				<p class="pp-muted">Заметок нет.</p>
			<?php else :
				$note_li = function ( $r ) use ( $who, $can_edit, $id ) {
					$flag = (string) $r['old_value'];
					?>
					<li class="<?php echo 'important' === $flag ? 'is-important' : ''; ?>">
						<?php if ( 'important' === $flag ) : ?><span class="crm-tag crm-tag--important">Важно</span><?php endif; ?>
						<div class="crm-text"><?php echo esc_html( $r['new_value'] ); ?></div>
						<span class="crm-sub crm-meta">
							<?php echo $who( $r['user_id'], $r['time'] ); // phpcs:ignore ?><?php echo 'resolved' === $flag ? ' · было важно' : ''; ?>
							<?php if ( $can_edit ) : ?>
								<button class="crm-link" type="button" data-crm-flag="<?php echo 'important' === $flag ? 'resolved' : 'important'; ?>" data-note="<?php echo (int) $r['id']; ?>" data-id="<?php echo (int) $id; ?>"><?php echo 'important' === $flag ? 'Снять «Важно»' : 'Важно'; ?></button>
							<?php endif; ?>
							<?php if ( pcb_crm_can_delete_note( $r ) ) : ?>
								<button class="crm-link crm-link--danger" type="button" data-crm-note-del data-note="<?php echo (int) $r['id']; ?>" data-id="<?php echo (int) $id; ?>">Удалить</button>
							<?php endif; ?>
						</span>
					</li>
					<?php
				};
				$pinned = array_filter( $notes, function ( $r ) { return 'important' === $r['old_value']; } );
				$rest   = array_filter( $notes, function ( $r ) { return 'important' !== $r['old_value']; } );
				?>
				<?php if ( $pinned ) : ?><ul class="crm-notes crm-notes--pinned"><?php array_map( $note_li, $pinned ); ?></ul><?php endif; ?>
				<?php if ( $rest ) : ?><ul class="crm-notes crm-scroll"><?php array_map( $note_li, $rest ); ?></ul><?php endif; ?>
			<?php endif; ?>
		</details>

		<details class="crm-box crm-fold" open>
			<summary><h2>История</h2><span class="crm-sub"><?php echo (int) count( array_filter( $history, function ( $r ) { return ! in_array( $r['action'], array( 'note', 'email' ), true ); } ) ); ?></span></summary>
			<ul class="crm-history crm-scroll">
				<?php foreach ( $history as $r ) : if ( in_array( $r['action'], array( 'note', 'email' ), true ) ) { continue; } ?>
					<li>
						<span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
						<span class="crm-sub"><?php echo $who( $r['user_id'], $r['time'] ); // phpcs:ignore ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>

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

<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
