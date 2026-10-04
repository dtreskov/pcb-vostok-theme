/* Ссылки меню ведут на /#якорь — так они работают с любой страницы (контакты,
   калькулятор, политики). Если якорь есть на текущей странице (главная), делаем
   ссылку локальной (#якорь): переход без перезагрузки, его ловят подсветка раздела
   ниже и utils.js (открытие спойлера раздела). Выполняется до них. (2026-10-03) */
(function(){
  'use strict';
  [].forEach.call(document.querySelectorAll('.wp-site-blocks > header a[href^="/#"]'), function(a){
    var id = a.getAttribute('href').slice(2);
    if (id && document.getElementById(id)) a.setAttribute('href', '#' + id);
  });
})();

/* Шапка сайта: логотип (концепция 25), сжатие при скролле, подсветка раздела, копирование контактов */

/* ===== логотип: построитель концепции 25 + разметка для анимаций ===== */
(function(){

'use strict';
var LS=[14,26,38,50];
function T(k, after, gap, cls, size, y, txt, extra){
  return '<text ' + (k ? 'data-k="' + k + '" ' : '') + (after ? 'data-after="' + after + '" data-gap="' + gap + '" ' : '')
    + 'class="' + cls + '" font-size="' + size + '" x="0" y="' + y + '"' + (extra || '') + '>' + txt + '</text>';
}
function G(k, after, gap, inner){
  return '<g ' + (k ? 'data-k="' + k + '" ' : '') + (after ? 'data-after="' + after + '" data-gap="' + gap + '"' : '') + '>' + inner + '</g>';
}

/* ---------- SOIC8 ---------- */
var LS = [14,26,38,50];
function soic(o){
  o = o || {}; var l = o.l || 's-cu', r = o.r || 's-au', mode = o.mode || 'none', s = '';
  var fl = l.replace('s-','f-'), fr = r.replace('s-','f-');
  var A = ' stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"';
  if (mode === 'fan'){
    var C = [23,29,35,41];
    LS.forEach(function(L,i){ var d = Math.abs(L-C[i]);
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H24L'+(24-d)+' '+C[i]+'H6"/><circle class="'+fl+'" cx="4" cy="'+C[i]+'" r="2.8"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H92L'+(92+d)+' '+C[i]+'H110"/><circle class="'+fr+'" cx="112" cy="'+C[i]+'" r="2.8"/>';
    });
  } else if (mode === 'stagger'){
    var EL = [6,16,6,16], ER = [110,100,110,100];
    LS.forEach(function(L,i){
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H'+(EL[i]+3.4)+'"/><circle class="'+l+'" stroke-width="2.2" style="fill:var(--bg)" cx="'+EL[i]+'" cy="'+L+'" r="3.4"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H'+(ER[i]-3.4)+'"/><circle class="'+r+'" stroke-width="2.2" style="fill:var(--bg)" cx="'+ER[i]+'" cy="'+L+'" r="3.4"/>';
    });
  } else if (mode === 'stub'){
    (o.stubs || [0,1,2]).forEach(function(i){ var L = LS[i];
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H22.4"/><circle class="'+(o.vl||l)+'" stroke-width="2.2" style="fill:var(--bg)" cx="19" cy="'+L+'" r="3.4"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H93.6"/><circle class="'+(o.vr||r)+'" stroke-width="2.2" style="fill:var(--bg)" cx="97" cy="'+L+'" r="3.4"/>';
    });
  } else if (mode === 'ul'){
    LS.slice(0,3).forEach(function(L){
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H21"/><circle class="'+fl+'" cx="19" cy="'+L+'" r="2.8"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H95"/><circle class="'+fr+'" cx="97" cy="'+L+'" r="2.8"/>';
    });
  }
  LS.forEach(function(L){
    s += '<rect class="f-au" x="30" y="'+(L-2.5)+'" width="8" height="5" rx="1"/><rect class="f-au2" x="35.5" y="'+(L-2.5)+'" width="2.5" height="5"/>';
    s += '<rect class="f-au" x="78" y="'+(L-2.5)+'" width="8" height="5" rx="1"/><rect class="f-au2" x="78" y="'+(L-2.5)+'" width="2.5" height="5"/>';
  });
  s += '<rect class="'+(o.body || 'f-body')+'" x="38" y="5" width="40" height="54" rx="3"/>';
  s += '<rect class="s-hi" stroke-width="1" x="40.5" y="7.5" width="35" height="49" rx="2"/>';
  s += '<circle class="f-hi" cx="45.5" cy="14" r="2.8"/>';
  if (o.mark) s += '<text class="mo f-mk" font-weight="600" font-size="10.5" x="58" y="36" text-anchor="middle" style="letter-spacing:.06em">'+o.mark+'</text>';
  return s;
}
/* дорожка-подчёркивание: от точки на соседнем элементе под всё слово */
function UL(textKey, relKey, x0, y1, y2, dir, mode, cls, capCls){
  return '<path data-ul="'+textKey+'" data-rel="'+relKey+'" data-x0="'+x0+'" data-y1="'+y1+'" data-y2="'+y2+'" data-dir="'+dir+'" data-mode="'+mode+'" class="'+cls+'" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" d="M0 0"/>'
    + '<circle data-cap="1" class="'+(capCls||cls)+'" stroke-width="2.2" style="fill:var(--bg)" r="3.4" cx="0" cy="0"/>';
}

var FULL=T('a','',0,'i8 f-wt',44,48,'PCB') + G('u','a',10,soic({mode:'stub',stubs:[1,2],l:'s-cu',r:'s-cu',vl:'s-au',vr:'s-au'})) + T('w','u',10,'i8 f-ink',44,48,'ВОСТОК') + UL('a','u',30,14,6,-1,'h','s-cu','s-au') + UL('w','u',86,14,6,1,'h','s-cu','s-au') + UL('a','u',30,50,58,-1,'h','s-cu','s-au') + UL('w','u',86,50,58,1,'h','s-cu','s-au');
var NS='http://www.w3.org/2000/svg';
function bb(el){ try { return el.getBBox(); } catch(e){ return {x:0,y:0,width:0,height:0}; } }
function layout(svg){
  var map = {};
  [].forEach.call(svg.querySelectorAll('[data-k]'), function(n){ map[n.getAttribute('data-k')] = n; n._dx = 0; });
  var ok = true;
  [].forEach.call(svg.querySelectorAll('[data-after]'), function(n){
    var r = map[n.getAttribute('data-after')]; if (!r) return;
    var rb = bb(r), nb = bb(n);
    if (!rb.width || !nb.width) { ok = false; return; }
    var x = (r._dx || 0) + rb.x + rb.width + (+n.getAttribute('data-gap')) - nb.x;
    n.setAttribute('transform','translate(' + x.toFixed(2) + ' 0)'); n._dx = x;
  });
  if (!ok) return false;
  [].forEach.call(svg.querySelectorAll('[data-ul]'), function(p){
    var t = map[p.getAttribute('data-ul')], rel = map[p.getAttribute('data-rel')]; if (!t || !rel) return;
    var x0 = +p.getAttribute('data-x0'), y1 = +p.getAttribute('data-y1'), y2 = +p.getAttribute('data-y2'), dir = +p.getAttribute('data-dir');
    var sx = (rel._dx || 0) + x0, tb = bb(t), tx = tb.x + (t._dx || 0);
    var end = dir < 0 ? tx + 3 : tx + tb.width - 3, dy = Math.abs(y2 - y1), d, md = p.getAttribute('data-mode');
    if (md === 's'){ y2 = y1; d = 'M'+sx+' '+y1+'H'+(end - dir*3.4); }
    else if (md === 'v') d = 'M'+sx+' '+y1+'V'+(y1+2)+'L'+(sx+dir*(dy-2))+' '+y2+'H'+end;
    else d = 'M'+sx+' '+y1+'H'+(sx+dir*5)+'L'+(sx+dir*(5+dy))+' '+y2+'H'+(end - dir*3.4);
    p.setAttribute('d', d);
    var c = p.nextElementSibling; if (c && c.hasAttribute('data-cap')){ c.setAttribute('cx', end); c.setAttribute('cy', y2); }
  });
  var b = bb(svg.querySelector('g.root'));
  if (!b.width || !b.height) return false;
  var x = b.x, y = b.y, w = b.width, h = b.height;
  if (svg._sq){ var m = Math.max(w,h); x -= (m-w)/2; y -= (m-h)/2; w = h = m; }
  else { var pd = h*0.04; x -= pd; y -= pd; w += 2*pd; h += 2*pd; }
  svg.setAttribute('viewBox', [x,y,w,h].map(function(v){return v.toFixed(2);}).join(' '));
  svg.setAttribute('height', svg._h);
  svg.setAttribute('width', (svg._h * w / h).toFixed(1));
  return true;
}


function clonePulse(p){
  var c = document.createElementNS(NS,'path');
  c.setAttribute('d', p.getAttribute('d')); c.setAttribute('pathLength','1');
  c.setAttribute('class','pulse'); c.setAttribute('data-clone','1');
  p.parentNode.insertBefore(c, p.nextSibling);
}
function decorate(svg){
  var hdr = document.querySelector('.wp-site-blocks > header');
  [].forEach.call(svg.querySelectorAll('[data-clone]'), function(n){ n.remove(); });
  [].forEach.call(svg.querySelectorAll('[data-ul]'), function(p){
    p.setAttribute('pathLength','1'); p.classList.add('tr');
    var cap = p.nextElementSibling;
    if (cap && cap.hasAttribute('data-cap')) cap.classList.add('cap');
    clonePulse(p);
  });
  var chip = svg.querySelector('[data-k="u"]');
  [].forEach.call(chip.querySelectorAll('path.s-cu'), function(p){ p.setAttribute('pathLength','1'); p.classList.add('stub'); });
  [].forEach.call(chip.querySelectorAll('circle'), function(c){ if ((c.getAttribute('style')||'').indexOf('--bg') > -1) c.classList.add('via'); });
  /* слова: g.wtxt-pin (translate к точке привязки) > g.wtxt (CSS scale/translate от 0 0)
     > g.wtxt-in (translate обратно) > text. Точка привязки — край слова, обращённый к чипу.
     Без transform-box:fill-box — Firefox по нему терял первую букву при перерисовке. */
  ['a','w'].forEach(function(k){
    var t = svg.querySelector('text[data-k="' + k + '"]');
    var inner = t.parentNode, pin;
    if (!inner.classList.contains('wtxt-in')){
      pin = document.createElementNS(NS,'g'); pin.setAttribute('class','wtxt-pin');
      var g = document.createElementNS(NS,'g'); g.setAttribute('class','wtxt wtxt--' + k);
      inner = document.createElementNS(NS,'g'); inner.setAttribute('class','wtxt-in');
      t.parentNode.insertBefore(pin, t); pin.appendChild(g); g.appendChild(inner); inner.appendChild(t);
    } else pin = inner.parentNode.parentNode;
    var tb = t.getBBox(), dx = t._dx || 0;
    var ox = k === 'a' ? dx + tb.x + tb.width : dx + tb.x, oy = tb.y + tb.height / 2;
    pin.setAttribute('transform', 'translate(' + ox.toFixed(2) + ' ' + oy.toFixed(2) + ')');
    inner.setAttribute('transform', 'translate(' + (-ox).toFixed(2) + ' ' + (-oy).toFixed(2) + ')');
    hdr.style.setProperty(k === 'a' ? '--wa' : '--ww', tb.width.toFixed(2));
  });
  var vb = svg.getAttribute('viewBox').split(' ').map(Number);
  hdr.style.setProperty('--k', (54 / (44 * 0.727)).toFixed(4));   /* высота корпуса / высота прописных */
  hdr.style.setProperty('--r-full', (vb[2] / vb[3]).toFixed(4));
  hdr.style.setProperty('--vb-h', vb[3].toFixed(3));
  /* правка сборки: запас по бокам viewBox (3 высоты), чтобы слова компактной шапки оставались внутри SVG */
  var pad = vb[3] * 3;
  svg.setAttribute('viewBox', [vb[0] - pad, vb[1], vb[2] + 2 * pad, vb[3]].map(function(v){return v.toFixed(2);}).join(' '));
  hdr.style.setProperty('--vb-pad', pad.toFixed(3));
  svg.removeAttribute('width'); svg.removeAttribute('height');
  window.dispatchEvent(new Event('pv-logo-ready'));
}
var host = document.getElementById('logo-host');
var s = document.createElementNS(NS,'svg');
s.setAttribute('class','lg'); s.setAttribute('role','img'); s.setAttribute('aria-label','PCB Восток'); s.id = 'logo-svg';
s.innerHTML = '<g class="root">' + FULL + '</g>';
s._h = 50; s._sq = false; s.setAttribute('viewBox','-4 0 400 64');
host.appendChild(s);
var tries = 0;
function run(){ if (layout(s)) decorate(s); else if (tries++ < 120) setTimeout(run, 250); }
function again(){ [].forEach.call(s.querySelectorAll('[data-clone]'), function(n){ n.remove(); }); tries = 0; run(); }
if (document.fonts && document.fonts.ready){ document.fonts.ready.then(again); try{ document.fonts.load('800 44px Inter').then(again); }catch(e){} }
run();
})();

