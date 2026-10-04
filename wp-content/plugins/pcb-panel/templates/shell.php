<?php
/**
 * Каркас панели: шапка с вкладками + содержимое вкладки.
 * wp_head() не вызывается намеренно: стили и скрипты темы и чужих плагинов
 * в панель не попадают, подключаем только своё.
 */

defined( 'ABSPATH' ) || exit;

$view    = $GLOBALS['pcb_panel_view'];
$all     = pcb_panel_tabs();
$tabs    = pcb_panel_allowed_tabs();
$current = isset( $all[ $view ] ) ? $view : '';
$user    = wp_get_current_user();

if ( $current ) {
	$title = $all[ $current ]['title'];
} elseif ( 'forbidden' === $view ) {
	$title = 'Нет доступа';
} else {
	$title = 'Раздел не найден';
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' — панель PCB Восток' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( get_theme_file_uri( 'assets/css/globals.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/panel.css?ver=' . PCB_PANEL_VERSION ); ?>">
</head>
<body class="pp">

<header class="pp-head">
	<div class="pp-head__in">
		<a class="pp-brand" href="<?php echo esc_url( pcb_panel_url() ); ?>">
			<span class="pp-brand__name">PCB Восток</span>
			<span class="pp-brand__sub">панель</span>
		</a>

		<?php if ( $tabs ) : ?>
		<nav class="pp-tabs" aria-label="Разделы панели">
			<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a class="pp-tab<?php echo $slug === $current ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( pcb_panel_url( $slug ) ); ?>"
					<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<div class="pp-user">
			<?php if ( $user->exists() ) : ?>
				<span class="pp-user__name"><?php echo esc_html( $user->display_name ); ?></span>
				<span class="pp-role<?php echo current_user_can( 'manage_options' ) ? '' : ' pp-role--ro'; ?>"><?php echo esc_html( pcb_panel_role_label() ); ?></span>
				<a class="pp-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Выйти</a>
			<?php endif; ?>
		</div>
	</div>
</header>

<main class="pp-main">
	<?php include PCB_PANEL_DIR . 'views/' . $view . '.php'; ?>
</main>

<footer class="pp-foot">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Открыть сайт</a>
	<span>Версия панели <?php echo esc_html( PCB_PANEL_VERSION ); ?></span>
</footer>

</body>
</html>
