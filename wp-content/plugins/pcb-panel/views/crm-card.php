<?php
/**
 * Карточка заявки: /panel/crm/<номер>/
 * Слева — что прислал клиент, справа — рабочая часть (статус, услуга, заметки, история).
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
$calc     = (array) $m( 'calc' );
$mail     = (array) $m( 'mail' );
$can_edit = current_user_can( 'pcb_crm_edit' );
$can_del  = current_user_can( 'pcb_crm_delete' );
$history  = pcb_crm_log_get( $id );
$notes    = array_filter( $history, function ( $r ) { return 'note' === $r['action']; } );

// варианты услуги: известные коды + текущее и клиентское значение, если их нет в списке
$services = PCB_RQ_SERVICES;
foreach ( array( $service, $client ) as $v ) {
	if ( '' !== $v && ! isset( $services[ $v ] ) ) {
		$services[ $v ] = $v;
	}
}

// другие заявки с той же почты или компании
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

/**
 * Данные калькулятора (PCBCalc.getData() темы): вложенный массив → строки «параметр — значение».
 * Ключи подписаны по-русски; значения — коды калькулятора (читаемая сводка — в описании задачи).
 */
$calc_names = array(
	'qty' => 'Тираж', 'len' => 'Длина', 'wid' => 'Ширина', 'layers' => 'Слоёв', 'thick' => 'Толщина',
	'cuout' => 'Медь, внешние слои', 'cuin' => 'Медь, внутренние слои', 'mat' => 'Материал', 'finish' => 'Покрытие',
	'mask' => 'Маска', 'silk' => 'Маркировка', 'track' => 'Дорожка / зазор', 'hole' => 'Мин. отверстие',
	'sides' => 'Стороны', 'panel' => 'Панелизация', 'special' => 'Особые требования', 'asm' => 'Монтаж',
	'asm_values' => 'Монтаж', 'finish_pending' => 'Покрытие уточняется', 'estimate' => 'Оценка',
	'lo' => 'от', 'hi' => 'до', 'per' => 'за плату', 'term' => 'Срок', 'warn' => 'Предупреждения', 'files' => 'Файлы калькулятора',
);
$calc_rows = function ( $data, $prefix = '' ) use ( &$calc_rows, $calc_names ) {
	$out = array();
	foreach ( (array) $data as $k => $v ) {
		$name = $calc_names[ $k ] ?? (string) $k;
		$key  = '' !== $prefix ? $prefix . ' · ' . $name : $name;
		if ( is_array( $v ) ) {
			$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
			if ( $is_list && ! array_filter( $v, 'is_array' ) ) {
				$out[ $key ] = implode( ', ', array_map( 'strval', $v ) );
			} else {
				$out += $calc_rows( $v, $key );
			}
		} elseif ( is_bool( $v ) ) {
			$out[ $key ] = $v ? 'да' : 'нет';
		} elseif ( null !== $v && '' !== $v ) {
			$out[ $key ] = (string) $v;
		}
	}
	return $out;
};

