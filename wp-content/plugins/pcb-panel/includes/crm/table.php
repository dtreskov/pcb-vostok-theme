<?php
/**
 * Общая таблица списков панели (0.9.0): «Заявки», «Клиенты», «Журнал действий».
 *
 * Состояние списка — в адресе: ?sort=<ключ>&dir=asc|desc, фильтры, поиск ?q=, вкладка, страница ?pg=.
 * Ссылкой можно поделиться — откроется тот же срез. Сортировка и фильтры — на сервере.
 * Строка таблицы с data-href открывается кликом (assets/table.js); ссылки внутри строки работают как обычно.
 *
 * Описание фильтров ($filters):
 *   'key' => array( 'type' => 'select', 'label' => 'Услуга', 'options' => array( value => подпись ) )
 *            ( + 'chip_value' => true — в чипе само значение, а не подпись: у меток подпись с числом записей )
 *   'key' => array( 'type' => 'check',  'label' => 'Есть важные заметки' )          значение '1'
 *   'key' => array( 'type' => 'period', 'label' => 'Период' )  значения 7 | 30 | 90 | custom (+ ?from=&to=, ГГГГ-ММ-ДД)
 * Вкладки сверху ($keep) — обычные параметры адреса (status, type), чипами не показываются.
 */

defined( 'ABSPATH' ) || exit;

class PCB_List {

	public $base;
	public $sort;
	public $dir;
	public $q   = '';
	public $pg  = 1;
	public $per = 50;
	public $f   = array(); // значения фильтров
	public $keep = array(); // вкладки: параметр => значение
	private $keep_def = array();

	private $sorts;
	private $filters;

