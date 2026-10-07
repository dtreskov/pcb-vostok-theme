/**
 * Cookie: уведомление, настройки и загрузка Яндекс Метрики только после согласия.
 *
 * Разметка и конфиг — inc/cookie-consent.php:
 *   window.PCBConsent = { metrika: <номер счётчика или 0>, track: <false для вошедших в WP> }
 *   #cookie-bar    уведомление (пилюля) при первом визите
 *   #cookie-prefs  панель «Настройки cookie»
 *   [data-cookie-settings]  любая ссылка/кнопка, открывающая настройки (ссылка в подвале)
 *
 * Выбор хранится в cookie pcb_consent = all | necessary, 1 год.
 * Для целей Метрики из других скриптов:
 *   if (typeof ym === 'function') ym(PCBConsent.metrika, 'reachGoal', 'request_sent');
 * Событие document 'pcb:consent' (detail.value) — после каждого изменения выбора.
 */
(function () {
  'use strict';

  var CFG = window.PCBConsent || {};
  var NAME = 'pcb_consent';
  var MAX_AGE = 365 * 24 * 60 * 60;

  var bar = document.getElementById('cookie-bar');
  var prefs = document.getElementById('cookie-prefs');
  if (!bar || !prefs) return;

  var sw = prefs.querySelector('input[name="cookie-analytics"]');
  var metrikaLoaded = false;
  var lastFocus = null;

  /* --- хранение выбора --- */

  function read() {
    var m = document.cookie.match(/(?:^|;\s*)pcb_consent=(all|necessary)/);
    return m ? m[1] : null;
  }

  function write(value) {
    document.cookie = NAME + '=' + value + '; max-age=' + MAX_AGE + '; path=/; SameSite=Lax' +
      (location.protocol === 'https:' ? '; Secure' : '');
  }

  /* --- Метрика --- */

  function loadMetrika() {
    if (metrikaLoaded || !CFG.metrika || CFG.track === false) return;
    metrikaLoaded = true;

    window.ym = window.ym || function () { (window.ym.a = window.ym.a || []).push(arguments); };
    window.ym.l = +new Date();

    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://mc.yandex.ru/metrika/tag.js';
    document.head.appendChild(s);

    window.ym(CFG.metrika, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      webvisor: true
    });
  }

  /* удаляем cookie и localStorage Метрики (_ym*) после отзыва согласия */
  function clearMetrika() {
    var host = location.hostname;
    var domains = ['', host, '.' + host.replace(/^www\./, '')];

    document.cookie.split(';').forEach(function (c) {
      var name = c.split('=')[0].trim();
      if (!/^_ym/.test(name)) return;
      domains.forEach(function (d) {
        document.cookie = name + '=; max-age=0; path=/' + (d ? '; domain=' + d : '');
      });
    });

    try {
      Object.keys(localStorage).forEach(function (k) { if (/^_ym/.test(k)) localStorage.removeItem(k); });
    } catch (e) { /* хранилище недоступно — пропускаем */ }
  }

  /* --- показ --- */

  function show(el) { el.hidden = false; }
  function hide(el) { el.hidden = true; }

  function openPrefs() {
    lastFocus = document.activeElement;
    if (sw) sw.checked = read() === 'all';
    hide(bar);
    show(prefs);
    var first = prefs.querySelector('input, button');
    if (first) first.focus();
  }

  function closePrefs() {
    hide(prefs);
    if (!read()) show(bar);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  /* --- применение выбора --- */

  function apply(value) {
    var wasLoaded = metrikaLoaded;

    write(value);
    hide(bar);
    hide(prefs);

    document.dispatchEvent(new CustomEvent('pcb:consent', { detail: { value: value } }));

    if (value === 'all') {
      loadMetrika();
    } else {
      clearMetrika();
      /* счётчик уже работает на странице — остановить его можно только перезагрузкой */
      if (wasLoaded) location.reload();
    }
  }

  /* --- события --- */

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-cookie]');
    var link = e.target.closest('[data-cookie-settings]');

    if (link) {
      e.preventDefault();
      openPrefs();
      return;
    }
    if (!t) return;

    switch (t.getAttribute('data-cookie')) {
      case 'accept':    apply('all'); break;
      case 'necessary': apply('necessary'); break;
      case 'save':      apply(sw && sw.checked ? 'all' : 'necessary'); break;
      case 'close':     closePrefs(); break;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !prefs.hidden) closePrefs();
  });

  /* --- старт --- */

  var state = read();
  if (state === 'all') loadMetrika();
  if (!state) show(bar);

  window.PCBConsent = CFG;
  CFG.open = openPrefs;
  CFG.get = read;
})();
