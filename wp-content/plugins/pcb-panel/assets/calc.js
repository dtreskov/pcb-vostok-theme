/* Вкладка «Калькулятор» панели. Сама ничего не считает: каждое изменение черновика уходит на сервер
   (POST calc/draft), в ответ — пересчёт эталонов той же формулой, что и на сайте (includes/calc/engine.php).
   Валюта отображения — только показ: значения хранятся в своей валюте (прайс фабрики — ¥, остальное — ₽). */
(function () {
  'use strict';
  var B = window.PCBCalcPanel;
  if (!B) return;
  var $ = function (s) { return document.querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var fmt = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
  var fmt2 = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });
  var SYM = { RUB: '₽', USD: '$', CNY: '¥' }, CUR = 'RUB';
  try { var sc = localStorage.getItem('pcb-calc-cur'); if (SYM[sc]) CUR = sc; } catch (e) { /* без хранилища */ }
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  var D = B.draft, PUB = B.pub.params, PV = B.preview, DEF = B.defs, G = B.groups;
  var rev = B.rev, cur = 0, group = 'factory', saving = false, again = false, timer = 0;

  /* ---------- валюты ---------- */
  function fx() { return PV.fx || { cny: D.rate, usd: D.usd }; }
  function toRub(v, c) { return c === 'CNY' ? v * fx().cny : c === 'USD' ? v * fx().usd : v; }
  function fromRub(v, c) { return c === 'CNY' ? v / fx().cny : c === 'USD' ? v / fx().usd : v; }
  function conv(v, from, to) { return fromRub(toRub(v, from), to || CUR); }
  function money(v, c) {
    var a = Math.abs(v), f = a < 10 ? new Intl.NumberFormat('ru-RU', { maximumFractionDigits: a < 1 ? 3 : 2 }) : fmt;
    return f.format(a < 10 ? v : Math.round(v)) + ' ' + SYM[c || CUR];
  }
  function show(v, c) { return money(conv(v, c || 'RUB')); }          // сумма в своей валюте → в валюте отображения
  function siteRub(v) { return fmt.format(Math.round(v)) + ' ₽'; }    // на сайте — всегда рубли
  function edit(v) { var a = Math.abs(v); return +(a < 10 ? v.toFixed(a < 1 ? 4 : 3) : a < 1000 ? v.toFixed(2) : v.toFixed(0)); }
  function pc(x) { return fmt2.format(x) + ' %'; }

  /* ---------- черновик ---------- */
  function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
  function changed() { return Object.keys(DEF).filter(function (k) { return !same(D[k], PUB[k]); }); }
  function groupChanged(g) { return changed().some(function (k) { return DEF[k].g === g; }); }
  function bad(k) { var d = DEF[k]; return d.min !== undefined && (D[k] < d.min || D[k] > d.max); }
  function E() { return PV.etalons[cur] || PV.etalons[0]; }
  function R() { return E().res; }

  function api(path, data) {
    return fetch(B.api + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': B.nonce },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || 'Ошибка ' + r.status); e.status = r.status; throw e; }
        return j;
      });
    });
  }
  function toast(t, err) {
    var el = $('#cs-toast'); el.textContent = t; el.classList.toggle('is-error', !!err); el.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(function () { el.hidden = true; }, err ? 6000 : 2800);
  }
  function scheduleSave() { clearTimeout(timer); timer = setTimeout(save, 350); drawDraft(); }
  function save() {
    timer = 0;
    if (saving) { again = true; return; }
    saving = true; drawDraft();
    api('draft', { params: D, rev: rev }).then(function (j) {
      rev = j.rev; PV = j.preview;
      saving = false;
      if (again) { again = false; save(); return; }
      render(true);
    }).catch(function (e) {
      saving = false; drawDraft();
      toast(e.message, true);
      if (e.status === 409) setTimeout(function () { location.reload(); }, 1800);
    });
  }
  function applyState(s) {
    D = s.draft; rev = s.rev; B.pub = s.pub; PUB = s.pub.params; PV = s.preview;
    B.versions = s.versions; B.rates = s.rates; B.draftBy = s.draftBy;
    if (cur >= PV.etalons.length) cur = 0;
    render();
  }

  /* ---------- шапка: курсы, версия, черновик ---------- */
  function drawRates() {
    var f = fx(), r = B.rates || {};
    var src = f.src === 'cbr' ? 'Пересчёт по ЦБ на ' + esc(f.date || r.date || '') : f.src === 'manual' ? 'Пересчёт по закреплённым курсам' : 'Курс ЦБ ещё не загружен — ручные курсы';
    $('#cs-rates').innerHTML = src + ': <b>1 ¥ = ' + fmt2.format(f.cny) + ' ₽</b> · <b>1 $ = ' + fmt2.format(f.usd) + ' ₽</b> · 1 $ = ' + fmt2.format(f.usd / f.cny) + ' ¥' +
      '<br>В цену для клиента идёт курс с надбавками — шаг 3 «Курс и платёж»';
    $$('[data-cur]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.cur === CUR); });
  }
  function drawDraft() {
    var ch = changed(), p = B.pub;
    $('#cs-ver').innerHTML = B.siteOn
      ? 'На сайте: <b>версия ' + p.v + '</b> от ' + esc(p.time) + ' · ' + esc(p.user)
      : 'На сайте: <b>старая формула темы</b> · сравниваем с исходными значениями';
    var pill = $('#cs-pill');
    if (saving || timer) { pill.className = 'cs-pill cs-pill--busy'; pill.textContent = 'Сохраняем'; }
    else {
      pill.className = 'cs-pill ' + (ch.length ? 'cs-pill--draft' : 'cs-pill--ok');
      pill.textContent = ch.length ? 'Черновик: изменений — ' + ch.length : (B.siteOn ? 'Черновик совпадает с сайтом' : 'Без изменений');
      if (ch.length && B.draftBy) pill.title = 'Черновик сохранён: ' + B.draftBy;
    }
    var canPub = ch.length || !B.siteOn;
    if ($('#cs-reset')) $('#cs-reset').disabled = !ch.length;
    if ($('#cs-publish')) $('#cs-publish').disabled = !canPub || saving || !!timer;
    if (!canPub) $('#cs-confirm').hidden = true;
  }

  /* ---------- формула цены: она же навигация по шагам ---------- */
  var STEPS = Object.keys(G).filter(function (g) { return g !== 'rules'; });
  function drawRuleBtn() {
    var rb = $('#cs-rulebtn'); rb.setAttribute('aria-pressed', group === 'rules');
    rb.innerHTML = 'Когда сайт не считает сам и какие сроки показывает' + (groupChanged('rules') ? ' · <b>изменено</b>' : '') + ' →';
  }
  function softGroup(g) {   // red | yellow | ''
    var w = (PV.soft || []).filter(function (x) { return DEF[x.k] && DEF[x.k].g === g; });
    return w.some(function (x) { return x.lvl === 'red'; }) ? 'red' : w.length ? 'yellow' : '';
  }
  function stepBtn(g, name, val, warn, sub) {
    var i = STEPS.indexOf(g) + 1;
    return '<button class="cs-fx__t' + (warn ? ' cs-fx__t--warn' : '') + '" type="button" data-g="' + g + '" aria-pressed="' + (g === group && !warn) + '"' + (sub ? ' title="' + esc(sub) + '"' : '') + '>' +
      '<span class="cs-fx__l">' + (warn ? '' : i + ' · ') + esc(G[g].l) + '</span><span class="cs-fx__n">' + esc(name) + '</span><span class="cs-fx__v">' + val + '</span>' +
      (groupChanged(g) && !warn ? '<i class="cs-dot" title="Есть изменения в черновике"></i>' : '') + (!warn && softGroup(g) ? '<i class="cs-dot cs-dot--' + softGroup(g) + '" title="Есть предупреждения — откройте шаг"></i>' : '') + '</button>';
  }
  function T(it) {
    var v = it.m ? money(conv(it.m[0], it.m[1])) + (it.per || '') : it.x !== undefined ? '×' + fmt2.format(it.x) : it.p !== undefined ? pc(it.p) : fmt2.format(it.num) + (it.u ? ' ' + it.u : '');
    return '<span class="cs-f"><span class="cs-f__n">' + esc(it.n) + '</span><span class="cs-f__v">' + v + '</span></span>';
  }
  function O(o) { return '<span class="cs-op" aria-hidden="true">' + o + '</span>'; }
  function drawFormula() {
    var res = R();
    drawRuleBtn();
    if (!res.ok) {  // цены нет — шаги всё равно доступны для правки
      $('#cs-fx').innerHTML = STEPS.map(function (g, i) { return (i ? O('+') : '') + stepBtn(g, G[g].t, '—'); }).join('') +
        '<p class="cs-muted" style="flex-basis:100%">' + esc(res.reason || 'Нет данных') + '</p>';
      $('#cs-fxrows').innerHTML = ''; return;
    }
    var x = res.x;
    $('#cs-fx').innerHTML = x.terms.map(function (t, i) {
      var val = t.g === 'asm' && !t.m ? 'нет' : show(t.m);
      var sub = t.g === 'money' ? 'Курс в расчёте: ' + fmt2.format(x.rate) + ' ₽ за ¥ (ЦБ ' + fmt2.format(x.cbr) + ')' : t.g === 'margin' ? 'Платы +' + fmt2.format(x.mP) + ' %, монтаж +' + fmt2.format(x.mA) + ' % — по кривой при себестоимости ' + show(x.cost) : '';
      return (i ? O('+') : '') + stepBtn(t.g, t.n, val, t.warn, sub);
    }).join('') + O('=') + '<span class="cs-fx__t cs-fx__t--tot"><span class="cs-fx__l">Итог</span><span class="cs-fx__n">Стоимость</span><span class="cs-fx__v">' + show(x.gross) + '</span></span>' +
      (res.engineer ? '<p class="cs-eng" style="flex-basis:100%"><b>Сайт не покажет цену — расчёт инженером:</b> ' + res.warn.map(esc).join(', ') + '. Ниже — как сложилась бы цена.</p>' : '');
    $('#cs-fxrows').innerHTML = x.blocks.map(function (b) {
      var note = b.note ? esc(b.note) : '';
      if (b.warn) note += ' <b>По тарифу ' + show(b.warn.by) + ' — меньше минимума, берём минимум за отправку ' + show(b.warn.min) + '.</b>';
      if (b.ivat) note += ' НДС на ввоз ' + show(b.ivat) + ' принимаем к вычету — в цену не входит.';
      if (b.g === 'vat') {
        note = (b.top ? '<b>Заказ меньше минимальной суммы ' + show(D.minOrder) + ' — добавлено ' + show(b.top) + '.</b> ' : '') +
          'Итог округлён до ' + fmt.format(b.round) + ' ₽. На сайте — вилка от ' + siteRub(b.lo) + ' до ' + siteRub(b.hi) + ' (' + fmt2.format(b.loP) + ' / +' + fmt2.format(b.hiP) + ' %). Вилка: ' +
          (b.band || []).map(function (r) { return esc(r.n) + ' ' + fmt2.format(r.lo) + ' / +' + fmt2.format(r.hi) + ' %'; }).join('; ') +
          (b.loP <= D.bandMin || b.hiP >= D.bandMax ? ' — обрезано ограничением «не ниже / не выше».' : '.');
      }
      var f = b.f.length ? b.f.map(function (it) { return typeof it === 'string' ? O(it) : T(it); }).join('') : '';
      return '<div class="cs-fxrow' + (b.g === group ? ' is-on' : '') + '"><div class="cs-fxrow__h"><b>' + esc(b.n) + '</b><span>' + (b.m || b.g !== 'asm' ? show(b.m) : '—') + '</span></div><div class="cs-fx">' + f + '</div>' +
        (note ? '<div class="cs-fxrow__note">' + note + '</div>' : '') + '</div>';
    }).join('');
  }

  /* ---------- редактор шага ---------- */
  function unitOf(d) { return d.cur ? SYM[CUR] + (d.per || '') : d.t === 'pct' ? '%' : d.t === 'mul' ? '×' : (d.u || ''); }
  function shown(d, v) { return d.cur ? edit(conv(v, d.cur)) : v; }
  function wasTxt(d, v) { return d.t === 'sel' ? d.opts[v] : d.cur ? money(conv(v, d.cur)) : fmt2.format(v) + ' ' + unitOf(d); }
  function curWord(c) { return { CNY: 'юанях', RUB: 'рублях', USD: 'долларах' }[c]; }
  function curSym(s) { return String(s).replace(/[¥₽$]/g, SYM[CUR]); }

  /* подсказка «?»: что это, на что влияет, как подобрать; открытые запоминаются между перерисовками */
  var openHelp = {}, openChart = {};
  function helpBtn(k) {
    var d = DEF[k];
    return d.help ? '<button type="button" class="cs-q" data-help="' + k + '" aria-expanded="' + !!openHelp[k] + '" aria-controls="cs-help-' + k + '" title="Что это и как подобрать">?</button>' : '';
  }
  function helpBox(k) { var d = DEF[k]; return d.help ? '<div class="cs-help" id="cs-help-' + k + '"' + (openHelp[k] ? '' : ' hidden') + '>' + esc(d.help) + '</div>' : ''; }
  function softOf(k) { return (PV.soft || []).filter(function (w) { return w.k === k; }); }
  function softList(k) {
    var w = softOf(k);
    return '<div data-warn="' + k + '">' + (w.length ? '<ul class="cs-warn">' + w.map(function (x) { return '<li class="is-' + x.lvl + '">' + esc(x.t) + '</li>'; }).join('') + '</ul>' : '') + '</div>';
  }
  function refreshLive() {   // после сохранения, пока фокус в редакторе: графики и предупреждения без перерисовки полей
    $$('[data-chartb]').forEach(function (el) { var k = el.dataset.chartb; el.innerHTML = chart(DEF[k], D[k]); });
    $$('[data-warn]').forEach(function (el) { var tmp = document.createElement('div'); tmp.innerHTML = softList(el.dataset.warn); el.innerHTML = tmp.firstChild.innerHTML; });
  }
  function head(k, extra) {
    var d = DEF[k];
    return '<div class="cs-prm__head"><span class="cs-prm__n">' + esc(d.n) + (extra || '') + '</span>' + helpBtn(k) + '</div>' + (d.h ? '<div class="cs-prm__h">' + esc(d.h) + '</div>' : '') + helpBox(k);
  }

  /* таблица опций: строки × столбцы */
  function gridField(k) {
    var d = DEF[k], v = D[k], was = PUB[k], cols = Object.keys(d.cols), ch = !same(v, was);
    var cc = d.ccur || {};
    var rows = Object.keys(d.rows).map(function (r) {
      return '<tr><th scope="row">' + esc(d.rows[r]) + '</th>' + cols.map(function (c) {
        var x = v[r][c], w = was[r] ? was[r][c] : x, c1 = x !== w;
        var val = cc[c] ? edit(conv(x, cc[c])) : x;
        var tip = c1 ? 'было ' + (cc[c] ? money(conv(w, cc[c])) : fmt2.format(w)) : (cc[c] && cc[c] !== CUR ? 'в исходной валюте: ' + money(x, cc[c]) : '');
        return '<td' + (c1 ? ' class="is-changed"' : '') + '><input class="num" type="number" step="any" data-k="' + k + '" data-r="' + esc(r) + '" data-c="' + c + '" value="' + val + '" aria-label="' + esc(d.rows[r] + ', ' + d.cols[c]) + '"' + (tip ? ' title="' + esc(tip) + '"' : '') + '></td>';
      }).join('') + '</tr>';
    }).join('');
    var anyCur = cols.some(function (c) { return cc[c] && cc[c] !== CUR; });
    return '<div class="cs-prm cs-prm--full' + (ch ? ' is-changed' : '') + '" data-row="' + k + '"><div>' + head(k) +
      (anyCur ? '<div class="cs-prm__src">Суммы хранятся в ' + curWord(cc[cols[0]] || 'CNY') + ', показаны в ' + curWord(CUR) + '; исходное значение — в подсказке поля</div>' : '') +
      '<div class="cs-gwrap"><table class="cs-gtab"><thead><tr><th></th>' + cols.map(function (c) { return '<th scope="col">' + esc(cc[c] ? curSym(d.cols[c]) : d.cols[c]) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
      softList(k) + '</div></div>';
  }

  /* кривая по точкам: X — в шапке, строки — значения; под спойлером — график */
  var PAL = ['#1f6f50', '#b8642c', '#2f5fa8', '#b0902a', '#7a3f8f', '#2a8a8a', '#a33a3a', '#5c7a1f', '#c06a9a', '#44546a', '#8a6a3a', '#3a7ac0', '#6a6a6a'];
  function cX(d, x) { return d.xcur ? conv(x, d.xcur) : x; }
  function cY(d, y) { return d.cur ? conv(y, d.cur) : y; }
  function curveField(k) {
    var d = DEF[k], v = D[k], was = PUB[k], rows = Object.keys(d.rows), ch = !same(v, was), n = v.x.length;
    var xu = d.xcur ? SYM[CUR] : d.xu, yu = d.cur ? SYM[CUR] + (d.per || '') : (d.u || '');
    var hx = v.x.map(function (x, i) {
      var c1 = !was || was.x[i] !== x;
      return '<th scope="col"' + (c1 ? ' class="is-changed"' : '') + '><input class="num" type="number" step="any" min="0" data-k="' + k + '" data-cx="' + i + '" value="' + edit(cX(d, x)) + '" aria-label="' + esc(d.xn + ', точка ' + (i + 1)) + '">' +
        (n > 1 && B.canEdit ? '<button type="button" class="cs-ptdel" data-delpt="' + k + '" data-i="' + i + '" title="Убрать точку">×</button>' : '') + '</th>';
    }).join('');
    var body = rows.map(function (r, ri) {
      return '<tr><th scope="row">' + (rows.length > 1 ? '<i class="cs-sw" style="background:' + PAL[ri % PAL.length] + '"></i>' : '') + esc(d.rows[r]) + '</th>' + v.x.map(function (x, i) {
        var y = v.y[r][i], w = was && was.y[r] ? was.y[r][i] : y, c1 = y !== w;
        var tip = c1 ? 'было ' + (d.cur ? money(conv(w, d.cur)) : fmt2.format(w)) : (d.cur && d.cur !== CUR ? 'в исходной валюте: ' + money(y, d.cur) : '');
        return '<td' + (c1 ? ' class="is-changed"' : '') + '><input class="num" type="number" step="any" data-k="' + k + '" data-r="' + esc(r) + '" data-ci="' + i + '" value="' + edit(cY(d, y)) + '"' + (tip ? ' title="' + esc(tip) + '"' : '') + ' aria-label="' + esc(d.rows[r] + ', точка ' + (i + 1)) + '"></td>';
      }).join('') + '</tr>';
    }).join('');
    return '<div class="cs-prm cs-prm--full' + (ch ? ' is-changed' : '') + '" data-row="' + k + '"><div>' + head(k, ', ' + yu) +
      '<div class="cs-gwrap"><table class="cs-gtab cs-ctab"><thead><tr><th scope="col">' + esc(d.xn) + ', ' + esc(xu) + '</th>' + hx +
      (B.canEdit && n < 12 ? '<th><button type="button" class="cs-ptadd" data-addpt="' + k + '">+ точка</button></th>' : '') + '</tr></thead><tbody>' + body + '</tbody></table></div>' +
      softList(k) +
      '<details class="cs-chart" data-chart="' + k + '"' + (openChart[k] ? ' open' : '') + '><summary>График</summary><div data-chartb="' + k + '">' + chart(d, v) + '</div></details></div></div>';
  }
  function chart(d, v) {
    var W = 620, H = 230, L = 58, R = 14, T = 12, Bm = 34, rows = Object.keys(d.rows), lg = d.log;
    var xs = v.x.map(function (x) { return cX(d, x); });
    var x0 = xs[0], x1 = xs[xs.length - 1];
    if (xs.length === 1) { x0 = lg ? x0 / 4 : x0 - Math.max(1, Math.abs(x0)); x1 = lg ? x1 * 4 : x1 + Math.max(1, Math.abs(x1)); }
    else if (lg) { x0 = x0 / 1.6; x1 = x1 * 1.6; } else { var pd = (x1 - x0) * 0.1; x0 -= pd; x1 += pd; }
    var tx = function (x) { return L + (lg ? (Math.log(x) - Math.log(x0)) / (Math.log(x1) - Math.log(x0)) : (x - x0) / (x1 - x0)) * (W - L - R); };
    var ymax = 0;
    rows.forEach(function (r) { v.y[r].forEach(function (y) { ymax = Math.max(ymax, cY(d, y)); }); });
    ymax = ymax > 0 ? ymax * 1.12 : 1;
    var ty = function (y) { return T + (1 - y / ymax) * (H - T - Bm); };
    var g = '';
    for (var i = 0; i <= 4; i++) {
      var yv = ymax * i / 4, yy = ty(yv);
      g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + yy.toFixed(1) + '" y2="' + yy.toFixed(1) + '" class="cs-ch-grid"/><text x="' + (L - 6) + '" y="' + (yy + 4).toFixed(1) + '" text-anchor="end" class="cs-ch-t">' + fmt2.format(+yv.toPrecision(3)) + '</text>';
    }
    xs.forEach(function (x) {
      var xx = tx(x);
      g += '<line x1="' + xx.toFixed(1) + '" x2="' + xx.toFixed(1) + '" y1="' + T + '" y2="' + (H - Bm) + '" class="cs-ch-grid cs-ch-grid--x"/><text x="' + xx.toFixed(1) + '" y="' + (H - Bm + 16) + '" text-anchor="middle" class="cs-ch-t">' + fmt2.format(+x.toPrecision(4)) + '</text>';
    });
    g += '<text x="' + (W - R) + '" y="' + (H - 4) + '" text-anchor="end" class="cs-ch-t">' + esc(d.xn) + ', ' + esc(d.xcur ? SYM[CUR] : d.xu) + (lg ? ' (логарифмическая шкала)' : '') + '</text>';
    rows.forEach(function (r, ri) {
      var c = PAL[ri % PAL.length], pts = xs.map(function (x, i) { return [tx(x), ty(cY(d, v.y[r][i]))]; });
      var first = pts[0], last = pts[pts.length - 1];
      g += '<polyline points="' + L + ',' + first[1].toFixed(1) + ' ' + first[0].toFixed(1) + ',' + first[1].toFixed(1) + '" class="cs-ch-ext" stroke="' + c + '"/>';
      g += '<polyline points="' + last[0].toFixed(1) + ',' + last[1].toFixed(1) + ' ' + (W - R) + ',' + last[1].toFixed(1) + '" class="cs-ch-ext" stroke="' + c + '"/>';
      g += '<polyline points="' + pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ') + '" class="cs-ch-line" stroke="' + c + '"><title>' + esc(d.rows[r]) + '</title></polyline>';
      pts.forEach(function (p, i) { g += '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="3" fill="' + c + '"><title>' + esc(d.rows[r]) + ': ' + fmt2.format(cY(d, v.y[r][i])) + '</title></circle>'; });
    });
    var leg = rows.length > 1 ? '<div class="cs-ch-leg">' + rows.map(function (r, ri) { return '<span><i class="cs-sw" style="background:' + PAL[ri % PAL.length] + '"></i>' + esc(d.rows[r]) + '</span>'; }).join('') + '</div>' : '';
    return '<svg class="cs-ch" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(d.n) + '">' + g + '</svg>' + leg +
      '<p class="cs-prm__h">Сплошная линия — между точками' + (lg ? ' (плавно по логарифму)' : '') + '; пунктир — за крайними точками значение не меняется. Одна точка — прямая: одно значение для всех.</p>';
  }
  function normCurve(c) {   // по возрастанию X, без повторов и пустых — как сделает сервер
    var idx = c.x.map(function (x, i) { return i; }).filter(function (i) { return isFinite(c.x[i]) && c.x[i] > 0; });
    idx.sort(function (a, b) { return c.x[a] - c.x[b]; });
    var seen = {}, keep = idx.filter(function (i) { if (seen[c.x[i]]) return false; seen[c.x[i]] = 1; return true; });
    var out = { x: keep.map(function (i) { return c.x[i]; }), y: {} };
    Object.keys(c.y).forEach(function (r) { out.y[r] = keep.map(function (i) { return c.y[r][i]; }); });
    return out.x.length ? out : c;
  }

  function field(k) {
    var d = DEF[k], v = D[k], was = PUB[k], ch = !same(v, was);
    if (d.t === 'grid') return gridField(k);
    if (d.t === 'curve') return curveField(k);
    if (d.t === 'table') {
      var cells = Object.keys(d.rows).map(function (r) {
        var c = v[r] !== was[r], note = [];
        if (d.cur && d.cur !== CUR) note.push(money(v[r], d.cur));
        if (c) note.push('<b>было ' + (d.cur ? money(conv(was[r], d.cur)) : fmt2.format(was[r])) + '</b>');
        return '<label class="cs-tc' + (c ? ' is-changed' : '') + '"><span>' + esc(d.rows[r]) + '</span><input class="num" type="number" step="any" min="0" data-k="' + k + '" data-r="' + esc(r) + '" value="' + shown(d, v[r]) + '">' +
          (note.length ? '<small>' + note.join(' · ') + '</small>' : '') + '</label>';
      }).join('');
      return '<div class="cs-prm cs-prm--full" data-row="' + k + '"><div>' + head(k, ', ' + unitOf(d)) +
        (d.cur && d.cur !== CUR ? '<div class="cs-prm__src">Хранится в ' + curWord(d.cur) + ' — под полями исходные значения</div>' : '') +
        '<div class="cs-tgrid">' + cells + '</div>' + softList(k) + '</div></div>';
    }
    if (d.t === 'bool') {
      return '<div class="cs-prm cs-prm--full" data-row="' + k + '"><div><div class="cs-prm__head"><label class="cs-toggle" for="cs-i-' + k + '"><input type="checkbox" id="cs-i-' + k + '" data-k="' + k + '"' + (v ? ' checked' : '') + '><span><b style="font-weight:500">' + esc(d.n) + '</b>' +
        (d.h ? '<br><span class="cs-prm__h">' + esc(d.h) + '</span>' : '') + (ch ? ' <span class="cs-prm__was">изменено</span>' : '') + '</span></label>' + helpBtn(k) + '</div>' + helpBox(k) + '</div></div>';
    }
    var off = (k === 'rate' || k === 'usd') && !D.rateFix;
    var input = d.t === 'sel'
      ? '<select id="cs-i-' + k + '" data-k="' + k + '">' + Object.keys(d.opts).map(function (o) { return '<option value="' + esc(o) + '"' + (o === v ? ' selected' : '') + '>' + esc(d.opts[o]) + '</option>'; }).join('') + '</select>'
      : d.t === 'text'
        ? '<input id="cs-i-' + k + '" type="text" maxlength="120" data-k="' + k + '" value="' + esc(v) + '">'
        : '<input id="cs-i-' + k + '" class="num" type="number" step="' + (d.cur ? 'any' : (d.step || (d.t === 'mul' ? 0.01 : 1))) + '" data-k="' + k + '" value="' + shown(d, v) + '"' + (off ? ' disabled' : '') + '><span>' + unitOf(d) + '</span>';
    var isBad = bad(k), hint = d.h || '', sw = softOf(k);
    if (off) hint = 'Используется, если закрепить курсы вручную. Сейчас в расчёте курс ЦБ: ' + fmt2.format(k === 'rate' ? fx().cny : fx().usd) + ' ' + d.u;
    var lim = d.cur ? money(conv(d.min, d.cur)) + ' до ' + money(conv(d.max, d.cur)) : fmt2.format(d.min) + ' до ' + fmt2.format(d.max) + ' ' + unitOf(d);
    return '<div class="cs-prm' + (d.t === 'text' ? ' cs-prm--wide' : '') + (ch ? ' is-changed' : '') + (isBad ? ' is-bad' : '') + (sw.length ? ' is-soft' : '') + '" data-row="' + k + '"><div><div class="cs-prm__head"><label class="cs-prm__n" for="cs-i-' + k + '">' + esc(d.n) + '</label>' + helpBtn(k) + '</div>' +
      (hint ? '<div class="cs-prm__h">' + esc(hint) + '</div>' : '') + (d.cur && d.cur !== CUR ? '<span class="cs-prm__src">в исходной валюте: ' + money(v, d.cur) + '</span>' : '') +
      (ch ? '<div class="cs-prm__was">было ' + esc(wasTxt(d, was)) + '</div>' : '') + '</div><div class="cs-inp">' + input + '</div>' + helpBox(k) +
      (isBad ? '<div class="cs-prm__err">Допустимо от ' + lim + '</div>' : '') + (sw.length ? '<div class="cs-prm__err cs-prm__err--soft">' + sw.map(function (x) { return esc(x.t); }).join(' ') + '</div>' : '') + '</div>';
  }
  function drawEditor() {
    var g = G[group], keys = Object.keys(DEF).filter(function (k) { return DEF[k].g === group; }), extra = '';
    if (group === 'money') {
      var f = fx();
      extra = '<p class="cs-prm__h" style="margin-top:10px">Итоговый курс в расчёте: ' + fmt2.format(f.cny) + ' × ' + (1 + D.bank / 100).toFixed(3) + ' × ' + (1 + D.agent / 100).toFixed(3) + ' × ' + (1 + D.buffer / 100).toFixed(3) +
        ' = <b class="num">' + fmt2.format(R().ok ? R().x.rate : f.cny) + ' ₽ за ¥</b></p>';
    }
    if (group === 'rules') extra = '<p class="cs-prm__h" style="margin-top:10px">Всегда — расчёт инженером: ' + (PV.engFixed || B.engFixed).map(esc).join(', ') + '.</p>';
    if (group === 'vat' && PV.fx) extra = '<p class="cs-prm__h" style="margin-top:10px">Разброс курса юаня по последним загрузкам ЦБ: <b>' + (PV.fx.vol ? fmt2.format(PV.fx.vol) + ' %' : 'пока не считается (нужно 5 загрузок)') + '</b> — для строки «Колебание юаня».</p>';
    var cl = g.l === 'Фабрика' && group !== 'rules' ? '<span class="cs-pill">прайс фабрики в юанях' + (CUR !== 'CNY' ? ' · показан в ' + curWord(CUR) : '') + '</span>' : '';
    $('#cs-editor').innerHTML = '<div class="cs-boxhead"><h2>' + esc(g.l + ' · ' + g.t) + '</h2>' + cl + '</div><p class="cs-hint">' + esc(g.h) + '</p><div>' + keys.map(field).join('') + '</div>' + extra +
      (B.canEdit ? '' : '<p class="cs-prm__h" style="margin-top:10px">Изменять формулу может менеджер или администратор.</p>');
  }
  function onInput(i) {
    var k = i.dataset.k, d = DEF[k];
    if (!d || !B.canEdit) return;
    var x = parseFloat(i.value);
    if (d.t === 'grid') {
      if (!isFinite(x)) return;
      var c = i.dataset.c, cc = (d.ccur || {})[c];
      D[k] = JSON.parse(JSON.stringify(D[k])); D[k][i.dataset.r][c] = cc ? conv(x, CUR, cc) : x;
      i.parentNode.classList.toggle('is-changed', D[k][i.dataset.r][c] !== PUB[k][i.dataset.r][c]);
    } else if (d.t === 'curve') {
      if (!isFinite(x)) return;
      D[k] = JSON.parse(JSON.stringify(D[k]));
      if (i.dataset.cx !== undefined) D[k].x[+i.dataset.cx] = d.xcur ? conv(x, CUR, d.xcur) : x;
      else D[k].y[i.dataset.r][+i.dataset.ci] = d.cur ? conv(x, CUR, d.cur) : x;
      i.parentNode.classList.add('is-changed');
    } else if (i.dataset.r) { if (!isFinite(x)) return; D[k] = Object.assign({}, D[k]); D[k][i.dataset.r] = d.cur ? conv(x, CUR, d.cur) : x; }
    else if (i.type === 'checkbox') D[k] = i.checked;
    else if (i.tagName === 'SELECT' || d.t === 'text') D[k] = i.value;
    else { if (!isFinite(x)) return; D[k] = d.cur ? conv(x, CUR, d.cur) : x; }
    // подсветка без перерисовки поля, чтобы не сбить ввод
    var row = i.closest('[data-row]');
    if (row) { row.classList.toggle('is-changed', !same(D[k], PUB[k])); row.classList.toggle('is-bad', bad(k)); }
    if (i.dataset.r && d.t === 'table') i.closest('.cs-tc').classList.toggle('is-changed', D[k][i.dataset.r] !== PUB[k][i.dataset.r]);
    if (i.type === 'checkbox' || i.tagName === 'SELECT') { scheduleSave(); drawEditor(); return; }
    scheduleSave();
  }
  function addPoint(k) {
    var d = DEF[k], c = JSON.parse(JSON.stringify(D[k])), n = c.x.length, last = c.x[n - 1];
    c.x.push(d.log ? last * 2 : last + (n > 1 ? last - c.x[n - 2] : Math.max(1, last)));
    Object.keys(c.y).forEach(function (r) { c.y[r].push(c.y[r][n - 1]); });
    D[k] = c; scheduleSave(); drawEditor();
  }
  function delPoint(k, i) {
    var c = JSON.parse(JSON.stringify(D[k]));
    if (c.x.length < 2) return;
    c.x.splice(i, 1); Object.keys(c.y).forEach(function (r) { c.y[r].splice(i, 1); });
    D[k] = c; scheduleSave(); drawEditor();
  }
  $('#cs-editor').addEventListener('click', function (e) {
    var h = e.target.closest('[data-help]');
    if (h) { var k = h.dataset.help, box = $('#cs-help-' + k); openHelp[k] = !openHelp[k]; if (box) box.hidden = !openHelp[k]; h.setAttribute('aria-expanded', !!openHelp[k]); return; }
    var a = e.target.closest('[data-addpt]');
    if (a && B.canEdit) { addPoint(a.dataset.addpt); return; }
    var dl = e.target.closest('[data-delpt]');
    if (dl && B.canEdit) { delPoint(dl.dataset.delpt, +dl.dataset.i); }
  });
  $('#cs-editor').addEventListener('toggle', function (e) { if (e.target.dataset && e.target.dataset.chart) openChart[e.target.dataset.chart] = e.target.open; }, true);
  $('#cs-editor').addEventListener('input', function (e) { if (e.target.dataset.k && e.target.type !== 'checkbox' && e.target.tagName !== 'SELECT') onInput(e.target); });
  $('#cs-editor').addEventListener('change', function (e) { if (e.target.dataset.k && (e.target.type === 'checkbox' || e.target.tagName === 'SELECT')) onInput(e.target); });
  $('#cs-editor').addEventListener('focusout', function () {
    setTimeout(function () {
      if ($('#cs-editor').contains(document.activeElement)) return;
      Object.keys(DEF).forEach(function (k) { if (DEF[k].t === 'curve' && DEF[k].g === group) { var c = normCurve(D[k]); if (!same(c, D[k])) { D[k] = c; scheduleSave(); } } });
      drawEditor();
    }, 0);
  });

  /* ---------- правая колонка ---------- */
  function drawEtalons() {
    $('#cs-etal').innerHTML = PV.etalons.map(function (e, i) {
      var r = e.res, now = r.ok && !r.engineer ? r.total : null, was = e.pub, chip = '', old = '';
      if (now !== null && was) {
        var d = (now - was) / was * 100, ad = Math.abs(d);
        if (ad >= 0.05) {
          chip = '<span class="cs-delta ' + (ad > 15 ? 'cs-delta--big' : d > 0 ? 'cs-delta--up' : 'cs-delta--down') + '">' + (d > 0 ? '+' : '') + d.toFixed(1).replace('.', ',') + ' %</span>';
          old = '<span class="cs-et__old">' + show(was) + '</span>';
        }
      }
      var price = now !== null ? '<b>' + show(now) + '</b>' : '<b class="cs-muted">' + (r.engineer ? 'инженер' : '—') + '</b>';
      return '<div class="cs-et" role="button" tabindex="0" data-i="' + i + '" aria-pressed="' + (i === cur) + '"><span class="cs-et__n">' + esc(e.n) + '</span><span class="cs-et__d">' + esc(e.d) + '</span>' +
        '<span class="cs-et__p num">' + old + price + chip + '</span>' + (B.canEdit && PV.etalons.length > 1 ? '<button class="cs-et__del" type="button" data-del="' + esc(e.id) + '">убрать</button>' : '') + '</div>';
    }).join('');
  }
  function drawClient() {
    var e = E(), r = e.res, body;
    if (!r.ok) body = '<p class="cs-muted">' + esc(r.reason || '') + '</p>';
    else if (r.engineer) body = '<div class="cs-client"><span class="cs-client__l">Ориентировочная стоимость партии</span><span class="cs-client__p">Расчёт инженером</span><span class="cs-client__v">' + esc(r.warn.join(', ')) + '</span><span class="cs-client__v">Срок: ' + esc(r.term) + '</span></div>';
    else {
      body = '<div class="cs-client"><span class="cs-client__l">Ориентировочная стоимость партии</span><span class="cs-client__p num">' + siteRub(r.lo) + ' – ' + siteRub(r.hi) + '</span>' +
        '<span class="cs-client__v num">≈ ' + siteRub(r.per) + (r.set ? ' за комплект · ' + fmt.format(r.qty) + ' компл.' : ' за плату · ' + fmt.format(r.qty) + ' шт') + (r.vat.show ? ' · <b>в т. ч. НДС ' + fmt2.format(r.vat.pct) + ' %</b>' : '') + '</span>' +
        '<span class="cs-client__v">Срок: ' + esc(r.term) + '</span><ul>' + r.lines.map(function (l) {
          return '<li' + (l.soft ? ' class="cs-soft"' : '') + '><span>' + esc(l.name) + '</span><span>' + (l.text ? esc(l.text) : '≈ ' + siteRub(l.v)) + '</span></li>';
        }).join('') + '</ul></div>';
    }
    $('#cs-client').innerHTML = '<h2>Так увидит клиент</h2><p class="cs-hint">' + esc(e.n + ' — ' + e.d) + '. На сайте цена всегда в рублях; наценка и расходы на платёж разнесены по строкам.</p>' + body;
  }
  function drawRate() {
    var f = fx(), r = B.rates || {}, hist = (r.hist || []).map(function (h) { return h[1]; }), svg = '';
    if (hist.length >= 2) {
      var mn = Math.min.apply(null, hist), mx = Math.max.apply(null, hist), w = 260, h = 48, pad = (mx - mn) * 0.1 || 0.05; mn -= pad; mx += pad;
      var xy = hist.map(function (v, i) { return [i * (w - 8) / (hist.length - 1) + 4, h - 4 - (v - mn) / (mx - mn) * (h - 8)]; });
      var line = xy.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' '), last = xy[xy.length - 1];
      svg = '<svg class="cs-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="Курс юаня по дням загрузки"><polygon points="4,' + (h - 4) + ' ' + line + ' ' + last[0].toFixed(1) + ',' + (h - 4) + '" fill="var(--green-l)"/><polyline points="' + line + '" fill="none" stroke="var(--green)" stroke-width="2" stroke-linejoin="round"/><circle cx="' + last[0] + '" cy="' + last[1] + '" r="3.5" fill="var(--green)"/></svg>';
    } else svg = '<span class="cs-muted">График появится через несколько дней загрузок.</span>';
    var meta = f.src === 'cbr' ? 'Курс ЦБ на ' + esc(f.date) + (r.fetched ? ', загружен ' + esc(r.fetched.slice(8, 10) + '.' + r.fetched.slice(5, 7) + ' в ' + r.fetched.slice(11, 16)) : '') + '.'
      : f.src === 'manual' ? 'Курсы закреплены вручную — курс ЦБ в расчёт не идёт.' : 'Курс ЦБ ещё не загружен — в расчёте ручные значения из шага «Курс и платёж». Нажмите «Обновить».';
    if (B.pub.rate) {
      var d = (f.cny / B.pub.rate - 1) * 100;
      meta += ' С последней публикации: <b style="color:var(--copper-d)">' + (d > 0 ? '+' : '') + d.toFixed(1).replace('.', ',') + ' %</b>';
    }
    $('#cs-rate').innerHTML = '<span class="cs-rate__v num">' + fmt2.format(f.cny) + '<small>₽ за ¥</small></span>' + svg + '<span class="cs-rate__m">' + meta + '</span>' +
      '<div class="cs-chips" style="grid-column:1/-1"><span class="cs-pill">1 $ = ' + fmt2.format(f.usd) + ' ₽</span><span class="cs-pill">1 $ = ' + fmt2.format(f.usd / f.cny) + ' ¥</span></div>';
  }
  function drawSens() {
    var rows = E().sens || [];
    if (!rows.length) { $('#cs-sens').innerHTML = '<p class="cs-muted">Для этого эталона цена не считается автоматически.</p>'; return; }
    var mx = Math.max(0.1, rows[0][1]);
    $('#cs-sens').innerHTML = rows.map(function (r) {
      return '<div class="cs-sens__r"><span>' + esc(r[0]) + '</span><span class="cs-sens__track"><span class="cs-sens__b" style="width:' + Math.max(1, r[1] / mx * 100).toFixed(1) + '%"></span></span><span class="cs-sens__v">+' + r[1].toFixed(1).replace('.', ',') + ' %</span></div>';
    }).join('');
  }
  function drawHist() {
    var list = B.versions || [];
    $('#cs-hist').innerHTML = list.length ? list.map(function (v) {
      var dl = v.deltas && v.deltas.length ? 'Эталоны: от ' + fmt2.format(v.deltas[0]) + ' % до ' + fmt2.format(v.deltas[1]) + ' %. ' : '';
      return '<li><span class="v">v' + v.v + '</span><span>' + esc(v.note || 'Без комментария') + '</span><span class="w">' + esc(v.time + ' · ' + v.user) + '</span><span class="c">' +
        (v.changes.length ? 'Изменено: ' + v.changes.map(esc).join('; ') + '. ' : '') + dl +
        (B.canEdit && v.v !== B.pub.v ? '<button type="button" data-restore="' + v.v + '">Взять в черновик</button>' : (v.v === B.pub.v ? '<b>сейчас на сайте</b>' : '')) +
        (B.csv ? ' · <a href="' + esc(B.csv.replace('__SRC__', v.v)) + '">CSV</a>' : '') + '</span></li>';
    }).join('') : '<li><span class="v">—</span><span>Версий пока нет. Сайт считает по старой формуле темы, пока вы не опубликуете первую.</span></li>';
  }
  function render(keepEditor) {
    drawRates(); drawDraft(); drawFormula(); drawEtalons(); drawClient(); drawRate(); drawSens(); drawHist();
    if (!keepEditor || !$('#cs-editor').contains(document.activeElement)) drawEditor(); else refreshLive();
  }

  /* ---------- события ---------- */
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-g]');
    if (g && $('#cs').contains(g)) { group = g.dataset.g; render(); return; }
    var del = e.target.closest('[data-del]');
    if (del) {
      e.stopPropagation();
      api('etalon-delete', { id: del.dataset.del }).then(function (s) { applyState(s); toast('Эталон убран'); }).catch(function (er) { toast(er.message, true); });
      return;
    }
    var et = e.target.closest('.cs-et');
    if (et) { cur = +et.dataset.i; render(); return; }
    var rs = e.target.closest('[data-restore]');
    if (rs) {
      api('restore', { v: +rs.dataset.restore }).then(function (s) { applyState(s); showView('formula'); toast('Версия ' + rs.dataset.restore + ' в черновике — проверьте и опубликуйте'); }).catch(function (er) { toast(er.message, true); });
    }
  });
  $('#cs-etal').addEventListener('keydown', function (e) { var et = e.target.closest('.cs-et'); if (et && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); cur = +et.dataset.i; render(); } });
  $$('[data-cur]').forEach(function (b) {
    b.onclick = function () { CUR = b.dataset.cur; try { localStorage.setItem('pcb-calc-cur', CUR); } catch (e) { /* без хранилища */ } render(); };
  });
  function showView(v, jump) {
    $$('.cs-sub button').forEach(function (x) { x.setAttribute('aria-selected', x.dataset.view === v && (x.dataset.jump || '') === (jump || '')); });
    $('#cs-view-formula').hidden = v === 'history'; $('#cs-view-history').hidden = v !== 'history';
  }
  $$('.cs-sub button').forEach(function (b) {
    b.onclick = function () {
      showView(b.dataset.view, b.dataset.jump);
      if (b.dataset.jump) { group = b.dataset.jump; render(); $('#cs-editor').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    };
  });
  if ($('#cs-reset')) $('#cs-reset').onclick = function () {
    clearTimeout(timer);
    api('reset').then(function (s) { applyState(s); toast('Черновик сброшен'); }).catch(function (er) { toast(er.message, true); });
  };
  if ($('#cs-publish')) $('#cs-publish').onclick = function () {
    var ch = PV.changes || [], d = [];
    PV.etalons.forEach(function (e) { if (e.pub && e.res.ok && !e.res.engineer) d.push((e.res.total - e.pub) / e.pub * 100); });
    var big = d.filter(function (x) { return Math.abs(x) > 15; }).length;
    $('#cs-confirm-h').textContent = B.siteOn ? 'Опубликовать версию ' + (B.pub.v + 1) + '?' : 'Опубликовать первую версию? Сайт перейдёт на расчёт по этой формуле.';
    $('#cs-confirm-list').innerHTML = (ch.length ? ch.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') : '<li>Значения по умолчанию без изменений</li>') +
      (d.length ? '<li>Эталоны: от ' + fmt2.format(Math.min.apply(null, d)) + ' % до ' + fmt2.format(Math.max.apply(null, d)) + ' %' + (big ? ' — <b>у ' + big + ' больше 15 %, проверьте</b>' : '') + '</li>' : '') +
      (Object.keys(PV.errors || {}).length ? '<li><b>Есть значения вне допустимого — исправьте их перед публикацией.</b></li>' : '') +
      ((PV.soft || []).length ? '<li>Предупреждения: ' + (PV.soft || []).filter(function (x) { return x.lvl === 'red'; }).length + ' красных, ' + (PV.soft || []).filter(function (x) { return x.lvl === 'yellow'; }).length + ' жёлтых — публикацию не блокируют, но проверьте шаги с точкой.</li>' : '');
    $('#cs-confirm').hidden = false; $('#cs-confirm-note').focus();
  };
  $('#cs-cancel').onclick = function () { $('#cs-confirm').hidden = true; };
  $('#cs-do-publish').onclick = function () {
    var btn = this, first = !B.siteOn; btn.disabled = true;
    api('publish', { note: $('#cs-confirm-note').value, rev: rev }).then(function (s) {
      btn.disabled = false; $('#cs-confirm').hidden = true; $('#cs-confirm-note').value = '';
      if (first) { location.reload(); return; }
      applyState(s); toast('Опубликовано: версия ' + s.pub.v + ' на сайте');
    }).catch(function (er) { btn.disabled = false; toast(er.message, true); });
  };
  /* ---------- CSV: загрузка в черновик (README §9.6) ---------- */
  var csvToken = '';
  function csvPct(p) { return p === null || p === undefined ? '' : (p > 0 ? '+' : '') + fmt2.format(p) + ' %'; }
  function drawCsv(j) {
    var box = $('#cs-csv'), n = j.changes.length;
    var h = '<h3>' + (n ? 'Файл «' + esc(j.file) + '»: изменится значений — ' + n : 'Файл «' + esc(j.file) + '» совпадает с черновиком') + '</h3>' +
      '<p class="cs-hint">Строк с параметрами: ' + j.lines + '. Сравнение — с текущим черновиком; на сайт попадёт только после публикации.</p>';
    if (n) {
      h += '<div class="cs-csvtab"><table><thead><tr><th>Параметр</th><th>Было</th><th>Стало</th><th></th></tr></thead><tbody>' +
        j.changes.map(function (c) {
          var big = c.pct !== null && Math.abs(c.pct) >= 50;
          return '<tr><td>' + esc(c.name) + '<span class="cs-csvstep">' + esc(G[c.g] ? G[c.g].l + ' · ' + G[c.g].t : '') + '</span></td><td class="num">' + esc(c.old) + '</td><td class="num"><b>' + esc(c.new) + '</b></td><td class="num' + (big ? ' cs-csvbig' : '') + '">' + csvPct(c.pct) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    if (j.range.length) {
      h += '<div class="cs-csvbad"><b>Вне допустимого (' + j.range.length + ').</b> В черновик попадут, но опубликовать не получится, пока не исправите:<ul>' +
        j.range.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>';
    }
    if (j.warn.length) {
      h += '<details class="cs-csvwarn"' + (n ? '' : ' open') + '><summary>Пропущено строк: ' + (j.warn.length + j.more) + '</summary><ul>' +
        j.warn.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + (j.more ? '<li>…и ещё ' + j.more + '</li>' : '') + '</ul></details>';
    }
    if (n && j.etalons.length) {
      h += '<div class="cs-csvtab"><table><thead><tr><th>Эталон</th><th>Черновик сейчас</th><th>После загрузки</th><th></th></tr></thead><tbody>' +
        j.etalons.map(function (e) {
          var big = e.pct !== null && Math.abs(e.pct) > 15;
          return '<tr><td>' + esc(e.n) + '</td><td class="num">' + (e.now ? siteRub(e.now) : 'инженер') + '</td><td class="num">' + (e.next ? siteRub(e.next) : 'инженер') + '</td><td class="num' + (big ? ' cs-csvbig' : '') + '">' + csvPct(e.pct) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    h += '<div class="cs-acts"><button class="pp-btn pp-btn--ghost" type="button" id="cs-csv-cancel">Отмена</button>' +
      '<button class="pp-btn" type="button" id="cs-csv-apply"' + (n ? '' : ' disabled') + '>Применить к черновику</button></div>';
    box.innerHTML = h; box.hidden = false;
    $('#cs-csv-cancel').onclick = function () { box.hidden = true; csvToken = ''; };
    $('#cs-csv-apply').onclick = function () {
      var btn = this; btn.disabled = true; clearTimeout(timer); timer = 0;
      api('csv-apply', { token: csvToken }).then(function (s) {
        box.hidden = true; csvToken = ''; applyState(s); toast('CSV в черновике: изменено значений — ' + n + '. Проверьте эталоны и опубликуйте');
      }).catch(function (er) { btn.disabled = false; toast(er.message, true); if (er.status === 409 || er.status === 410) box.hidden = true; });
    };
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  if ($('#cs-csv-file')) $('#cs-csv-file').addEventListener('change', function () {
    var inp = this, f = inp.files && inp.files[0];
    if (!f) return;
    if (saving || timer) { toast('Дождитесь сохранения черновика и загрузите файл ещё раз', true); inp.value = ''; return; }
    var fd = new FormData(); fd.append('file', f);
    toast('Проверяем файл…');
    fetch(B.api + 'csv-check', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce }, body: fd })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'Ошибка ' + r.status); return j; }); })
      .then(function (j) { csvToken = j.token; $('#cs-toast').hidden = true; $('#cs-confirm').hidden = true; drawCsv(j); })
      .catch(function (er) { toast(er.message, true); })
      .then(function () { inp.value = ''; });
  });

  if ($('#cs-rates-refresh')) $('#cs-rates-refresh').onclick = function () {
    var btn = this; btn.disabled = true;
    api('rates').then(function (s) { btn.disabled = false; applyState(s); toast('Курсы ЦБ обновлены'); }).catch(function (er) { btn.disabled = false; toast(er.message, true); });
  };
  if ($('#cs-addet')) $('#cs-addet').addEventListener('submit', function (e) {
    e.preventDefault();
    var inp = this.number;
    api('etalon', { number: inp.value }).then(function (s) { applyState(s); cur = PV.etalons.length - 1; render(); inp.value = ''; toast('Эталон добавлен'); }).catch(function (er) { toast(er.message, true); });
  });
  window.addEventListener('beforeunload', function (e) { if (saving || timer) { e.preventDefault(); e.returnValue = ''; } });

  render();
})();
