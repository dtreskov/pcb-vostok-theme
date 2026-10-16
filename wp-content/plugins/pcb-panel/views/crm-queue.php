<?php
/**
 * Очередь «Проверить»: /panel/crm/clients/queue/ — вероятные совпадения, которые система не связала сама.
 */
defined( 'ABSPATH' ) || exit;

$queue    = pcb_cl_queue();
$can_edit = current_user_can( 'pcb_crm_edit' );
$titles   = array( 'link' => 'Контакт из этой компании?', 'cmerge' => 'Одна и та же компания?', 'pmerge' => 'Один и тот же человек?' );
$card     = function ( $type, $id ) {
	$r = pcb_cl_get( $type, $id );
	if ( ! $r ) {
		return '<span class="pp-muted">запись удалена</span>';
	}
	if ( 'company' === $type ) {
		$sub = trim( ( $r['inn'] ? 'ИНН ' . $r['inn'] : '' ) . ' · заявок: ' . count( pcb_cl_requests( 'company', $id ) ), ' ·' );
	} else {
		$co  = $r['company_id'] ? pcb_cl_company( $r['company_id'] ) : null;
		$sub = trim( implode( ', ', pcb_cl_ids_of( 'contact', $id )['email'] ?? array() ) . ( $co ? ' · ' . $co['name'] : '' ) . ' · заявок: ' . count( pcb_cl_requests( 'contact', $id ) ), ' ·' );
	}
	return '<a href="' . esc_url( pcb_cl_url( $type, $id ) ) . '" target="_blank" rel="noopener"><b>' . esc_html( $r['name'] ?: '#' . $id ) . '</b></a><span class="crm-sub">' . esc_html( $sub ) . '</span>';
};
$crm_nav = 'queue';
include __DIR__ . '/_crm-nav.php';
?>
<div class="pp-page-head">
	<div>
		<h1>Проверить совпадения</h1>
		<p>Точные совпадения по почте, телефону и ИНН система связывает сама. Здесь — вероятные: общий домен почты, похожее название компании, одинаковое ФИО. «Это разные» — предложение больше не появится.</p>
	</div>
</div>

<?php if ( ! $queue ) : ?>
	<section class="pp-empty"><h2>Проверять нечего</h2><p>Все клиенты разобраны.</p></section>
<?php else : ?>
	<div class="crm-queue">
		<?php foreach ( $queue as $q ) :
			$ta = 'cmerge' === $q['type'] ? 'company' : 'contact';
			$tb = 'pmerge' === $q['type'] ? 'contact' : 'company';
			?>
			<article class="crm-box crm-queue__item">
				<div class="crm-box__head"><h2><?php echo esc_html( $titles[ $q['type'] ] ); ?></h2><span class="crm-tag">совпадает: <?php echo esc_html( $q['why'] ); ?></span></div>
				<div class="crm-queue__pair">
					<div><?php echo $card( $ta, $q['a'] ); // phpcs:ignore ?></div>
					<span class="crm-queue__arrow" aria-hidden="true"><?php echo 'link' === $q['type'] ? '→' : '='; ?></span>
					<div><?php echo $card( $tb, $q['b'] ); // phpcs:ignore ?></div>
				</div>
				<?php if ( $can_edit ) : ?>
					<div class="crm-form__acts">
						<button class="pp-btn pp-btn--sm" type="button" data-api-click="clients/queue" data-body="<?php echo esc_attr( wp_json_encode( array( 'pair' => $q['pair'], 'act' => 'apply' ) ) ); ?>"><?php echo 'link' === $q['type'] ? 'Привязать к компании' : 'Объединить'; ?></button>
						<button class="pp-btn pp-btn--sm pp-btn--ghost" type="button" data-api-click="clients/queue" data-body="<?php echo esc_attr( wp_json_encode( array( 'pair' => $q['pair'], 'act' => 'skip' ) ) ); ?>">Это разные</button>
					</div>
					<?php if ( 'link' !== $q['type'] ) : ?><p class="crm-sub">Объединение — в более раннюю запись; её реквизиты главнее, пустые поля дополнятся из второй.</p><?php endif; ?>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
