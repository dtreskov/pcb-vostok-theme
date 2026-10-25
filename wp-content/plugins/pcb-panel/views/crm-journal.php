<?php
/**
 * Общий журнал действий: /panel/crm/journal/ (право pcb_panel_log_view). Общая таблица (README §6.2):
 * фильтры — категория, сотрудник, объект, период; поиск по номеру и коду заявки; сортировка по времени.
 * Записи удалённых заявок остаются — по номеру и коду видно, кто и когда удалил.
 */
defined( 'ABSPATH' ) || exit;

$cats = pcb_crm_log_categories();
$l    = new PCB_List(
	pcb_panel_url( 'crm' ) . 'journal/',
	array( 'time' => 'desc' ),
	array(
		'cat'    => array( 'type' => 'select', 'label' => 'Категория', 'options' => wp_list_pluck( $cats, 0 ) ),
		'user'   => array( 'type' => 'select', 'label' => 'Сотрудник', 'options' => pcb_crm_log_users() ),
		'obj'    => array( 'type' => 'select', 'label' => 'Объект', 'options' => pcb_crm_log_objects() ),
		'period' => array( 'type' => 'period', 'label' => 'Период' ),
	)
);
$l->per = 100;
$res    = pcb_crm_journal_query( $l );

/** Объект записи: подпись и ссылка ('' — объекта больше нет). */
$object = function ( array $r ) {
	$kind = pcb_crm_log_object( $r );
	if ( 'seo' === $kind ) {
		$sk = substr( $r['entity'], 4 );
		$sl = ctype_digit( $sk ) ? 'SEO: ' . ( get_the_title( (int) $sk ) ?: '#' . $sk ) : 'SEO: ' . ( array( 'site' => 'данные сайта', 'markup' => 'разметка', 'semantic' => 'семантика', 'cards' => 'карточки организаций', 'robots' => 'robots.txt', 'maint' => 'техработы' )[ $sk ] ?? $sk );
		return array( $sl, pcb_panel_url( 'seo' ) . ( ctype_digit( $sk ) ? $sk : ( 'maint' === $sk ? 'maintenance' : $sk ) ) . '/' );
	}
	if ( 'calc' === $kind ) {
		return array( 'Калькулятор', current_user_can( 'pcb_calc_view' ) ? pcb_panel_url( 'calc' ) : '' );
	}
	if ( 'client' === $kind ) {
		list( $et, $eid ) = array_pad( explode( ':', $r['entity'] ), 2, 0 );
		$er               = pcb_cl_get( $et, (int) $eid );
		return array( ( 'company' === $et ? 'Компания ' : 'Контакт ' ) . ( $er ? ( $er['name'] ?: '#' . $eid ) : '#' . $eid ), pcb_cl_url( $et, $eid ) );
	}
	if ( 'settings' === $kind ) {
		return array( 'Реакция на заявку', pcb_panel_url( 'crm' ) . 'settings/' );
	}
	if ( 'user' === $kind ) {
		return array( 'Пользователь', '' );
	}
	$label = trim( $r['number'] . ( '' !== $r['code'] ? ' · ' . $r['code'] : '' ) );
	if ( 'export' === $r['action'] ) {
		return array( 'Заявки', '' );
	}
	$alive = $r['request_id'] && pcb_crm_is_request( $r['request_id'] );
	return array( '' !== $label ? $label : '—', $alive ? pcb_rq_panel_url( (int) $r['request_id'] ) : '' );
};
?>
<?php $crm_nav = 'journal'; include __DIR__ . '/_crm-nav.php'; ?>

<div class="pp-page-head">
	<div>
		<h1>Журнал действий</h1>
		<p>Кто и что менял: заявки, клиенты, SEO, техработы и калькулятор. Наведите на имя — увидите логин. У удалённых заявок тексты заметок, писем и значения контактов стёрты.</p>
	</div>
</div>

<?php echo $l->toolbar( 'Номер или код заявки' ); // phpcs:ignore -- разметка собрана с экранированием ?>

<?php if ( ! $res['rows'] ) : ?>
	<section class="pp-empty"><h2><?php echo $l->filtered() ? 'Ничего не найдено' : 'Записей пока нет'; ?></h2><?php if ( $l->filtered() ) : ?><p>Попробуйте другой запрос или сбросьте фильтры.</p><?php endif; ?></section>
<?php else : ?>
	<div class="pp-table-wrap">
		<table class="pp-table crm-journal">
			<thead><tr><?php echo $l->th( 'time', 'Когда' ) . $l->th( '', 'Кто' ) . $l->th( '', 'Объект' ) . $l->th( '', 'Категория' ) . $l->th( '', 'Действие' ); // phpcs:ignore ?></tr></thead>
			<tbody>
			<?php foreach ( $res['rows'] as $r ) :
				list( $ol, $ou ) = $object( $r );
				$cat             = pcb_crm_log_category( $r['action'] );
				?>
				<tr<?php echo PCB_List::row( $ou ); // phpcs:ignore ?>>
					<td><?php echo PCB_List::date( $r['time'] ); // phpcs:ignore ?></td>
					<td><?php echo pcb_crm_user_html( $r['user_id'] ); // phpcs:ignore -- экранировано ?></td>
					<td class="crm-nowrap"><?php echo '' !== $ou ? '<a href="' . esc_url( $ou ) . '">' . esc_html( $ol ) . '</a>' : esc_html( $ol ); ?></td>
					<td><span class="crm-cat crm-cat--<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $cats[ $cat ][0] ); ?></span></td>
					<td><?php echo esc_html( pcb_crm_log_text( $r ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php echo $l->pages( $res['total'] ); // phpcs:ignore ?>
<?php endif; ?>
<?php echo PCB_List::script(); // phpcs:ignore ?>
