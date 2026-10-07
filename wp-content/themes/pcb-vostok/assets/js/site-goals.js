/**
 * Цели Метрики и собственные обезличенные события сайта — для /panel/analytics.
 *
 * Цели (ym reachGoal) уходят только если посетитель принял cookie: до этого они копятся
 * и отправляются после согласия в той же странице. Идентификаторы — как в каталоге плагина
 * pcb-panel (includes/analytics/yandex.php, pcb_goals_catalog) — менять только вместе.
 *
 * Свои события — без cookie и идентификаторов, суммарные счётчики (navigator.sendBeacon):
 *   consent  accept | necessary            выбор в уведомлении/настройках cookie
 *   calc     all, layers:…, qty:…, …       параметры расчёта — один раз за просмотр страницы
 *   calc_err zip | rar | format | ipc | xml  файлы, которые калькулятор не разобрал
 * Вошедшие в WordPress (админ, менеджер) не учитываются: PCBConsent.track === false.
 *
 * Другие скрипты темы сообщают о шагах событием:
 *   document.dispatchEvent(new CustomEvent('pcb:track', { detail: { goal: 'calc_upload' } }));
 *   document.dispatchEvent(new CustomEvent('pcb:track', { detail: { ev: [['calc_err', 'zip']] } }));
 */
(function () {
  'use strict';

  var C = window.PCBConsent || {};
  var EP = (window.PCBEvents || {}).endpoint;
  var TRACK = C.track !== false;

  /* ---------- отправка ---------- */

  var reached = {}, pending = [];

  function ymReady() { return typeof window.ym === 'function' && C.metrika && C.get && C.get() === 'all'; }

  function goal(id) {
    if (!TRACK || reached[id]) return;
    reached[id] = true;
    if (ymReady()) window.ym(C.metrika, 'reachGoal', id);
    else pending.push(id);
  }

  function events(list) {
    if (!TRACK || !EP || !list || !list.length) return;
    var body = JSON.stringify({ e: list.slice(0, 12) });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(EP, new Blob([body], { type: 'text/plain' }))) return;
    } catch (e) { /* ниже — обычный запрос */ }
    try { fetch(EP, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }); } catch (e) { /* без статистики */ }
  }

  document.addEventListener('pcb:track', function (e) {
    var d = e.detail || {};
    if (d.goal) goal(d.goal);
    if (d.ev) events(d.ev);
  });

  document.addEventListener('pcb:consent', function (e) {
    var v = e.detail && e.detail.value;
    events([['consent', v === 'all' ? 'accept' : 'necessary']]);
    if (v === 'all') {
      /* Метрика грузится в cookie-consent.js; накопленные цели отдаём после инициализации */
      setTimeout(function () {
        if (!ymReady()) return;
        pending.splice(0).forEach(function (id) { window.ym(C.metrika, 'reachGoal', id); });
      }, 0);
    } else {
      pending.length = 0;
    }
  });

  /* ---------- главная: секции, FAQ, действия, начало формы ---------- */

  var SECTIONS = ['main', 'services', 'capabilities', 'order', 'audit', 'manufacturing', 'components', 'installation', 'qc', 'inspection', 'logistics', 'overview', 'about', 'faq', 'request'];
  var isHome = document.body.classList.contains('home');

  if (isHome && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        goal('sec_' + en.target.id);
        io.unobserve(en.target);
      });
    }, { rootMargin: '0px 0px -30% 0px' }); // секция зашла в верхние 70% экрана — работает и для очень высоких секций
    SECTIONS.forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
  }

  if (isHome) {
    var faq = document.getElementById('faq');
    if (faq) {
      [].filter.call(faq.querySelectorAll('details'), function (d) { return !d.classList.contains('details-link'); })
        .slice(0, 6).forEach(function (d, i) {
          d.addEventListener('toggle', function () { if (d.open) goal('faq_' + (i + 1)); });
        });
    }
    [].forEach.call(document.querySelectorAll('details.details-link'), function (d) {
      d.addEventListener('toggle', function () { if (d.open) goal('act_more'); });
    });
    document.addEventListener('focusin', function (e) {
      if (e.target.closest && e.target.closest('.request-form')) goal('form_start');
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('a, button');
    if (!t) return;
    var href = t.getAttribute('href') || '';
    if (/^tel:/i.test(href)) goal('act_phone');
    else if (/^mailto:/i.test(href)) goal('act_mail');
    else if (isHome && /\/calculator\/?(#|\?|$)/.test(href)) goal('act_calc');
    else if (t.matches('.pcar__arrow, .pcar__dots button, .pcar__dots *')) goal('act_carousel');
    else if (t.hasAttribute('data-svc-select')) goal('act_filter');
  });

  /* ---------- калькулятор: открыли и параметры расчёта ---------- */

  var isCalc = !!document.getElementById('calc-total');
  if (isCalc) goal('calc_open');

  function bucket(kind, v) {
    v = parseFloat(v);
    if (!(v > 0)) return '';
    if (kind === 'layers') return v >= 14 ? '14+' : v >= 8 ? '8-12' : String(Math.round(v));
    if (kind === 'qty') return v <= 10 ? '1-10' : v <= 50 ? '11-50' : v <= 200 ? '51-200' : v <= 1000 ? '201-1000' : '1000+';
    if (kind === 'size') return v <= 50 ? '0-50' : v <= 100 ? '50-100' : v <= 200 ? '100-200' : '200+';
    return '';
  }

  /* итог расчёта отправляем один раз — когда посетитель уходит со страницы или сворачивает её */
  var calcSent = false;
  function flushCalc() {
    if (!isCalc || calcSent || !window.PCBCalc || !window.PCBCalc.getData) return;
    var d;
    try { d = window.PCBCalc.getData(); } catch (e) { return; }
    if (!d || !d.estimate) return; // расчёта не было
    calcSent = true;
    var list = [['calc', 'all']];
    var l = bucket('layers', d.layers), q = bucket('qty', d.qty), s = bucket('size', Math.max(parseFloat(d.len) || 0, parseFloat(d.wid) || 0));
    if (l) list.push(['calc', 'layers:' + l]);
    if (q) list.push(['calc', 'qty:' + q]);
    if (s) list.push(['calc', 'size:' + s]);
    if (d.finish && !d.finish_pending) list.push(['calc', 'finish:' + d.finish]);
    if (d.mat) list.push(['calc', 'mat:' + d.mat]);
    events(list);
  }
  if (isCalc) {
    addEventListener('pagehide', flushCalc);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushCalc(); });
  }
})();
