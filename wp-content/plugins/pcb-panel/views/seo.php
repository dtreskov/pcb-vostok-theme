<?php
defined( 'ABSPATH' ) || exit;
$pages   = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title' ) );
$can_edit = current_user_can( 'pcb_seo_edit' );
?>
<div class="pp-page-head">
	<div>
		<h1>SEO</h1>
		<p>Заголовок и описание каждой страницы для поисковиков и превью в мессенджерах.</p>
	</div>
</div>

<div class="pp-table-wrap">
	<table class="pp-table">
		<thead>
			<tr><th>Страница</th><th>Title</th><th>Description</th><?php if ( $can_edit ) : ?><th><span class="pp-sr">Действия</span></th><?php endif; ?></tr>
		</thead>
		<tbody>
		<?php foreach ( $pages as $p ) :
			$seo_title = get_post_meta( $p->ID, '_pcb_seo_title', true );
			$seo_desc  = get_post_meta( $p->ID, '_pcb_seo_description', true );
			?>
			<tr>
				<td><a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $p ) ); ?></a></td>
				<td><?php echo $seo_title ? esc_html( $seo_title ) : '<span class="pp-muted">не задан</span>'; ?></td>
				<td><?php echo $seo_desc ? esc_html( $seo_desc ) : '<span class="pp-muted">не задано</span>'; ?></td>
				<?php if ( $can_edit ) : ?><td><button class="pp-btn pp-btn--sm" type="button" disabled>Изменить</button></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