	/**
	 * @param string $base    адрес списка
	 * @param array  $sorts   ключ => направление по умолчанию ('asc' | 'desc'); первый ключ — сортировка по умолчанию
	 * @param array  $filters описание фильтров (см. выше)
	 * @param array  $keep    параметры вкладок: имя => допустимые значения (первое — по умолчанию)
	 */
	public function __construct( $base, array $sorts, array $filters = array(), array $keep = array() ) {
		$this->base    = $base;
		$this->sorts   = $sorts;
		$this->filters = $filters;

		$get = function ( $k ) {
			return isset( $_GET[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};

		foreach ( $keep as $k => $allowed ) {
			$v                = $get( $k );
			$this->keep_def[ $k ] = (string) reset( $allowed );
			$this->keep[ $k ]     = in_array( $v, $allowed, true ) ? $v : $this->keep_def[ $k ];
		}

		$keys       = array_keys( $sorts );
		$this->sort = in_array( $get( 'sort' ), $keys, true ) ? $get( 'sort' ) : $keys[0];
		$dir        = $get( 'dir' );
		$this->dir  = in_array( $dir, array( 'asc', 'desc' ), true ) ? $dir : $sorts[ $this->sort ];
		$this->q    = mb_substr( $get( 'q' ), 0, 100 );
		$this->pg   = max( 1, (int) $get( 'pg' ) );

		foreach ( $filters as $k => $def ) {
			$v = $get( $k );
			switch ( $def['type'] ) {
				case 'select':
					$v = isset( $def['options'][ $v ] ) ? $v : '';
					break;
				case 'check':
					$v = '1' === $v ? '1' : '';
					break;
				case 'period':
					$v = in_array( $v, array( '7', '30', '90', 'custom' ), true ) ? $v : '';
					if ( 'custom' === $v ) {
						$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'from' ) ) ? $get( 'from' ) : '';
						$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'to' ) ) ? $get( 'to' ) : '';
						$this->f['from'] = $from;
						$this->f['to']   = $to;
						$v = ( '' !== $from || '' !== $to ) ? 'custom' : '';
					}
					break;
				default:
					$v = '';
			}
			$this->f[ $k ] = $v;
		}
	}

	/** Значение фильтра или вкладки. */
	public function val( $k ) {
		return $this->f[ $k ] ?? ( $this->keep[ $k ] ?? '' );
	}

	/** Все параметры текущего состояния (без пустых и без значений по умолчанию). */
	public function params() {
		$p = $this->keep;
		foreach ( $this->filters as $k => $def ) {
			$p[ $k ] = $this->f[ $k ];
			if ( 'period' === $def['type'] && 'custom' === $this->f[ $k ] ) {
				$p['from'] = $this->f['from'];
				$p['to']   = $this->f['to'];
			}
		}
		$p['q'] = $this->q;
		$keys   = array_keys( $this->sorts );
		if ( $this->sort !== $keys[0] || $this->dir !== $this->sorts[ $this->sort ] ) {
			$p['sort'] = $this->sort;
			$p['dir']  = $this->dir;
		}
		return $p;
	}

	/** Адрес с изменёнными параметрами; страница сбрасывается, если не задана явно. */
	public function url( array $over = array() ) {
		$p = array_merge( $this->params(), $over );
		foreach ( $this->keep_def as $k => $def ) {
			if ( isset( $p[ $k ] ) && (string) $p[ $k ] === $def ) {
				unset( $p[ $k ] ); // вкладка по умолчанию в адрес не пишется
			}
		}
		$p = array_filter( array_map( 'strval', $p ), 'strlen' );
		return add_query_arg( array_map( 'rawurlencode', $p ), $this->base );
	}

	/** Есть ли выбранные фильтры или поиск. */
	public function filtered() {
		return '' !== $this->q || $this->active_count() > 0;
	}

	public function active_count() {
		$n = 0;
		foreach ( $this->filters as $k => $def ) {
			$n += '' !== $this->f[ $k ] ? 1 : 0;
		}
		return $n;
	}

	/** Начало периода (локальное время, 'Y-m-d H:i:s') и конец — для фильтра типа period. */
	public function period( $k ) {
		$v = $this->f[ $k ] ?? '';
		if ( '' === $v ) {
			return array( '', '' );
		}
		if ( 'custom' === $v ) {
			return array(
				'' !== $this->f['from'] ? $this->f['from'] . ' 00:00:00' : '',
				'' !== $this->f['to'] ? $this->f['to'] . ' 23:59:59' : '',
			);
		}
		return array( wp_date( 'Y-m-d 00:00:00', time() - ( (int) $v - 1 ) * DAY_IN_SECONDS ), '' );
	}

	/* ---------- разметка ---------- */

	/** Заголовок столбца; $key = '' — без сортировки. */
	public function th( $key, $label, $class = '' ) {
		$cls = trim( 'pp-th ' . $class );
		if ( '' === $key || ! isset( $this->sorts[ $key ] ) ) {
			return '<th class="' . esc_attr( $cls ) . '" scope="col">' . esc_html( $label ) . '</th>';
		}
		$on   = $key === $this->sort;
		$next = $on ? ( 'asc' === $this->dir ? 'desc' : 'asc' ) : $this->sorts[ $key ];
		$aria = $on ? ( 'asc' === $this->dir ? 'ascending' : 'descending' ) : 'none';
		$arr  = $on ? ( 'asc' === $this->dir ? '↑' : '↓' ) : '↕';
		return '<th class="' . esc_attr( $cls . ( $on ? ' is-sorted' : '' ) ) . '" scope="col" aria-sort="' . $aria . '">'
			. '<a class="pp-sort" href="' . esc_url( $this->url( array( 'sort' => $key, 'dir' => $next, 'pg' => '' ) ) ) . '">'
			. esc_html( $label ) . '<span class="pp-sort__arr" aria-hidden="true">' . $arr . '</span></a></th>';
	}

	/** Вкладки сверху: $items = значение => подпись; $counts = значение => число (необязательно). */
	public function tabs( $param, array $items, array $counts = array(), $aria = '' ) {
		$h = '<nav class="crm-filters" aria-label="' . esc_attr( $aria ) . '">';
		foreach ( $items as $v => $label ) {
			$on = (string) $v === (string) $this->keep[ $param ];
			$n  = $counts[ $v ] ?? null;
			$h .= '<a class="pp-filter' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( $this->url( array( $param => $v, 'pg' => '' ) ) ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>'
				. esc_html( $label ) . ( null !== $n ? '<span class="pp-filter__n">' . (int) $n . '</span>' : '' ) . '</a>';
		}
		return $h . '</nav>';
	}

	/**
	 * Поиск, кнопка «Фильтры» с выпадающей панелью и чипы выбранных фильтров.
	 * Всё — одна GET-форма: Enter в поиске и «Применить» сохраняют и поиск, и фильтры.
	 */
	public function toolbar( $placeholder, $tabs_html = '' ) {
		$h  = '<form class="crm-toolbar pp-list-tools" method="get" action="' . esc_url( $this->base ) . '">';
		$h .= $tabs_html;
		$h .= '<div class="pp-list-tools__right">';
		foreach ( $this->keep as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$p = $this->params();
		if ( isset( $p['sort'] ) ) {
			$h .= '<input type="hidden" name="sort" value="' . esc_attr( $p['sort'] ) . '"><input type="hidden" name="dir" value="' . esc_attr( $p['dir'] ) . '">';
		}
		$h .= '<label class="crm-search"><span class="pp-sr">Поиск</span><input type="search" name="q" value="' . esc_attr( $this->q ) . '" placeholder="' . esc_attr( $placeholder ) . '"></label>';
		if ( $this->filters ) {
			$n  = $this->active_count();
			$h .= '<details class="pp-fpanel"><summary class="pp-filter pp-fpanel__btn' . ( $n ? ' is-on' : '' ) . '">Фильтры' . ( $n ? '<span class="pp-filter__n">' . $n . '</span>' : '' ) . '</summary>';
			$h .= '<div class="pp-fpanel__box">';
			foreach ( $this->filters as $k => $def ) {
				$h .= $this->field( $k, $def );
			}
			$h .= '<div class="crm-form__acts"><a class="pp-btn pp-btn--sm pp-btn--ghost" href="' . esc_url( $this->reset_url() ) . '">Сбросить</a><button class="pp-btn pp-btn--sm" type="submit">Применить</button></div>';
			$h .= '</div></details>';
		}
		$h .= '</div></form>';
		return $h . $this->chips();
	}

	private function field( $k, array $def ) {
		$v  = $this->f[ $k ];
		$id = 'pf-' . $k;
		switch ( $def['type'] ) {
			case 'select':
				$o = '<option value="">Все</option>';
				foreach ( $def['options'] as $ov => $ol ) {
					$o .= '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $ov, $v, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				return '<label class="pp-fpanel__row" for="' . $id . '"><span>' . esc_html( $def['label'] ) . '</span><select id="' . $id . '" name="' . esc_attr( $k ) . '">' . $o . '</select></label>';
			case 'check':
				return '<label class="pp-fpanel__row pp-fpanel__row--check"><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( '1', $v, false ) . '><span>' . esc_html( $def['label'] ) . '</span></label>';
			case 'period':
				$o = '<option value="">За всё время</option>';
				foreach ( array( '7' => '7 дней', '30' => '30 дней', '90' => '90 дней', 'custom' => 'Свой период' ) as $ov => $ol ) {
					$o .= '<option value="' . $ov . '"' . selected( $ov, $v, false ) . '>' . $ol . '</option>';
				}
				return '<div class="pp-fpanel__row pp-fpanel__period"><label for="' . $id . '">' . esc_html( $def['label'] ) . '</label><select id="' . $id . '" name="' . esc_attr( $k ) . '" data-period>' . $o . '</select>'
					. '<span class="pp-fpanel__dates"' . ( 'custom' === $v ? '' : ' hidden' ) . '><input type="date" name="from" value="' . esc_attr( $this->f['from'] ?? '' ) . '" aria-label="С"> — <input type="date" name="to" value="' . esc_attr( $this->f['to'] ?? '' ) . '" aria-label="По"></span></div>';
		}
		return '';
	}

	/** Сброс фильтров и поиска; вкладки и сортировка остаются. */
	public function reset_url() {
		$p = array();
		foreach ( $this->filters as $k => $def ) {
			$p[ $k ] = '';
		}
		return $this->url( array_merge( $p, array( 'q' => '', 'from' => '', 'to' => '', 'pg' => '' ) ) );
	}

	/** Чипы выбранных фильтров: «Услуга: монтаж ×», «Сбросить всё». */
	public function chips() {
		$items = array();
		foreach ( $this->filters as $k => $def ) {
			$v = $this->f[ $k ];
			if ( '' === $v ) {
				continue;
			}
			$off = array( $k => '', 'pg' => '' );
			switch ( $def['type'] ) {
				case 'select':
					$text = $def['label'] . ': ' . ( ! empty( $def['chip_value'] ) ? $v : $def['options'][ $v ] );
					break;
				case 'check':
					$text = $def['label'];
					break;
				case 'period':
					$off['from'] = '';
					$off['to']   = '';
					if ( 'custom' === $v ) {
						$a    = '' !== $this->f['from'] ? mysql2date( 'd.m.Y', $this->f['from'] ) : '';
						$b    = '' !== $this->f['to'] ? mysql2date( 'd.m.Y', $this->f['to'] ) : '';
						$text = $def['label'] . ': ' . ( '' !== $a && '' !== $b ? $a . ' — ' . $b : ( '' !== $a ? 'с ' . $a : 'по ' . $b ) );
					} else {
						$text = $def['label'] . ': ' . $v . ' дней';
					}
					break;
				default:
					$text = $v;
			}
			$items[] = '<a class="pp-chip" href="' . esc_url( $this->url( $off ) ) . '" title="Убрать фильтр">' . esc_html( $text ) . '<span aria-hidden="true">×</span></a>';
		}
		if ( ! $items ) {
			return '';
		}
		if ( count( $items ) > 1 || '' !== $this->q ) {
			$items[] = '<a class="pp-chip pp-chip--reset" href="' . esc_url( $this->reset_url() ) . '">Сбросить всё</a>';
		}
		return '<div class="pp-chips">' . implode( '', $items ) . '</div>';
	}

	/** Страницы: ‹ 1 2 … › */
	public function pages( $total ) {
		$max = (int) ceil( $total / $this->per );
		if ( $max < 2 ) {
			return '';
		}
		$h = '<nav class="crm-pages" aria-label="Страницы">';
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( $max > 12 && $i > 2 && $i < $max - 1 && abs( $i - $this->pg ) > 2 ) {
				if ( 3 === $i || $max - 2 === $i ) {
					$h .= '<span class="pp-pages__gap">…</span>';
				}
				continue;
			}
			$h .= '<a class="pp-filter' . ( $i === $this->pg ? ' is-on' : '' ) . '" href="' . esc_url( $this->url( array( 'pg' => $i > 1 ? (string) $i : '' ) ) ) . '">' . (int) $i . '</a>';
		}
		return $h . '</nav>';
	}

	/** Атрибуты строки, открываемой кликом. */
	public static function row( $href, $class = '' ) {
		$cls = trim( 'pp-row' . ( '' !== $class ? ' ' . $class : '' ) );
		return ' class="' . esc_attr( $cls ) . '"' . ( '' !== (string) $href ? ' data-href="' . esc_url( $href ) . '"' : '' );
	}

	/** Дата в две строки: «17.10.2026» и мелко серым «09:58» (+ подпись после времени). Время — локальное, как в базе. */
	public static function date( $mysql, $after = '' ) {
		if ( ! $mysql || 0 === strpos( (string) $mysql, '0000' ) ) {
			return '<span class="pp-muted">—</span>';
		}
		return '<span class="pp-dt">' . esc_html( mysql2date( 'd.m.Y', $mysql ) ) . '<span class="pp-dt__time">' . esc_html( mysql2date( 'H:i', $mysql ) ) . ( '' !== $after ? ' · ' . $after : '' ) . '</span></span>';
	}

	/** Подключение скрипта таблицы (один раз на странице). */
	public static function script() {
		return '<script src="' . esc_url( PCB_PANEL_URL . 'assets/table.js?ver=' . PCB_PANEL_VERSION ) . '" defer></script>';
	}
}
