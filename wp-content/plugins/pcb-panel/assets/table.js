/**
 * Общая таблица списков панели (README §6.2): строка с data-href открывается кликом,
 * Ctrl/⌘/Shift или средняя кнопка — в новой вкладке. Ссылки, кнопки и поля внутри строки работают как обычно,
 * выделение текста строку не открывает. С клавиатуры — через ссылку в первой ячейке.
 * Панель «Фильтры»: закрывается по Esc и клику мимо; «Свой период» показывает поля дат.
 */
( function () {
	'use strict';

	var SKIP = 'a, button, input, select, textarea, label, summary, details, [data-no-row]';

	function target( e ) {
		var tr = e.target.closest && e.target.closest( 'tr[data-href]' );
		if ( ! tr || e.target.closest( SKIP ) ) {
			return null;
		}
		return tr;
	}

	document.addEventListener( 'click', function ( e ) {
		var tr = target( e );
		if ( ! tr || e.button !== 0 ) {
			return;
		}
		var sel = window.getSelection && String( window.getSelection() );
		if ( sel && tr.contains( window.getSelection().anchorNode ) ) {
			return; // выделяли текст — не открываем
		}
		if ( e.ctrlKey || e.metaKey || e.shiftKey ) {
			window.open( tr.dataset.href, '_blank', 'noopener' );
		} else {
			window.location.href = tr.dataset.href;
		}
	} );

	// средняя кнопка: без автопрокрутки браузера, открыть в новой вкладке
	document.addEventListener( 'mousedown', function ( e ) {
		if ( e.button === 1 && target( e ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'auxclick', function ( e ) {
		var tr = target( e );
		if ( tr && e.button === 1 ) {
			e.preventDefault();
			window.open( tr.dataset.href, '_blank', 'noopener' );
		}
	} );

	// панель «Фильтры»
	document.addEventListener( 'click', function ( e ) {
		document.querySelectorAll( '.pp-fpanel[open]' ).forEach( function ( d ) {
			if ( ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			document.querySelectorAll( '.pp-fpanel[open]' ).forEach( function ( d ) {
				d.open = false;
				d.querySelector( 'summary' ).focus();
			} );
		}
	} );
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-period]' ) ) {
			var dates = e.target.parentNode.querySelector( '.pp-fpanel__dates' );
			if ( dates ) {
				dates.hidden = e.target.value !== 'custom';
			}
		}
	} );

	// пустые поля не пишем в адрес — ссылка остаётся короткой
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( ! f.classList.contains( 'pp-list-tools' ) ) {
			return;
		}
		f.querySelectorAll( 'input, select' ).forEach( function ( el ) {
			if ( ( el.type === 'checkbox' && ! el.checked ) || el.value === '' || ( el.closest( '.pp-fpanel__dates' ) && el.closest( '.pp-fpanel__dates' ).hidden ) ) {
				el.disabled = true;
			}
		} );
	} );
	// «Назад» из карточки: страница из кеша браузера — поля снова доступны
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.pp-list-tools [disabled]' ).forEach( function ( el ) {
			el.disabled = false;
		} );
	} );
} )();