/* ===== assets/js/header.js: состояния шапки, импульс, текущий раздел ===== */
(function(){
  'use strict';
  var hdr = document.querySelector('.wp-site-blocks > header'), sentinel = document.getElementById('hdr-sentinel');
  /* метка для отслеживания скролла — вне «липкой» шапки, в начале body */
  if (!sentinel) { sentinel = document.createElement('div'); sentinel.id = 'hdr-sentinel'; sentinel.className = 'hdr-sentinel'; sentinel.setAttribute('aria-hidden','true'); document.body.insertBefore(sentinel, document.body.firstChild); }
  if (!hdr || !sentinel) return;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches, compact = false;

  function pulse(){
    var svg = document.getElementById('logo-svg'); if (reduce || !svg) return;
    svg.classList.remove('is-pulsing'); void svg.getBoundingClientRect(); svg.classList.add('is-pulsing');
  }
  document.addEventListener('animationend', function(e){
    if (e.animationName === 'lgPulse') document.getElementById('logo-svg').classList.remove('is-pulsing');
  });
  window.addEventListener('pv-logo-ready', pulse);

  function setCompact(on){
    if (on === compact) return;
    compact = on; hdr.classList.toggle('is-compact', on);
    if (!on) setTimeout(pulse, 340);               /* дорожки вернулись — пробегает импульс */
  }
  if ('IntersectionObserver' in window) new IntersectionObserver(function(en){ setCompact(!en[0].isIntersecting); }).observe(sentinel);
  else window.addEventListener('scroll', function(){ setCompact(window.scrollY > 48); }, {passive:true});

  /* текущий раздел: последний якорь, чей верх выше 30% окна */
  var links = [].slice.call(hdr.querySelectorAll('.site-nav__link[href^="#"]'));
  var targets = links.map(function(a){ return document.getElementById(a.getAttribute('href').slice(1)); });
  var tick = false;
  function spy(){
    tick = false;
    var line = window.innerHeight * .3, cur = -1;
    targets.forEach(function(t, i){ if (t && t.getBoundingClientRect().top <= line) cur = i; });
    links.forEach(function(a, i){ a.classList.toggle('is-active', i === cur); });
  }
  window.addEventListener('scroll', function(){ if (!tick){ tick = true; requestAnimationFrame(spy); } }, {passive:true});
  spy();

  /* клик по телефону или почте — копирование в буфер обмена */
  var tip = document.createElement('div'); tip.className = 'copy-tip'; tip.setAttribute('role','status'); document.body.appendChild(tip);
  var tipT;
  function fallbackCopy(text){
    var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly','');
    ta.style.cssText = 'position:fixed;top:-100px;opacity:0'; document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch(e){}
    ta.remove(); return ok;
  }
  function show(a, msg){
    var r = a.getBoundingClientRect();
    tip.textContent = msg; tip.style.left = (r.left + r.width / 2) + 'px'; tip.style.top = (hdr.getBoundingClientRect().bottom + 8) + 'px';   /* под шапкой, не перекрывая контакты */
    tip.classList.add('is-on'); clearTimeout(tipT); tipT = setTimeout(function(){ tip.classList.remove('is-on'); }, 1400);
  }
  [].forEach.call(hdr.querySelectorAll('[data-copy]'), function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var text = a.getAttribute('data-copy'), kind = a.href.indexOf('tel:') === 0 ? 'Номер' : 'Адрес';
      var done = function(){ show(a, kind + ' скопирован'); }, fail = function(){ show(a, fallbackCopy(text) ? kind + ' скопирован' : 'Не удалось скопировать'); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fail); else fail();
    });
  });
})();


