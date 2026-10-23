/**
 * Метки заявок и клиентов (README §6.3). Разметка — pcb_tags_html(): .pp-tags[data-tags-type][data-tags-id][data-tags].
 * В карточке (.pp-tags--card) у меток крестики и кнопка «+ метка»; в списке (.pp-tags--list) редактор открывается
 * по клику на ячейку. Ввод: Enter или запятая — добавить, Backspace в пустом поле — убрать последнюю, Esc — закрыть.
 * Подсказки — уже использованные метки этого набора (<datalist>). Каждое изменение сразу сохраняется: POST /tags.
 */
( function () {
	'use strict';
	var CFG = window.PCBTags || {};
	if ( ! CFG.api ) {
		return;
	}

	function datalist( type ) {
		var set = type === 'request' ? 'request' : 'client';
		var id = 'pp-tags-sug-' + set;
		var dl = document.getElementById( id );
		if ( ! dl ) {
			dl = document.createElement( 'datalist' );
			dl.id = id;
			document.body.appendChild( dl );
			fill( dl, CFG.sug[ type ] || [] );
		}
		return dl;
	}
	function fill( dl, list ) {
		dl.textContent = '';
		list.forEach( function ( t ) {
			var o = document.createElement( 'option' );
			o.value = t;
			dl.appendChild( o );
		} );
	}

	function say( text ) {
		var msg = document.querySelector( '.crm-msg' );
		if ( ! msg ) {
			msg = document.createElement( 'p' );
			msg.className = 'crm-msg';
			msg.setAttribute( 'role', 'status' );
			document.body.appendChild( msg );
		}
		msg.textContent = text;
		msg.classList.add( 'is-error' );
		msg.hidden = false;
		clearTimeout( say.t );
		say.t = setTimeout( function () { msg.hidden = true; }, 6000 );
	}

	function clean( v ) {
		return String( v || '' ).replace( /,/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, CFG.max || 30 );
	}

	function Editor( el ) {
		this.el = el;
		this.type = el.getAttribute( 'data-tags-type' );
		this.id = el.getAttribute( 'data-tags-id' );
		this.card = el.classList.contains( 'pp-tags--card' );
		try {
			this.tags = JSON.parse( el.getAttribute( 'data-tags' ) || '[]' );
		} catch ( e ) {
			this.tags = [];
		}
		this.open = false;
		this.busy = false;
		this.render();
	}

	Editor.prototype.render = function ( focus ) {
		var self = this, el = this.el;
		el.textContent = '';
		el.classList.toggle( 'is-editing', this.open );
		this.tags.forEach( function ( t, i ) {
			var chip = document.createElement( 'span' );
			chip.className = 'pp-tag';
			chip.textContent = t;
			if ( self.open || self.card ) {
				var x = document.createElement( 'button' );
				x.type = 'button';
				x.className = 'pp-tag__x';
				x.setAttribute( 'aria-label', 'Убрать метку «' + t + '»' );
				x.textContent = '×';
				x.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // не терять фокус поля
				x.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					var next = self.tags.slice();
					next.splice( i, 1 );
					self.save( next, true );
				} );
				chip.appendChild( x );
			}
			el.appendChild( chip );
		} );
		if ( this.open ) {
			var inp = document.createElement( 'input' );
			inp.type = 'text';
			inp.className = 'pp-tags__input';
			inp.maxLength = CFG.max || 30;
			inp.placeholder = this.tags.length ? 'ещё метка' : 'метка';
			inp.setAttribute( 'list', datalist( this.type ).id );
			inp.setAttribute( 'aria-label', 'Новая метка' );
			inp.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ',' ) {
					e.preventDefault();
					self.add( inp.value );
				} else if ( e.key === 'Escape' ) {
					e.preventDefault();
					self.close();
				} else if ( e.key === 'Backspace' && inp.value === '' && self.tags.length ) {
					self.save( self.tags.slice( 0, -1 ), true );
				}
			} );
			// выбор из подсказки в части браузеров приходит событием input без Enter
			inp.addEventListener( 'input', function ( e ) {
				if ( e.inputType === 'insertReplacementText' ) {
					var v = clean( inp.value ), dl = datalist( self.type );
					for ( var k = 0; k < dl.options.length; k++ ) {
						if ( dl.options[ k ].value === v ) {
							self.add( v );
							return;
						}
					}
				}
			} );
			inp.addEventListener( 'blur', function () {
				setTimeout( function () {
					if ( ! self.open || self.el.contains( document.activeElement ) ) {
						return;
					}
					if ( clean( inp.value ) ) {
						self.add( inp.value, true );
					} else {
						self.close();
					}
				}, 120 );
			} );
			el.appendChild( inp );
			if ( focus !== false ) {
				inp.focus();
			}
		} else {
			var add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'pp-tags__add';
			add.textContent = this.card && ! this.tags.length ? '+ метка' : '+';
			add.setAttribute( 'aria-label', 'Добавить метку' );
			add.title = 'Добавить метку';
			add.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				self.start();
			} );
			el.appendChild( add );
		}
	};

	Editor.prototype.start = function () {
		if ( this.busy ) {
			return;
		}
		this.open = true;
		this.render();
	};
	Editor.prototype.close = function () {
		this.open = false;
		this.render();
	};
	Editor.prototype.add = function ( raw, thenClose ) {
		var t = clean( raw );
		if ( ! t ) {
			return;
		}
		var low = t.toLowerCase();
		if ( this.tags.some( function ( x ) { return x.toLowerCase() === low; } ) ) {
			if ( thenClose ) {
				this.close();
			} else {
				this.render();
			}
			return;
		}
		if ( this.tags.length >= ( CFG.per || 10 ) ) {
			say( 'Не больше ' + ( CFG.per || 10 ) + ' меток у одной записи.' );
			return;
		}
		this.save( this.tags.concat( [ t ] ), ! thenClose );
	};

	Editor.prototype.save = function ( next, keepOpen ) {
		var self = this;
		if ( this.busy ) {
			return;
		}
		this.busy = true;
		this.el.classList.add( 'is-busy' );
		fetch( CFG.api, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify( { type: self.type, id: Number( self.id ), tags: next } )
		} ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j.message || 'Не удалось сохранить метки (' + r.status + ')' );
				}
				return j;
			} );
		} ).then( function ( j ) {
			self.tags = j.tags || [];
			self.el.setAttribute( 'data-tags', JSON.stringify( self.tags ) );
			if ( j.sug ) {
				CFG.sug[ self.type ] = j.sug;
				fill( datalist( self.type ), j.sug );
			}
		} ).catch( function ( err ) {
			say( err.message );
		} ).then( function () {
			self.busy = false;
			self.el.classList.remove( 'is-busy' );
			self.open = !! keepOpen && self.open;
			self.render();
		} );
	};

	document.querySelectorAll( '.pp-tags[data-tags-type]' ).forEach( function ( el ) {
		var ed = new Editor( el );
		// в списке — редактор по клику на ячейку
		var cell = el.closest( 'td' );
		if ( cell ) {
			cell.addEventListener( 'click', function ( e ) {
				if ( ! ed.open && ! e.target.closest( 'button, input, a' ) ) {
					ed.start();
				}
			} );
		}
	} );
} )();
