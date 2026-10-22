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
  function stepBtn(g, name, val, warn, sub) {
    var i = STEPS.indexOf(g) + 1;
    return '<button class="cs-fx__t' + (warn ? ' cs-fx__t--warn' : '') + '" type="button" data-g="' + g + '" aria-pressed="' + (g === group && !warn) + '"' + (sub ? ' title="' + esc(sub) + '"' : '') + '>' +
      '<span class="cs-fx__l">' + (warn ? '' : i + ' · ') + esc(G[g].l) + '</span><span class="cs-fx__n">' + esc(name) + '</span><span class="cs-fx__v">' + val + '</span>' +
      (groupChanged(g) && !warn ? '<i class="cs-dot" title="Есть изменения в черновике"></i>' : '') + '</button>';
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
      var sub = t.g === 'money' ? 'Курс в расчёте: ' + fmt2.format(x.rate) + ' ₽ за ¥ (ЦБ ' + fmt2.format(x.cbr) + ')' : t.g === 'margin' ? 'Платы +' + fmt2.format(D.mPcb) + ' %, монтаж +' + fmt2.format(D.mAsm) + ' %' : '';
      return (i ? O('+') : '') + stepBtn(t.g, t.n, val, t.warn, sub);
    }).join('') + O('=') + '<span class="cs-fx__t cs-fx__t--tot"><span class="cs-fx__l">Итог</span><span class="cs-fx__n">Стоимость</span><span class="cs-fx__v">' + show(x.gross) + '</span></span>' +
      (res.engineer ? '<p class="cs-eng" style="flex-basis:100%"><b>Сайт не покажет цену — расчёт инженером:</b> ' + res.warn.map(esc).join(', ') + '. Ниже — как сложилась бы цена.</p>' : '');
    $('#cs-fxrows').innerHTML = x.blocks.map(function (b) {
      var note = b.note ? esc(b.note) : '';
      if (b.warn) note += ' <b>По тарифу ' + show(b.warn.by) + ' — меньше минимума, берём минимум за отправку ' + show(b.warn.min) + '.</b>';
      if (b.ivat) note += ' НДС на ввоз ' + show(b.ivat) + ' принимаем к вычету — в цену не входит.';
      if (b.g === 'vat') {
        note = (b.top ? '<b>Заказ меньше минимальной суммы ' + show(D.minOrder) + ' — добавлено ' + show(b.top) + '.</b> ' : '') +
          'Итог округлён до ' + fmt.format(b.round) + ' ₽. На сайте — вилка от ' + siteRub(b.lo) + ' до ' + siteRub(b.hi) + ' (' + fmt2.format(D.lo) + ' / +' + fmt2.format(D.hi) + ' %).';
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
  function field(k) {
    var d = DEF[k], v = D[k], was = PUB[k], ch = !same(v, was);
    if (d.t === 'table') {
      var cells = Object.keys(d.rows).map(function (r) {
        var c = v[r] !== was[r], note = [];
        if (d.cur && d.cur !== CUR) note.push(money(v[r], d.cur));
        if (c) note.push('<b>было ' + (d.cur ? money(conv(was[r], d.cur)) : fmt2.format(was[r])) + '</b>');
        return '<label class="cs-tc' + (c ? ' is-changed' : '') + '"><span>' + esc(d.rows[r]) + '</span><input class="num" type="number" step="any" min="0" data-k="' + k + '" data-r="' + esc(r) + '" value="' + shown(d, v[r]) + '">' +
          (note.length ? '<small>' + note.join(' · ') + '</small>' : '') + '</label>';
      }).join('');
      return '<div class="cs-prm" style="grid-template-columns:1fr"><div><div class="cs-prm__n">' + esc(d.n) + ', ' + unitOf(d) + '</div>' + (d.h ? '<div class="cs-prm__h">' + esc(d.h) + '</div>' : '') +
        (d.cur && d.cur !== CUR ? '<div class="cs-prm__src">Хранится в ' + curWord(d.cur) + ' — под полями исходные значения</div>' : '') +
        '</div><div class="cs-tgrid">' + cells + '</div></div>';
    }
    if (d.t === 'bool') {
      return '<label class="cs-toggle" for="cs-i-' + k + '"><input type="checkbox" id="cs-i-' + k + '" data-k="' + k + '"' + (v ? ' checked' : '') + '><span><b style="font-weight:500">' + esc(d.n) + '</b>' +
        (d.h ? '<br><span class="cs-prm__h">' + esc(d.h) + '</span>' : '') + (ch ? ' <span class="cs-prm__was">изменено</span>' : '') + '</span></label>';
    }
    var off = (k === 'rate' || k === 'usd') && !D.rateFix;
    var input = d.t === 'sel'
      ? '<select id="cs-i-' + k + '" data-k="' + k + '">' + Object.keys(d.opts).map(function (o) { return '<option value="' + esc(o) + '"' + (o === v ? ' selected' : '') + '>' + esc(d.opts[o]) + '</option>'; }).join('') + '</select>'
      : d.t === 'text'
        ? '<input id="cs-i-' + k + '" type="text" maxlength="120" data-k="' + k + '" value="' + esc(v) + '">'
        : '<input id="cs-i-' + k + '" class="num" type="number" step="' + (d.cur ? 'any' : (d.step || (d.t === 'mul' ? 0.01 : 1))) + '" data-k="' + k + '" value="' + shown(d, v) + '"' + (off ? ' disabled' : '') + '><span>' + unitOf(d) + '</span>';
    var isBad = bad(k), hint = d.h || '';
    if (off) hint = 'Используется, если закрепить курсы вручную. Сейчас в расчёте курс ЦБ: ' + fmt2.format(k === 'rate' ? fx().cny : fx().usd) + ' ' + d.u;
    var lim = d.cur ? money(conv(d.min, d.cur)) + ' до ' + money(conv(d.max, d.cur)) : fmt2.format(d.min) + ' до ' + fmt2.format(d.max) + ' ' + unitOf(d);
    return '<div class="cs-prm' + (d.t === 'text' ? ' cs-prm--wide' : '') + (ch ? ' is-changed' : '') + (isBad ? ' is-bad' : '') + '" data-row="' + k + '"><div><label class="cs-prm__n" for="cs-i-' + k + '">' + esc(d.n) + '</label>' +
      (hint ? '<div class="cs-prm__h">' + esc(hint) + '</div>' : '') + (d.cur && d.cur !== CUR ? '<span class="cs-prm__src">в исходной валюте: ' + money(v, d.cur) + '</span>' : '') +
      (ch ? '<div class="cs-prm__was">было ' + esc(wasTxt(d, was)) + '</div>' : '') + '</div><div class="cs-inp">' + input + '</div>' + (isBad ? '<div class="cs-prm__err">Допустимо от ' + lim + '</div>' : '') + '</div>';
  }
  function drawEditor() {
    var g = G[group], keys = Object.keys(DEF).filter(function (k) { return DEF[k].g === group; }), extra = '';
    if (group === 'money') {
      var f = fx();
      extra = '<p class="cs-prm__h" style="margin-top:10px">Итоговый курс в расчёте: ' + fmt2.format(f.cny) + ' × ' + (1 + D.bank / 100).toFixed(3) + ' × ' + (1 + D.agent / 100).toFixed(3) + ' × ' + (1 + D.buffer / 100).toFixed(3) +
        ' = <b class="num">' + fmt2.format(R().ok ? R().x.rate : f.cny) + ' ₽ за ¥</b></p>';
    }
    if (group === 'rules') extra = '<p class="cs-prm__h" style="margin-top:10px">Всегда — расчёт инженером: ' + B.engFixed.map(esc).join(', ') + '.</p>';
    var cl = g.l === 'Фабрика' && group !== 'rules' ? '<span class="cs-pill">прайс фабрики в юанях' + (CUR !== 'CNY' ? ' · показан в ' + curWord(CUR) : '') + '</span>' : '';
    $('#cs-editor').innerHTML = '<div class="cs-boxhead"><h2>' + esc(g.l + ' · ' + g.t) + '</h2>' + cl + '</div><p class="cs-hint">' + esc(g.h) + '</p><div>' + keys.map(field).join('') + '</div>' + extra +
      (B.canEdit ? '' : '<p class="cs-prm__h" style="margin-top:10px">Изменять формулу может менеджер или администратор.</p>');
  }
  function onInput(i) {
    var k = i.dataset.k, d = DEF[k];
    if (!d || !B.canEdit) return;
    if (i.dataset.r) { var x = parseFloat(i.value); if (!isFinite(x)) return; D[k] = Object.assign({}, D[k]); D[k][i.dataset.r] = d.cur ? conv(x, CUR, d.cur) : x; }
    else if (i.type === 'checkbox') D[k] = i.checked;
    else if (i.tagName === 'SELECT' || d.t === 'text') D[k] = i.value;
    else { var y = parseFloat(i.value); if (!isFinite(y)) return; D[k] = d.cur ? conv(y, CUR, d.cur) : y; }
    // подсветка без перерисовки поля, чтобы не сбить ввод
    var row = i.closest('[data-row]');
    if (row) { row.classList.toggle('is-changed', !same(D[k], PUB[k])); row.classList.toggle('is-bad', bad(k)); }
    if (i.dataset.r) i.closest('.cs-tc').classList.toggle('is-changed', D[k][i.dataset.r] !== PUB[k][i.dataset.r]);
    if (i.type === 'checkbox' || i.tagName === 'SELECT') { scheduleSave(); drawEditor(); return; }
    scheduleSave();
  }
  $('#cs-editor').addEventListener('input', function (e) { if (e.target.dataset.k && e.target.type !== 'checkbox' && e.target.tagName !== 'SELECT') onInput(e.target); });
  $('#cs-editor').addEventListener('change', function (e) { if (e.target.dataset.k && (e.target.type === 'checkbox' || e.target.tagName === 'SELECT')) onInput(e.target); });
  $('#cs-editor').addEventListener('focusout', function () { setTimeout(function () { if (!$('#cs-editor').contains(document.activeElement)) drawEditor(); }, 0); });

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
        (B.canEdit && v.v !== B.pub.v ? '<button type="button" data-restore="' + v.v + '">Взять в черновик</button>' : (v.v === B.pub.v ? '<b>сейчас на сайте</b>' : '')) + '</span></li>';
    }).join('') : '<li><span class="v">—</span><span>Версий пока нет. Сайт считает по старой формуле темы, пока вы не опубликуете первую.</span></li>';
  }
  function render(keepEditor) {
    drawRates(); drawDraft(); drawFormula(); drawEtalons(); drawClient(); drawRate(); drawSens(); drawHist();
    if (!keepEditor || !$('#cs-editor').contains(document.activeElement)) drawEditor();
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
      (Object.keys(PV.errors || {}).length ? '<li><b>Есть значения вне допустимого — исправьте их перед публикацией.</b></li>' : '');
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
