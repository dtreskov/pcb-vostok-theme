<?php
/**
 * SEO → Страницы: title, description, картинка для мессенджеров и индексация каждой опубликованной страницы.
 * Правка — /panel/seo/<id>/.
 */
defined( 'ABSPATH' ) || exit;

$seo_nav = 'pages';
include __DIR__ . '/_seo-nav.php';
$pages = pcb_seo_pages();
$front = (int) get_option( 'page_on_front' );
$chip  = function ( $state, $text ) {
	return '<span class="seo-chip seo-chip--' . esc_attr( $state ) . '">' . esc_html( $text ) . '</span>';
};
?>
<div class="pp-page-head">
	<div>
		<h1>Страницы</h1>
		<p>Как страницы выглядят в поиске и в превью ссылок в мессенджерах. Откройте страницу, чтобы изменить заголовок, описание и картинку.</p>
	</div>
</div>

<div class="pp-table-wrap">
	<table class="pp-table seo-table">
		<thead><tr><th>Страница</th><th>Title</th><th>Description</th><th>Картинка</th><th>Индексация</th></tr></thead>
		<tbody>
		<?php foreach ( $pages as $p ) :
			$m     = pcb_seo_meta( $p->ID );
			$title = pcb_seo_title( $p->ID );
			$len_t = mb_strlen( $title );
			$len_d = mb_strlen( $m['description'] );
			$st_t  = pcb_seo_len_state( 'title', $title );
			$st_d  = pcb_seo_len_state( 'description', $m['description'] );
			?>
			<tr>
				<td>
					<a class="crm-num" href="<?php echo esc_url( pcb_panel_url( 'seo' ) . $p->ID . '/' ); ?>"><?php echo esc_html( get_the_title( $p ) ?: '(без названия)' ); ?></a>
					<span class="crm-sub"><?php echo esc_html( $front === $p->ID ? 'Главная · /' : wp_make_link_relative( get_permalink( $p ) ) ); ?></span>
				</td>
				<td>
					<?php echo esc_html( $title ); ?>
					<span class="crm-sub"><?php echo $chip( $st_t, $len_t . ' зн.' ); // phpcs:ignore ?><?php echo '' === $m['title'] ? ' по шаблону' : ''; ?></span>
				</td>
				<td>
					<?php if ( '' === $m['description'] ) : ?>
						<?php echo $chip( 'bad', 'не задано' ); // phpcs:ignore ?>
					<?php else : ?>
						<span class="seo-clamp"><?php echo esc_html( $m['description'] ); ?></span>
						<span class="crm-sub"><?php echo $chip( $st_d, $len_d . ' зн.' ); // phpcs:ignore ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo $m['og'] ? $chip( 'ok', 'своя' ) : '<span class="pp-muted">общая</span>'; // phpcs:ignore ?></td>
				<td><?php echo $m['noindex'] ? $chip( 'warn', 'закрыта' ) : '<span class="pp-muted">открыта</span>'; // phpcs:ignore ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<p class="crm-sub">Ориентиры: title — <?php echo (int) PCB_SEO_LEN['title'][0]; ?>–<?php echo (int) PCB_SEO_LEN['title'][1]; ?> знаков, description — <?php echo (int) PCB_SEO_LEN['description'][0]; ?>–<?php echo (int) PCB_SEO_LEN['description'][1]; ?>. Длиннее — поисковик обрежет, без описания он соберёт сниппет из текста страницы сам.</p>
