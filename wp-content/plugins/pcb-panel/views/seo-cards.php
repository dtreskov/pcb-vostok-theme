<?php
/**
 * SEO → Карточки организаций: эталон данных для Яндекс Бизнеса и 2ГИС, ссылки на карточки и кабинеты,
 * отметка сверки. Править сами карточки из панели нельзя — у сервисов нет открытого API для владельца.
 * Сохранение ссылок — REST seo/cards, сверка — seo/cards/check.
 */
defined( 'ABSPATH' ) || exit;

$cards    = pcb_seo_cards_get();
$svc      = pcb_seo_card_services();
$ref      = pcb_seo_card_reference();
$can_edit = current_user_can( 'pcb_seo_edit' );
$history  = pcb_cl_log_get( 'seo:cards' );
$changed  = pcb_seo_card_data_changed();
$seo_nav  = 'cards';
include __DIR__ . '/_seo-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Карточки организаций</h1>
		<p>Карточки компании в Яндекс Бизнесе и 2ГИС правятся в их кабинетах — открытого API для этого у сервисов нет. Здесь — эталон: данные должны совпадать с сайтом буква в букву, поисковики сверяют название, адрес и телефон. После правки в кабинете отметьте сверку.</p>
	</div>
</div>

<div class="crm-card">
	<div class="crm-col">
		<section class="crm-box">
			<h2>Эталон для карточек</h2>
			<dl class="seo-ref">
				<?php foreach ( $ref as $label => $val ) : $id = 'seo-ref-' . md5( $label ); ?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><span id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $val ); ?></span>
						<?php if ( '' !== (string) $val ) : ?><button class="crm-link" type="button" data-seo-copy="#<?php echo esc_attr( $id ); ?>">скопировать</button><?php endif; ?></dd>
				<?php endforeach; ?>
			</dl>
			<p class="crm-sub">Название, адрес, телефон и почта — из <a href="<?php echo esc_url( pcb_panel_url( 'seo' ) . 'site/' ); ?>">данных сайта</a>, описание — из <a href="<?php echo esc_url( pcb_panel_url( 'seo' ) . 'markup/' ); ?>">разметки</a>. Часы работы не указываем. Отзывы в карточках может оставить любой посетитель.</p>
		</section>

		<form class="crm-box crm-form" data-api="seo/cards">
			<h2>Карточки</h2>
			<?php foreach ( $svc as $k => $info ) :
				$c     = $cards[ $k ];
				$stale = pcb_seo_card_stale( $c );
				?>
				<div class="seo-cardrow">
					<div class="seo-cardrow__head">
						<b><?php echo esc_html( $info['label'] ); ?></b>
						<?php if ( '' === $c['checked'] ) : ?>
							<span class="seo-chip seo-chip--warn">не сверена</span>
						<?php elseif ( $stale ) : ?>
							<span class="seo-chip seo-chip--warn">данные сайта менялись после сверки</span>
						<?php else : ?>
							<span class="seo-chip seo-chip--ok">сверена</span>
						<?php endif; ?>
						<a class="crm-link" href="<?php echo esc_url( $info['cabinet'] ); ?>" target="_blank" rel="noopener">Кабинет</a>
						<?php if ( $c['url'] ) : ?><a class="crm-link" href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener">Карточка</a><?php endif; ?>
					</div>
					<label data-field="<?php echo esc_attr( $k ); ?>_url">Ссылка на карточку
						<input type="url" name="<?php echo esc_attr( $k ); ?>_url" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://"<?php echo $can_edit ? '' : ' readonly'; ?>>
						<span class="crm-err" hidden></span>
					</label>
					<p class="crm-sub"><?php echo esc_html( $info['hint'] ); ?> Ссылка попадёт в разметку организации на главной.</p>
					<p class="crm-sub">
						<?php if ( $c['checked'] ) : ?>
							Сверена <?php echo esc_html( mysql2date( 'd.m.Y H:i', $c['checked'] ) ); ?> · <?php echo pcb_crm_user_html( $c['by'] ); // phpcs:ignore ?>
						<?php else : ?>
							Сверки ещё не было.
						<?php endif; ?>
						<?php if ( $can_edit ) : ?>
							<button class="crm-link" type="button" data-api-click="seo/cards/check" data-body="<?php echo esc_attr( wp_json_encode( array( 'card' => $k ) ) ); ?>" data-confirm="Данные в карточке <?php echo esc_attr( $info['label'] ); ?> совпадают с эталоном?">Отметить сверку</button>
						<?php endif; ?>
					</p>
				</div>
			<?php endforeach; ?>
			<?php if ( $can_edit ) : ?>
				<div class="crm-form__acts"><button class="pp-btn" type="submit">Сохранить ссылки</button></div>
			<?php endif; ?>
		</form>
	</div>

	<div class="crm-col crm-col--side">
		<section class="crm-box crm-box--muted">
			<h2>Порядок</h2>
			<ol class="seo-list">
				<li>Завести или получить права на карточку в кабинете сервиса.</li>
				<li>Внести данные из эталона, скопировав каждое поле.</li>
				<li>Вставить сюда ссылку на карточку и отметить сверку.</li>
				<li>Поменяли данные сайта — панель покажет, какие карточки пора сверить.</li>
			</ol>
			<?php if ( $changed ) : ?><p class="crm-sub">Данные сайта или описание компании последний раз менялись <?php echo esc_html( mysql2date( 'd.m.Y H:i', $changed ) ); ?>.</p><?php endif; ?>
		</section>
		<details class="crm-box crm-fold"<?php echo $history ? ' open' : ''; ?>>
			<summary><h2>История</h2><span class="crm-sub"><?php echo (int) count( $history ); ?></span></summary>
			<ul class="crm-history crm-scroll">
				<?php foreach ( $history as $r ) : ?>
					<li><span><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></span>
						<span class="crm-sub"><?php echo pcb_crm_user_html( $r['user_id'] ) . ' · ' . esc_html( mysql2date( 'd.m.Y H:i', $r['time'] ) ); // phpcs:ignore ?></span></li>
				<?php endforeach; ?>
				<?php if ( ! $history ) : ?><li class="pp-muted">Изменений пока не было.</li><?php endif; ?>
			</ul>
		</details>
	</div>
</div>

<?php include __DIR__ . '/_seo-scripts.php'; ?>