/* ===== бургер-меню (2026-10-03): уровни шапки по ширине окна, см. header.css ===== */
(function(){
  'use strict';
  var hdr = document.querySelector('.wp-site-blocks > header');
  if (!hdr) return;
  var inner = hdr.querySelector('.site-header__inner'), menu = hdr.querySelector('#site-menu'),
      btn = hdr.querySelector('.site-burger'), bar = hdr.querySelector('.site-header__bar'),
      nav = hdr.querySelector('.site-nav'), list = hdr.querySelector('.site-nav__list'),
      contacts = hdr.querySelector('.site-header__contacts');
  if (!inner || !menu || !btn || !bar || !list) return;
  var accent = [].slice.call(list.querySelectorAll('.site-nav__item--accent'));
  var actions = document.createElement('ul'); actions.className = 'site-header__actions';
  var ctHome = contacts && contacts.parentNode, ctNext = contacts && contacts.nextSibling;
  var level = -1;

  function setOpen(on){
    hdr.classList.toggle('is-menu-open', on);
    btn.setAttribute('aria-expanded', String(on));
    btn.setAttribute('aria-label', on ? 'Закрыть меню' : 'Открыть меню');
  }
  /* раскладка уровня: те же узлы — в строку или обратно на место (в панель) */
  function arrange(l){
    accent.forEach(function(li){ list.appendChild(li); });          /* кнопки — последние в списке */
    if (contacts) ctHome.insertBefore(contacts, ctNext);
    if (actions.parentNode) actions.parentNode.removeChild(actions);
    hdr.classList.toggle('is-collapsed', l > 0);
    if (l === 1){ accent.forEach(function(li){ actions.appendChild(li); }); bar.appendChild(actions); }
    if (contacts && (l === 1 || l === 2)) bar.appendChild(contacts);
  }
  function overflows(){
    return inner.scrollWidth > inner.clientWidth + 1 || (nav && nav.scrollWidth > nav.clientWidth + 1);
  }
  function fits(){
    hdr.classList.remove('is-compact'); var a = !overflows();
    hdr.classList.add('is-compact');    var b = !overflows();
    return a && b;
  }
  /* Уровень зависит только от ширины окна: каждая раскладка примеряется в полной
     и компактной шапке (компактная шире — кнопки-пилюли и контакты в строку).
     Примерка синхронная, без переходов, до отрисовки кадра — на экране не видна. */
  function check(){
    var wasCompact = hdr.classList.contains('is-compact');
    hdr.classList.add('is-measuring');
    var l = 0;
    for (; l < 3; l++){ arrange(l); if (fits()) break; }
    if (l === 3) arrange(3);
    hdr.classList.toggle('is-compact', wasCompact);
    getComputedStyle(hdr).getPropertyValue('--p'); void hdr.offsetWidth;   /* фиксируем стиль до возврата переходов */
    hdr.classList.remove('is-measuring');
    hdr.setAttribute('data-hdr', l);
    if (l === 0) setOpen(false);
    level = l;
  }
  var queued = false;
  function later(){ if (!queued){ queued = true; requestAnimationFrame(function(){ queued = false; check(); }); } }

  window.addEventListener('resize', later);
  window.addEventListener('pv-logo-ready', later);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(later);
  /* ширина логотипа и подписей меняется после загрузки шрифта и сборки логотипа */
  if ('ResizeObserver' in window){
    var ro = new ResizeObserver(later);
    [hdr.querySelector('.logo-crop'), list].forEach(function(el){ if (el) ro.observe(el); });
  }

  btn.addEventListener('click', function(){ setOpen(!hdr.classList.contains('is-menu-open')); });
  menu.addEventListener('click', function(e){ if (e.target.closest('.site-nav a')) setOpen(false); });
  bar.addEventListener('click', function(e){ if (e.target.closest('.site-nav a')) setOpen(false); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && hdr.classList.contains('is-menu-open')){ setOpen(false); btn.focus(); }
  });
  document.addEventListener('click', function(e){
    if (hdr.classList.contains('is-menu-open') && !hdr.contains(e.target)) setOpen(false);
  });
  check();
})();
