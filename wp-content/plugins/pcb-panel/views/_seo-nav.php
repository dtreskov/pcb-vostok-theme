<?php
/** Подразделы SEO и строка состояния сайта (домен, индексация, техработы, конфликты). $seo_nav — текущий. */
defined( 'ABSPATH' ) || exit;
$seo_nav = $seo_nav ?? 'pages';
$st      = pcb_seo_status();
$base    = pcb_panel_url( 'seo' );
$items   = array(
	'pages'       => array( 'Страницы', $base ),
	'site'        => array( 'Данные сайта', $base . 'site/' ),
	'robots'      => array( 'robots.txt', $base . 'robots/' ),
	'maintenance' => array( 'Техработы', $base . 'maintenance/' ),
);
?>
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.css?ver=' . PCB_PANEL_VERSION ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( PCB_PANEL_URL . 'assets/seo.css?ver=' . PCB_PANEL_VERSION ); ?>">
<nav class="crm-subnav" aria-label="Разделы SEO">
	<?php foreach ( $items as $k => $it ) : ?>
		<a class="<?php echo $k === $seo_nav ? 'is-on' : ''; ?>" href="<?php echo esc_url( $it[1] ); ?>"<?php echo $k === $seo_nav ? ' aria-current="page"' : ''; ?>>
			<?php echo esc_html( $it[0] ); ?><?php if ( 'maintenance' === $k && $st['maint'] ) : ?><span class="pp-badge" title="Сайт закрыт заглушкой">вкл</span><?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>

<div class="seo-state">
	<?php if ( ! $st['prod'] ) : ?>
		<p class="seo-state__row seo-state__row--info">Тестовый домен — закрыт от поиска; на <?php echo esc_html( $st['prodhost'] ); ?> откроется сам.</p>
	<?php elseif ( $st['maint'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>Включены техработы</b> — посетители и поисковики видят заглушку с кодом 503. <a href="<?php echo esc_url( $base . 'maintenance/' ); ?>">Настроить</a></p>
	<?php elseif ( ! $st['public'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>Индексация запрещена в настройках WordPress</b> («Чтение» → «Попросить поисковые системы не индексировать сайт»). Снимите галочку, когда сайт готов.</p>
	<?php else : ?>
		<p class="seo-state__row seo-state__row--ok"><b>Боевой домен <?php echo esc_html( $st['host'] ); ?></b> — сайт открыт для поиска.</p>
	<?php endif; ?>
	<?php if ( ! $st['prod'] && $st['maint'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>Включены техработы</b> — посетители видят заглушку. <a href="<?php echo esc_url( $base . 'maintenance/' ); ?>">Настроить</a></p>
	<?php endif; ?>
	<?php if ( $st['ucmm'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>Ещё включён плагин Under Construction</b> — его заглушка работает поверх. Техработы теперь в панели: отключите и удалите плагин в «Плагинах» WordPress.</p>
	<?php endif; ?>
	<?php if ( $st['conflict'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>Включён <?php echo esc_html( $st['conflict'] ); ?></b> — мета, разметку и robots.txt выводит он; настройки этой вкладки на сайт не попадают (кроме защиты тестового домена).</p>
	<?php endif; ?>
	<?php if ( $st['robots'] ) : ?>
		<p class="seo-state__row seo-state__row--warn"><b>В корне сайта лежит файл robots.txt</b> — сервер отдаёт его, а не тот, что собирает панель. Удалите файл на хостинге.</p>
	<?php endif; ?>
</div>
