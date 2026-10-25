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
} elseif ( in_array( $view, array( 'crm-company', 'crm-contact' ), true ) ) {
	$cl    = $GLOBALS['pcb_panel_client'] ?? null;
	$title = $cl ? ( $cl['name'] ?: ( 'crm-company' === $view ? 'Компания' : 'Контакт' ) ) : 'Новая компания';
} elseif ( 'crm-contact-new' === $view ) {
	$title = 'Новый контакт';
} elseif ( 'crm-clients' === $view ) {
	$title = 'Клиенты';
} elseif ( 'crm-queue' === $view ) {
	$title = 'Проверить совпадения';
} elseif ( 'seo-page' === $view ) {
	$title = 'SEO: ' . wp_strip_all_tags( get_the_title( $GLOBALS['pcb_panel_seo_page'] ) );
} elseif ( in_array( $view, array( 'seo-site', 'seo-markup', 'seo-semantic', 'seo-cards', 'seo-robots', 'seo-maintenance' ), true ) ) {
	$title = array( 'seo-site' => 'Данные сайта', 'seo-markup' => 'Разметка', 'seo-semantic' => 'Семантика', 'seo-cards' => 'Карточки организаций', 'seo-robots' => 'robots.txt', 'seo-maintenance' => 'Техработы' )[ $view ];
} elseif ( 'profile' === $view ) {
	$title = 'Мой профиль';
} elseif ( 'crm-card' === $view ) {
	$title = 'Заявка ' . pcb_rq_label( $GLOBALS['pcb_panel_request_id'] );
} elseif ( 'crm-journal' === $view ) {
	$title = 'Журнал действий';
} elseif ( 'crm-settings' === $view ) {
	$title = 'Реакция на заявку';
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
<?php echo pcb_seo_icon_links(); // phpcs:ignore -- адреса экранированы ?>
<title><?php echo esc_html( $title . ' — панель PCB Восток' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( get_theme_file_uri( 'assets/css/globals.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/panel.css?ver=' . PCB_PANEL_VERSION ); ?>">
<style>.pp-menu{position:relative}.pp-menu__btn{display:flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;cursor:pointer;list-style:none;font-weight:600}.pp-menu__btn::-webkit-details-marker{display:none}.pp-menu__btn:hover,.pp-menu[open] .pp-menu__btn{background:var(--color-section-alt)}.pp-ico{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;color:var(--color-text-primary)}.pp-ico--chev{width:14px;height:14px;transition:transform .2s}.pp-menu[open] .pp-ico--chev{transform:rotate(180deg)}.pp-menu__list{position:absolute;right:0;top:calc(100% + 6px);z-index:30;min-width:260px;display:grid;padding:6px;background:#fff;border:1px solid var(--color-separator);border-radius:8px;box-shadow:0 12px 32px rgba(11,44,34,.14)}.pp-menu__list a{padding:9px 12px;border-radius:6px;color:var(--color-text-black)!important;text-decoration:none;font-size:14px}.pp-menu__list a:hover{background:var(--color-section-alt)}.pp-menu__meta{padding:6px 12px 8px;font-size:12.5px;color:var(--color-text-grey);border-bottom:1px solid var(--color-separator);margin-bottom:4px}.crm-user[title]{cursor:help;border-bottom:1px dotted currentColor}.pp-site{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:1px solid var(--color-separator);border-radius:999px;color:var(--color-text-primary)!important;font-size:13.5px;font-weight:600;text-decoration:none;white-space:nowrap}.pp-site:hover{border-color:var(--color-primary);background:var(--color-primary-light)}.pp-site .pp-ico{width:16px;height:16px}@media (max-width:767px){.pp-site span{display:none}.pp-site{padding:0 9px}}.pp-tzwarn{width:var(--content-size);margin:16px auto 0;box-sizing:border-box;padding:10px 14px;border-radius:8px;background:var(--color-gold-light);color:#6B5418;font-size:13.5px;line-height:1.5}.pp-tzwarn a{color:inherit;font-weight:600}.pp-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;margin-left:6px;padding:0 6px;border-radius:999px;background:var(--color-copper-dark);color:#fff;font-size:12px;font-weight:600;line-height:1}</style>
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
			<a class="pp-site" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" title="Открыть сайт в новой вкладке">
				<svg class="pp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
				<span>Сайт</span>
			</a>
			<?php if ( $user->exists() ) : ?>
				<span class="pp-role<?php echo current_user_can( 'manage_options' ) ? '' : ' pp-role--ro'; ?>"><?php echo esc_html( pcb_panel_role_label() ); ?></span>
				<details class="pp-menu">
					<summary class="pp-menu__btn" title="<?php echo esc_attr( 'Логин: ' . $user->user_login ); ?>">
						<svg class="pp-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
						<span class="pp-user__name"><?php echo esc_html( $user->display_name ); ?></span>
						<svg class="pp-ico pp-ico--chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
					</summary>
					<div class="pp-menu__list">
						<span class="pp-menu__meta">Логин: <?php echo esc_html( $user->user_login ); ?></span>
						<a href="<?php echo esc_url( pcb_panel_url( 'profile' ) ); ?>">Мой профиль — имя и подпись</a>
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">Пароль и двухфакторный вход</a>
						<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Выйти</a>
					</div>
				</details>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php if ( current_user_can( 'manage_options' ) && 0 === (int) wp_timezone()->getOffset( new DateTime( 'now' ) ) ) : ?>
	<div class="pp-tzwarn">В настройках WordPress часовой пояс — UTC, поэтому время в панели (заявки, журнал, обновление Метрики) отстаёт от московского на 3 часа.
		<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Настройки → Общие → Часовой пояс: «Москва»</a>. Уже записанные события останутся со старым временем.</div>
<?php endif; ?>

<main class="pp-main">
	<?php include PCB_PANEL_DIR . 'views/' . $view . '.php'; ?>
</main>

<footer class="pp-foot">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Открыть сайт</a>
	<span>Версия панели <?php echo esc_html( PCB_PANEL_VERSION ); ?></span>
</footer>

</body>
</html>