$reply = 'mailto:' . rawurlencode( (string) $m( 'email' ) ) . '?subject=' . rawurlencode( 'Заявка № ' . $m( 'code' ) . ' — PCB Восток' );
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
		<a class="pp-btn" href="<?php echo esc_url( $reply ); ?>">Ответить</a>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<section class="crm-box">
			<h2>Клиент</h2>
			<dl class="crm-dl">
				<dt>Почта</dt><dd><a href="<?php echo esc_url( 'mailto:' . $m( 'email' ) ); ?>"><?php echo esc_html( $m( 'email' ) ); ?></a></dd>
				<dt>Телефон</dt><dd><?php echo $m( 'phone' ) ? '<a href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $m( 'phone' ) ) ) . '">' . esc_html( $m( 'phone' ) ) . '</a>' : '—'; ?></dd>
				<dt>Имя</dt><dd><?php echo esc_html( $m( 'name' ) ?: '—' ); ?></dd>
				<dt>Компания</dt><dd><?php echo esc_html( $m( 'company' ) ?: '—' ); ?></dd>
			</dl>
		</section>

		<section class="crm-box">
			<h2>Описание задачи</h2>
			<?php if ( '' !== trim( (string) $post->post_content ) ) : ?>
				<div class="crm-text"><?php echo esc_html( $post->post_content ); ?></div>
			<?php else : ?>
				<p class="pp-muted">Клиент не написал описание.</p>
			<?php endif; ?>
		</section>

		<?php $rows = $calc_rows( $calc ); if ( $rows ) : ?>
			<section class="crm-box">
				<h2>Данные калькулятора</h2>
				<dl class="crm-dl crm-dl--wide">
					<?php foreach ( $rows as $k => $v ) : ?>
						<dt><?php echo esc_html( $k ); ?></dt><dd><?php echo esc_html( $v ); ?></dd>
					<?php endforeach; ?>
				</dl>
			</section>
		<?php endif; ?>

		<section class="crm-box">
			<h2>Файлы</h2>
			<?php if ( ! $files ) : ?>
				<p class="pp-muted">Не приложены.</p>
			<?php else : ?>
				<ul class="crm-files">
					<?php foreach ( $files as $f ) : ?>
						<li>
							<?php if ( pcb_rq_file_path( $f ) ) : ?>
								<a href="<?php echo esc_url( pcb_rq_file_url( $id, $f['key'] ) ); ?>"><?php echo esc_html( $f['name'] ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( $f['name'] ); ?></span> <span class="crm-sub">файл не найден на диске</span>
							<?php endif; ?>
							<span class="crm-sub"><?php echo esc_html( size_format( $f['size'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="crm-box crm-box--muted">
			<h2>Служебное</h2>
			<dl class="crm-dl">
				<dt>Письмо менеджерам</dt><dd><?php echo esc_html( $mail['notify'] ?? '—' ); ?></dd>
				<dt>Письмо клиенту</dt><dd><?php echo esc_html( $mail['client'] ?? '—' ); ?></dd>
				<dt>Капча</dt><dd><?php echo esc_html( $m( 'captcha' ) ?: '—' ); ?></dd>
				<dt>Страница</dt><dd><?php echo esc_html( $m( 'page' ) ?: '—' ); ?></dd>
				<dt>Переход с</dt><dd><?php echo esc_html( $m( 'referrer' ) ?: '—' ); ?></dd>
				<?php $consent = (array) $m( 'consent' ); if ( $consent ) : ?>
					<dt>Согласие на ПДн</dt><dd><?php echo esc_html( ( $consent['time'] ?? '' ) . ', политика ' . ( $consent['version'] ?? '' ) ); ?></dd>
				<?php endif; ?>
			</dl>
		</section>
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
					<button class="pp-btn pp-btn--sm" type="submit">Добавить заметку</button>
				</form>
			<?php endif; ?>
			<?php if ( ! $notes ) : ?>
				<p class="pp-muted">Заметок нет.</p>
			<?php else : ?>
				<ul class="crm-notes">
					<?php foreach ( $notes as $r ) : ?>
						<li>
							<div class="crm-text"><?php echo esc_html( $r['new_value'] ); ?></div>
							<span class="crm-sub"><?php echo esc_html( pcb_crm_log_user( $r['user_id'] ) . ' · ' . mysql2date( 'd.m.Y H:i', $r['time'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="crm-box">
			<h2>История</h2>
			<ul class="crm-history">
				<?php foreach ( $history as $r ) : if ( 'note' === $r['action'] ) { continue; } ?>
					<li>
						<span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
						<span class="crm-sub"><?php echo esc_html( pcb_crm_log_user( $r['user_id'] ) . ' · ' . mysql2date( 'd.m.Y H:i', $r['time'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
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
