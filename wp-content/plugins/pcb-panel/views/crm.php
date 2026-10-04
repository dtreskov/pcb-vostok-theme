<?php defined( 'ABSPATH' ) || exit; ?>
<div class="pp-page-head">
	<div>
		<h1>Заявки</h1>
		<p>Заявки с формы на сайте и из калькулятора: статус, ответственный, файлы.</p>
	</div>
	<?php if ( current_user_can( 'pcb_crm_delete' ) ) : ?>
		<button class="pp-btn pp-btn--ghost" type="button" disabled>Выгрузить в CSV</button>
	<?php endif; ?>
</div>

<div class="pp-toolbar" aria-label="Фильтры">
	<button class="pp-filter is-on" type="button" disabled>Все</button>
	<button class="pp-filter" type="button" disabled>Новые</button>
	<button class="pp-filter" type="button" disabled>Аудит файлов</button>
	<button class="pp-filter" type="button" disabled>Расчёт</button>
	<button class="pp-filter" type="button" disabled>В работе</button>
	<button class="pp-filter" type="button" disabled>Закрытые</button>
</div>

<section class="pp-empty">
	<h2>Заявок пока нет</h2>
	<p>Они появятся здесь, когда новая форма заявки начнёт сохранять обращения в панель. Это следующий этап работы над CRM.</p>
</section>
