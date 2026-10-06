/**
 * Онлайн-калькулятор (страница /calculator, _gutenberg/pages/709-calculator.html).
 *
 * — Разбор файлов проекта прямо в браузере: IPC-2581 (.xml, .cvg),
 *   ZIP с Gerber и сверловкой, .gbrjob. Файлы на сервер не отправляются.
 *   Распаковка ZIP — assets/js/vendor/jszip.min.js (подключается раньше).
 * — Слияние источников: IPC-2581 → .gbrjob → Gerber, расхождения помечаются.
 * — Оценка монтажа: быстрая и подробная, автоподстановка из IPC-2581.
 * — Итоговая панель и текстовая сводка для формы заявки (window.PCBCalc → assets/js/request-form.js).
 *
 * ВНИМАНИЕ: ставки в объекте R — демонстрационные, формула цены ещё не утверждена.
 */
(function () {
  'use strict';
  var root = document.getElementById('calculator');
  if (!root) return;
  var $ = function (s, c) { return (c || root).querySelector(s); };
  var $$ = function (s, c) { return [].slice.call((c || root).querySelectorAll(s)); };

  /* ================= Поля ================= */
  function el(key) { return $('[data-key="' + key + '"]'); }
  function num(key) { var e = el(key); if (!e) return 0; var v = parseFloat(String(e.value).replace(',', '.')); return isFinite(v) ? v : NaN; }
  function val(key) { var e = el(key); return e ? e.value : ''; }
  function chk(key) { var e = el(key); return !!(e && e.checked); }
  function fmt(n) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n)); }
  function fmtR(n) { return fmt(n) + ' ₽'; }
  function fmtMM(n) { return (Math.round(n * 100) / 100).toString().replace('.', ','); }

  /* Бейджи и состояния полей: ok | need | err | manual */
  var badgeMap = { qty: 'f-qty', size: 'f-len', layers: 'f-layers', thick: 'f-thick', cuout: 'f-cuout', cuin: 'f-cuin', mat: 'f-mat', finish: 'f-finish', mask: 'f-mask', silk: 'f-silk', track: 'f-track', hole: 'f-hole', q_smd: 'q-smd', q_dip: 'q-dip', q_bga: 'q-bga', q_qfn: 'q-qfn', sides: 'f-sides' };
  var badgeText = { ok: '', need: 'заполните', err: 'ошибка', manual: 'вручную', conflict: 'расхождение' };
  function setBadge(field, state, src) {
    var b = $('.cx-badge[data-for="' + field + '"]'); if (!b) return;
    var wrap = b.closest('.cx-field');
    wrap.classList.remove('is-need', 'is-err', 'is-conflict');
    if (!state) { b.textContent = ''; b.removeAttribute('data-state'); return; }
    b.dataset.state = state;
    b.textContent = state === 'ok' ? (src ? 'из ' + src : 'из файла') : badgeText[state];
    if (state === 'need') {
      wrap.classList.add('is-need');
      var sel = wrap.querySelector('select');
      if (sel && sel.querySelector('option[value=""]')) sel.value = '';
      else if (sel) b.textContent = 'уточните';
    }
    if (state === 'ok') { wrap.classList.remove('is-flash'); void wrap.offsetWidth; wrap.classList.add('is-flash'); setTimeout(function () { wrap.classList.remove('is-flash'); }, 1700); }
    if (state === 'err') wrap.classList.add('is-err');
    if (state === 'conflict') wrap.classList.add('is-conflict');
  }
  var asmKeys = ['q_smd', 'q_dip', 'q_bga', 'q_qfn'];
  function clearBadges() { Object.keys(badgeMap).forEach(function (k) { if (asmKeys.indexOf(k) < 0) setBadge(k, null); }); }
  var parsed = false, finishPending = false;
  // ручная правка поля после разбора → «вручную»
  Object.keys(badgeMap).forEach(function (k) {
    var ids = k === 'size' ? ['f-len', 'f-wid'] : [badgeMap[k]];
    ids.forEach(function (id) {
      var e = document.getElementById(id);
      var onEdit = function () {
        if (k === 'finish') { finishPending = false; $('#calc-finish-confirm').hidden = true; }
        if (asmKeys.indexOf(k) >= 0 || parsed) setBadge(k, 'manual');
      };
      e.addEventListener('change', onEdit);
      if (asmKeys.indexOf(k) >= 0 && e.tagName === 'INPUT') e.addEventListener('input', onEdit);
    });
  });

  /* Медь внутренних слоёв — только от 4 слоёв */
  function syncInner() { el('cuin').disabled = parseInt(val('layers'), 10) < 4; }
  el('layers').addEventListener('change', syncInner);

  /* ================= Загрузка нескольких файлов ================= */
  var drop = $('#calc-drop'), fileIn = $('#calc-file');
  var store = [], seq = 0, busyCount = 0;
  function kb(n) { return n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' КБ' : (n / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }
  function plural(n, a, b, c) { var m = n % 10, h = n % 100; return m === 1 && h !== 11 ? a : (m >= 2 && m <= 4 && (h < 10 || h >= 20) ? b : c); }
  function readText(f) { return f.text ? f.text() : new Response(f).text(); }
  function isIPC(t) { return /<IPC-2581[\s>]/.test(t.slice(0, 4000)); }

  drop.addEventListener('click', function () { fileIn.click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) handleFiles([].slice.call(e.dataTransfer.files)); });
  fileIn.addEventListener('change', function () { handleFiles([].slice.call(fileIn.files)); fileIn.value = ''; });

  function handleFiles(files) {
    if (!files.length) return;
    files.forEach(function (f) {
      store = store.filter(function (it) { return it.name !== f.name; }); // тот же файл — заменяем
      var it = { id: ++seq, file: f, name: f.name, size: f.size, kind: '?', status: 'busy', note: '', entries: [], ipc: [] };
      store.push(it);
      busyCount++;
      readItem(f, it).then(function () { busyCount--; renderFiles(); if (!busyCount) setTimeout(safeAnalyse, 350); });
    });
    renderFiles();
  }

  function readItem(f, it) {
    var n = f.name.toLowerCase();
    if (/\.zip$/.test(n)) {
      it.kind = 'zip';
      if (typeof JSZip === 'undefined') { it.status = 'err'; it.note = 'не удалось загрузить модуль распаковки ZIP'; return Promise.resolve(); }
      return JSZip.loadAsync(f).then(function (z) {
        var ps = [];
        z.forEach(function (path, zf) {
          if (zf.dir) return;
          ps.push(zf.async('string').then(function (t) {
            var nm = path.split('/').pop();
            if (/\.(xml|cvg)$/i.test(nm) && isIPC(t)) it.ipc.push({ name: nm, text: t });
            else it.entries.push({ name: nm, text: t });
          }));
        });
        return Promise.all(ps).then(function () { it.status = 'ok'; });
      }).catch(function () { it.status = 'err'; it.note = 'архив повреждён или это не ZIP'; });
    }
    if (/\.gbrjob$/.test(n)) { it.kind = 'job'; return readText(f).then(function (t) { it.entries.push({ name: f.name, text: t }); it.status = 'ok'; }); }
    if (/\.(xml|cvg)$/.test(n)) {
      it.kind = 'ipc';
      return readText(f).then(function (t) {
        if (isIPC(t)) { it.ipc.push({ name: f.name, text: t }); it.status = 'ok'; }
        else { it.kind = 'bad'; it.status = 'err'; it.note = 'это не файл IPC-2581'; }
      });
    }
    it.kind = 'bad'; it.status = 'err';
    it.note = /\.(rar|7z)$/.test(n) ? 'RAR и 7z не поддерживаются — перепакуйте в ZIP' : 'формат не поддерживается';
    return Promise.resolve();
  }

  var typeLabel = { zip: 'ZIP', job: '.gbrjob', ipc: 'IPC-2581', bad: '—', '?': '…' };
  function renderFiles() {
    var box = $('#calc-files-box'), list = $('#calc-file-list');
    box.hidden = !store.length;
    drop.classList.toggle('is-compact', !!store.length);
    list.innerHTML = '';
    store.forEach(function (it) {
      var li = document.createElement('li');
      var tp = document.createElement('span');
      tp.className = 'cx-ftype' + (it.kind === 'ipc' || (it.kind === 'zip' && it.ipc.length) ? ' cx-ftype--ipc' : '') + (it.status === 'err' ? ' cx-ftype--bad' : '');
      tp.textContent = it.kind === 'zip' && it.ipc.length ? 'ZIP · IPC-2581' : typeLabel[it.kind];
      var nm = document.createElement('span'); nm.className = 'cx-fname';
      var b = document.createElement('b'); b.textContent = it.name; nm.appendChild(b);
      var sub = document.createElement('span');
      if (it.status === 'busy') sub.innerHTML = '<span class="cx-spin cx-fspin"></span>Разбираем…';
      else { sub.textContent = it.note; if (it.status === 'err') sub.className = 'is-err'; }
      nm.appendChild(sub);
      var sz = document.createElement('span'); sz.className = 'cx-fsize'; sz.textContent = kb(it.size);
      var del = document.createElement('button'); del.type = 'button'; del.className = 'cx-fdel'; del.textContent = '×';
      del.setAttribute('aria-label', 'Удалить ' + it.name);
      del.addEventListener('click', function () { store = store.filter(function (x) { return x !== it; }); renderFiles(); safeAnalyse(); });
      li.appendChild(tp); li.appendChild(nm); li.appendChild(sz); li.appendChild(del);
      list.appendChild(li);
    });
    if (busyCount) setHead('busy', 'Разбираем файлы…', '');
  }
  function setHead(state, title, sub) {
    var h = $('#calc-files-head'); h.dataset.state = state;
    $('#calc-file-status').innerHTML = '';
    $('#calc-file-status').textContent = title;
    if (sub) { var s = document.createElement('span'); s.textContent = sub; $('#calc-file-status').appendChild(s); }
    $('#calc-live').textContent = title + (sub ? '. ' + sub : '');
  }
  $('#calc-reset').addEventListener('click', function () { store = []; renderFiles(); safeAnalyse(); });

  /* Ошибка разбора не должна оставлять калькулятор в состоянии «Разбираем файлы…» */
  function safeAnalyse() {
    try { analyseAll(); }
    catch (e) {
      if (window.console) console.error('Калькулятор: ошибка разбора файлов', e);
      setHead('err', 'Не удалось разобрать файлы', 'Заполните параметры вручную или приложите файлы к заявке — разберём их сами.');
      recalc();
    }
  }

  /* ================= Разбор IPC-2581 ================= */
  function parseIPC(text) {
    var doc = new DOMParser().parseFromString(text, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length) throw new Error('xml');
    var all = function (n, ctx) { return [].slice.call((ctx || doc).getElementsByTagNameNS('*', n)); };
    var at = function (e, a) { return e ? e.getAttribute(a) : null; };
    var hdr = all('CadHeader')[0], u = (at(hdr, 'units') || 'MILLIMETER').toUpperCase();
    var k = u === 'INCH' ? 25.4 : u === 'MICRON' ? 0.001 : 1;
    var out = { src: 'IPC-2581' };

    var layers = {};
    all('Layer').forEach(function (l) { layers[at(l, 'name')] = { fn: (at(l, 'layerFunction') || '').toUpperCase(), side: (at(l, 'side') || '').toUpperCase() }; });
    var isCu = function (fn) { return /^(CONDUCTOR|SIGNAL|PLANE|MIXED|POWER_GROUND|CONDFILM|CONDFOIL)$/.test(fn); };
    var cuNames = Object.keys(layers).filter(function (n) { return isCu(layers[n].fn); });
    if (cuNames.length) out.layers = cuNames.length;

    var specs = {};
    all('Spec').forEach(function (s) { specs[at(s, 'name')] = s; });
    var specText = function (s) { return s ? all('Property', s).map(function (p) { return at(p, 'text'); }).filter(Boolean) : []; };
    var colorOf = function (s) {
      if (!s) return null;
      var c = specText(s).map(function (t) { var m = /^Color\s*:\s*(.+)$/i.exec(t); return m ? m[1].trim() : null; }).filter(Boolean)[0];
      if (!c) { var ce = all('Color', s)[0]; c = at(ce, 'name') || at(ce, 'term'); }
      return c && !/not specified/i.test(c) ? c : null;
    };

    var stack = all('Stackup')[0];
    var th = parseFloat(at(stack, 'overallThickness'));
    if (th > 0) out.thick = th * k;
    all('StackupLayer').forEach(function (sl) {
      var ref = at(sl, 'layerOrGroupRef'), L = layers[ref]; if (!L) return;
      var t = parseFloat(at(sl, 'thickness')) * k;
      var sr = all('SpecRef', sl)[0], sp = sr ? specs[at(sr, 'id')] : null;
      if (isCu(L.fn) && t > 0) {
        var um = Math.round(t * 1000);
        if (L.side === 'INTERNAL') { if (!out.cuin) out.cuin = um; }
        else if (!out.cuout) out.cuout = um;
      }
      if (/^DIEL/.test(L.fn) && !out.mat) {
        var m = specText(sp).filter(function (x) { return !/^(type|color)\s*:/i.test(x); })[0] || at(sl, 'materialType');
        if (m) out.mat = m;
      }
      if (L.fn === 'SOLDERMASK' && !out.mask) out.mask = colorOf(sp);
      if (/^(SILKSCREEN|LEGEND)$/.test(L.fn) && !out.silk) out.silk = colorOf(sp);
    });
    var sf = all('SurfaceFinish')[0];
    if (sf) {
      var fe = [sf].concat(all('*', sf)).filter(function (e) { return e.getAttribute && e.getAttribute('type'); })[0];
      if (fe) out.finish = fe.getAttribute('type');
    }

    // минимальная ширина дорожки: линии на медных слоях
    var lineDict = {};
    all('EntryLineDesc').forEach(function (e) { var ld = all('LineDesc', e)[0]; if (ld) lineDict[at(e, 'id')] = parseFloat(at(ld, 'lineWidth')); });
    var widths = [];
    all('LayerFeature').forEach(function (lf) {
      var L = layers[at(lf, 'layerRef')]; if (!L || !isCu(L.fn)) return;
      all('Line', lf).concat(all('Polyline', lf)).forEach(function (ln) {
        var d = all('LineDesc', ln)[0], r = all('LineDescRef', ln)[0];
        var w = d ? parseFloat(at(d, 'lineWidth')) : r ? lineDict[at(r, 'id')] : NaN;
        if (w > 0) widths.push(w * k);
      });
    });
    if (widths.length) out.track = Math.min.apply(null, widths);

    // габариты по контуру
    var prof = all('Profile')[0];
    if (prof) {
      var xs = [], ys = [];
      all('*', prof).forEach(function (e) { var x = parseFloat(at(e, 'x')), y = parseFloat(at(e, 'y')); if (isFinite(x) && isFinite(y)) { xs.push(x); ys.push(y); } });
      if (xs.length > 2) out.size = { w: (Math.max.apply(null, xs) - Math.min.apply(null, xs)) * k, h: (Math.max.apply(null, ys) - Math.min.apply(null, ys)) * k };
    }
    // отверстия
    var holes = all('Hole').map(function (h) { return parseFloat(at(h, 'diameter')) * k; }).filter(function (d) { return d > 0; });
    if (!holes.length) holes = all('PadstackHoleDef').map(function (h) { return parseFloat(at(h, 'diameter')) * k; }).filter(function (d) { return d > 0; });
    if (holes.length) out.hole = { min: Math.min.apply(null, holes), hits: all('Hole').length };

    // монтаж: компоненты, выводы, BOM
    var pk = {};
    all('Package').forEach(function (p) {
      var pins = all('Pin', p), thru = pins.filter(function (x) { return /THRU/i.test(at(x, 'type') || ''); }).length;
      pk[at(p, 'name')] = { pins: pins.length, thru: thru };
    });
    var skip = {};
    all('BomItem').forEach(function (bi) {
      all('RefDes', bi).forEach(function (r) { if (at(r, 'populate') === 'false') skip[at(r, 'name')] = 1; });
    });
    var bomUniq = all('BomItem').filter(function (bi) {
      if (/^(DOCUMENT|MECHANICAL)$/i.test(at(bi, 'category') || '')) return false;
      return all('RefDes', bi).some(function (r) { return at(r, 'populate') !== 'false'; });
    }).length;
    var a = { smd: 0, tht: 0, thtPins: 0, top: 0, bot: 0, parts: {}, groups: {}, unknown: 0, total: 0 };
    var G = function (g, pins) { var x = a.groups[g] || (a.groups[g] = { n: 0, pins: 0 }); x.n++; x.pins += pins; };
    all('Component').forEach(function (c) {
      if (skip[at(c, 'refDes')]) return;
      var p = pk[at(c, 'packageRef')]; if (!p || !p.pins) return;
      a.total++;
      var L = layers[at(c, 'layerRef')] || {};
      if (L.side === 'BOTTOM') a.bot++; else a.top++;
      if (at(c, 'part')) a.parts[at(c, 'part')] = 1;
      var name = (at(c, 'packageRef') || '').toLowerCase();
      if (p.thru > 0) { a.tht++; a.thtPins += p.thru; if (/conn|header|usb|jst|molex|terminal|socket/.test(name)) G('conn', p.thru); else G('tht', p.thru); return; }
      a.smd++;
      if (/bga/.test(name)) G('bga', p.pins);
      else if (/qfn|dfn|lga|wson|vson/.test(name)) G('qfn', p.pins);
      else if (/qfp/.test(name)) G('qfp', p.pins);
      else if (/soic|tssop|ssop|msop|(^|[^a-z])sop/.test(name)) G('soic', p.pins);
      else if (/sot|sod|sc-?70/.test(name)) G('sot', p.pins);
      else if (/(^|\D)0201(\D|$)/.test(name)) G('0201', 2);
      else if (/(^|\D)0402(\D|$)/.test(name)) G('0402', 2);
      else if (/(^|\D)(0603|0805|1206|1210|1812|2010|2512)(\D|$)/.test(name)) G('0603', 2);
      else if (/conn|header|usb|jst|molex/.test(name)) G('conn', p.pins);
      else a.unknown++;
    });
    a.uniq = bomUniq || Object.keys(a.parts).length;
    a.sides = a.bot > 0 && a.top > 0 ? 2 : 1;
    a.bgaqfn = !!(a.groups.bga || a.groups.qfn);
    if (a.total) out.asm = a;
    var sw = all('SoftwarePackage')[0];
    out.software = sw ? [at(sw, 'name'), at(sw, 'revision')].filter(Boolean).join(' ') : '';
    return out;
  }

  /* ================= Слияние источников ================= */
  var ipcAsm = null, reportShown = false;
  function analyseAll() {
    clearBadges();
    var entries = [], ipcDocs = [], errs = 0;
    store.forEach(function (it) {
      if (it.status === 'err') { errs++; return; }
      entries = entries.concat(it.entries.map(function (e) { e.from = it; return e; }));
      ipcDocs = ipcDocs.concat(it.ipc.map(function (d) { d.from = it; return d; }));
    });
    // Gerber + gbrjob
    var job = null, types = {}, copper = [], outline = null, drills = [], paste = [], copperText = [];
    entries.forEach(function (e) {
      var c = classify(e); types[c.type] = (types[c.type] || 0) + 1; e.cls = c.type;
      if (c.type === 'job') { try { job = readJob(e.text); job.src = '.gbrjob'; } catch (x) { e.from.note = 'файл .gbrjob не читается'; } }
      if (c.type === 'copper') { copper.push(c); copperText.push(e.text); }
      if (c.type === 'outline') outline = e;
      if (c.type === 'drill') drills.push(e);
      if (c.type === 'paste') paste.push(e);
    });
    var ipc = null;
    ipcDocs.forEach(function (d) {
      if (ipc) return;
      try { ipc = parseIPC(d.text); d.from.note = 'IPC-2581' + (ipc.software ? ' · ' + ipc.software : '') + (ipc.asm ? ' · ' + fmt(ipc.asm.total) + ' компонентов' : ''); }
      catch (x) { d.from.status = 'err'; d.from.note = 'не удалось прочитать XML'; errs++; }
    });
    // подписи файлов в списке
    store.forEach(function (it) {
      if (it.kind === 'zip' && it.status === 'ok' && !it.ipc.length) {
        var f = it.entries.filter(function (e) { return e.cls && e.cls !== 'skip' && e.cls !== 'gerber?'; });
        var cu = it.entries.filter(function (e) { return e.cls === 'copper'; }).length, hasJob = it.entries.some(function (e) { return e.cls === 'job'; });
        it.note = 'Gerber: ' + f.length + ' ' + plural(f.length, 'файл', 'файла', 'файлов') + (cu ? ', медных слоёв ' + cu : '') + (hasJob ? ', есть .gbrjob' : '');
      }
      if (it.kind === 'job' && it.status === 'ok' && !it.note) it.note = 'параметры платы и стек';
    });

    var G = {};
    if (outline) { var bb = gerberBBox(outline.text); if (bb && bb.w > 1 && bb.h > 1) G.size = bb; }
    if (copper.length) G.layers = copper.length;
    var mins = copperText.map(minAperture).filter(Boolean); if (mins.length) G.track = Math.min.apply(null, mins);
    if (drills.length) { var di = drills.map(function (d) { return drillInfo(d.text); }), mn = di.map(function (d) { return d.min; }).filter(Boolean); if (mn.length) G.hole = { min: Math.min.apply(null, mn), hits: di.reduce(function (s, d) { return s + d.hits; }, 0) }; }
    // слой пасты на сторону — один: в архиве бывают копии одного слоя из разных выгрузок
    // (KiCad 5 пишет и F.Paste, и F_Paste) — берём тот, где площадок больше, а не сумму
    var pasteBySide = {};
    paste.forEach(function (p) {
      var bot = /Bot|b[._]paste|\.gbp$/i.test(p.text.slice(0, 200) + p.name), n = flashes(p.text), k = bot ? 'bot' : 'top';
      if (!(k in pasteBySide) || n > pasteBySide[k]) pasteBySide[k] = n;
    });
    gerberPads = (pasteBySide.top || 0) + (pasteBySide.bot || 0);
    gerberBottom = pasteBySide.bot || 0;
    ipcAsm = ipc && ipc.asm ? ipc.asm : null;
    if (ipcAsm) ipc.sides = ipcAsm.sides;
    if (gerberPads) G.sides = gerberBottom > 0 ? 2 : 1;

    parsed = store.length > 0;
    var items = [];
    function put(field, state, label, value, src) { items.push({ field: field, state: state, label: label, value: value, src: src }); setBadge(field, state, src); }
    var srcs = [ipc, job, null].filter(Boolean);
    function cands(field) {
      var c = [];
      if (ipc && ipc[field] != null) c.push({ v: ipc[field], src: 'IPC-2581' });
      if (job && job[field] != null) c.push({ v: job[field], src: '.gbrjob' });
      if (G[field] != null) c.push({ v: G[field], src: { size: 'контура Gerber', layers: 'медных слоёв Gerber', track: 'апертур, оценка', hole: 'сверловки', sides: 'слоя пасты Gerber' }[field] });
      return c;
    }
    function sizeTxt(v) { return fmtMM(Math.max(v.w, v.h)) + ' × ' + fmtMM(Math.min(v.w, v.h)) + ' мм'; }
    var same = {
      size: function (a, b) { return Math.abs(Math.max(a.w, a.h) - Math.max(b.w, b.h)) < 0.6 && Math.abs(Math.min(a.w, a.h) - Math.min(b.w, b.h)) < 0.6; },
      layers: function (a, b) { return a === b; },
      sides: function (a, b) { return a === b; },
      thick: function (a, b) { return Math.abs(a - b) < 0.06; },
      cuout: function (a, b) { return Math.abs(a - b) < 4; },
      hole: function (a, b) { return Math.abs(a.min - b.min) < 0.03; }
    };
    var txt = {
      sides: function (v) { return v === 2 ? 'две стороны' : 'одна сторона'; }, size: sizeTxt, layers: String, thick: function (v) { return fmtMM(v) + ' мм'; }, cuout: function (v) { return v + ' мкм'; }, cuin: function (v) { return v + ' мкм'; },
      hole: function (v) { return fmtMM(v.min) + ' мм' + (v.hits ? ' · ' + fmt(v.hits) + ' отв.' : ''); }, track: function (v) { return fmtMM(v) + ' мм'; }
    };
    var apply = {
      sides: function (v) { el('sides').value = String(v); },
      size: function (v) { el('len').value = Math.round(Math.max(v.w, v.h) * 10) / 10; el('wid').value = Math.round(Math.min(v.w, v.h) * 10) / 10; },
      layers: function (v) { var o = nearest(el('layers'), v); if (!o) return false; el('layers').value = o.value; syncInner(); },
      thick: function (v) { var o = nearest(el('thick'), v); if (!o) return false; el('thick').value = o.value; },
      cuout: function (v) { var o = nearest(el('cuout'), v); if (!o) return false; el('cuout').value = o.value; },
      cuin: function (v) { var o = nearest(el('cuin'), v); if (!o) return false; el('cuin').value = o.value; },
      hole: function (v) { el('hole').value = Math.round(v.min * 100) / 100; },
      track: function (v) { el('track').value = Math.round(v * 1000) / 1000; },
      mat: function (v) { var m = matMap(v); if (!m) return false; el('mat').value = m; },
      mask: function (v) { var c = colorMap[String(v).toLowerCase()]; if (!c) return false; el('mask').value = c; },
      silk: function (v) { var c = colorMap[String(v).toLowerCase()]; if (!c || !el('silk').querySelector('[value="' + c + '"]')) return false; el('silk').value = c; }
    };
    var labels = { sides: 'Расположение компонентов', size: 'Габариты', layers: 'Число слоёв', thick: 'Толщина платы', cuout: 'Медь наружных слоёв', cuin: 'Медь внутренних слоёв', mat: 'Материал', mask: 'Цвет маски', silk: 'Цвет маркировки', track: 'Мин. ширина дорожки', hole: 'Мин. отверстие' };
    function resolve(field) {
      var c = cands(field), label = labels[field];
      if (!c.length) return put(field, 'need', label, field === 'track' || field === 'hole' || field === 'size' || field === 'layers' ? 'не найдено в файлах' : 'нет в файлах', null);
      var top = c[0];
      if (apply[field](top.v) === false) return put(field, 'need', label, top.v + ' — выберите из списка', null);
      var f = txt[field] || String;
      if (same[field]) {
        var diff = c.slice(1).filter(function (x) { return !same[field](top.v, x.v); });
        if (diff.length) return put(field, 'conflict', label, [top].concat(diff).map(function (x) { return x.src.split(',')[0] + ': ' + f(x.v); }).join(' · '), null);
      }
      put(field, 'ok', label, f(top.v), top.src);
    }
    if (store.length && (entries.length || ipc)) {
      ['size', 'layers', 'thick', 'cuout'].forEach(resolve);
      if (parseInt(val('layers'), 10) >= 4) resolve('cuin');
      resolve('mat');
      // покрытие: из IPC-2581 или .gbrjob, иначе HASL с подсветкой
      (function () {
        var cf = $('#calc-finish-confirm'), c = [];
        if (ipc && ipc.finish) c.push({ v: ipc.finish, src: 'IPC-2581' });
        if (job && job.finish) c.push({ v: job.finish, src: '.gbrjob' });
        var hit = null, top = c[0];
        if (top) hit = finishMap.filter(function (p) { return p[0].test(top.v); })[0];
        if (hit) { el('finish').value = hit[1]; finishPending = false; cf.hidden = true; put('finish', 'ok', 'Финишное покрытие', top.v, top.src); return; }
        el('finish').value = 'hasl'; finishPending = true;
        cf.firstChild.textContent = top ? 'В файле указано «' + top.v + '» — подставили HASL. ' : 'В файлах покрытие не указано — подставили HASL. ';
        cf.hidden = false;
        put('finish', 'need', 'Финишное покрытие', (top ? 'не распознано' : 'не указано') + ' → HASL', null);
      })();
      resolve('mask'); resolve('silk'); resolve('track'); resolve('hole');
      if (cands('sides').length) resolve('sides');
      if (ipc && ipc.asm) items.push({ field: 'asm', state: 'ok', label: 'Компоненты — для монтажа', value: fmt(ipc.asm.total) + ' шт', src: 'IPC-2581' });
      else if (gerberPads) items.push({ field: 'asm', state: 'ok', label: 'SMD-площадок — для оценки монтажа', value: '≈ ' + fmt(gerberPads), src: 'слоя пасты' });
      else items.push({ field: 'asm', state: 'need', label: 'Данные для монтажа', value: 'не найдены', src: null });
    } else { finishPending = false; $('#calc-finish-confirm').hidden = true; }

    // шапка списка файлов
    var fields = items.filter(function (i) { return i.field !== 'asm'; });
    var ok = fields.filter(function (i) { return i.state === 'ok'; }).length, conf = fields.filter(function (i) { return i.state === 'conflict'; }).length, need = fields.length - ok;
    var nOk = store.filter(function (it) { return it.status === 'ok'; }).length;
    if (store.length) {
      if (!fields.length) setHead('err', 'Не удалось получить данные из файлов', errs ? 'Проверьте формат: нужны IPC-2581, ZIP с Gerber или .gbrjob' : '');
      else setHead(conf ? 'err' : need ? 'warn' : 'ok', 'Обработано файлов: ' + nOk + ' из ' + store.length + ' · заполнено ' + ok + ' из ' + fields.length + ' параметров',
        conf ? 'Источники расходятся в ' + conf + ' ' + plural(conf, 'поле', 'полях', 'полях') + ' — проверьте отмеченные' : need ? need + ' ' + plural(need, 'поле требует', 'поля требуют', 'полей требуют') + ' внимания' : 'Проверьте значения ниже');
    }
    $('#calc-files').classList.toggle('is-done', fields.length > 0);
    renderFiles();
    if (busyCount) setHead('busy', 'Разбираем файлы…', '');

    // отчёт
    var src = []; if (ipc) src.push('IPC-2581'); if (job) src.push('.gbrjob'); if (copper.length || outline || drills.length) src.push('Gerber');
    $('#calc-report-files').textContent = src.length ? 'Источники: ' + src.join(', ') + '. При расхождении приоритет у IPC-2581, затем .gbrjob, затем Gerber.' : '';
    var list = $('#calc-report-list'); list.innerHTML = '';
    var order = { conflict: 0, err: 1, need: 2, ok: 3 };
    items.sort(function (a, b) { return order[a.state] - order[b.state]; }).forEach(function (i) {
      var li = document.createElement('li');
      var note = i.src ? 'из ' + i.src : i.state === 'conflict' ? 'выберите верное значение вручную' : i.field === 'finish' ? 'уточните выбор' : 'заполните вручную';
      li.innerHTML = '<span class="ic ic--' + i.state + '" aria-hidden="true"></span><span><span class="lbl"></span> <span class="src"></span></span><span class="v"></span>';
      li.querySelector('.lbl').textContent = i.label; li.querySelector('.src').textContent = note; li.querySelector('.v').textContent = i.value;
      list.appendChild(li);
    });
    $('#calc-report').hidden = !items.length;
    var bad = items.filter(function (i) { return i.state !== 'ok'; }).length;
    $('#calc-report-sum').textContent = 'Что взято из файлов: ' + items.filter(function (i) { return i.state === 'ok'; }).length + ' из ' + items.length + (bad ? ' · ' + bad + ' ' + plural(bad, 'требует', 'требуют', 'требуют') + ' внимания' : '');
    if (!reportShown && items.length) $('#calc-report').open = true; // при первой загрузке файлов — раскрыт, дальше как оставил пользователь
    reportShown = items.length > 0;
    if (asmFilled) asmInit();
    asmFilled = false;
    updatePasteHint();
    if (ipcAsm) { // точные данные из IPC-2581: подставляем сразу и открываем подробную оценку
      applyAsm();
      $('input[name="asm"][value="detail"]').checked = true; syncAsm();
    }
    // «Ещё параметры» раскрываем, если внутри есть поле, требующее внимания
    var hidden = items.some(function (i) { return (i.field === 'track' || i.field === 'hole' || i.field === 'sides') && i.state !== 'ok'; });
    if (hidden) $('#calc-more').open = true;
    recalc();
  }
  $('#calc-finish-ok').addEventListener('click', function () { finishPending = false; $('#calc-finish-confirm').hidden = true; setBadge('finish', 'manual'); recalc(); });

  /* ================= Демо-проекты ================= */
  function demoIPC() {
    var L = function (n, fn, side) { return '<Layer name="' + n + '" layerFunction="' + fn + '" polarity="POSITIVE" side="' + side + '"/>'; };
    var specs = '<Spec name="MASK_S"><General type="MATERIAL"><Property text="SOLDERMASK"/><Property text="Color : Green"/></General></Spec>' +
      '<Spec name="SILK_S"><General type="MATERIAL"><Property text="Color : White"/></General></Spec>' +
      '<Spec name="CORE_S"><General type="MATERIAL"><Property text="FR4"/><Property text="Type : core"/></General></Spec>' +
      '<Spec name="FIN_S"><SurfaceFinish><Finish type="ENIG-N"/></SurfaceFinish></Spec>';
    var sl = function (ref, t, spec, seq) { return '<StackupLayer layerOrGroupRef="' + ref + '" thickness="' + t + '" sequence="' + seq + '">' + (spec ? '<SpecRef id="' + spec + '"/>' : '') + '</StackupLayer>'; };
    var pkgDefs = [['R_0402_1005Metric', 2, 0], ['C_0603_1608Metric', 2, 0], ['SOT-23', 3, 0], ['SOIC-8_3.9x4.9mm', 8, 0], ['QFN-32_5x5mm', 33, 0], ['PinHeader_2x05_P2.54mm', 10, 10], ['TO-220-3', 3, 3]];
    var pk = pkgDefs.map(function (p) { var s = '<Package name="' + p[0] + '" type="OTHER">'; for (var i = 1; i <= p[1]; i++) s += '<Pin number="' + i + '" type="' + (i <= p[2] ? 'THRU' : 'SURFACE') + '"/>'; return s + '</Package>'; }).join('');
    var comps = [], bom = {}, n = 0;
    function add(pkg, part, cnt, side) { for (var i = 0; i < cnt; i++) { var rd = part.charAt(0) + (++n); comps.push('<Component refDes="' + rd + '" packageRef="' + pkg + '" part="' + part + '" layerRef="' + (side === 'b' ? 'B.Cu' : 'F.Cu') + '" mountType="' + (/PinHeader|TO-220/.test(pkg) ? 'THMT' : 'SMT') + '"><Location x="0" y="0"/></Component>'); (bom[part] = bom[part] || []).push(rd); } }
    ['R10K', 'R1K', 'R4K7', 'R100', 'R0'].forEach(function (p, i) { add('R_0402_1005Metric', p, [20, 12, 8, 6, 4][i], i === 4 ? 'b' : 't'); });
    ['C100N', 'C10U', 'C1U'].forEach(function (p, i) { add('C_0603_1608Metric', p, [14, 4, 6][i], i === 0 ? 'b' : 't'); });
    add('SOT-23', 'Q_MMBT3904', 6, 't'); add('SOT-23', 'U_LDO', 2, 't');
    add('SOIC-8_3.9x4.9mm', 'U_EEPROM', 2, 't'); add('QFN-32_5x5mm', 'U_MCU', 1, 't');
    add('PinHeader_2x05_P2.54mm', 'J_SWD', 1, 't'); add('TO-220-3', 'U_REG', 1, 't');
    var bomXml = Object.keys(bom).map(function (p) { return '<BomItem OEMDesignNumberRef="' + p + '" quantity="' + bom[p].length + '" category="ELECTRICAL">' + bom[p].map(function (r) { return '<RefDes name="' + r + '" populate="true"/>'; }).join('') + '</BomItem>'; }).join('');
    var holes = ''; for (var i = 0; i < 96; i++) holes += '<Hole name="V' + i + '" diameter="0.30" platingStatus="PLATED" x="' + i + '" y="1"/>'; for (i = 0; i < 13; i++) holes += '<Hole name="H' + i + '" diameter="1.0" platingStatus="PLATED" x="' + i + '" y="2"/>';
    var xml = '<?xml version="1.0" encoding="UTF-8"?>\n<IPC-2581 revision="C" xmlns="http://webstds.ipc.org/2581">' +
      '<Content roleRef="Owner"><FunctionMode mode="ASSEMBLY"/></Content>' +
      '<HistoryRecord number="1"><FileRevision fileRevisionId="1"><SoftwarePackage name="Altium Designer" revision="24 (демо)"/></FileRevision></HistoryRecord>' +
      '<Bom name="demo_bom">' + bomXml + '</Bom>' +
      '<Content><DictionaryLineDesc units="MILLIMETER"><EntryLineDesc id="TRK_015"><LineDesc lineWidth="0.15" lineEnd="ROUND"/></EntryLineDesc><EntryLineDesc id="TRK_025"><LineDesc lineWidth="0.25" lineEnd="ROUND"/></EntryLineDesc></DictionaryLineDesc></Content>' +
      '<Ecad name="Design"><CadHeader units="MILLIMETER">' + specs + '</CadHeader><CadData>' +
      L('Top Overlay', 'SILKSCREEN', 'TOP') + L('Top Solder', 'SOLDERMASK', 'TOP') + L('Top Finish', 'COATINGCOND', 'TOP') + L('F.Cu', 'SIGNAL', 'TOP') + L('Core1', 'DIELCORE', 'INTERNAL') + L('GND', 'PLANE', 'INTERNAL') + L('Prepreg', 'DIELPREG', 'INTERNAL') + L('PWR', 'PLANE', 'INTERNAL') + L('Core2', 'DIELCORE', 'INTERNAL') + L('B.Cu', 'SIGNAL', 'BOTTOM') + L('Bottom Solder', 'SOLDERMASK', 'BOTTOM') +
      '<Stackup name="Primary" overallThickness="1.6"><StackupGroup name="G">' +
      sl('Top Overlay', '0', 'SILK_S', 1) + sl('Top Solder', '0.01', 'MASK_S', 2) + sl('Top Finish', '0.001', 'FIN_S', 3) + sl('F.Cu', '0.035', null, 4) + sl('Core1', '0.2', 'CORE_S', 5) + sl('GND', '0.0175', null, 6) + sl('Prepreg', '1.065', 'CORE_S', 7) + sl('PWR', '0.0175', null, 8) + sl('Core2', '0.2', 'CORE_S', 9) + sl('B.Cu', '0.035', null, 10) + sl('Bottom Solder', '0.01', 'MASK_S', 11) +
      '</StackupGroup></Stackup>' +
      '<Step name="demo" type="BOARD"><Profile><Polygon><PolyBegin x="0" y="0"/><PolyStepSegment x="80" y="0"/><PolyStepSegment x="80" y="55"/><PolyStepSegment x="0" y="55"/><PolyStepSegment x="0" y="0"/></Polygon></Profile>' +
      pk + comps.join('') +
      '<LayerFeature layerRef="F.Cu"><Set net="SIG"><Features><Line startX="10" startY="10" endX="40" endY="10"><LineDescRef id="TRK_015"/></Line><Line startX="10" startY="20" endX="40" endY="20"><LineDescRef id="TRK_025"/></Line></Features></Set></LayerFeature>' +
      '<LayerFeature layerRef="B.Cu"><Set net="GND"><Features><Line startX="10" startY="30" endX="40" endY="30"><LineDesc lineWidth="0.20" lineEnd="ROUND"/></Line></Features></Set></LayerFeature>' +
      '<LayerFeature layerRef="F.Cu_B.Cu"><Set>' + holes + '</Set></LayerFeature></Step>' +
      '</CadData></Ecad></IPC-2581>';
    return Promise.resolve(new File([xml], 'demo-board.cvg', { type: 'application/xml' }));
  }
  function scrollToFiles() { $('#calc-drop').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); }
  /* Демо-проект заменяет загруженные файлы: если среди них есть файлы клиента — сначала спрашиваем. */
  var demoNames = {};
  function loadDemo(make) {
    store = []; renderFiles();
    make().then(function (f) { demoNames[f.name] = true; handleFiles([f]); scrollToFiles(); });
  }
  var demoWarn = $('#calc-demo-warn');
  $('#calc-demo-ipc').addEventListener('click', function () {
    var own = store.filter(function (it) { return !demoNames[it.name]; });
    if (!own.length) { demoWarn.hidden = true; loadDemo(demoIPC); return; }
    $('#calc-demo-warn-text').textContent = (own.length === 1 ? 'Вы уже загрузили файл ' + own[0].name : 'Вы уже загрузили свои файлы (' + own.length + ')') +
      '. Демо-проект заменит ' + (own.length === 1 ? 'его' : 'их') + ', а поля калькулятора заполнятся данными примера.';
    $('#calc-demo-cancel').textContent = own.length === 1 ? 'Оставить мой файл' : 'Оставить мои файлы';
    demoWarn.hidden = false;
    $('#calc-demo-replace').focus();
  });
  $('#calc-demo-replace').addEventListener('click', function () { demoWarn.hidden = true; loadDemo(demoIPC); });
  $('#calc-demo-cancel').addEventListener('click', function () { demoWarn.hidden = true; $('#calc-demo-ipc').focus(); });

  /* ================= Монтаж: режимы ================= */
  function asmMode() { var r = $('input[name="asm"]:checked'); return r ? r.value : 'quick'; }
  function syncAsm() {
    var m = asmMode();
    $$('[data-mode]').forEach(function (b) { b.hidden = b.dataset.mode !== m; });
    updatePasteHint();
  }
  $$('input[name="asm"]').forEach(function (r) { r.addEventListener('change', function () { syncAsm(); recalc(); }); });
  var asmFilled = false;
  function asmInit() {
    asmKeys.forEach(function (k) { el(k).value = 0; setBadge(k, 'need'); });
    $$('.cx-table input[type="number"]').forEach(function (i) { i.value = 0; i.classList.add('is-need'); });
  }
  $$('.cx-table input[type="number"]').forEach(function (i) {
    i.addEventListener('input', function () { i.classList.remove('is-need'); });
  });
  $('#calc-to-detail').addEventListener('click', function () { $('input[name="asm"][value="detail"]').checked = true; syncAsm(); recalc(); });
  var applyBtn = $('#calc-asm-apply');
  function syncApply() {
    applyBtn.textContent = asmFilled ? 'Подставлено' : 'Подставить';
    applyBtn.classList.toggle('is-applied', asmFilled);
  }
  var groupMap = { '0201': ['d_0201'], '0402': ['d_0402'], '0603': ['d_0603'], sot: ['d_sot'], soic: ['d_soic', 'p_soic'], qfp: ['d_qfp', 'p_qfp'], qfn: ['d_qfn', 'p_qfn'], bga: ['d_bga', 'p_bga'], tht: ['d_tht', 'p_tht'], conn: ['d_conn'] };
  var groupName = { '0201': 'чип 0201', '0402': 'чип 0402', '0603': 'чип 0603 и крупнее', sot: 'SOT/SOD', soic: 'SOIC/TSSOP', qfp: 'QFP', qfn: 'QFN/DFN', bga: 'BGA', tht: 'DIP', conn: 'разъёмы' };
  applyBtn.addEventListener('click', function () { applyAsm(); });
  $('#calc-asm-reset').addEventListener('click', function () { asmInit(); asmFilled = false; syncApply(); recalc(); });
  function applyAsm() {
    if (ipcAsm) {
      var a = ipcAsm;
      var gn = function (g) { return a.groups[g] ? a.groups[g].n : 0; };
      el('q_smd').value = a.smd - gn('bga') - gn('qfn') - gn('qfp'); el('q_dip').value = a.thtPins; el('q_bga').value = gn('bga'); el('q_qfn').value = gn('qfn') + gn('qfp');
      asmKeys.forEach(function (k) { setBadge(k, 'ok', 'IPC-2581'); });
      $$('.cx-table input[type="number"]').forEach(function (i) { i.value = 0; i.classList.remove('is-need'); });
      Object.keys(a.groups).forEach(function (g) {
        var m = groupMap[g]; if (!m) return;
        var gi = a.groups[g], c = el(m[0]); c.value = gi.n; c.classList.remove('is-need');
        if (m[1]) { var p = el(m[1]); p.value = Math.round(gi.pins / gi.n); p.classList.remove('is-need'); }
      });
    } else {
      el('q_smd').value = Math.round(gerberPads / 2.4);
      setBadge('q_smd', 'ok', 'файлов, оценка');
      ['q_dip', 'q_bga', 'q_qfn'].forEach(function (k) { setBadge(k, 'need'); });
    }
    asmFilled = true; syncApply(); recalc();
  }
  function updatePasteHint() {
    var n = $('#calc-asm-note'), why = $('#calc-asm-why');
    var mode = asmMode();
    if ((!gerberPads && !ipcAsm) || mode === 'none' || (mode === 'detail' && !ipcAsm)) { n.hidden = true; return; }
    n.hidden = false;
    why.innerHTML = '';
    var P = function (html) { var p = document.createElement('p'); p.innerHTML = html; why.appendChild(p); return p; };
    if (ipcAsm) {
      var a = ipcAsm;
      $('#calc-asm-title').innerHTML = 'В файле IPC-2581: <b>' + fmt(a.total) + ' ' + plural(a.total, 'компонент', 'компонента', 'компонентов') + '</b> — SMD ' + fmt(a.smd) + ', DIP ' + fmt(a.tht) + ' (' + fmt(a.thtPins) + ' выв.)' + (a.sides === 2 ? ', на двух сторонах' : '') + '.';
      P('<b>Как считаем.</b> Компоненты, стороны и тип выводов взяты из посадочных мест и BOM в файле — это точнее, чем оценка по слою пасты.');
      var gs = Object.keys(a.groups).map(function (g) { return groupName[g] + ' — ' + a.groups[g].n; });
      if (gs.length) P('<b>Группы корпусов</b> для подробной оценки распознаны по названиям посадочных мест: ' + gs.join(', ') + '.');
      if (a.unknown) P('<b>Не распознано ' + fmt(a.unknown) + ' ' + plural(a.unknown, 'корпус', 'корпуса', 'корпусов') + '.</b> Их нет в подробной таблице — добавьте вручную в подходящие группы.');
    } else {
      $('#calc-asm-title').innerHTML = 'В ваших файлах <b>≈ ' + fmt(gerberPads) + ' SMD-площадок</b> — грубо это около ' + fmt(Math.round(gerberPads / 2.4)) + ' компонентов' + (gerberBottom ? ', на двух сторонах' : '') + '.';
      P('<b>Как считаем.</b> На слое пасты ≈ ' + fmt(gerberPads) + ' площадок. Большинство компонентов на типичной плате — резисторы и конденсаторы с двумя площадками, микросхемы добавляют больше. В среднем выходит около 2,4 площадки на компонент.');
      P('<b>Это грубая оценка.</b> На платах с крупными микросхемами реальное число компонентов может быть заметно меньше.');
      P('<b>Что заполнить вручную:</b> выводы DIP-компонентов и число корпусов BGA, QFN и QFP — по Gerber их не отличить. Или переключитесь на подробную оценку и укажите группы корпусов. Точные цифры даст файл IPC-2581.');
    }
    $('#calc-to-detail').hidden = mode === 'detail';
    syncApply();
  }



  /* --- Определение типа файла --- */
  function classify(e) {
    var n = e.name.toLowerCase(), t = e.text;
    if (/\.gbrjob$/.test(n)) return { type: 'job' };
    var ff = /%TF\.FileFunction,([^*]+)\*%/.exec(t);
    if (ff) {
      var p = ff[1].split(',');
      if (p[0] === 'Copper') return { type: 'copper', layer: parseInt(String(p[1]).replace(/\D/g, ''), 10), side: p[2] };
      if (p[0] === 'Profile') return { type: 'outline' };
      if (p[0] === 'Paste') return { type: 'paste', side: p[1] };
      if (p[0] === 'Soldermask') return { type: 'mask' };
      if (p[0] === 'Legend') return { type: 'silk' };
      if (/Plated|NonPlated/.test(p[0])) return { type: 'drill' };
    }
    if (/^M48/m.test(t) || /\.(drl|xln|exc)$/.test(n)) return { type: 'drill' };
    if (/edge[._]cuts|\.gko$|\.gm1$|outline|profile/.test(n)) return { type: 'outline' };
    if (/[fb][._]paste|\.gtp$|\.gbp$/.test(n)) return { type: 'paste' };
    if (/[fb][._]mask|\.gts$|\.gbs$/.test(n)) return { type: 'mask' };
    if (/silk|\.gto$|\.gbo$/.test(n)) return { type: 'silk' };
    if (/f[._]cu|\.gtl$/.test(n)) return { type: 'copper', side: 'Top' };
    if (/b[._]cu|\.gbl$/.test(n)) return { type: 'copper', side: 'Bot' };
    if (/in\d+[._]cu|\.g\d+$/.test(n)) return { type: 'copper', side: 'Inr' };
    if (/%FS[LT][AI]X\d\dY\d\d\*%/.test(t)) return { type: 'gerber?' };
    return { type: 'skip' };
  }

  /* --- Габариты по контуру --- */
  function gerberBBox(t) {
    var fs = /%FS([LT])[AI]X(\d)(\d)Y(\d)(\d)\*%/.exec(t);
    if (!fs) return null;
    var inch = /%MOIN\*%/.test(t), dec = parseInt(fs[3], 10), intd = parseInt(fs[2], 10), trail = fs[1] === 'T';
    function cv(s) {
      var neg = s[0] === '-'; s = s.replace(/[+-]/, '');
      if (trail) while (s.length < intd + dec) s += '0';
      var v = parseInt(s, 10) / Math.pow(10, dec); return neg ? -v : v;
    }
    var x = 0, y = 0, minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity, n = 0;
    t.split('*').forEach(function (c) {
      c = c.trim();
      var m = /(?:X([+-]?\d+))?(?:Y([+-]?\d+))?(?:I[+-]?\d+)?(?:J[+-]?\d+)?D0?[123]$/.exec(c);
      if (!m || (m[1] === undefined && m[2] === undefined)) return;
      if (m[1] !== undefined) x = cv(m[1]);
      if (m[2] !== undefined) y = cv(m[2]);
      minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y); n++;
    });
    if (n < 2) return null;
    var k = inch ? 25.4 : 1;
    return { w: (maxX - minX) * k, h: (maxY - minY) * k };
  }
  function minAperture(t) {
    var inch = /%MOIN\*%/.test(t), r = /%ADD\d+C,([\d.]+)/g, m, min = Infinity;
    while ((m = r.exec(t))) { var v = parseFloat(m[1]); if (v > 0) min = Math.min(min, v); }
    return isFinite(min) ? min * (inch ? 25.4 : 1) : null;
  }
  function drillInfo(t) {
    var inch = /INCH/.test(t) && !/METRIC/.test(t), r = /^T(\d+)[^\n]*?C([\d.]+)/gm, m, min = Infinity;
    while ((m = r.exec(t))) { var v = parseFloat(m[2]); if (v > 0) min = Math.min(min, v); }
    var hits = (t.match(/^[XY][+-]?[\d.]+/gm) || []).length;
    return { min: isFinite(min) ? min * (inch ? 25.4 : 1) : null, hits: hits };
  }
  function flashes(t) { return (t.match(/D0?3\*/g) || []).length + (t.match(/G36\*/g) || []).length; }

  /* --- .gbrjob --- */
  function readJob(t) {
    var j = JSON.parse(t), out = {}, g = j.GeneralSpecs || {};
    if (g.Size && g.Size.X && g.Size.Y) out.size = { w: +g.Size.X, h: +g.Size.Y };
    if (g.LayerNumber) out.layers = +g.LayerNumber;
    if (g.BoardThickness) out.thick = +g.BoardThickness;
    if (g.Finish && !/^none$/i.test(g.Finish)) out.finish = g.Finish;
    var st = j.MaterialStackup || [], cu = st.filter(function (s) { return s.Type === 'Copper'; });
    // толщина меди есть не у всех САПР (KiCad 5 пишет слои без Thickness) — берём только реальные числа
    var um = function (s) { var t = parseFloat(s && s.Thickness); return isFinite(t) && t > 0 ? Math.round(t * 1000) : null; };
    if (cu.length && um(cu[0]) != null) out.cuout = um(cu[0]);
    if (cu.length > 2 && um(cu[1]) != null) out.cuin = um(cu[1]);
    if (out.thick != null && !(isFinite(out.thick) && out.thick > 0)) delete out.thick;
    if (out.layers != null && !(isFinite(out.layers) && out.layers > 0)) delete out.layers;
    st.forEach(function (s) {
      if (s.Type === 'SolderMask' && s.Color && !out.mask) out.mask = s.Color;
      if (s.Type === 'Legend' && s.Color && !out.silk) out.silk = s.Color;
      if (s.Type === 'Dielectric' && s.Material && !out.mat) out.mat = s.Material;
    });
    (j.DesignRules || []).forEach(function (r) { if (r.MinLineWidth && !out.track) out.track = +r.MinLineWidth; });
    return out;
  }

  /* Сопоставление значений из файла со списками */
  function nearest(sel, v) {
    var best = null, d = Infinity;
    if (typeof v !== 'number' || !isFinite(v)) return null;
    [].forEach.call(sel.options, function (o) { var dd = Math.abs(parseFloat(o.value) - v); if (dd < d) { d = dd; best = o; } });
    return best;
  }
  var finishMap = [[/enepig/i, 'enepig'], [/enig/i, 'enig'], [/hard|^G$/i, 'hard'], [/flash|^GS$/i, 'flash'], [/silver|^I?Ag$/i, 'iag'], [/hal|hasl|^S$|^T$|^X$/i, 'hasl']];
  var colorMap = { green: 'green', black: 'black', blue: 'blue', red: 'red', white: 'white', yellow: 'yellow' };
  function matMap(s) { s = String(s).toLowerCase(); if (/fr-?4/.test(s)) return /tg/.test(s) ? 'fr4tg' : 'fr4'; if (/poly|pi\b/.test(s)) return 'pi'; if (/alu/.test(s)) return 'alu'; if (/rogers|ptfe|ro\d/.test(s)) return 'hf'; return null; }

  var gerberPads = 0, gerberBottom = 0;

  /* ================= Расчёт (ДЕМО-ставки) ================= */
  var R = {
    prepPcb: 4500, prepPerLayer: 900,
    dm2: { 1: 380, 2: 520, 4: 1150, 6: 1750, 8: 2350, 10: 3000, 12: 3650, 14: 4300, 16: 5000, 18: 5800, 20: 6600, 22: 7400, 24: 8200 },
    thick: { 0.4: 1.15, 0.6: 1.08, 0.8: 1.03, 1.0: 1, 1.2: 1, 1.6: 1, 2.0: 1.08, 2.4: 1.15, 3.2: 1.3 },
    cu: { 18: 0.97, 35: 1, 70: 1.25, 105: 1.5 },
    finish: { hasl: 1, enig: 1.25, iag: 1.18, enepig: 1.4, flash: 1.3, hard: 1.6, other: 1.2 },
    mat: { fr4: 1, fr4tg: 1.2, alu: 1.35 },
    mask: { green: 1, black: 1.03, blue: 1.03, red: 1.03, white: 1.05, yellow: 1.05, other: 1.05 },
    asmSetupSide: 9000, feeder: 180, joint: { chip: 0.9, fine: 1.4, qfn: 2.2, bga: 3.2 }, tht: 6, conn: 45, fw: 60, coat: 0.6, axi: 6000
  };
  function feeders(n) { return n ? Math.min(n, Math.round(6 + n * 0.2)) : 0; } // оценка числа уникальных позиций
  function volumeK(area) { if (area < 2) return 1.6; if (area < 10) return 1.25; if (area < 50) return 1; if (area < 200) return 0.85; return 0.72; }

  function compute() {
    var qty = num('qty'), L = num('len'), W = num('wid');
    var res = { ok: false, lines: [], warn: [], engineer: false };
    if (!(qty > 0) || !(L > 0) || !(W > 0)) { res.reason = 'Укажите тираж и габариты платы, чтобы увидеть оценку.'; return res; }
    var empty = ['thick', 'cuout', 'mat', 'finish', 'mask', 'silk'].filter(function (k) { return !val(k); });
    if (parseInt(val('layers'), 10) >= 4 && !val('cuin')) empty.push('cuin');
    if (empty.length) { res.reason = 'Заполните отмеченные поля — после этого покажем оценку.'; return res; }
    var layers = parseInt(val('layers'), 10);
    var area = L * W / 10000 * qty; // дм² на партию
    var mat = val('mat');
    if (mat === 'pi') { res.engineer = true; res.warn.push('полиимидное основание'); }
    if (mat === 'hf') { res.engineer = true; res.warn.push('ВЧ / СВЧ-материал'); }
    if (layers > 16) { res.engineer = true; res.warn.push('более 16 слоёв'); }
    if (chk('imp')) { res.engineer = true; res.warn.push('контроль импеданса'); }
    if (chk('blind')) { res.engineer = true; res.warn.push('слепые / скрытые отверстия'); }
    if (chk('vip')) { res.engineer = true; res.warn.push('via-in-pad'); }
    if (chk('gold')) { res.engineer = true; res.warn.push('золочёные ламели'); }
    if (val('finish') === 'other') { res.engineer = true; res.warn.push('нестандартное покрытие'); }
    var t = num('track'), h = num('hole');
    if (t > 0 && t < 0.1) { res.engineer = true; res.warn.push('дорожка ' + fmtMM(t) + ' мм — тоньше стандартной технологии'); }
    if (h > 0 && h < 0.2) { res.engineer = true; res.warn.push('отверстие ' + fmtMM(h) + ' мм — микроотверстия'); }
    if (asmMode() === 'detail' && num('d_0201') > 0) { res.engineer = true; res.warn.push('чип-компоненты 0201 — по согласованию'); }

    var prep = R.prepPcb + R.prepPerLayer * Math.max(0, layers - 2);
    var k = (R.thick[parseFloat(val('thick'))] || 1) * (R.cu[val('cuout')] || 1) * (R.finish[val('finish')] || 1) * (R.mat[mat] || 1) * (R.mask[val('mask')] || 1);
    if (layers >= 4) k *= val('cuin') === '70' ? 1.15 : 1;
    if (parseInt(val('sides'), 10) === 2) k *= 1.03; // маска и маркировка с двух сторон
    var pcb = area * (R.dm2[layers] || 1000) * k * volumeK(area);
    res.lines.push({ name: 'Подготовка производства', v: prep });
    res.lines.push({ name: 'Производство плат', v: pcb });

    var m = asmMode(), asm = 0, axi = false, sides = parseInt(val('sides'), 10) || 1, comps = 0;
    if (m === 'quick') {
      var smd = num('q_smd') || 0, dip = num('q_dip') || 0, bga = num('q_bga') || 0, qfn = num('q_qfn') || 0;
      axi = bga + qfn > 0;
      comps = smd + bga + qfn + Math.ceil(dip / 4);
      var perBoard = smd * 2.4 * R.joint.chip + qfn * 40 * R.joint.qfn + bga * 200 * R.joint.bga + dip * R.tht;
      asm = R.asmSetupSide * sides + feeders(comps) * R.feeder + perBoard * qty;
    } else if (m === 'detail') {
      var j = 0;
      j += ((num('d_0201') || 0) * 2) * R.joint.chip * 1.4;
      j += ((num('d_0402') || 0) * 2 + (num('d_0603') || 0) * 2 + (num('d_sot') || 0) * 3) * R.joint.chip;
      j += (num('d_soic') || 0) * (num('p_soic') || 0) * R.joint.chip;
      j += (num('d_qfp') || 0) * (num('p_qfp') || 0) * R.joint.fine;
      j += (num('d_qfn') || 0) * (num('p_qfn') || 0) * R.joint.qfn;
      j += (num('d_bga') || 0) * (num('p_bga') || 0) * R.joint.bga;
      j += (num('d_tht') || 0) * (num('p_tht') || 0) * R.tht;
      j += (num('d_conn') || 0) * R.conn;
      axi = (num('d_bga') || 0) > 0 || (num('d_qfn') || 0) > 0;
      comps = ['d_0201', 'd_0402', 'd_0603', 'd_sot', 'd_soic', 'd_qfp', 'd_qfn', 'd_bga', 'd_tht', 'd_conn'].reduce(function (s, k) { return s + (num(k) || 0); }, 0);
      asm = R.asmSetupSide * sides + feeders(comps) * R.feeder + j * qty;
    }
    var asmEmpty = (m === 'quick' && !['q_smd', 'q_dip', 'q_bga', 'q_qfn'].some(function (k) { return num(k) > 0; })) ||
      (m === 'detail' && !$$('.cx-table input[type="number"]').some(function (i) { return parseFloat(i.value) > 0 && /^d_/.test(i.dataset.key); }));
    if (m !== 'none' && asmEmpty) {
      res.lines.push({ name: 'Монтаж', text: 'заполните блок 3', soft: true });
      res.lines.push({ name: 'Компоненты', text: 'по BOM', soft: true });
      asm = 0; axi = false;
    } else if (m !== 'none') {
      res.lines.push({ name: 'Монтаж' + (sides === 2 ? ', 2 стороны' : ''), v: asm });
      if (axi) res.lines.push({ name: 'Рентген-контроль BGA / QFN', v: R.axi });
      res.lines.push({ name: 'Компоненты', text: 'по BOM', soft: true });
    }
    res.lines.push({ name: 'Проверка по ПМИ', text: 'входит', incl: true });
    res.lines.push({ name: 'Приёмка ОТК на фабрике', text: 'входит', incl: true });
    var goods = res.lines.reduce(function (a, l) { return a + (l.v || 0); }, 0);
    var ship = goods * 0.1 + 3500;
    res.lines.push({ name: 'Доставка и таможня', v: ship });
    res.total = goods + ship;
    res.notes = [];
    if (finishPending) res.notes.push('Финишное покрытие не уточнено — в расчёте HASL.');
    res.lo = res.total * 0.85; res.hi = res.total * 1.2;
    res.per = res.total / qty;
    res.term = res.engineer ? '2–3 месяца от размещения заказа' : 'около 6–8 недель от размещения заказа';
    res.ok = true;
    res.qty = qty;
    return res;
  }

  /* ================= Итог ================= */
  var S = { total: $('#calc-total'), mini: $('#calc-total-mini'), per: $('#calc-per'), state: $('#calc-state'), br: $('#calc-break'), warn: $('#calc-warn'), term: $('#calc-term') };
  var lastRes = null;
  function recalc() {
    var r = compute(); lastRes = r;
    S.br.innerHTML = ''; S.warn.hidden = true; S.per.textContent = ''; S.state.textContent = ''; S.term.innerHTML = '';
    S.total.classList.remove('is-muted');
    if (!r.ok) {
      S.total.textContent = 'Нужны данные'; S.total.classList.add('is-muted'); S.mini.textContent = '—';
      S.state.textContent = r.reason; renderSummaryText(r); return;
    }
    if (r.engineer) {
      S.total.textContent = 'Расчёт инженером'; S.total.classList.add('is-muted'); S.mini.textContent = 'расчёт инженером';
      S.state.textContent = 'Для выбранных параметров автоматическая оценка была бы неточной — рассчитаем вручную по вашей заявке.';
    } else {
      S.total.textContent = fmt(r.lo) + ' – ' + fmtR(r.hi);
      S.mini.textContent = 'от ' + fmtR(r.lo);
      S.per.textContent = '≈ ' + fmtR(r.per) + ' за плату · ' + fmt(r.qty) + ' шт';
    }
    r.lines.forEach(function (l) {
      var li = document.createElement('li');
      if (l.soft) li.className = 'is-soft'; if (l.incl) li.className = 'is-incl';
      li.innerHTML = '<span></span><span></span>';
      li.children[0].textContent = l.name;
      li.children[1].textContent = l.text ? l.text : (r.engineer ? '—' : '≈ ' + fmtR(l.v));
      S.br.appendChild(li);
    });
    if (r.warn.length || (r.notes && r.notes.length)) {
      S.warn.hidden = false; S.warn.innerHTML = '';
      if (r.warn.length) {
        var bb = document.createElement('b'); bb.textContent = 'Требует расчёта инженером:'; S.warn.appendChild(bb);
        var ul = document.createElement('ul');
        r.warn.forEach(function (w) { var li = document.createElement('li'); li.textContent = w; ul.appendChild(li); });
        S.warn.appendChild(ul);
      }
      (r.notes || []).forEach(function (n) { var p = document.createElement('p'); p.textContent = n; S.warn.appendChild(p); });
    }
    S.term.innerHTML = 'Срок: <b>' + r.term + '</b>';
    renderSummaryText(r);
  }

  function selText(key) { var e = el(key); return e && e.selectedOptions ? e.selectedOptions[0].textContent : ''; }
  /* Текстовая сводка всех заполненных полей — для формы заявки (кнопка «Прикрепить данные из калькулятора»). */
  function buildSummary(r) {
    var L = [], lc = function (t) { return t ? t.charAt(0).toLowerCase() + t.slice(1) : t; };
    var has = function (k) { return String(val(k)).trim() !== ''; };
    var dec = function (k) { return String(val(k)).trim().replace('.', ','); };
    // плата
    L.push('Тираж: ' + (val('qty') || '—') + ' шт');
    L.push('Габариты: ' + (dec('len') || '—') + ' × ' + (dec('wid') || '—') + ' мм');
    L.push('Слоёв: ' + val('layers') + (has('thick') ? ', толщина ' + selText('thick') + ' мм' : ''));
    var cu = [];
    if (has('cuout')) cu.push('наружные слои ' + val('cuout') + ' мкм');
    if (!el('cuin').disabled && has('cuin')) cu.push('внутренние ' + val('cuin') + ' мкм');
    if (cu.length) L.push('Медь: ' + cu.join(', '));
    if (has('mat')) L.push('Материал: ' + selText('mat'));
    L.push('Финишное покрытие: ' + (finishPending ? 'не уточнено (в расчёте HASL)' : selText('finish')));
    var ms = [];
    if (has('mask')) ms.push('маска ' + lc(selText('mask')));
    if (has('silk')) ms.push('маркировка ' + lc(selText('silk')));
    if (ms.length) L.push('Цвет: ' + ms.join(', '));
    var ex = [];
    if (has('track')) ex.push('мин. ширина дорожки ' + dec('track') + ' мм');
    if (has('hole')) ex.push('мин. отверстие ' + dec('hole') + ' мм');
    if (ex.length) L.push('Топология: ' + ex.join(', '));
    L.push('Компоненты: ' + lc(selText('sides')) + '; поставка: ' + lc(selText('panel')));
    var req = $$('.cx-checks input:checked').map(function (i) { return lc(i.parentNode.textContent.trim()); });
    if (req.length) L.push('Особые требования: ' + req.join(', '));
    // монтаж
    var m = asmMode();
    if (m === 'none') L.push('Монтаж: без монтажа');
    else if (m === 'quick') {
      L.push('Монтаж (быстрая оценка): простые SMD — ' + (val('q_smd') || 0) + ', DIP-выводов — ' + (val('q_dip') || 0) +
        ', BGA — ' + (val('q_bga') || 0) + ', QFN/QFP и др. многовыводные — ' + (val('q_qfn') || 0));
    } else {
      var rows = $$('.cx-table tbody tr').map(function (tr) {
        var ins = tr.querySelectorAll('input'), n = ins[0] ? +ins[0].value || 0 : 0;
        if (!n) return null;
        var pins = ins[1] ? +ins[1].value || 0 : 0;
        return tr.querySelector('th').textContent.trim() + ' — ' + n + ' шт' + (pins ? ' × ' + pins + ' выв.' : '');
      }).filter(Boolean);
      L.push('Монтаж (подробная оценка)' + (rows.length ? ':' : ': корпуса не указаны'));
      rows.forEach(function (t) { L.push('  · ' + t); });
    }
    if (m !== 'none') L.push('Проверка по ПМИ: входит в заказ');
    // оценка
    if (r && r.ok) {
      L.push('Оценка калькулятора: ' + (r.engineer ? 'расчёт инженером' : fmt(r.lo) + ' – ' + fmtR(r.hi) + ' (≈ ' + fmtR(r.per) + ' за плату)'));
      if (!r.engineer && r.lines && r.lines.length) L.push('Из чего складывается: ' + r.lines.map(function (l) { return l.name + ' ' + (l.text || '≈ ' + fmtR(l.v)); }).join('; '));
      if (r.warn && r.warn.length) L.push('Требует расчёта инженером: ' + r.warn.join(', '));
      if (r.term) L.push('Срок: ' + r.term);
    } else if (r && r.reason) L.push('Оценка калькулятора: нет — ' + lc(r.reason));
    var okFiles = store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; });
    if (okFiles.length) L.push('Файлы проекта: ' + okFiles.join(', ') + ' — приложены к заявке');
    return L.join('\n');
  }
  function renderSummaryText(r) {
    summaryText = buildSummary(r);
    var out = $('#calc-summary-text'); if (out) out.textContent = summaryText;
  }
  var summaryText = '';

  /* Для формы заявки (assets/js/request-form.js): сводка и загруженные файлы. */
  window.PCBCalc = {
    getSummary: function () { return buildSummary(lastRes || compute()); },
    /* структурированные данные расчёта — для аналитики заявок (/panel) */
    getData: function () {
      var r = lastRes || compute(), d = {};
      ['qty', 'len', 'wid', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel'].forEach(function (k) { d[k] = val(k); });
      if (el('cuin').disabled) delete d.cuin;
      d.special = $$('.cx-checks input:checked').map(function (i) { return i.dataset.key; });
      d.asm = asmMode();
      d.asm_values = {};
      $$('[data-key^="q_"], [data-key^="d_"], [data-key^="p_"]').forEach(function (i) { if (+i.value) d.asm_values[i.dataset.key] = +i.value; });
      d.finish_pending = finishPending;
      if (r && r.ok) { d.estimate = r.engineer ? 'engineer' : { lo: Math.round(r.lo), hi: Math.round(r.hi), per: Math.round(r.per) }; d.term = r.term; d.warn = r.warn; }
      d.files = store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; });
      return d;
    },
    getFiles: function () { return store.filter(function (it) { return it.status === 'ok' && it.file; }).map(function (it) { return it.file; }); }
  };

  /* поля формы заявки (этап 4, #request) на расчёт не влияют */
  function isCalcField(t) { return t.matches('input,select') && !t.closest('#request'); }
  root.addEventListener('input', function (e) { if (isCalcField(e.target)) recalc(); });
  root.addEventListener('change', function (e) { if (isCalcField(e.target)) recalc(); });

  $('#calc-cta').addEventListener('click', function () {
    $('#request').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    sumBox.classList.remove('is-open'); $('#calc-summary-toggle').setAttribute('aria-expanded', 'false');
  });
  var sumBox = $('#calc-summary');
  $('#calc-summary-toggle').addEventListener('click', function () {
    var o = sumBox.classList.toggle('is-open'); this.setAttribute('aria-expanded', o ? 'true' : 'false');
  });

  asmInit(); syncInner(); syncAsm(); recalc();

})();

