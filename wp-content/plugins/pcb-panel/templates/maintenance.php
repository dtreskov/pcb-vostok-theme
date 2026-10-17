<?php
/**
 * Заглушка режима техработ (код 503). Стили и шрифт — из темы, тексты — из настроек панели (SEO → Техработы),
 * подпись под текстом — тоже оттуда (по умолчанию почта и телефон из SEO → Данные сайта).
 */
defined( 'ABSPATH' ) || exit;

$m     = pcb_maint_get();
$s     = pcb_seo_get();
$logo  = pcb_seo_image( 'logo' );
$svg   = pcb_seo_logo_svg_url();
$paras = array_filter( array_map( 'trim', preg_split( '/\R{1,}/', (string) $m['text'] ) ) );
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $m['title'] . ' — ' . $s['brand'] ); ?></title>
<?php echo pcb_seo_icon_links(); // phpcs:ignore -- адреса экранированы ?>
<link rel="stylesheet" href="<?php echo esc_url( get_theme_file_uri( 'assets/css/fonts.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( get_theme_file_uri( 'assets/css/globals.css' ) ); ?>">
<style>
html,body{margin:0;min-height:100%}
body.pcb-maint{min-height:100vh;display:flex;flex-direction:column;background:var(--color-page-background);color:var(--color-text-black)}
.pcb-maint__main{flex:1;display:flex;align-items:center;justify-content:center;padding:var(--space-xxl) 16px}
.pcb-maint__card{width:100%;max-width:620px}
.pcb-maint__head{background:var(--color-primary-dark);border-bottom:1px solid var(--color-copper);padding:12px 16px}
.pcb-maint__head-in{max-width:620px;margin:0 auto}
.pcb-maint__logo{display:block;height:36px;width:auto}
.pcb-maint__brand{color:#fff;font-weight:800;font-size:22px}
.pcb-maint__rule{width:56px;height:3px;background:var(--color-copper);border:0;margin:0 0 var(--space-lg)}
.pcb-maint h1{margin:0 0 var(--space-lg);font-size:clamp(28px,5vw,40px);line-height:1.15;font-weight:700;color:var(--color-text-primary-dark)}
.pcb-maint p{margin:0 0 var(--space-md);font-size:var(--font-size-lead);line-height:1.55;color:var(--color-text-grey)}
.pcb-maint__contacts{display:grid;gap:var(--space-sm);margin-top:var(--space-xl);padding-top:var(--space-lg);border-top:1px solid var(--color-separator)}
.pcb-maint__contacts a{font-size:20px;font-weight:600;color:var(--color-text-primary);text-decoration:none}
.pcb-maint__contacts a:hover{color:var(--color-text-copper-dark)}
.pcb-maint__addr{font-size:15px;color:var(--color-text-grey)}
.pcb-maint__foot{padding:var(--space-lg) 16px;text-align:center;font-size:13px;color:var(--color-text-grey)}
</style>
</head>
<body class="pcb-maint">
<header class="pcb-maint__head"><div class="pcb-maint__head-in">
	<?php if ( '' !== $svg ) : ?>
		<img class="pcb-maint__logo" src="<?php echo esc_url( $svg ); ?>" alt="<?php echo esc_attr( $s['brand'] ); ?>" width="268" height="44">
	<?php elseif ( '' !== $logo['url'] ) : ?>
		<img class="pcb-maint__logo" src="<?php echo esc_url( $logo['url'] ); ?>" alt="<?php echo esc_attr( $s['brand'] ); ?>" width="<?php echo (int) $logo['w']; ?>" height="<?php echo (int) $logo['h']; ?>">
	<?php else : ?>
		<span class="pcb-maint__brand"><?php echo esc_html( $s['brand'] ); ?></span>
	<?php endif; ?>
</div></header>
<main class="pcb-maint__main">
	<div class="pcb-maint__card">
		<hr class="pcb-maint__rule">
		<h1><?php echo esc_html( $m['title'] ); ?></h1>
		<?php foreach ( $paras as $p ) : ?>
			<p><?php echo esc_html( $p ); ?></p>
		<?php endforeach; ?>
		<?php $sign = pcb_maint_sign_lines(); if ( $sign ) : ?>
			<div class="pcb-maint__contacts">
				<?php foreach ( $sign as $l ) : ?>
					<?php if ( $l['href'] ) : ?><a href="<?php echo esc_attr( $l['href'] ); ?>"><?php echo esc_html( $l['text'] ); ?></a>
					<?php else : ?><span class="pcb-maint__addr"><?php echo esc_html( $l['text'] ); ?></span><?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<footer class="pcb-maint__foot">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . $s['brand'] ); ?></footer>
</body>
</html>
