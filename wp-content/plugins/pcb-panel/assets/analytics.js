/**
 * Панель /panel/analytics — загрузка данных и отрисовка.
 * Данные: GET window.PCBAnalytics.api?days=7|30|90 (includes/analytics/data.php).
 * Для превью без сервера можно подложить window.PCBAnalyticsDemo(days) — функцию, возвращающую тот же объект.
 */
(function () {
  'use strict';

  var CFG = window.PCBAnalytics || {};
  var root = document.getElementById('pa');
  if (!root) return;

  /* ================= утилиты ================= */

  var NF = new Intl.NumberFormat('ru-RU');
  function fmt(v) { return v == null ? '—' : NF.format(v); }
  function pct(v, d) { return v == null ? '—' : (v * 100).toFixed(d == null ? 0 : d).replace('.', ',') + '%'; }
  function $(s) { return root.querySelector(s); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function nice(max) { if (max <= 0) return 1; var e = Math.pow(10, Math.floor(Math.log10(max))), m = max / e; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * e; }
  function periodWord(n) { return n === 7 ? '7 дней' : n === 30 ? '30 дней' : '90 дней'; }
  function parseDay(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function dshort(d) { return d.getDate() + '.' + String(d.getMonth() + 1).padStart(2, '0'); }
  var WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  function empty(sel, text) { var el = $(sel); if (el) el.innerHTML = '<p class="pa-empty">' + text + '</p>'; }
  function hasData(rows) { return rows && rows.some(function (r) { return (r.s1 || 0) + (r.s2 || 0) > 0; }); }

  var NO_YM = 'Нет данных Метрики — см. сообщение вверху страницы.';
  var NO_GOALS = 'Нет данных по целям Метрики — см. сообщение вверху страницы.';

  /* подсказка */
  var tip = document.createElement('div'); tip.className = 'pa-tip'; tip.hidden = true; document.body.appendChild(tip);
  function showTip(html, x, y) {
    tip.innerHTML = html; tip.hidden = false;
    var w = tip.offsetWidth, h = tip.offsetHeight, left = x + 14, top = y + 14;
    if (left + w > innerWidth - 8) left = x - w - 14;
    if (top + h > innerHeight - 8) top = y - h - 14;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hideTip() { tip.hidden = true; }
  document.addEventListener('mousemove', function (e) {
    var t = e.target.closest && e.target.closest('[data-tip]');
    if (t) showTip(t.getAttribute('data-tip'), e.clientX, e.clientY);
    else if (!(e.target.closest && e.target.closest('.pa-chart'))) hideTip();
  });

  /* ================= блоки ================= */

  function delta(cur, prev, n) {
    if (cur == null || !prev) return '';
    var r = (cur - prev) / prev, up = r >= 0;
    return '<p class="pa-tile__delta ' + (up ? 'is-up' : 'is-down') + '">' + (up ? '▲ ' : '▼ ') + pct(Math.abs(r)) + ' <span>к прошлым ' + periodWord(n) + '</span></p>';
  }
  function src(kind) {
    var s = {
      full: ['full', 'Заявки · все', 'Заявки из базы сайта — учтены все обращения'],
      own: ['full', 'Свои события · без cookie', 'Собственные обезличенные события сайта, без cookie и идентификаторов. Не учитываются только посетители с блокировщиками'],
      ym: ['part', 'Метрика · с согласием', 'Яндекс Метрика: только посетители, которые приняли cookie и у которых браузер не блокирует счётчик'],
      est: ['part', 'Оценка', 'Оценка: визиты Метрики, пересчитанные на долю согласий']
    }[kind];
    return '<span class="pa-src pa-src--' + s[0] + '" data-tip="' + esc(s[2]) + '">' + s[1] + '</span>';
  }

  function tiles(D) {
    var s = D.sum, n = D.n, ym = s.visits != null;
    var t = [
      ['Заявки', fmt(s.req), delta(s.req, s.preq, n), 'главная — ' + s.reqHome + ', калькулятор — ' + s.reqCalc, src('full')],
      ['Визиты в Метрике', fmt(s.visits), delta(s.visits, s.pvisits, n), ym ? (s.share ? '≈ ' + fmt(s.est) + ' с учётом отказов от cookie' : 'доля согласий пока неизвестна') : 'Метрика не подключена', src('ym')],
      ['Конверсия в заявку', s.conv == null ? '—' : pct(s.conv, 1), delta(s.conv, s.pconv, n), ym ? 'заявки к оценке всех визитов' : 'нужны данные Метрики', src('est')],
      ['Расчёты в калькуляторе', fmt(s.calcs), delta(s.calcs, s.pcalcs, n), 'дошли до заявки — ' + s.calcToReq + (s.calcs ? ' (' + pct(Math.min(1, s.calcToReq / s.calcs)) + ')' : ''), src('own')],
      ['Согласие на cookie', s.share == null ? '—' : pct(s.share), delta(s.share, s.pshare, n), '«Принять» — ' + fmt(s.accept) + ', «Только необходимые» — ' + fmt(s.necessary), src('own')]
    ];
    $('#pa-tiles').innerHTML = t.map(function (x) {
      return '<article class="pa-card pa-tile"><p class="pa-tile__label">' + x[0] + '</p><p class="pa-tile__val">' + x[1] + '</p>' + x[2] + '<p class="pa-tile__sub">' + x[3] + '</p>' + x[4] + '</article>';
    }).join('');
  }

  /* --- визиты (линия) + заявки (столбцы), общая вертикаль при наведении --- */
  function timeCharts(D) {
    var days = D.days, n = days.length, boxV = $('#pa-ch-visits'), boxR = $('#pa-ch-req');
    if (!boxV.offsetParent) return; // секция свёрнута — нарисуем при раскрытии
    var hasV = days.some(function (d) { return d.visits != null; });
    var W = Math.max(boxV.clientWidth, 280), padL = 34, padR = 8, iw = W - padL - padR, step = iw / n;
    function x(i) { return padL + iw * (i + 0.5) / n; }
    var labEvery = n <= 7 ? 1 : n <= 30 ? 5 : 14;

    /* визиты */
    var H1 = 170, top1 = 8, b1 = H1 - 22;
    var maxV = nice(Math.max(1, Math.max.apply(null, days.map(function (d) { return d.visits || 0; }))) * 1.05);
    function y1(v) { return b1 - (b1 - top1) * v / maxV; }
    var g = '', xl = '';
    for (var k = 0; k <= 4; k++) { var v = maxV * k / 4, yy = y1(v); g += '<line x1="' + padL + '" x2="' + (W - padR) + '" y1="' + yy + '" y2="' + yy + '" stroke="var(--pa-grid)"/><text x="' + (padL - 6) + '" y="' + (yy + 4) + '" text-anchor="end">' + fmt(Math.round(v)) + '</text>'; }
    days.forEach(function (d, i) { if ((n - 1 - i) % labEvery === 0) xl += '<text x="' + x(i) + '" y="' + (H1 - 4) + '" text-anchor="middle">' + dshort(parseDay(d.date)) + '</text>'; });
    var line = '';
    if (hasV) {
      var pts = days.map(function (d, i) { return x(i).toFixed(1) + ',' + y1(d.visits || 0).toFixed(1); });
      line = '<path d="M' + x(0) + ',' + b1 + ' L' + pts.join(' L') + ' L' + x(n - 1) + ',' + b1 + ' Z" fill="var(--pa-s1-wash)"/>' +
        '<polyline points="' + pts.join(' ') + '" fill="none" stroke="var(--pa-s1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
        '<circle cx="' + x(n - 1) + '" cy="' + y1(days[n - 1].visits || 0) + '" r="4" fill="var(--pa-s1)" stroke="var(--pa-surface)" stroke-width="2"/>';
    } else {
      line = '<text x="' + (padL + iw / 2) + '" y="' + (b1 / 2) + '" text-anchor="middle">Метрика не подключена — показываем только заявки</text>';
    }
    boxV.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H1 + '" height="' + H1 + '" role="img" aria-label="Визиты по дням">' + g + line + xl +
      '<line class="pa-cross" x1="0" x2="0" y1="' + top1 + '" y2="' + b1 + '" stroke="var(--color-text-grey)" stroke-width="1" visibility="hidden"/>' +
      '<circle class="pa-dot" r="4" fill="var(--pa-s1)" stroke="var(--pa-surface)" stroke-width="2" visibility="hidden"/>' +
      '<rect class="pa-hit" x="' + padL + '" y="0" width="' + iw + '" height="' + H1 + '"/></svg>';

    /* заявки */
    var H2 = 92, top2 = 6, b2 = H2 - 6;
    var maxR = Math.max(2, Math.max.apply(null, days.map(function (d) { return d.reqHome + d.reqCalc; })));
    function y2(v) { return b2 - (b2 - top2) * v / maxR; }
    var bw = Math.min(14, Math.max(2, step - 3)), bars = '';
    days.forEach(function (d, i) {
      var xx = x(i) - bw / 2, yh = y2(d.reqHome), yc = y2(d.reqHome + d.reqCalc);
      if (d.reqHome) bars += '<rect x="' + xx + '" y="' + yh + '" width="' + bw + '" height="' + (b2 - yh) + '" fill="var(--pa-s1)"' + (d.reqCalc ? '' : ' rx="2"') + '/>';
      if (d.reqCalc) bars += '<rect x="' + xx + '" y="' + yc + '" width="' + bw + '" height="' + Math.max(0, yh - yc - (d.reqHome ? 2 : 0)) + '" fill="var(--pa-s2)" rx="2"/>';
    });
    boxR.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H2 + '" height="' + H2 + '" role="img" aria-label="Заявки по дням">' +
      '<line x1="' + padL + '" x2="' + (W - padR) + '" y1="' + b2 + '" y2="' + b2 + '" stroke="var(--pa-grid)"/>' +
      '<line x1="' + padL + '" x2="' + (W - padR) + '" y1="' + top2 + '" y2="' + top2 + '" stroke="var(--pa-grid)"/>' +
      '<text x="' + (padL - 6) + '" y="' + (top2 + 4) + '" text-anchor="end">' + maxR + '</text><text x="' + (padL - 6) + '" y="' + (b2 + 4) + '" text-anchor="end">0</text>' +
      bars + '<line class="pa-cross" x1="0" x2="0" y1="' + top2 + '" y2="' + b2 + '" stroke="var(--color-text-grey)" stroke-width="1" visibility="hidden"/>' +
      '<rect class="pa-hit" x="' + padL + '" y="0" width="' + iw + '" height="' + H2 + '"/></svg>';

    function move(e) {
      var svg = e.currentTarget.ownerSVGElement, r = svg.getBoundingClientRect(), px = (e.clientX - r.left) * W / r.width;
      var i = Math.max(0, Math.min(n - 1, Math.floor((px - padL) / step))), d = days[i], dt = parseDay(d.date);
      [boxV, boxR].forEach(function (b) { var c = b.querySelector('.pa-cross'); c.setAttribute('x1', x(i)); c.setAttribute('x2', x(i)); c.setAttribute('visibility', 'visible'); });
      if (hasV) { var dot = boxV.querySelector('.pa-dot'); dot.setAttribute('cx', x(i)); dot.setAttribute('cy', y1(d.visits || 0)); dot.setAttribute('visibility', 'visible'); }
      showTip('<b>' + WD[dt.getDay()] + ', ' + dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) + '</b>' + (hasV ? '<br>Визиты в Метрике: ' + fmt(d.visits) : '') +
        '<br><i style="background:var(--pa-s1)"></i>Заявки с главной: ' + d.reqHome + '<br><i style="background:var(--pa-s2)"></i>Заявки с калькулятора: ' + d.reqCalc, e.clientX, e.clientY);
    }
    function leave() { [boxV, boxR].forEach(function (b) { b.querySelector('.pa-cross').setAttribute('visibility', 'hidden'); }); boxV.querySelector('.pa-dot').setAttribute('visibility', 'hidden'); hideTip(); }
    [boxV, boxR].forEach(function (b) { var h = b.querySelector('.pa-hit'); h.addEventListener('mousemove', move); h.addEventListener('mouseleave', leave); });
  }

  /* --- горизонтальные полосы (одна или две серии) --- */
  function bars(sel, rows, opt, none) {
    opt = opt || {};
    if (!hasData(rows)) { empty(sel, none || 'За период данных нет.'); return; }
    var max = Math.max.apply(null, rows.map(function (r) { return (r.s1 || 0) + (r.s2 || 0); })) || 1;
    var total = rows.reduce(function (s, r) { return s + (r.s1 || 0) + (r.s2 || 0); }, 0) || 1;
    $(sel).innerHTML = rows.map(function (r) {
      var v = (r.s1 || 0) + (r.s2 || 0);
      var t = '<b>' + esc(r.label) + '</b>' + (opt.two ? '<br><i style="background:var(--pa-s1)"></i>В заявках: ' + (r.s1 || 0) + '<br><i style="background:var(--pa-s2)"></i>Только расчёт: ' + (r.s2 || 0) : '') + '<br>Всего: ' + fmt(v) + ' (' + pct(v / total) + ')';
      return '<div class="pa-row' + (opt.stack ? ' pa-row--stack' : '') + '" data-tip="' + esc(t) + '"><span class="pa-row__label">' + esc(r.label) + (r.hint ? '<small>' + esc(r.hint) + '</small>' : '') + '</span>' +
        '<span class="pa-row__track">' + (r.s1 ? '<span class="pa-row__seg s1" style="width:' + (100 * r.s1 / max) + '%"></span>' : '') + (r.s2 ? '<span class="pa-row__seg s2" style="width:' + (100 * r.s2 / max) + '%"></span>' : '') + '</span>' +
        '<span class="pa-row__val">' + fmt(v) + (opt.share ? '<small>' + pct(v / total) + '</small>' : '') + '</span></div>';
    }).join('');
  }

  function ladder(D) {
    var L = D.ladder;
    if (!L || !L.length || !L[0].n) { empty('#pa-ladder', L ? 'За период данных нет.' : NO_GOALS); return; }
    var worst = 0, wi = -1;
    for (var i = 1; i < L.length; i++) { var dr = L[i - 1].pct - L[i].pct; if (dr > worst) { worst = dr; wi = i; } }
    $('#pa-ladder').innerHTML = L.map(function (r, i) {
      var t = '<b>' + esc(r.label) + '</b> ' + esc(r.hint) + '<br>Дошли: ' + fmt(r.n) + ' (' + r.pct + '%)' + (i ? '<br>Ушли после предыдущей секции: ' + (L[i - 1].pct - r.pct) + ' п. п.' : '');
      return '<div class="pa-row" data-tip="' + esc(t) + '"><span class="pa-row__label">' + esc(r.label) + (i === wi ? '<span class="pa-row__flag">−' + worst + ' п. п. — наибольший уход</span>' : '') + '</span><span class="pa-row__track"><span class="pa-row__seg s1" style="width:' + Math.min(100, r.pct) + '%"></span></span><span class="pa-row__val">' + r.pct + '%</span></div>';
    }).join('');
  }

  function actions(D) {
    if (!D.actions) { empty('#pa-actions', NO_GOALS); return; }
    $('#pa-actions').innerHTML = '<div class="pp-table-wrap"><table class="pp-table pa-table"><tbody>' + D.actions.map(function (a) { return '<tr><td>' + esc(a[0]) + '</td><td class="pa-num">' + fmt(a[1]) + '</td></tr>'; }).join('') + '</tbody></table></div>';
  }

  function funnel(sel, steps) {
    if (!steps) { empty(sel, NO_GOALS); return; }
    var first = steps[0][1];
    if (!first) { empty(sel, 'За период данных нет.'); return; }
    $(sel).innerHTML = steps.map(function (s, i) {
      var html = '';
      if (i) { var pr = steps[i - 1][1]; if (pr) html += '<div class="pa-drop">теряем <b>' + pct(Math.max(0, 1 - s[1] / pr)) + '</b> — ' + fmt(Math.max(0, pr - s[1])) + '</div>'; }
      return html + '<div class="pa-step" data-tip="' + esc('<b>' + s[0] + '</b><br>' + fmt(s[1]) + ' — ' + pct(s[1] / first, 1) + ' от первого шага') + '"><span class="pa-step__name">' + esc(s[0]) + '</span><span class="pa-step__num">' + fmt(s[1]) + '<small>' + pct(s[1] / first) + '</small></span><span class="pa-step__track"><span class="pa-step__fill" style="display:block;width:' + Math.max(0.5, Math.min(100, 100 * s[1] / first)) + '%"></span></span></div>';
    }).join('');
  }

  var RAMP = ['#F1F5F3', '#D3E6DC', '#A9D0BB', '#76B394', '#43926D', '#1F7A55', '#145A3E'];
  function heat(D) {
    var g = D.heat;
    if (!g) { $('#pa-heat').innerHTML = ''; $('#pa-heat-scale').innerHTML = '<p class="pa-empty">' + NO_YM + '</p>'; return; }
    var max = Math.max.apply(null, g.map(function (r) { return Math.max.apply(null, r); })) || 1;
    var names = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'], h = '<span></span>';
    for (var k = 0; k < 24; k++) h += '<span class="pa-heat__h">' + k + '</span>';
    g.forEach(function (row, j) {
      h += '<span class="pa-heat__d">' + names[j] + '</span>';
      row.forEach(function (v, hr) {
        var c = v === 0 ? RAMP[0] : RAMP[Math.min(6, 1 + Math.floor(5.99 * v / max))];
        h += '<span class="pa-heat__c" style="background:' + c + '" data-tip="' + esc('<b>' + names[j] + ', ' + hr + ':00–' + (hr + 1) + ':00</b><br>Визиты: ' + v) + '"></span>';
      });
    });
    $('#pa-heat').innerHTML = h;
    $('#pa-heat-scale').innerHTML = 'меньше <span>' + RAMP.map(function (c) { return '<i style="background:' + c + '"></i>'; }).join('') + '</span> больше';
  }

  function split2(a, b, la, lb) {
    var t = a + b;
    if (!t) return '';
    return '<div class="pa-split"><div style="width:' + (100 * a / t) + '%;background:var(--pa-s1)" data-tip="' + esc('<b>' + la + '</b><br>' + fmt(a) + ' (' + pct(a / t) + ')') + '"></div><div style="width:' + (100 * b / t) + '%;background:var(--pa-s2)" data-tip="' + esc('<b>' + lb + '</b><br>' + fmt(b) + ' (' + pct(b / t) + ')') + '"></div></div>' +
      '<div class="pa-legend"><span><i style="background:var(--pa-s1)"></i>' + la + ' — ' + fmt(a) + ' (' + pct(a / t) + ')</span><span><i style="background:var(--pa-s2)"></i>' + lb + ' — ' + fmt(b) + '</span></div>';
  }

  function devices(D) {
    if (!D.devices) { empty('#pa-dev', NO_YM); return; }
    var d = D.devices, s = split2(d.desk, d.mob, 'Компьютер', 'Телефон и планшет');
    $('#pa-dev').innerHTML = (s || '<p class="pa-empty">За период данных нет.</p>') + (D.newShare != null ? '<p class="pa-tile__sub">Новые посетители — ' + pct(D.newShare) + ', вернувшиеся — ' + pct(1 - D.newShare) + '</p>' : '');
  }

  function consent(D) {
    var s = D.sum, box = $('#pa-consent-box');
    var html = split2(s.accept, s.necessary, '«Принять»', '«Только необходимые»');
    if (!html) { empty('#pa-consent-box', 'За период выбор ещё никто не сделал.'); return; }
    box.innerHTML = html + '<p class="pa-tile__sub">Коэффициент пересчёта Метрики: <b>×' + (1 / s.share).toFixed(2).replace('.', ',') + '</b>. На него умножаются визиты в сводке, чтобы оценить весь трафик. Посетители с блокировщиками не попадают ни в Метрику, ни в эту долю, поэтому оценка остаётся чуть заниженной.</p>';
  }

  function tech(D) {
    $('#pa-tech-rows').innerHTML = D.tech.map(function (r) {
      return '<tr><td>' + esc(r.name) + '<small>' + esc(r.sub) + '</small></td><td class="pa-num">' + esc(r.val) + '</td><td><span class="pa-status pa-status--' + r.st + '">' + (r.st === 'ok' ? '✓' : r.st === 'warn' ? '!' : '✕') + ' ' + esc(r.stl) + '</span></td></tr>';
    }).join('');
  }

  function seo(D) {
    var s = D.seo;
    if (!s) { empty('#pa-seo', D.status.wmMsg || 'Вебмастер не подключён.'); return; }
    $('#pa-seo').innerHTML = '<div><b>' + fmt(s.shows) + '</b><span>показы в поиске</span></div><div><b>' + fmt(s.clicks) + '</b><span>переходы из поиска</span></div><div><b>' + (s.pos == null ? '—' : String(s.pos).replace('.', ',')) + '</b><span>средняя позиция</span></div><div><b>' + fmt(s.idx) + '</b><span>страниц в поиске</span></div>' + (CFG.seoUrl ? '<a href="' + esc(CFG.seoUrl) + '">Подробнее в разделе SEO →</a>' : '') +
      (s.note ? '<p class="pa-empty" style="flex-basis:100%">' + esc(s.note) + '</p>' : '');
  }

  /* --- сообщения о подключении --- */
  function statusBox(D) {
    var st = D.status, out = [];
    if (st.metrika === 'off') out.push(['warn', '<b>Метрика не подключена.</b> ' + esc(st.metrikaMsg) + ' Константы задаются в wp-config.php. Без Метрики не будет визитов, трафика, лестницы секций и воронок; заявки, спрос и согласия работают.']);
    if (st.metrika === 'error') out.push(['bad', '<b>Метрика не ответила.</b> ' + esc(st.metrikaMsg)]);
    if (st.metrika === 'nogoals' && D.goals) {
      var list = Object.keys(D.goals).map(function (k) { return '<li><code>' + esc(k) + '</code> — ' + esc(D.goals[k]) + '</li>'; }).join('');
      out.push(['warn', '<p><b>В счётчике не хватает целей: ' + st.goalsMissing + '.</b> Без них не заполнятся секции главной, FAQ, действия и воронки.' + (st.canSetup ? ' Панель может создать их сама — нужен токен с правом metrika:write.' : ' Создать их может администратор.') + '</p>' +
        (st.canSetup ? '<button type="button" class="pp-btn pp-btn--sm" id="pa-goals-btn">Создать цели</button>' : '') +
        '<details><summary>Список для ручного создания: тип «JavaScript-событие», условие «совпадает»</summary><ol>' + list + '</ol></details>']);
    }
    if (st.webmaster === 'error') out.push(['bad', '<b>Вебмастер не ответил.</b> ' + esc(st.wmMsg)]);
    $('#pa-status').innerHTML = out.map(function (o) { return '<div class="pa-alert pa-alert--' + o[0] + '">' + (o[1].indexOf('<p>') === 0 ? o[1] : '<p>' + o[1] + '</p>') + '</div>'; }).join('');
    var gb = $('#pa-goals-btn');
    if (gb) gb.addEventListener('click', function () {
      gb.disabled = true; gb.textContent = 'Создаём…';
      post('/goals').then(function (r) { if (r.message) alertMsg(r.message); load(); }).catch(function () { alertMsg('Не удалось создать цели. Попробуйте ещё раз.'); gb.disabled = false; gb.textContent = 'Создать цели'; });
    });
  }
  function alertMsg(text) { var box = $('#pa-status'); box.insertAdjacentHTML('afterbegin', '<div class="pa-alert pa-alert--bad"><p>' + esc(text) + '</p></div>'); }

  /* ================= загрузка и сборка ================= */

  var curN = 30, data = null;

  function render(D) {
    data = D;
    tiles(D);
    timeCharts(D);
    var two = { two: true, share: true }, noCalc = 'Пока нет расчётов за период.';
    bars('#pa-d-layers', D.demand.layers, two, noCalc); bars('#pa-d-qty', D.demand.qty, two, noCalc); bars('#pa-d-finish', D.demand.finish, two, noCalc);
    bars('#pa-d-mat', D.demand.mat, two, noCalc); bars('#pa-d-size', D.demand.size, two, noCalc); bars('#pa-d-services', D.demand.services, { share: true }, 'Заявок за период нет.');
    ladder(D);
    if (D.faq) bars('#pa-faq', D.faq, { stack: true }); else empty('#pa-faq', NO_GOALS);
    actions(D);
    funnel('#pa-f-home', D.funnels && D.funnels.home); funnel('#pa-f-calc', D.funnels && D.funnels.calc);
    if (D.sources) bars('#pa-sources', D.sources, { share: true }); else empty('#pa-sources', NO_YM);
    if (D.cities) bars('#pa-cities', D.cities, { share: true }); else empty('#pa-cities', NO_YM);
    devices(D); heat(D); consent(D); tech(D); seo(D); statusBox(D);
    $('#pa-fresh').textContent = D.fresh ? 'Метрика обновлена ' + D.fresh + ' · заявки — в реальном времени' : 'Заявки и свои события — в реальном времени';
    root.querySelectorAll('.pa-period-word').forEach(function (s) { s.textContent = periodWord(D.n); });
    document.querySelectorAll('.pa-period-word').forEach(function (s) { s.textContent = periodWord(D.n); });
  }

  function api(path, opt) {
    opt = opt || {};
    opt.credentials = 'same-origin';
    opt.headers = { 'X-WP-Nonce': CFG.nonce || '' };
    return fetch(CFG.api + path, opt).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); });
  }
  function post(path) { return api(path, { method: 'POST' }); }

  function load() {
    root.classList.add('is-loading'); root.setAttribute('aria-busy', 'true');
    root.querySelectorAll('[data-period]').forEach(function (b) { b.setAttribute('aria-pressed', +b.dataset.period === curN ? 'true' : 'false'); });
    var p = typeof window.PCBAnalyticsDemo === 'function' ? Promise.resolve(window.PCBAnalyticsDemo(curN)) : api('?days=' + curN);
    return p.then(function (D) { render(D); }).catch(function (e) {
      $('#pa-fresh').textContent = 'Не удалось загрузить данные';
      alertMsg((e && e.message) || 'Не удалось загрузить данные аналитики. Обновите страницу.');
    }).then(function () { root.classList.remove('is-loading'); root.setAttribute('aria-busy', 'false'); });
  }

  root.querySelectorAll('[data-period]').forEach(function (b) {
    b.addEventListener('click', function () { if (+b.dataset.period !== curN) { curN = +b.dataset.period; load(); } });
  });

  var refresh = document.getElementById('pa-refresh');
  if (refresh) refresh.addEventListener('click', function () {
    refresh.disabled = true;
    (typeof window.PCBAnalyticsDemo === 'function' ? Promise.resolve() : post('/refresh')).then(load).catch(function () { alertMsg('Не удалось сбросить кеш.'); }).then(function () { refresh.disabled = false; });
  });

  /* сворачивание секций: запоминаем в браузере, при раскрытии перерисовываем график */
  var KEY = 'pcbpa:closed', closed = [];
  try { closed = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { closed = []; }
  root.querySelectorAll('details.pa-sec').forEach(function (d) {
    if (closed.indexOf(d.dataset.sec) >= 0) d.open = false;
    d.addEventListener('toggle', function () {
      var i = closed.indexOf(d.dataset.sec);
      if (d.open && i >= 0) closed.splice(i, 1);
      if (!d.open && i < 0) closed.push(d.dataset.sec);
      try { localStorage.setItem(KEY, JSON.stringify(closed)); } catch (e) { /* хранилище недоступно */ }
      if (d.open && d.dataset.sec === 'summary' && data) timeCharts(data);
    });
  });
  /* переход по ссылке навигации раскрывает секцию */
  root.querySelectorAll('.pa-nav a').forEach(function (a) {
    a.addEventListener('click', function () { var d = root.querySelector(a.getAttribute('href')); if (d && !d.open) d.open = true; });
  });

  var rt;
  addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { if (data) timeCharts(data); }, 150); });

  load();
})();
