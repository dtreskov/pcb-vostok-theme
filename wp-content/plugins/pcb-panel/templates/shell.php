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
$current = isset( $all[ $GLOBALS['pcb_panel_tab'] ?? '' ] ) ? $GLOBALS['pcb_panel_tab'] : '';
$unseen  = isset( $tabs['crm'] ) ? pcb_crm_unseen_count() : 0;
$user    = wp_get_current_user();

$bare = ! empty( $GLOBALS['pcb_panel_bare'] );
if ( 'crm-reply' === $view ) {
	$title = 'Письмо по заявке ' . pcb_rq_label( $GLOBALS['pcb_panel_request_id'] );
} elseif ( 'profile' === $view ) {
	$title = 'Мой профиль';
} elseif ( 'crm-card' === $view ) {
	$title = 'Заявка ' . pcb_rq_label( $GLOBALS['pcb_panel_request_id'] );
} elseif ( 'crm-journal' === $view ) {
	$title = 'Журнал действий';
} elseif ( $current ) {
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
<style>.pp-user__name{color:inherit!important;text-decoration:none}.pp-user__name:hover{color:var(--color-text-primary)!important;text-decoration:underline}.pp-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;margin-left:6px;padding:0 6px;border-radius:999px;background:var(--color-copper-dark);color:#fff;font-size:12px;font-weight:600;line-height:1}</style>
</head>
<body class="pp">

<header class="pp-head">
	<div class="pp-head__in">
		<a class="pp-brand" href="<?php echo esc_url( pcb_panel_url() ); ?>">
			<span class="pp-brand__name">PCB Восток</span>
			<span class="pp-brand__sub">панель</span>
		</a>

		<?php if ( $tabs && ! $bare ) : ?>
		<nav class="pp-tabs" aria-label="Разделы панели">
			<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a class="pp-tab<?php echo $slug === $current ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( pcb_panel_url( $slug ) ); ?>"
					<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?><?php if ( 'crm' === $slug && $unseen ) : ?><span class="pp-badge" title="Новые, ещё не открытые"><?php echo (int) $unseen; ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<div class="pp-user">
			<?php if ( $user->exists() ) : ?>
				<a class="pp-user__name" href="<?php echo esc_url( pcb_panel_url( 'profile' ) ); ?>" title="Мой профиль: подпись в письмах"><?php echo esc_html( $user->display_name ); ?></a>
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
