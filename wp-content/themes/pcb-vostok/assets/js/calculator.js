/**
 * Онлайн-калькулятор (страница /calculator, _gutenberg/pages/709-calculator.html).
 *
 * — Разбор файлов проекта прямо в браузере: IPC-2581 (.xml, .cvg), ODB++ (.tgz, .tar, .zip, папка odb/ в архиве),
 *   файл требований к плате (.txt),
 *   ZIP с Gerber и сверловкой, .gbrjob. Файлы на сервер не отправляются.
 *   Распаковка ZIP — assets/js/vendor/jszip.min.js (подключается раньше);
 *   RAR и 7z — assets/js/vendor/libarchive/ (WASM, ~1 МБ, загружается только при первом таком архиве).
 * — Слияние источников: IPC-2581 → .gbrjob → Gerber, расхождения помечаются.
 * — Оценка монтажа: быстрая и подробная, автоподстановка из IPC-2581.
 * — Итоговая панель и данные для формы заявки (window.PCBCalc → assets/js/request-form.js).
 *   Об изменении расчёта калькулятор сообщает событием document 'pcb:calc' (detail: { pending }) —
 *   по нему форма показывает «оценка считается» и замечает, что расчёт изменился после прикрепления.
 * — Восстановление расчёта из заявки: кнопка «Показать в калькуляторе» в /panel (плагин pcb-panel)
 *   кладёт данные в localStorage и открывает страницу с #pcb-restore — см. restoreFromPanel().
 * — Черновик: поля и файлы калькулятора сохраняются в браузере (window.PCBDraft, form-draft.js, запись 'calc')
 *   и восстанавливаются при возврате на страницу — см. restoreDraft(). Пока идёт восстановление,
 *   PCBCalc.isRestoring() === true: форма заявки не сравнивает прикреплённый расчёт с текущим.
 *
 * ВНИМАНИЕ: ставки в объекте R — демонстрационные, формула цены ещё не утверждена.
 */
(function () {
  'use strict';
  // папка скрипта: рядом лежит vendor/libarchive (RAR и 7z); путь можно задать в window.PCBCalcLib.archive
  var JS_DIR = (function () { var s = document.currentScript; return s && s.src ? s.src.replace(/[?#].*$/, '').replace(/[^\/]*$/, '') : ''; })();
  var root = document.getElementById('calculator');
  if (!root) return;
  var $ = function (s, c) { return (c || root).querySelector(s); };
  var $$ = function (s, c) { return [].slice.call((c || root).querySelectorAll(s)); };

  /* ================= Поля ================= */
  function el(key) { return $('[data-key="' + key + '"]'); }
  function num(key) { var e = el(key); if (!e) return 0; var v = parseFloat(String(e.value).replace(',', '.')); return isFinite(v) ? v : NaN; }
  function val(key) { var e = el(key); return e ? e.value : ''; }
  // Габариты, дорожка, отверстие — текстовые поля с запятой (как везде на сайте); в данные уходит точка
  var DEC = ['len', 'wid', 'track', 'hole'];
  function decIn(v) { return v === '' || v == null ? '' : String(v).replace('.', ','); }
  function decOut(v) { return String(v == null ? '' : v).trim().replace(',', '.'); }
  DEC.forEach(function (k) {
    var e = el(k); if (!e) return;
    e.addEventListener('input', function () { var v = e.value.replace('.', ',').replace(/[^\d,]/g, ''), p = v.indexOf(','); if (p >= 0) v = v.slice(0, p + 1) + v.slice(p + 1).replace(/,/g, ''); if (v !== e.value) e.value = v; });
  });
  function chk(key) { var e = el(key); return !!(e && e.checked); }
  var NF = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
  function fmt(n) { return NF.format(Math.round(n)); }
  function fmtR(n) { return fmt(n) + ' ₽'; }
  function fmtMM(n) { return (Math.round(n * 100) / 100).toString().replace('.', ','); }

  /* Бейджи и состояния полей: ok | need | err | manual */
  var badgeMap = { qty: 'f-qty', size: 'f-len', layers: 'f-layers', thick: 'f-thick', cuout: 'f-cuout', cuin: 'f-cuin', mat: 'f-mat', finish: 'f-finish', mask: 'f-mask', silk: 'f-silk', track: 'f-track', hole: 'f-hole', q_smd: 'q-smd', q_dip: 'q-dip', q_bga: 'q-bga', q_qfn: 'q-qfn', sides: 'f-sides' };
  var badgeText = { ok: '', need: 'заполните', err: 'ошибка', manual: 'вручную', conflict: 'расхождение', check: 'проверьте' };
  function shortSrc(src) {
    if (!src) return 'из файла';
    if (/оценка/.test(src)) return 'оценка';
    if (/Gerber|сверловки|апертур|слоя пасты|контура/.test(src)) return 'из Gerber';
    return 'из ' + src;
  }
  var flashQ = [], flashT = 0, switching = false;
  function flashLater(w) {
    flashQ.push(w);
    if (flashT) return;
    flashT = requestAnimationFrame(function () {
      var q = flashQ; flashQ = []; flashT = 0;
      q.forEach(function (x) { x.classList.remove('is-flash'); });
      void root.offsetWidth; // одна перерисовка на всю пачку
      q.forEach(function (x) { x.classList.add('is-flash'); });
      setTimeout(function () { q.forEach(function (x) { x.classList.remove('is-flash'); }); }, 1700);
    });
  }
  function setBadge(field, state, src, text) {
    var b = $('.cx-badge[data-for="' + field + '"]'); if (!b) return;
    var wrap = b.closest('.cx-field');
    if (state !== 'check' && state !== 'conflict') fieldNote(field, '');
    wrap.classList.remove('is-need', 'is-err', 'is-conflict');
    if (!state) { b.textContent = ''; b.removeAttribute('data-state'); return; }
    b.dataset.state = state;
    b.textContent = state === 'ok' ? shortSrc(src) : badgeText[state];
    if (state === 'need') {
      wrap.classList.add('is-need');
      var sel = wrap.querySelector('select');
      if (sel && sel.querySelector('option[value=""]')) sel.value = '';
      else if (sel) b.textContent = 'уточните';
    }
    if (state === 'ok' && !silent && !switching) flashLater(wrap);
    if (state === 'check') wrap.classList.add('is-need'); // значение подставлено, но его стоит проверить
    if (text) b.textContent = text;
    if (state === 'err') wrap.classList.add('is-err');
    if (state === 'conflict') wrap.classList.add('is-conflict');
  }
  var asmKeys = ['q_smd', 'q_dip', 'q_bga', 'q_qfn'];
  // Пояснение под полем: «В ТЗ 1,5 мм — в списке нет, считаем по 1,6 мм.» Ряд полей выровнен по верху (calculator.css)
  function fieldNote(field, msg) {
    var b = $('.cx-badge[data-for="' + field + '"]'); if (!b) return;
    var wrap = b.closest('.cx-field'), n = wrap.querySelector('.cx-fnote');
    if (!msg) { if (n) n.hidden = true; return; }
    if (!n) { n = document.createElement('p'); n.className = 'cx-fnote'; wrap.appendChild(n); }
    n.textContent = msg; n.hidden = false;
  }
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
  var store = [], seq = 0, busyCount = 0, restoreApply = null;
  var used = false; // клиент заполнял калькулятор или загружал файлы (не просто открыл страницу)
  var restoring = true; // до проверки черновика (restoreDraft) — расчёт ещё не окончательный
  function kb(n) { return n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' КБ' : (n / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }
  function plural(n, a, b, c) { var m = n % 10, h = n % 100; return m === 1 && h !== 11 ? a : (m >= 2 && m <= 4 && (h < 10 || h >= 20) ? b : c); }
  function readText(f) { return f.text ? f.text() : new Response(f).text(); }
  function isIPC(t) { return /<IPC-2581[\s>]/.test(t.slice(0, 4000)); }

  drop.addEventListener('click', function () { fileIn.click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) {
    var dt = e.dataTransfer; if (!dt) return;
    var ents = [].slice.call(dt.items || []).map(function (i) { return i.webkitGetAsEntry ? i.webkitGetAsEntry() : null; });
    if (!ents.some(function (x) { return x && x.isDirectory; })) { if (dt.files.length) handleFiles([].slice.call(dt.files)); return; }
    // папки упаковываем в ZIP прямо в браузере — дальше они разбираются как обычный архив
    var plain = [].slice.call(dt.files).filter(function (f, i) { return !(ents[i] && ents[i].isDirectory); });
    Promise.all(ents.filter(function (x) { return x && x.isDirectory; }).map(folderToZip)).then(function (zips) {
      handleFiles(plain.concat(zips.filter(Boolean)));
    });
  });
  // Папка (FileSystemDirectoryEntry) → File «имя.zip» без бинарных файлов; до 3000 файлов и 8 уровней вложенности
  function folderToZip(dir) {
    if (typeof JSZip === 'undefined') return Promise.resolve(null);
    var z = new JSZip(), count = 0;
    function walk(d, path, depth) {
      return new Promise(function (ok) {
        var rd = d.createReader(), all = [];
        (function next() {
          rd.readEntries(function (part) { if (part.length) { all = all.concat(part); next(); } else ok(all); }, function () { ok(all); });
        })();
      }).then(function (list) {
        return Promise.all(list.map(function (en) {
          if (count > 3000) return null;
          if (en.isDirectory) return depth < 8 ? walk(en, path + en.name + '/', depth + 1) : null;
          if (BIN.test(en.name)) return null;
          return new Promise(function (ok) { en.file(function (f) { count++; z.file(path + en.name, f); ok(); }, function () { ok(); }); });
        }));
      });
    }
    return walk(dir, dir.name + '/', 0).then(function () {
      if (!count) return null;
      return z.generateAsync({ type: 'blob' }).then(function (b) { return new File([b], dir.name + '.zip', { type: 'application/zip' }); });
    }).catch(function () { return null; });
  }
  fileIn.addEventListener('change', function () { handleFiles([].slice.call(fileIn.files)); fileIn.value = ''; });

  /* шаги для /panel/analytics — обрабатывает site-goals.js */
  function track(d) { try { document.dispatchEvent(new CustomEvent('pcb:track', { detail: d })); } catch (e) { /* без статистики */ } }
  function errCode(it) { return it.kind === 'zip' ? 'zip' : it.kind === 'tar' ? 'tar' : it.kind === 'rar' || it.kind === '7z' ? 'rar' : /IPC/.test(it.note) ? 'ipc' : 'format'; }
  function isArc(it) { return /^(zip|tar|rar|7z)$/.test(it.kind); }

  function handleFiles(files, quiet) {
    if (!files.length) return;
    if (!quiet) { used = true; track({ goal: 'calc_upload' }); hideToast(); openAfterParse = true; } // quiet — файлы из черновика, не новая загрузка
    files.forEach(function (f) {
      store = store.filter(function (it) { return it.name !== f.name; }); // тот же файл — заменяем
      var it = { id: ++seq, file: f, name: f.name, size: f.size, kind: '?', status: 'busy', note: '', entries: [], ipc: [], odb: [], spec: [] };
      store.push(it);
      busyCount++;
      readItem(f, it).then(function () {
        if (it.status === 'err') track({ ev: [['calc_err', errCode(it)]] });
        busyCount--; renderFiles(); if (!busyCount) setTimeout(function () { safeAnalyse(); if (restoreApply) { var f = restoreApply; restoreApply = null; f(); } openAttention(); }, 350);
      });
    });
    renderFiles();
    emit(); // файлы разбираются — форма покажет «считается»
  }

  /* --- Архивы: ZIP (JSZip), .tgz / .tar.gz / .tar (штатный DecompressionStream), RAR и 7z (libarchive), вложенные --- */
  // Что заведомо не текст — не распаковываем и не читаем (PDF, таблицы, исходники САПР, картинки, 3D)
  var BIN = /\.(pdf|xlsx?|xlsm|docx?|pptx?|odt|ods|pcbdoc|schdoc|prjpcb|pcblib|schlib|intlib|brd|dsn|sch|kicad_pcb|kicad_sch|kicad_pro|step|stp|igs|iges|wrl|stl|dwg|dxf|png|jpe?g|gif|bmp|tiff?|svg|webp|exe|dll|bin|mp4|mov|z)$/i;
  var ARCH = /\.(zip|tgz|tar\.gz|tar|rar|7z)$/i;
  /* RAR и 7z — libarchive.js (WASM в отдельном потоке). Модуль ES подключаем через <script type="module">,
     чтобы сам calculator.js оставался обычным скриптом и не ломался в старых браузерах. */
  var arcLib = null;
  function loadArc() {
    if (arcLib) return arcLib;
    var base = (window.PCBCalcLib && window.PCBCalcLib.archive) || JS_DIR + 'vendor/libarchive/';
    try { base = new URL(base, location.href).href; } catch (e) { /* старый браузер — модулей всё равно нет */ }
    arcLib = new Promise(function (ok, no) {
      var id = '__pcbArc' + Date.now(), t = setTimeout(function () { delete window[id]; no(new Error('arclib')); }, 30000);
      window[id] = function (A) { clearTimeout(t); delete window[id]; if (A) ok(A); else no(new Error('arclib')); };
      var sc = document.createElement('script'); sc.type = 'module';
      sc.textContent = 'import(' + JSON.stringify(base + 'libarchive.js') + ').then(function (m) { window.' + id + '(m.Archive); }, function () { window.' + id + '(null); });';
      document.head.appendChild(sc);
    });
    arcLib.catch(function () { arcLib = null; }); // следующая попытка — заново
    return arcLib;
  }
  function unarc(name, u8, it) {
    return loadArc().then(function (A) {
      return A.open(new File([u8], name.split('/').pop())).then(function (arc) {
        var done = function (x) { try { arc.close(); } catch (e) { /* поток уже закрыт */ } return x; };
        return arc.hasEncryptedData().then(function (enc) {
          if (enc) throw new Error('enc');
          return arc.extractFiles();
        }).then(function (tree) {
          var files = [];
          (function walk(o, p) { Object.keys(o).forEach(function (k) { var v = o[k]; if (v instanceof Blob) files.push({ path: p + k, f: v }); else if (v && typeof v === 'object') walk(v, p + k + '/'); }); })(tree, '');
          return Promise.all(files.map(function (x) {
            if (BIN.test(x.path)) { it.skipped = (it.skipped || 0) + 1; return null; }
            return x.f.arrayBuffer().then(function (b) { return { path: x.path, data: new Uint8Array(b) }; });
          }));
        }).then(function (l) { return done(l.filter(Boolean)); }, function (e) { done(); throw e; });
      });
    });
  }
  function u8text(u8) {
    try { return new TextDecoder('utf-8').decode(u8); } catch (e) { var s = ''; for (var i = 0; i < u8.length; i += 8192) s += String.fromCharCode.apply(null, u8.subarray(i, i + 8192)); return s; }
  }
  function gunzip(u8) {
    if (typeof DecompressionStream === 'undefined') return Promise.reject(new Error('gzip'));
    return new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer().then(function (b) { return new Uint8Array(b); });
  }
  function untar(u8) { // ustar / GNU tar: заголовки по 512 байт
    var out = [], o = 0, longName = null, str = function (a, b) { var s = ''; for (var i = a; i < b && u8[i]; i++) s += String.fromCharCode(u8[i]); return s; };
    while (o + 512 <= u8.length && u8[o]) {
      var name = str(o, o + 100), size = parseInt(str(o + 124, o + 136).trim(), 8) || 0, type = String.fromCharCode(u8[o + 156] || 48), pre = str(o + 345, o + 500);
      var path = longName || ((pre ? pre + '/' : '') + name); longName = null;
      var data = u8.subarray(o + 512, o + 512 + size);
      if (type === 'L') longName = u8text(data).replace(/\0+$/, '');
      else if (type === '0' || type === '\0' || type === '7') out.push({ path: path, data: data });
      o += 512 + Math.ceil(size / 512) * 512;
    }
    if (!out.length && u8.length > 512) throw new Error('tar');
    return out;
  }
  // Раскрыть архив в список { path, data } — с вложенными архивами (до двух уровней)
  function expand(name, u8, depth, it) {
    var n = name.toLowerCase();
    var sub = function (list) {
      return Promise.all(list.map(function (e) {
        if (ARCH.test(e.path) && depth < 2) return expand(e.path, e.data, depth + 1, it).catch(function () { it.skipped = (it.skipped || 0) + 1; return []; });
        return [e];
      })).then(function (r) { return [].concat.apply([], r); });
    };
    if (/\.zip$/.test(n)) {
      if (typeof JSZip === 'undefined') return Promise.reject(new Error('jszip'));
      return JSZip.loadAsync(u8).then(function (z) {
        var ps = [];
        z.forEach(function (path, zf) {
          if (zf.dir) return;
          if (BIN.test(path)) { it.skipped = (it.skipped || 0) + 1; return; }
          ps.push(zf.async('uint8array').then(function (d) { return { path: path, data: d }; }));
        });
        return Promise.all(ps).then(sub);
      });
    }
    if (/\.(tgz|tar\.gz)$/.test(n)) return gunzip(u8).then(function (d) { return sub(untar(d).filter(function (e) { return !BIN.test(e.path); })); });
    if (/\.tar$/.test(n)) return Promise.resolve().then(function () { return sub(untar(u8).filter(function (e) { return !BIN.test(e.path); })); });
    if (/\.(rar|7z)$/.test(n)) return unarc(name, u8, it).then(sub);
    return Promise.resolve([{ path: name, data: u8 }]);
  }
  // Разложить файлы архива: ODB++ (папка с matrix/matrix), IPC-2581, файл требований, остальное — Gerber/сверловка
  function sortEntries(list, it) {
    var roots = [];
    list.forEach(function (e) { var m = /^(.*?)(?:^|\/)matrix\/matrix$/i.exec(e.path.replace(/\\/g, '/')); if (m) roots.push(m[1] ? m[1] + '/' : ''); });
    var odb = roots.map(function (r) { return { name: r.replace(/\/$/, '').split('/').pop() || it.name, root: r, files: {} }; });
    list.forEach(function (e) {
      var p = e.path.replace(/\\/g, '/');
      for (var i = 0; i < odb.length; i++) {
        if (p.indexOf(odb[i].root) === 0 && /(^|\/)(matrix|misc|steps)\//i.test(p.slice(odb[i].root.length))) {
          odb[i].files[p.slice(odb[i].root.length).toLowerCase()] = u8text(e.data); return;
        }
      }
      var nm = p.split('/').pop(), t = u8text(e.data);
      if (/\.(xml|cvg)$/i.test(nm) && isIPC(t)) it.ipc.push({ name: nm, text: t });
      else if (/\.(txt|md)$/i.test(nm) && isSpec(t)) it.spec.push({ name: nm, text: t });
      else it.entries.push({ name: nm, text: t, path: p });
    });
    it.odb = it.odb.concat(odb);
  }

  function readItem(f, it) {
    var n = f.name.toLowerCase();
    if (ARCH.test(n)) {
      it.kind = /\.zip$/.test(n) ? 'zip' : /\.rar$/.test(n) ? 'rar' : /\.7z$/.test(n) ? '7z' : 'tar';
      return f.arrayBuffer().then(function (b) { return expand(f.name, new Uint8Array(b), 0, it); })
        .then(function (list) { sortEntries(list, it); it.status = 'ok'; })
        .catch(function (e) {
          it.status = 'err';
          it.note = e && e.message === 'jszip' ? 'не удалось загрузить модуль распаковки ZIP'
            : e && e.message === 'arclib' ? 'не удалось загрузить модуль распаковки RAR и 7z — обновите страницу или перепакуйте в ZIP'
            : e && e.message === 'enc' ? 'архив защищён паролем — перепакуйте без пароля'
            : e && e.message === 'gzip' ? 'браузер не распаковывает .tgz — обновите его или перепакуйте в ZIP'
            : it.kind === 'zip' ? 'архив повреждён или это не ZIP'
            : it.kind === 'rar' || it.kind === '7z' ? 'архив повреждён или это не ' + (it.kind === 'rar' ? 'RAR' : '7z')
            : 'архив повреждён или это не .tgz / .tar';
        });
    }
    if (/\.gbrjob$/.test(n)) { it.kind = 'job'; return readText(f).then(function (t) { it.entries.push({ name: f.name, text: t }); it.status = 'ok'; }); }
    if (/\.(xml|cvg)$/.test(n)) {
      it.kind = 'ipc';
      return readText(f).then(function (t) {
        if (isIPC(t)) { it.ipc.push({ name: f.name, text: t }); it.status = 'ok'; }
        else { it.kind = 'bad'; it.status = 'err'; it.note = 'это не файл IPC-2581'; }
      });
    }
    if (/\.txt$/.test(n)) { // требования к плате — или сверловка Altium (.TXT)
      return readText(f).then(function (t) {
        if (isSpec(t)) { it.kind = 'spec'; it.spec.push({ name: f.name, text: t }); it.status = 'ok'; }
        else if (/^M48/m.test(t)) { it.kind = 'drill'; it.entries.push({ name: f.name, text: t }); it.status = 'ok'; it.note = 'сверловка'; }
        else if (isPnP(t)) { it.kind = 'pnp'; it.entries.push({ name: f.name, text: t }); it.status = 'ok'; it.note = 'Pick&Place — компоненты и стороны'; }
        else { it.kind = 'bad'; it.status = 'err'; it.note = 'параметры платы в файле не найдены'; }
      });
    }
    if (BIN.test(n) || f.size > 30 * 1048576) {
      it.kind = 'bad'; it.status = 'err';
      it.note = BIN.test(n) ? 'не нужен для расчёта' : 'файл слишком большой';
      if (BIN.test(n)) { it.kind = 'other'; it.status = 'ok'; }
      return Promise.resolve();
    }
    // отдельный файл Gerber или сверловки — расширения у САПР любые, поэтому смотрим на содержимое
    return readText(f).then(function (t) {
      var c = classify({ name: f.name, text: t }), side = { Top: ', верх', Bot: ', низ', Inr: ', внутренний' }[c.side] || '';
      if (!side && /paste|mask|silk/.test(c.type)) side = /(^|[._-])f[._]|\.gt[a-z]$|top/i.test(f.name) ? ', верх' : /(^|[._-])b[._]|\.gb[a-z]$|bot/i.test(f.name) ? ', низ' : '';
      var role = { copper: 'медный слой' + side, outline: 'контур платы', paste: 'паста' + side, mask: 'маска' + side, silk: 'маркировка' + side, drill: 'сверловка', pnp: 'Pick&Place — компоненты и стороны', 'gerber?': 'Gerber, назначение слоя не определено' }[c.type];
      if (!role) { it.kind = 'other'; it.status = 'ok'; it.note = 'не нужен для расчёта'; return; } // отчёты САПР, апертуры и т. п.
      it.kind = c.type === 'drill' ? 'drill' : c.type === 'pnp' ? 'pnp' : 'gerber'; it.entries.push({ name: f.name, text: t }); it.status = 'ok'; it.note = role;
    });
  }

  var typeLabel = { zip: 'ZIP', tar: 'TGZ', rar: 'RAR', '7z': '7z', job: '.gbrjob', ipc: 'IPC-2581', spec: 'ТЗ', drill: 'NC Drill', gerber: 'Gerber', pnp: 'P&P', other: '—', bad: '—', '?': '…' };
  function renderFiles() {
    var box = $('#calc-files-box'), list = $('#calc-file-list');
    box.hidden = !store.length;
    drop.classList.toggle('is-compact', !!store.length);
    list.innerHTML = '';
    var others = store.filter(function (it) { return it.kind === 'other' && it.status === 'ok'; });
    var rows = others.length > 1 ? store.filter(function (it) { return others.indexOf(it) < 0; }) : store;
    // отдельные файлы Gerber и сверловки — тоже одной строкой, если их больше трёх
    var loose = store.filter(function (it) { return (it.kind === 'gerber' || it.kind === 'drill') && it.status === 'ok'; });
    if (loose.length > 3) {
      var cu = loose.filter(function (x) { return /^медный/.test(x.note); }).length, dr = loose.filter(function (x) { return x.kind === 'drill'; }).length;
      var gi = rows.indexOf(loose[0]);
      rows = rows.filter(function (it) { return loose.indexOf(it) < 0; });
      rows.splice(Math.max(0, gi), 0, { group: loose, kind: 'gerber', status: 'ok', ipc: [], odb: [],
        name: 'Gerber и сверловка: ' + loose.length + ' ' + plural(loose.length, 'файл', 'файла', 'файлов'),
        note: [cu ? 'медных слоёв ' + cu : '', loose.some(function (x) { return x.note === 'контур платы'; }) ? 'контур' : '', dr ? 'сверловка' + (dr > 1 ? ' (' + dr + ')' : '') : ''].filter(Boolean).join(', '),
        size: loose.reduce(function (s, x) { return s + (x.size || 0); }, 0) });
    }
    if (others.length > 1) rows = rows.concat([{ group: others, kind: 'other', status: 'ok', ipc: [], odb: [],
      name: 'Не нужны для расчёта: ' + others.length + ' ' + plural(others.length, 'файл', 'файла', 'файлов'),
      note: others.map(function (x) { return x.name; }).join(', '), size: others.reduce(function (s, x) { return s + (x.size || 0); }, 0) }]);
    rows.forEach(function (it) {
      var li = document.createElement('li');
      var tp = document.createElement('span');
      var exact = it.ipc.length ? 'IPC-2581' : it.odb.length ? 'ODB++' : '';
      tp.className = 'cx-ftype' + (exact ? ' cx-ftype--ipc' : '') + (it.status === 'err' ? ' cx-ftype--bad' : '');
      tp.textContent = isArc(it) && exact ? (it.kind === 'tar' && exact === 'ODB++' ? 'ODB++' : typeLabel[it.kind] + ' · ' + exact) : typeLabel[it.kind];
      var nm = document.createElement('span'); nm.className = 'cx-fname';
      var b = document.createElement('b'); b.textContent = it.name; nm.appendChild(b);
      var sub = document.createElement('span');
      if (it.status === 'busy') sub.innerHTML = '<span class="cx-spin cx-fspin"></span>Разбираем…';
      else { sub.textContent = it.note; if (it.status === 'err') sub.className = 'is-err'; }
      nm.appendChild(sub);
      var sz = document.createElement('span'); sz.className = 'cx-fsize'; sz.textContent = kb(it.size);
      var del = document.createElement('button'); del.type = 'button'; del.className = 'cx-fdel'; del.textContent = '×';
      del.setAttribute('aria-label', 'Удалить ' + it.name);
      del.addEventListener('click', function () {
        var gone = it.group || [it], snap = takeSnap();
        store = store.filter(function (x) { return gone.indexOf(x) < 0; }); renderFiles(); safeAnalyse(); saveSoon();
        toast((it.group ? 'Файлы убраны' : '«' + it.name + '» убран') + (store.length ? '' : ' — поля из файлов сброшены, ваши правки остались'), snap);
      });
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
  (function () {
    var btn = $('#calc-reset'), head = $('#calc-files-head'), box = $('#calc-files-box'), foot = document.createElement('div');
    foot.className = 'cx-files__foot'; box.appendChild(foot);
    var mq = window.matchMedia ? matchMedia('(max-width: 767px)') : null;
    var place = function () { if (mq && mq.matches) foot.appendChild(btn); else head.appendChild(btn); foot.hidden = !(mq && mq.matches); };
    place(); if (mq) { if (mq.addEventListener) mq.addEventListener('change', place); else if (mq.addListener) mq.addListener(place); }
  })();
  /* «Сбросить всё» — в шапке списка файлов, под итогом и в подсказке «?»: файлы, поля, платы комплекта, черновик.
     Отмена — «Вернуть» во всплывающей строке (10 с). */
  var DEF = null, DEF_ASM = 'quick';
  function takeDefaults() {
    DEF = {};
    $$('[data-key]').forEach(function (i) { if (i.closest('#request')) return; DEF[i.dataset.key] = i.type === 'checkbox' ? i.checked : i.value; });
    DEF_ASM = asmMode();
  }
  function resetKey(k) {
    (k === 'size' ? ['len', 'wid'] : [k]).forEach(function (key) { var e = el(key); if (e && DEF && key in DEF) { if (e.type === 'checkbox') e.checked = DEF[key]; else e.value = DEF[key]; } });
  }
  function takeSnap() {
    return { store: store.slice(), data: fieldData(), states: JSON.parse(JSON.stringify(bstate)), n: Object.assign({}, perSetMap), off: Object.assign({}, offMap),
      solo: solo, setQty: setQty, board: (boardsInfo.filter(function (b) { return b.current; })[0] || {}).name || null, demo: Object.assign({}, demoNames),
      used: used, asmFilled: asmFilled, asmEst: asmEst, approx: estKeys() };
  }
  function applySnap(s) {
    store = s.store; bstate = s.states; perSetMap = s.n; offMap = s.off; solo = s.solo; setQty = s.setQty; demoNames = s.demo; wantBoard = s.board; used = s.used;
    keepStates = true; renderFiles(); safeAnalyse(); keepStates = false;
    if (s.asmFilled && !asmFilled && (ipcAsm || gerberPads)) applyAsm();
    restoreValues(s.data); asmEst = s.asmEst; setEst(s.approx);
    updatePasteHint(); recalc(); saveSoon();
  }
  function resetAll() {
    var snap = takeSnap();
    store = []; bstate = {}; perSetMap = {}; offMap = {}; solo = false; setQty = ''; wantBoard = null; boardKey = null; demoNames = {};
    fromFiles = {}; reportShown = false;
    if (!DEF) takeDefaults();
    Object.keys(DEF).forEach(resetKey);
    var r = $('input[name="asm"][value="' + DEF_ASM + '"]'); if (r) r.checked = true;
    asmInit(); asmFilled = false; asmEst = false; syncApply();
    finishPending = false; $('#calc-finish-confirm').hidden = true;
    $$('.cx-restore--draft').forEach(function (b) { b.remove(); }); // «Восстановили ваш расчёт» больше не про то, что на экране
    renderFiles(); safeAnalyse(); clearBadges(); syncInner(); syncAsm();
    used = false; clearTimeout(saveTimer); saveTimer = 0; if (D) D.del('calc');
    root.classList.add('is-fresh');
    recalc();
    if (snap.store.length || snap.used) toast('Калькулятор сброшен', snap);
  }
  $('#calc-reset').addEventListener('click', resetAll);
  $$('[data-calc-reset]').forEach(function (b) { b.addEventListener('click', function () { if (help) help.close(); resetAll(); }); });

  /* Всплывающая строка с отменой: «Калькулятор сброшен · Вернуть» */
  var toastBox = null, toastT = 0;
  function toast(msg, snap) {
    if (!toastBox) {
      toastBox = document.createElement('div'); toastBox.className = 'cx-toast'; toastBox.setAttribute('role', 'status');
      toastBox.innerHTML = '<span class="cx-toast__msg"></span><button type="button" class="cx-toast__undo">Вернуть</button><button type="button" class="cx-toast__close" aria-label="Закрыть">×</button>';
      document.body.appendChild(toastBox);
      toastBox.querySelector('.cx-toast__close').addEventListener('click', hideToast);
      toastBox.querySelector('.cx-toast__undo').addEventListener('click', function () { var s = toastBox.snap; hideToast(); if (s) applySnap(s); });
    }
    toastBox.querySelector('.cx-toast__msg').textContent = msg;
    toastBox.snap = snap; toastBox.hidden = false; root.classList.add('is-toast');
    requestAnimationFrame(function () { toastBox.classList.add('is-on'); });
    clearTimeout(toastT); toastT = setTimeout(hideToast, 10000);
  }
  function hideToast() { if (!toastBox) return; clearTimeout(toastT); root.classList.remove('is-toast'); toastBox.classList.remove('is-on'); toastBox.snap = null; toastBox.hidden = true; }

  /* Подсказка «?» в левом нижнем углу */
  var help = (function () {
    var box = $('#calc-help'); if (!box) return null;
    var btn = $('#calc-help-btn'), panel = $('#calc-help-panel');
    function set(o) { panel.hidden = !o; btn.setAttribute('aria-expanded', o ? 'true' : 'false'); box.classList.toggle('is-open', o); }
    btn.addEventListener('click', function () { set(panel.hidden); });
    $$('.cx-help__close', box).forEach(function (c) { c.addEventListener('click', function () { set(false); btn.focus(); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { set(false); btn.focus(); } });
    document.addEventListener('click', function (e) { if (!panel.hidden && !box.contains(e.target)) set(false); });
    // ссылки внутри подсказки ведут к этапам — закрываем, раскрываем этап и прокручиваем
    $$('a[href^="#"]', panel).forEach(function (a) { a.addEventListener('click', function () { set(false); if (stages) stages.open(a.getAttribute('href').slice(1)); }); });
    return { close: function () { set(false); } };
  })();

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
    var a = asmNew();
    all('Component').forEach(function (c) {
      if (skip[at(c, 'refDes')]) return;
      var p = pk[at(c, 'packageRef')]; if (!p || !p.pins) return;
      a.total++;
      var L = layers[at(c, 'layerRef')] || {};
      if (L.side === 'BOTTOM') a.bot++; else a.top++;
      if (at(c, 'part')) a.parts[at(c, 'part')] = 1;
      asmAdd(a, at(c, 'packageRef'), p.pins, p.thru);
    });
    a.uniq = bomUniq || Object.keys(a.parts).length;
    asmDone(a);
    if (a.total) out.asm = a;
    var sw = all('SoftwarePackage')[0];
    out.software = sw ? [at(sw, 'name'), at(sw, 'revision')].filter(Boolean).join(' ') : '';
    return out;
  }


  /* ================= Монтаж: группы корпусов (общие для IPC-2581 и ODB++) ================= */
  function asmNew() { return { smd: 0, tht: 0, thtPins: 0, top: 0, bot: 0, parts: {}, groups: {}, unknown: 0, total: 0 }; }
  function asmAdd(a, pkgName, pins, thru) {
    var name = String(pkgName || '').toLowerCase();
    var G = function (g, n) { var x = a.groups[g] || (a.groups[g] = { n: 0, pins: 0, k: {} }); x.n++; x.pins += n; x.k[n] = 1; };
    if (thru > 0) { a.tht++; a.thtPins += thru; if (/conn|header|usb|jst|molex|terminal|socket|pls|pbs|kls|idc|dg\d/.test(name)) G('conn', thru); else G('tht', thru); return; }
    a.smd++;
    if (/bga/.test(name)) G('bga', pins);
    else if (/qfn|dfn|lga|wson|vson/.test(name)) G('qfn', pins);
    else if (/qfp/.test(name)) G('qfp', pins);
    else if (/soic|tssop|ssop|msop|(^|[^a-z])sop|(^|[^a-z])so-?\d/.test(name)) G('soic', pins);
    else if (/sot|sod|sc-?70|d-?pak|to-?2[56]\d/.test(name)) G('sot', pins);
    else if (/(^|\D)0201(\D|$)/.test(name)) G('0201', 2);
    else if (/(^|\D)0402(\D|$)/.test(name)) G('0402', 2);
    else if (/(^|\D)(0603|0805|1206|1210|1812|2010|2512)(\D|$)/.test(name)) G('0603', 2);
    else if (/conn|header|usb|jst|molex/.test(name)) G('conn', pins);
    else a.unknown++;
  }
  function asmDone(a) { a.sides = a.bot > 0 && a.top > 0 ? 2 : 1; a.bgaqfn = !!(a.groups.bga || a.groups.qfn); }

  /* ================= Разбор ODB++ ================= */
  // files: { 'matrix/matrix': текст, 'steps/pcb/…': текст } — пути от корня ODB-папки, в нижнем регистре
  function parseODB(files) {
    var get = function (p) { return files[p] || ''; };
    var kv = function (t) { var o = {}; String(t).split(/\r?\n/).forEach(function (l) { var m = /^\s*\.?(\w+)\s*=\s*(.*?)\s*$/.exec(l); if (m && m[2] !== '' && !(m[1].toLowerCase() in o)) o[m[1].toLowerCase()] = m[2]; }); return o; };
    var mm = function (t) { return /UNITS\s*=\s*INCH/i.test(t) ? 25.4 : 1; }; // единицы файла: MM или INCH
    var mx = get('matrix/matrix');
    if (!mx) throw new Error('odb');
    var out = { src: 'ODB++' }, layers = [];
    mx.replace(/LAYER\s*\{([^}]*)\}/g, function (_, b) { var o = kv(b); o.row = +o.row; layers.push(o); return ''; });
    layers.sort(function (x, y) { return x.row - y.row; });
    var board = layers.filter(function (l) { return /board/i.test(l.context); });
    var sig = board.filter(function (l) { return /^(signal|power_ground|mixed)$/i.test(l.type); });
    var stepName = ((/STEP\s*\{[^}]*NAME\s*=\s*(\S+)/i.exec(mx) || [])[1] || 'pcb').toLowerCase();
    if (!Object.keys(files).some(function (p) { return p.indexOf('steps/' + stepName + '/') === 0; })) { // название шага не совпало — берём первый
      var any = Object.keys(files).map(function (p) { return (/^steps\/([^/]+)\//.exec(p) || [])[1]; }).filter(Boolean)[0];
      if (any) stepName = any;
    }
    var S = 'steps/' + stepName + '/', lf = function (l, f) { return get(S + 'layers/' + String(l.name).toLowerCase() + '/' + f); };
    var info = kv(get('misc/info'));
    out.software = info.save_app || info.odb_source || '';
    if (sig.length) out.layers = sig.length;
    // толщина и материал — из атрибутов САПР: если стек не заполнен, там значения по умолчанию, поэтому «проверьте»
    var ja = kv(get('misc/attrlist'));
    var th = parseFloat(ja.board_thickness) * mm(get('misc/attrlist'));
    if (th > 0.1) out.thick = th;
    var diel = board.filter(function (l) { return /dielectric/i.test(l.type); });
    var mat = diel.map(function (l) { return l.dielectric_name || kv(lf(l, 'attrlist')).comment; }).filter(Boolean)[0];
    if (mat) out.mat = mat;
    // медь: .copper_weight в атрибутах слоя (мкм)
    var cu = sig.map(function (l) { var t = lf(l, 'attrlist'), w = parseFloat(kv(t).copper_weight); return w > 0 ? w : null; });
    var snap = function (w) { var st = [12, 18, 35, 70, 105, 140].filter(function (x) { return Math.abs(w - x) / x < 0.08; })[0]; return st || Math.round(w); }; // 35,56 мкм (1 oz) → 35
    if (cu[0]) out.cuout = snap(cu[0]);
    if (sig.length > 2) { var ci = cu.slice(1, -1).filter(Boolean)[0]; if (ci) out.cuin = snap(ci); }
    // габариты — по контуру платы (profile)
    var pr = get(S + 'profile'), xs = [], ys = [], pk = mm(pr);
    pr.replace(/^\s*O[BSC]\s+(-?[\d.]+)\s+(-?[\d.]+)/gm, function (_, x, y) { xs.push(+x); ys.push(+y); return ''; });
    if (xs.length > 2) out.size = { w: (Math.max.apply(null, xs) - Math.min.apply(null, xs)) * pk, h: (Math.max.apply(null, ys) - Math.min.apply(null, ys)) * pk };
    // мин. дорожка — самый тонкий круглый символ, которым проведены линии (L) и дуги (A) на сигнальных слоях
    var track = Infinity;
    sig.forEach(function (l) {
      var t = lf(l, 'features'); if (!t) return;
      var k = mm(t) === 25.4 ? 0.0254 : 0.001, sy = {}; // символ r200 — в мкм (MM) или в mil (INCH)
      t.replace(/^\$(\d+)\s+(\S+)/gm, function (_, i, s) { sy[i] = s; return ''; });
      t.replace(/^(?:L(?:\s+\S+){4}|A(?:\s+\S+){6})\s+(\d+)/gm, function (_, i) { var m = /^r([\d.]+)$/.exec(sy[i] || ''); if (m) { var w = +m[1] * k; if (w > 0 && w < track) track = w; } return ''; });
    });
    if (track < Infinity) out.track = Math.round(track * 1000) / 1000;
    // отверстия — таблица инструментов сверловки
    var hmin = Infinity, hits = 0;
    board.filter(function (l) { return /^drill$/i.test(l.type); }).forEach(function (l) {
      var t = lf(l, 'tools'), k = mm(t) === 25.4 ? 0.0254 : 0.001;
      t.replace(/TOOLS\s*\{([^}]*)\}/g, function (_, b) { var o = kv(b), d = parseFloat(o.finish_size) || parseFloat(o.drill_size); if (d > 0 && d * k < hmin) hmin = d * k; return ''; });
      hits += (lf(l, 'features').match(/^P\s/gm) || []).length;
    });
    if (hmin < Infinity) out.hole = { min: Math.round(hmin * 1000) / 1000, hits: hits };
    // монтаж: корпуса и типы выводов — eda/data, компоненты — слои comp_+_top / comp_+_bot
    var pkgs = [], cur = null;
    get(S + 'eda/data').split(/\r?\n/).forEach(function (ln) {
      var m = /^PKG\s+(\S+)/.exec(ln); if (m) { cur = { name: m[1], pins: 0, thru: 0 }; pkgs.push(cur); return; }
      m = /^PIN\s+\S+\s+([TBS])\b/.exec(ln); if (m && cur) { cur.pins++; if (m[1] === 'T') cur.thru++; }
    });
    var a = asmNew(), firstSig = sig.length ? sig[0].row : 0;
    board.filter(function (l) { return /^component$/i.test(l.type); }).forEach(function (l) {
      var t = lf(l, 'components'); if (!t) return;
      var bottom = /bot/i.test(l.name) || l.row > firstSig, names = {};
      t.replace(/^@(\d+)\s+(\S+)/gm, function (_, i, n) { names[n.replace(/^\./, '')] = i; return ''; });
      t.split(/^CMP\s/m).slice(1).forEach(function (b) {
        var head = b.split(/\r?\n/)[0], f = head.split(/\s+/), p = pkgs[+f[0]] || { name: '', pins: 0, thru: 0 };
        var pins = (b.match(/^TOP\s/gm) || []).length || p.pins; if (!pins) return;
        var mt = names.comp_mount_type != null ? (new RegExp('[;,]' + names.comp_mount_type + '=(\\d)').exec(head) || [])[1] : null;
        var thru = p.pins ? Math.round(p.thru * pins / p.pins) : (mt === '2' || mt === '3' ? pins : 0);
        if (!p.pins && mt == null) thru = 0;
        a.total++; if (bottom) a.bot++; else a.top++;
        if (f[6] && f[6] !== '???') a.parts[f[6]] = 1;
        asmAdd(a, p.name, pins, thru);
      });
    });
    a.uniq = Object.keys(a.parts).length;
    asmDone(a);
    if (a.total) out.asm = a;
    return out;
  }

  /* ================= Файл требований к плате (.txt) ================= */
  // «Ключ: значение» на строку, ключи по-русски или по-английски. Формат свободный — берём то, что узнали.
  var specKeys = [
    ['cuout', /copper|\bcu\b|мед[ьи]|фольг/i], ['mask', /mask|маск/i], ['silk', /silk|legend|marking|маркир|шелко/i],
    ['finish', /finish|покрыт/i], ['thick', /thick|толщ/i], ['hole', /hole|drill|отверст|сверл/i],
    ['track', /track|trace|line|дорож|проводн/i], ['layers', /layer|сло[йёеия]/i], ['mat', /material|материал|основани/i],
    ['size', /size|dimension|габарит|размер/i], ['qty', /quantit|\bqty\b|кол-?во|количеств|тираж/i]
  ];
  function specNum(v) { var m = /(\d+(?:[.,]\d+)?)/.exec(v); return m ? parseFloat(m[1].replace(',', '.')) : NaN; }
  function parseSpec(text) {
    var out = {}, n = 0;
    String(text).split(/\r?\n|;/).forEach(function (ln) {
      var m = /^\s*([^:=]{2,40}?)\s*[:=]\s*(.+?)\s*$/.exec(ln) || /^\s*([^\-–—]{2,40}?)\s+[\-–—]\s+(.+?)\s*$/.exec(ln);
      if (!m) return;
      var key = m[1], v = m[2].replace(/[.;]+$/, '').trim(); if (!v) return;
      var hit = specKeys.filter(function (k) { return k[1].test(key); })[0]; if (!hit || out[hit[0]] != null) return;
      var f = hit[0], x;
      if (f === 'cuout') { var oz = /(\d+(?:[.,]\d+)?)\s*(oz|унц)/i.exec(v), um = /(\d+)\s*(мкм|µm|um|мк)/i.exec(v); x = oz ? Math.round(parseFloat(oz[1].replace(',', '.')) * 35) : um ? +um[1] : null; }
      else if (f === 'thick' || f === 'hole' || f === 'track') { x = specNum(v); if (/mil/i.test(v)) x *= 0.0254; else if (/мкм|µm|um/i.test(v)) x /= 1000; if (!(x > 0)) x = null; if (f === 'hole' && x) x = { min: x, hits: 0 }; }
      else if (f === 'layers' || f === 'qty') { x = parseInt(v, 10); if (!(x > 0)) x = null; }
      else if (f === 'size') { var s = /(\d+(?:[.,]\d+)?)\s*[x×хX*]\s*(\d+(?:[.,]\d+)?)/.exec(v); x = s ? { w: parseFloat(s[1].replace(',', '.')), h: parseFloat(s[2].replace(',', '.')) } : null; }
      else x = v;
      if (x != null) { out[f] = x; n++; }
    });
    out.count = n;
    return out;
  }
  function isSpec(t) { return t.length < 200000 && !/^M48/m.test(t) && !isPnP(t) && parseSpec(t.slice(0, 20000)).count >= 3; }


  /* ================= Pick&Place: Altium (.csv / .txt), KiCad (.pos / .csv) ================= */
  function isPnP(t) {
    var h = t.slice(0, 8000);
    return /(^|\n)[\s#]*"?(designator|ref|refdes)"?[\s,;\t]/i.test(h) && /(center-x|mid x|pos ?x|ref x|"x"|\bx\s*\(mm\))/i.test(h) && /(layer|side|\btb\b)/i.test(h);
  }
  function parsePnP(t) {
    var lines = String(t).split(/\r?\n/), hi = -1;
    for (var i = 0; i < lines.length && i < 200; i++) if (/^[\s#]*"?(designator|ref|refdes)"?[\s,;\t]/i.test(lines[i])) { hi = i; break; }
    if (hi < 0) return null;
    var head = lines[hi].replace(/^\s*#\s*/, ''), sep = /","/.test(head) || head.split(',').length > 3 ? ',' : head.split(';').length > 3 ? ';' : head.indexOf('\t') > 0 ? '\t' : ' ';
    var split, cols;
    if (sep === ' ') { // фиксированная ширина колонок — по позициям заголовка
      var pos = [], re = /\S+/g, m; while ((m = re.exec(lines[hi]))) pos.push(m.index);
      cols = lines[hi].trim().replace(/^#\s*/, '').split(/\s+/);
      if (cols.length !== pos.length) pos = pos.slice(pos.length - cols.length);
      var fw = function (l) { return pos.map(function (p, k) { return l.slice(p, k + 1 < pos.length ? pos[k + 1] : undefined).trim(); }); };
      // KiCad .pos: значения не всегда под заголовком — делим по пробелам, если число колонок совпало
      split = function (l) { var s = l.trim().split(/\s+/); return s.length === cols.length ? s : fw(l); };
    } else {
      split = function (l) { var out = [], cur = '', q = false; for (var k = 0; k < l.length; k++) { var ch = l[k]; if (ch === '"') q = !q; else if (ch === sep && !q) { out.push(cur.trim()); cur = ''; } else cur += ch; } out.push(cur.trim()); return out; };
      cols = split(head);
    }
    cols = cols.map(function (c) { return c.toLowerCase().replace(/"/g, ''); });
    var ix = function (re) { for (var k = 0; k < cols.length; k++) if (re.test(cols[k])) return k; return -1; };
    var iRef = ix(/^(designator|ref|refdes)$/), iFp = ix(/footprint|package|pattern/), iSide = ix(/^(layer|side|tb)$/), iVal = ix(/^(comment|val|value)$/);
    if (iRef < 0) return null;
    var rows = [], seen = {};
    lines.slice(hi + 1).forEach(function (l) {
      if (!l.trim() || /^\s*#/.test(l)) return;
      var c = split(l), ref = (c[iRef] || '').replace(/"/g, ''); if (!ref || seen[ref]) return;
      seen[ref] = 1;
      rows.push({ ref: ref, fp: iFp >= 0 ? (c[iFp] || '').replace(/"/g, '') : '', bot: iSide >= 0 && /^b|bot/i.test((c[iSide] || '').replace(/"/g, '')), val: iVal >= 0 ? (c[iVal] || '').replace(/"/g, '') : '' });
    });
    return rows.length ? { rows: rows, fp: iFp >= 0 } : null;
  }
  var THT = /pls|pbs|pld|kls|dip|sip|to-?(220|92|126|247|3p)|do-?(41|35|15|201|204)|axial|radial|tra\d|hc-?49|vdr|mf-?r|tht|pin.?header|pinheader|idc|dg\d|wago|terminal|jumper|[-_]pth|pth[-_]/i;
  function pinsByName(s) {
    s = String(s).toLowerCase(); var m;
    // «2x05» у разъёмов — ряды × выводы; «3.9x4.9mm», «3x3mm» — размеры корпуса, не выводы
    if ((m = /(?:^|[^\d.])(\d{1,2})\s*[xх]\s*(\d{1,3})(?![\d.]|\s*mm)/.exec(s))) return +m[1] * +m[2];
    if ((m = /sot-?23-(\d)/.exec(s))) return +m[1];
    if ((m = /(?:dip|sip|soic|so|tssop|ssop|msop|qfn|dfn|lga|lqfp|tqfp|qfp|bga|pls|pbs|idc|header)[-_ ]?(\d{1,3})/.exec(s))) return +m[1];
    if ((m = /(\d{1,2})p(?:\b|[-_])/.exec(s))) return +m[1];
    if (/to-?(220|92|126|247|3p)|sot-?23|d-?pak/.test(s)) return 3;
    return 2;
  }
  // Монтаж по Pick&Place: число компонентов и стороны — точно; SMD/DIP и выводы — по названиям посадочных мест (оценка)
  function pnpAsm(list) {
    var a = asmNew(), refs = {};
    a.est = true; a.noFp = 0;
    list.forEach(function (p) { p.rows.forEach(function (r) {
      if (refs[r.ref]) return; refs[r.ref] = 1; // KiCad: верх и низ — отдельными файлами
      a.total++; if (r.bot) a.bot++; else a.top++;
      a.parts[(r.val || '') + '|' + (r.fp || '')] = 1;
      var name = r.fp;
      if (!name) { a.noFp++; if (/^(X|XS|XP|XT|J|P|CN|CON)\d/i.test(r.ref)) name = 'conn'; } // без посадочных мест — разъёмы по позиционному обозначению
      var tht = name && (THT.test(name) || (name === 'conn' && !r.fp)), pins = pinsByName(name);
      asmAdd(a, name, pins, tht ? pins : 0);
    }); });
    a.uniq = Object.keys(a.parts).length;
    asmDone(a);
    return a.total ? a : null;
  }

  /* ================= Платы проекта ================= */
  // В одном архиве бывает несколько плат (изделие из нескольких плат, ревизии одной платы). Считаем одну — выбранную,
  // в заявку уходит список всех. Плату задают медные слои: по атрибутам Gerber X2 (ProjectId / SameCoordinates),
  // иначе по общему началу имён файлов. IPC-2581 и ODB++ — по одной плате, к Gerber привязываются по габаритам,
  // ТЗ — по габаритам или по имени.
  var boards = [], boardKey = null, wantBoard = null, boardsInfo = [];
  var LAYER_SUFFIX = /[-_. ]((f|b|in\d+)[._](cu|mask|paste|silks?|silkscreen|fab|crtyd|courtyard|adhes|adhesive)|edge[._]cuts|n?pth|job|drl|drill|drill[._]map|(round|slot|rect)holes|top|bottom|bot|outline|profile|copper|all-?pos|pos)$/i;
  function stemOf(name) {
    var s = String(name).toLowerCase().replace(/\.[^.]*$/, '').replace(/^pick ?place ?for ?/, '').trim(), p, first = s;
    do { p = s; s = s.replace(LAYER_SUFFIX, ''); } while (s !== p && s);
    return s || first;
  }
  function x2Id(t) {
    var h = t.slice(0, 4000), m = /TF\.ProjectId,([^,*]+)/.exec(h) || /TF\.SameCoordinates,([^*]+)\*/.exec(h);
    var v = m ? m[1].trim().toLowerCase() : ''; return v === 'original' ? '' : v;
  }
  function sameSize(a, b, tol) {
    tol = tol || 1; if (!a || !b) return false;
    return Math.abs(Math.max(a.w, a.h) - Math.max(b.w, b.h)) < tol && Math.abs(Math.min(a.w, a.h) - Math.min(b.w, b.h)) < tol;
  }
  function parseDocs(ipcDocs, odbDocs, specDocs) {
    var errs = 0;
    ipcDocs.forEach(function (d) {
      if (!d._p && !d._err) {
        try { d._p = parseIPC(d.text); d.from.note = 'IPC-2581' + (d._p.software ? ' · ' + d._p.software : '') + (d._p.asm ? ' · ' + fmt(d._p.asm.total) + ' ' + plural(d._p.asm.total, 'компонент', 'компонента', 'компонентов') : ''); }
        catch (x) { d._err = 1; d.from.status = 'err'; d.from.note = 'не удалось прочитать XML'; track({ ev: [['calc_err', 'xml']] }); }
      }
      if (d._err) errs++;
    });
    odbDocs.forEach(function (d) {
      if (d._p || d._err) return;
      try { d._p = parseODB(d.files); d.from.note = 'ODB++' + (d._p.software ? ' · ' + d._p.software : '') + (d._p.asm ? ' · ' + fmt(d._p.asm.total) + ' ' + plural(d._p.asm.total, 'компонент', 'компонента', 'компонентов') : ''); }
      catch (x) { d._err = 1; if (!d.from.note) d.from.note = 'не удалось прочитать ODB++'; track({ ev: [['calc_err', 'odb']] }); }
    });
    specDocs.forEach(function (d) {
      if (!d._p) d._p = parseSpec(d.text);
      if (d.from.kind === 'spec') d.from.note = 'требования к плате · ' + d._p.count + ' ' + plural(d._p.count, 'параметр', 'параметра', 'параметров');
    });
    return errs;
  }
  function boardSize(b) { return (b.gf && b.gf.G.size) || b.docSize || (b.spec[0] && b.spec[0]._p.size) || null; }
  function boardLayers(b) { return (b.gf && b.gf.G.layers) || b.docLayers || (b.spec[0] && b.spec[0]._p.layers) || null; }
  function boardComps(b) { var d = (b.ipc.concat(b.odb)).filter(function (x) { return x._p && x._p.asm; })[0]; return d ? d._p.asm.total : b.gf && b.gf.pnpAsm ? b.gf.pnpAsm.total : 0; }
  var BDC = null;
  function splitBoards(entries, ipcDocs, odbDocs, specDocs) {
    var sig = store.map(function (it) { return it.id + ':' + it.status; }).join(',');
    if (BDC && BDC.sig === sig) return selectBoard(BDC);
    var errs = parseDocs(ipcDocs, odbDocs, specDocs), list = [], byKey = {};
    var board = function (key) { if (!byKey[key]) { byKey[key] = { key: key, name: '', entries: [], ipc: [], odb: [], spec: [], stems: {}, dir: '', bucket: '', id: '', gf: null }; list.push(byKey[key]); } return byKey[key]; };
    entries.forEach(function (e) {
      e.cls = classify(e).type; e.stem = stemOf(e.name); e.board = null;
      e.id = /^(copper|outline|paste|mask|silk|gerber\?)$/.test(e.cls) ? x2Id(e.text) : '';
      e.bucket = isArc(e.from) ? 'a' + e.from.id : 'loose';
      e.dir = String(e.path || '').replace(/\\/g, '/').replace(/[^/]*$/, '');
    });
    var cu = entries.filter(function (e) { return e.cls === 'copper'; }), dirStems = {};
    cu.forEach(function (e) { var k = e.bucket + '|' + e.dir, o = dirStems[k] || (dirStems[k] = {}); o[e.stem] = (o[e.stem] || 0) + 1; });
    cu.forEach(function (e) {
      var ds = dirStems[e.bucket + '|' + e.dir], st = Object.keys(ds);
      var one = st.length > 1 && st.every(function (s) { return ds[s] === 1; }); // EasyEDA: у каждого медного слоя своё имя — это одна плата
      var b = board(e.id ? e.bucket + '|id:' + e.id : e.bucket + '|' + e.dir + '|' + (one ? '*' : e.stem));
      b.entries.push(e); b.stems[e.stem] = 1; b.dir = e.dir; b.bucket = e.bucket; if (e.id) b.id = e.id; e.board = b;
    });
    entries.forEach(function (e) {
      if (e.board || e.cls === 'skip') return;
      var cand = list.filter(function (x) { return x.bucket === e.bucket; }), b = null, bs;
      if (e.id) b = cand.filter(function (x) { return x.id === e.id; })[0] || null;
      if (!b) { bs = cand.filter(function (x) { return x.stems[e.stem]; }); b = bs.filter(function (x) { return x.dir === e.dir; })[0] || bs[0] || null; }
      if (!b) { bs = cand.filter(function (x) { return Object.keys(x.stems).some(function (s) { return s.length > 2 && (e.stem.indexOf(s) === 0 || s.indexOf(e.stem) === 0); }); }); if (bs.length === 1) b = bs[0]; }
      if (!b) { bs = cand.filter(function (x) { return x.dir === e.dir; }); if (bs.length === 1) b = bs[0]; }
      if (!b && cand.length === 1) b = cand[0];
      if (!b && list.length === 1) b = list[0];
      if (b) { b.entries.push(e); e.board = b; }
    });
    list.forEach(function (b) { b.gf = gerberFacts(b.entries); });
    // одна и та же плата дважды (архив и те же файлы отдельно) — оставляем первую
    list = list.filter(function (b, i) {
      return !list.slice(0, i).some(function (x) { return x.gf.G.layers === b.gf.G.layers && sameSize(x.gf.G.size, b.gf.G.size, 0.3) && Object.keys(x.stems).join() === Object.keys(b.stems).join(); });
    });
    // IPC-2581 и ODB++: к плате Gerber с теми же габаритами и слоями, иначе — отдельная плата
    [['ipc', ipcDocs], ['odb', odbDocs]].forEach(function (p) {
      p[1].forEach(function (d) {
        if (!d._p) return;
        var s = d._p.size, L = d._p.layers;
        var b = list.filter(function (x) { var xs = boardSize(x), xl = boardLayers(x); return s && xs ? sameSize(xs, s) && (!L || !xl || xl === L) : false; })[0];
        if (!b && list.length === 1 && !s) b = list[0];
        if (!b) { b = board('doc:' + p[0] + ':' + d.from.id + ':' + (d.name || '')); b.docSize = s; b.docLayers = L; b.docName = (d.name && d.name !== 'odb' ? d.name : d.from.name).replace(/\.[^.]*$/, ''); if (list.indexOf(b) < 0) list.push(b); }
        b[p[0]].push(d);
      });
    });
    // ТЗ: единственная плата — к ней; иначе по габаритам, затем по словам из имени файла
    var lost = 0;
    specDocs.forEach(function (d) {
      if (!list.length) return;
      var s = d._p || {}, b = list.length === 1 ? list[0] : null;
      if (!b && s.size) b = list.filter(function (x) { return sameSize(boardSize(x), s.size, 1.5); })[0] || null;
      if (!b) {
        var tok = stemOf(d.name).split(/[^a-zа-яё0-9]+/i).filter(function (t) { return t.length >= 3; });
        var txt = function (x) { return (x.dir + ' ' + Object.keys(x.stems).join(' ') + ' ' + (x.docName || '')).toLowerCase(); };
        var bs = list.filter(function (x) { return tok.some(function (t) { return txt(x).indexOf(t) >= 0; }); });
        if (bs.length === 1) b = bs[0];
      }
      if (b) b.spec.push(d);
      else { lost++; if (d.from.kind === 'spec') d.from.note = 'требования к плате — не удалось сопоставить с платой проекта'; }
    });
    // платы — в порядке файлов в списке (а не «сначала Gerber, потом IPC и ODB++»)
    var firstFile = function (b) { var ids = b.entries.map(function (e) { return e.from.id; }).concat(b.ipc.concat(b.odb).map(function (d) { return d.from.id; })); return ids.length ? Math.min.apply(null, ids) : 1e9; };
    list = list.map(function (b, i) { return { b: b, f: firstFile(b), i: i }; }).sort(function (x, y) { return (x.f - y.f) || (x.i - y.i); }).map(function (x) { return x.b; });
    // названия: имя «своего» ТЗ → отличающаяся часть имён файлов → папка → имя файла проекта
    var stems = list.map(function (b) { return Object.keys(b.stems)[0] || ''; }), pre = '';
    var named = stems.filter(Boolean);
    if (named.length > 1) { pre = named.reduce(function (a, s) { var k = 0; while (k < a.length && a[k] === s[k]) k++; return a.slice(0, k); }); pre = pre.replace(/[^-_. ]*$/, ''); }
    list.forEach(function (b, i) {
      var nm = '';
      if (b.spec.length === 1 && list.length > 1) nm = stemOf(b.spec[0].name);
      if (!nm && stems[i]) nm = stems[i].slice(pre.length).replace(/^[-_. ]+/, '') || stems[i];
      if (!nm && b.dir) nm = b.dir.replace(/\/$/, '').split('/').pop().replace(/^gerber[-_ ]*/i, '').replace(/[-_ ]*\(?\d{1,2}[._]\d{1,2}[._]\d{2,4}\)?/g, '');
      if (!nm) nm = b.docName || 'плата ' + (i + 1);
      b.name = nm.replace(/_+/g, ' ').replace(/\s+/g, ' ').trim();
    });
    list.forEach(function (b) { var same = list.filter(function (x) { return x.name === b.name; }); if (same.length > 1) same.forEach(function (x, k) { if (k) x.name += ' (' + (k + 1) + ')'; }); });
    var free = entries.filter(function (e) { return !e.board && e.cls !== 'skip' && e.cls !== 'gerber?'; }).length;
    BDC = { sig: sig, list: list, errs: errs, lostSpec: lost, free: list.length > 1 ? free : 0 };
    return selectBoard(BDC);
  }
  function selectBoard(C) {
    var list = C.list;
    // выбранная плата: восстановленная из черновика/заявки → прежняя → с наибольшим числом компонентов → самая большая
    if (wantBoard) { var w = list.filter(function (b) { return b.name === wantBoard; })[0]; if (w) boardKey = w.key; wantBoard = null; }
    var cur = list.filter(function (b) { return b.key === boardKey; })[0] || null;
    if (!cur && list.length) {
      var area = function (b) { var s = boardSize(b); return s ? s.w * s.h : 0; };
      cur = list.slice().sort(function (a, b) { return (boardComps(b) - boardComps(a)) || (area(b) - area(a)); })[0];
      boardKey = cur.key;
    }
    return { list: list, cur: cur, errs: C.errs, lostSpec: C.lostSpec, free: C.free };
  }
  function boardMeta(b) {
    var s = boardSize(b), L = boardLayers(b), n = boardComps(b), out = [];
    if (s) out.push(fmtMM(Math.max(s.w, s.h)) + ' × ' + fmtMM(Math.min(s.w, s.h)) + ' мм');
    if (L) out.push(L + ' ' + plural(L, 'слой', 'слоя', 'слоёв'));
    if (n) out.push(fmt(n) + ' комп.');
    return out.join(' · ');
  }
  /* ---------- Комплект: у каждой платы свои параметры, тираж в блоке 2 — число комплектов ---------- */
  var bstate = {}, perSetMap = {}, offMap = {}, storeSig = '', keepStates = false, analysing = false, silent = false, filling = false, lastBD = null;
  // solo — «Посчитать отдельно»: одна плата проекта без комплекта, тираж — штук этой платы; setQty — число комплектов до переключения
  var solo = false, setQty = '';
  function isMulti() { return boards.length > 1; }
  function isSet() { return boards.length > 1 && !solo; }
  function toggleSolo(on) {
    var c = curBoard(); if (!c || on === solo) return;
    if (on) { setQty = val('qty'); var s = parseFloat(setQty); solo = true; if (s > 0) el('qty').value = s * perSet(c.name); }
    else { solo = false; if (setQty !== '') el('qty').value = setQty; }
    used = true; renderBoards(lastBD); syncSetUI(); recalc(); saveSoon();
  }
  function perSet(name) { var n = perSetMap[name]; return n > 0 ? n : 1; }
  function inSet(name) { return !offMap[name]; }
  function curBoard() { return boards.filter(function (b) { return b.key === boardKey; })[0] || null; }
  // Записать значения в поля без бейджей и пересчёта — для расчёта соседних плат и сводки
  function putState(d) {
    DATA_KEYS.forEach(function (k) { var e = el(k); if (e && d[k] !== undefined && d[k] !== null) e.value = DEC.indexOf(k) >= 0 ? decIn(d[k]) : d[k]; });
    $$('.cx-checks input').forEach(function (i) { i.checked = (d.special || []).indexOf(i.dataset.key) >= 0; });
    var r = $('input[name="asm"][value="' + (d.asm || 'quick') + '"]'); if (r) r.checked = true;
    var av = d.asm_values || {};
    $$('[data-key^="q_"], [data-key^="d_"], [data-key^="p_"]').forEach(function (i) { i.value = av[i.dataset.key] || 0; });
    finishPending = !!d.finish_pending;
    asmEst = !!d.asm_est;
    syncInner();
  }
  // Выполнить fn для каждой платы комплекта: поля временно принимают значения платы, тираж = комплекты × штук в комплекте
  function eachBoard(fn, all) {
    var snap = fieldData(), sets = num('qty'), cur = curBoard(), out = [];
    boards.forEach(function (b) {
      if (!all && !inSet(b.name)) return;
      var st = cur && b.key === cur.key ? snap : bstate[b.name]; if (!st) return;
      putState(st); el('qty').value = sets > 0 ? sets * perSet(b.name) : '';
      out.push(fn(b, st));
    });
    putState(snap);
    return out;
  }
  function combineSet(list, sets) {
    var res = { ok: false, set: true, lines: [], warn: [], notes: [], engineer: false, boards: list, sets: sets };
    if (!(sets > 0)) { res.reason = 'Укажите число комплектов, чтобы увидеть оценку.'; return res; }
    if (!list.length) { res.reason = 'Отметьте платы, которые входят в заказ.'; return res; }
    var bad = list.filter(function (r) { return !r.ok; });
    if (bad.length) { res.reason = 'Заполните параметры ' + (bad.length > 1 ? 'плат ' : 'платы ') + bad.map(function (r) { return '«' + r.name + '»'; }).join(', ') + ' — выберите её в блоке «Файлы проекта».'; return res; }
    var eng = list.filter(function (r) { return r.engineer; }), priced = list.filter(function (r) { return !r.engineer; });
    // общая доставка: платы комплекта едут одной партией — постоянная часть (брокер, сборы, минимум за отправку)
    // платится один раз. Работает для формулы в браузере (у ответа есть goods/ship); ответ сервера складываем как есть.
    var shared = priced.length > 1 && priced.every(function (r) { return r.goods != null && r.ship != null; });
    var cur = curBoard(), estB = [];
    list.forEach(function (r) {
      var sub = (r.lines || []).filter(function (l) { return !l.incl && !(shared && /^Доставка/.test(l.name)); });
      res.lines.push({ board: r.name, cur: !!(cur && cur.name === r.name), sub: r.engineer ? null : sub,
        name: '«' + r.name + '»' + (r.n > 1 ? ' × ' + r.n : '') + ' — ' + fmt(r.qty) + ' шт',
        text: r.engineer ? 'расчёт инженером' : shared ? '≈ ' + fmtR(r.goods) : fmt(r.lo) + ' – ' + fmtR(r.hi) });
      (r.warn || []).forEach(function (w) { res.warn.push('«' + r.name + '»: ' + w); });
      (r.notes || []).forEach(function (t) { if (/^Монтаж посчитан ориентировочно/.test(t)) estB.push('«' + r.name + '»'); else res.notes.push('«' + r.name + '»: ' + t); });
      if (r.vat) res.vat = r.vat; if (r.version) res.version = r.version;
    });
    if (estB.length) res.notes.unshift('Монтаж посчитан ориентировочно — компоненты не учтены поштучно: ' + estB.join(', ') + '. Проверьте блок 3 у ' + (estB.length > 1 ? 'этих плат.' : 'этой платы.'));
    if (shared) {
      var goods = priced.reduce(function (s, r) { return s + r.goods; }, 0), apart = priced.reduce(function (s, r) { return s + r.ship; }, 0);
      res.ship = shipCost(goods); res.saving = Math.max(0, apart - res.ship);
      res.lines.push({ name: 'Доставка и таможня — одной партией', v: res.ship });
      if (res.saving > 1) res.lines.push({ name: 'Экономия против заказа плат по отдельности', text: '≈ ' + fmtR(res.saving), soft: true });
    }
    res.lines.push({ name: 'Проверка по ПМИ', text: 'входит', incl: true });
    res.lines.push({ name: 'Приёмка ОТК на фабрике', text: 'входит', incl: true });
    res.term = eng.length ? '2–3 месяца от размещения заказа' : (list[0].term || 'около 6–8 недель от размещения заказа');
    if (!priced.length) { res.ok = true; res.engineer = true; return res; }
    if (eng.length) res.notes.push('В сумму не входят платы с расчётом инженером: ' + eng.map(function (r) { return '«' + r.name + '»'; }).join(', ') + '.');
    if (shared) {
      res.total = priced.reduce(function (s, r) { return s + r.goods; }, 0) + res.ship;
      res.lo = res.total * 0.85; res.hi = res.total * 1.2;
    } else {
      res.lo = priced.reduce(function (s, r) { return s + r.lo; }, 0);
      res.hi = priced.reduce(function (s, r) { return s + r.hi; }, 0);
      res.total = priced.reduce(function (s, r) { return s + (r.total || (r.lo + r.hi) / 2); }, 0);
    }
    res.per = res.total / sets; res.qty = sets; res.ok = true; res.partial = eng.length > 0;
    return res;
  }
  function computeSet() {
    var sets = num('qty');
    var list = eachBoard(function (b) { var r = compute(); r.name = b.name; r.n = perSet(b.name); return r; });
    return combineSet(list, sets);
  }
  // Соседние платы, которые клиент ещё не открывал: разбираем их файлы «в тихом режиме» и запоминаем значения полей
  function fillStates() {
    if (filling || !isMulti()) return;
    var cur = curBoard(); if (!cur) return;
    var todo = boards.filter(function (b) { return b.key !== cur.key && !bstate[b.name]; });
    if (!todo.length) return;
    filling = true; silent = true;
    var sets = val('qty'), snap = fieldData();
    try {
      todo.forEach(function (b) { boardKey = b.key; analyseAll(); var st = fieldData(); st.qty = sets; bstate[b.name] = st; });
    } finally {
      boardKey = cur.key; analyseAll(); // поля и бейджи — снова текущей платы
      putState(snap); bstate[cur.name] = fieldData();
      silent = false; filling = false;
    }
  }
  function renderBoards(BD) {
    var box = $('#calc-boards'), now = $('#calc-board-now'), list = BD ? BD.list : [];
    boards = list; lastBD = BD;
    if (list.length < 2) solo = false;
    boardsInfo = list.length > 1 ? list.map(function (b) { var s = boardSize(b); return { name: b.name, size: s ? fmtMM(Math.max(s.w, s.h)) + ' × ' + fmtMM(Math.min(s.w, s.h)) : '', layers: boardLayers(b) || '', comps: boardComps(b) || 0, current: b === BD.cur, n: perSet(b.name), on: inSet(b.name) }; }) : [];
    if (!box) return;
    var many = list.length > 1; box.hidden = !many; if (now) now.hidden = !many;
    renderTabs(BD, many);
    if (!many) return;
    var n = list.length, idx = list.indexOf(BD.cur);
    box.classList.toggle('is-solo', solo);
    $('#calc-boards-title').innerHTML = (solo
      ? '<b>В проекте ' + n + ' ' + plural(n, 'плата', 'платы', 'плат') + ' — сейчас считаем только «' + esc(BD.cur.name) + '».</b> Плата посчитана отдельно, без комплекта: тираж в блоке 2 — штук этой платы. Нажмите на другую плату, чтобы посчитать отдельно её.'
      : '<b>В проекте ' + n + ' ' + plural(n, 'плата', 'платы', 'плат') + ' — считаем комплект.</b> Укажите число комплектов и сколько каждой платы в одном комплекте. У каждой платы свои параметры — нажмите на плату, чтобы проверить их в блоках 2 и 3.') +
      (BD.free ? ' Файлов, которые не удалось отнести ни к одной плате: ' + BD.free + '.' : '') + (BD.lostSpec ? ' Требования к плате (' + BD.lostSpec + ') не сопоставлены ни с одной платой.' : '');
    // «Комплектов: [N]» — под заголовком блока плат
    var sw = $('#calc-sets');
    if (!sw) {
      sw = document.createElement('label'); sw.id = 'calc-sets'; sw.className = 'cx-sets';
      sw.appendChild(document.createTextNode('Комплектов:'));
      var si = document.createElement('input'); si.type = 'number'; si.min = '1'; si.step = '1'; si.id = 'calc-sets-n'; si.setAttribute('inputmode', 'numeric');
      si.addEventListener('input', function () { el('qty').value = si.value; used = true; syncSetUI(); }); // пересчёт — общий обработчик ввода
      sw.appendChild(si);
      $('#calc-boards-title').after(sw);
    }
    sw.hidden = solo;
    var sb = $('#calc-solo');
    if (!sb) { sb = document.createElement('button'); sb.type = 'button'; sb.id = 'calc-solo'; sb.className = 'cx-mini cx-boards__solo'; sb.addEventListener('click', function () { toggleSolo(!solo); }); $('#calc-boards').appendChild(sb); }
    sb.textContent = solo ? 'Вернуться к расчёту комплекта' : 'Посчитать «' + BD.cur.name + '» отдельно';
    var wrap = $('#calc-boards-list'); wrap.innerHTML = '';
    list.forEach(function (b) {
      var card = document.createElement('div'); card.className = 'cx-board' + (b === BD.cur ? ' is-cur' : '') + (inSet(b.name) ? '' : ' is-off');
      card.dataset.name = b.name;
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'cx-board__pick';
      btn.setAttribute('aria-pressed', b === BD.cur ? 'true' : 'false');
      var t = document.createElement('b'); t.textContent = b.name; var m = document.createElement('span'); m.textContent = boardMeta(b);
      var sum = document.createElement('span'); sum.className = 'cx-board__sum';
      btn.appendChild(t); btn.appendChild(m); btn.appendChild(sum);
      // выбор — кликом по любой части карточки, кроме галочки и поля «в комплекте»
      card.addEventListener('click', function (e) { if (e.target.closest('input, label')) return; pickBoard(b, $('#calc-boards')); });
      var ctl = document.createElement('div'); ctl.className = 'cx-board__ctl';
      // «в заказе» — в правом верхнем углу карточки
      var lb = document.createElement('label'), cb = document.createElement('input');
      lb.className = 'cx-board__on'; cb.type = 'checkbox'; cb.checked = inSet(b.name); lb.appendChild(cb); lb.appendChild(document.createTextNode(' в заказе'));
      cb.addEventListener('change', function () { if (cb.checked) delete offMap[b.name]; else offMap[b.name] = 1; card.classList.toggle('is-off', !cb.checked); renderTabs(lastBD, true); used = true; syncSetUI(); recalc(); });
      // «по [N] в комплекте = M шт»
      var nl = document.createElement('label'), ni = document.createElement('input');
      nl.className = 'cx-board__per';
      ni.type = 'number'; ni.min = '1'; ni.max = '99'; ni.step = '1'; ni.value = perSet(b.name); ni.className = 'cx-board__n';
      ni.setAttribute('aria-label', 'Штук платы «' + b.name + '» в одном комплекте');
      nl.appendChild(document.createTextNode('по ')); nl.appendChild(ni); nl.appendChild(document.createTextNode(' в комплекте'));
      ni.addEventListener('input', function () { var v = parseInt(ni.value, 10); if (v > 0) perSetMap[b.name] = Math.min(v, 99); used = true; syncSetUI(); recalc(); });
      var tot = document.createElement('span'); tot.className = 'cx-board__total';
      ctl.appendChild(nl); ctl.appendChild(tot);
      card.appendChild(lb);
      card.appendChild(btn); card.appendChild(ctl);
      wrap.appendChild(card);
    });
    syncSetUI();
    if (now) now.textContent = solo
      ? 'Плата «' + BD.cur.name + '» (' + (idx + 1) + ' из ' + n + ') посчитана отдельно, без комплекта. Тираж — штук этой платы.'
      : 'Параметры платы «' + BD.cur.name + '» — ' + (idx + 1) + ' из ' + n + ' в комплекте. Другую плату выберите в блоке «Файлы проекта». Тираж — число комплектов.';
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // anchor — элемент, который не должен сдвинуться на экране (вкладки или блок плат): отчёт выше
  // у разных плат разной длины, без поправки страница «подпрыгивает»
  function pickBoard(b, anchor) {
    if (boardKey === b.key || root.classList.contains('is-busy')) return;
    var c = curBoard(); if (c) bstate[c.name] = fieldData();
    boardKey = b.key;
    // сразу показываем выбор и троббер, считаем — после отрисовки
    $$('.cx-btab').forEach(function (x) { x.setAttribute('aria-pressed', x.textContent === b.name ? 'true' : 'false'); });
    $$('#calc-boards-list .cx-board').forEach(function (x) { x.classList.toggle('is-cur', x.dataset.name === b.name); });
    var top0 = anchor ? anchor.getBoundingClientRect().top : 0;
    root.classList.add('is-busy');
    requestAnimationFrame(function () {
      setTimeout(function () {
        switching = true;
        try { safeAnalyse(); } finally { switching = false; root.classList.remove('is-busy'); }
        if (anchor) { var d = anchor.getBoundingClientRect().top - top0; if (Math.abs(d) > 1) window.scrollBy(0, d); }
        saveSoon();
      }, 0);
    });
  }
  // вкладки плат над параметрами: переключать плату там же, где её правят; длинные имена — с многоточием и подсказкой
  function renderTabs(BD, many) {
    ['#calc-btabs-1', '#calc-btabs-2', '#calc-btabs-3'].forEach(function (sel) { // 1 — в сводке свёрнутого этапа 1
      var t = $(sel); if (!t) return;
      t.hidden = !many; t.innerHTML = ''; if (!many) return;
      var lab = document.createElement('span'); lab.className = 'cx-btabs__label'; lab.textContent = solo ? 'Отдельно:' : 'Плата:'; t.appendChild(lab);
      BD.list.forEach(function (b) {
        var x = document.createElement('button'); x.type = 'button'; x.className = 'cx-btab' + (!solo && !inSet(b.name) ? ' is-off' : '');
        x.textContent = b.name; x.title = b.name + (boardMeta(b) ? ' — ' + boardMeta(b) : '') + (!solo && !inSet(b.name) ? ' · не в заказе' : '');
        x.setAttribute('aria-pressed', b === BD.cur ? 'true' : 'false');
        x.addEventListener('click', function () { pickBoard(b, t); });
        t.appendChild(x);
      });
    });
  }
  // Комплект: итог штук на карточках, «Комплектов» в блоке плат, тираж открытой платы в блоке 2 (только чтение)
  function syncSetUI() {
    var set = isSet(), sets = parseInt(val('qty'), 10), cur = curBoard();
    var si = $('#calc-sets-n'); if (si && document.activeElement !== si) si.value = val('qty');
    $$('#calc-boards-list .cx-board').forEach(function (card) {
      var t = card.querySelector('.cx-board__total'); if (!t) return;
      var nm = card.dataset.name;
      t.textContent = !inSet(nm) ? '' : sets > 0 ? '= ' + fmt(sets * perSet(nm)) + ' шт' : '';
    });
    var f = el('qty'), wrap = f.closest('.cx-field'), ro = $('#f-qty-total'), hint = $('#f-qty-set');
    if (!ro) {
      ro = document.createElement('input'); ro.type = 'text'; ro.id = 'f-qty-total'; ro.readOnly = true; ro.tabIndex = -1; ro.className = 'cx-qty-total';
      f.after(ro);
      hint = document.createElement('p'); hint.id = 'f-qty-set'; hint.className = 'cx-fnote cx-qty-set';
      (f.closest('.cx-num') || f).after(hint); // под полем, как пояснения к остальным полям
    }
    wrap.classList.toggle('is-set', set);
    hint.hidden = !set;
    if (!set || !cur) return;
    var k = perSet(cur.name);
    ro.value = sets > 0 ? fmt(sets * k) : '—';
    hint.textContent = '';
    hint.appendChild(document.createTextNode((sets > 0 ? fmt(sets) + ' ' + plural(sets, 'комплект', 'комплекта', 'комплектов') : 'Число комплектов не указано') + ' × ' + k + ' шт. Меняется в '));
    var a = document.createElement('button'); a.type = 'button'; a.className = 'cx-linkbtn'; a.textContent = 'блоке «Файлы проекта»';
    a.addEventListener('click', function () { var s = $('#calc-sets-n'); $('#calc-boards').scrollIntoView({ behavior: 'smooth', block: 'center' }); if (s) setTimeout(function () { s.focus({ preventScroll: true }); }, 400); });
    hint.appendChild(a); hint.appendChild(document.createTextNode('.'));
  }
  // суммы по платам — на карточках выбора
  function renderBoardSums(r) {
    syncSetUI();
    $$('#calc-boards-list .cx-board').forEach(function (card) {
      var s = card.querySelector('.cx-board__sum'); if (!s) return;
      var x = r && r.boards ? r.boards.filter(function (y) { return y.name === card.dataset.name; })[0] : null;
      s.textContent = !inSet(card.dataset.name) ? 'не в заказе' : !x ? '' : !x.ok ? 'заполните параметры' : x.engineer ? 'расчёт инженером' : r.ship != null && x.goods != null ? '≈ ' + fmtR(x.goods) + ' без доставки' : fmt(x.lo) + ' – ' + fmtR(x.hi);
    });
  }

  /* ================= Gerber одной платы ================= */
  function gerberFacts(entries) {
    var job = null, copper = [], outlines = [], drills = [], paste = [], copperText = [], pnps = [];
    entries.forEach(function (e) {
      var c = classify(e); e.cls = c.type;
      if (c.type === 'job') { try { job = readJob(e.text); job.src = '.gbrjob'; } catch (x) { e.from.note = 'файл .gbrjob не читается'; } }
      if (c.type === 'copper') { copper.push(c); copperText.push(e.text); }
      if (c.type === 'outline') outlines.push({ e: e, score: c.score || 1 });
      if (c.type === 'drill') drills.push(e);
      if (c.type === 'paste') paste.push(e);
      if (c.type === 'pnp') { if (e._pnp === undefined) e._pnp = parsePnP(e.text); if (e._pnp) pnps.push(e._pnp); }
    });
    var G = {};
    // контур: у Altium их бывает несколько (.GKO, .GM1…GM16) — берём слой с лучшим признаком,
    // а среди равных — размер, который повторяется чаще (на слое размеров рамка шире платы).
    // Проверка: точки контура далеко за пределами меди (надпись, метка, второй объект) не учитываем.
    var cuExt = null;
    copperText.forEach(function (t) { var x = gerberExt(t); if (x) cuExt = cuExt ? { x0: Math.min(cuExt.x0, x.x0), y0: Math.min(cuExt.y0, x.y0), x1: Math.max(cuExt.x1, x.x1), y1: Math.max(cuExt.y1, x.y1) } : x; });
    var clipped = false;
    var boxes = outlines.map(function (o) {
      var x = gerberExt(o.e.text);
      if (x && cuExt) {
        var m = Math.max(25, 0.5 * Math.max(cuExt.x1 - cuExt.x0, cuExt.y1 - cuExt.y0)); // поля без меди бывают широкими — режем только явные выбросы
        var cl = { x0: cuExt.x0 - m, y0: cuExt.y0 - m, x1: cuExt.x1 + m, y1: cuExt.y1 + m };
        if (x.x0 < cl.x0 || x.y0 < cl.y0 || x.x1 > cl.x1 || x.y1 > cl.y1) { var y = gerberExt(o.e.text, cl); if (y) { x = y; o.clip = true; } }
      }
      return { b: x ? { w: x.x1 - x.x0, h: x.y1 - x.y0 } : null, s: o.score, clip: o.clip };
    }).filter(function (o) { return o.b && o.b.w > 1 && o.b.h > 1; });
    if (boxes.length) {
      var best = Math.max.apply(null, boxes.map(function (o) { return o.s; })), cnt = {};
      boxes = boxes.filter(function (o) { return o.s === best; });
      boxes.forEach(function (o) { var k = Math.round(o.b.w * 2) + 'x' + Math.round(o.b.h * 2); cnt[k] = (cnt[k] || 0) + 1; o.k = k; });
      boxes.sort(function (x, y) { return (cnt[y.k] - cnt[x.k]) || (y.b.w * y.b.h - x.b.w * x.b.h); });
      G.size = boxes[0].b; clipped = boxes[0].clip;
    } else if (cuExt && outlines.length) { G.size = { w: cuExt.x1 - cuExt.x0, h: cuExt.y1 - cuExt.y0 }; clipped = 'cu'; }
    if (clipped) G.sizeNote = clipped === 'cu' ? 'контур в Gerber не прочитан — габариты оценены по медным слоям, проверьте' : 'на слое контура есть элементы далеко за пределами платы — их не учли, проверьте габариты';
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
    return { G: G, job: job, copper: copper, outlines: outlines, drills: drills, pads: (pasteBySide.top || 0) + (pasteBySide.bot || 0), padsBot: pasteBySide.bot || 0, pnpAsm: pnps.length ? pnpAsm(pnps) : null };
  }

  /* ================= Слияние источников ================= */
  var ipcAsm = null, asmSrc = 'IPC-2581', reportShown = false, reportOkOpen = false, fromFiles = {};
  function analyseAll() {
    analysing = true;
    try { analyseCore(); } finally { analysing = false; }
    if (!filling && isMulti()) { fillStates(); recalc(); }
  }
  function analyseCore() {
    // поля, которые прошлый разбор взял из файлов, — к значениям по умолчанию (ручные правки не трогаем);
    // дальше разбор заполнит их заново из оставшихся файлов. Иначе откреплённый проект оставлял свои значения.
    var man = {};
    Object.keys(badgeMap).forEach(function (k) { var b = $('.cx-badge[data-for="' + k + '"]'); if (b && b.dataset.state === 'manual') man[k] = 1; });
    if (DEF) Object.keys(fromFiles).forEach(function (k) { if (!man[k]) resetKey(k); });
    fromFiles = {};
    clearBadges();
    // набор файлов изменился — параметры плат собираем заново (кроме восстановления черновика или заявки)
    var sig = store.map(function (it) { return it.id; }).join(',');
    if (sig !== storeSig) { if (!keepStates) bstate = {}; storeSig = sig; }
    var entries = [], ipcDocs = [], odbDocs = [], specDocs = [], errs = 0;
    store.forEach(function (it) {
      if (it.status === 'err') { errs++; return; }
      entries = entries.concat(it.entries.map(function (e) { e.from = it; return e; }));
      ipcDocs = ipcDocs.concat(it.ipc.map(function (d) { d.from = it; return d; }));
      odbDocs = odbDocs.concat(it.odb.map(function (d) { d.from = it; return d; }));
      specDocs = specDocs.concat(it.spec.map(function (d) { d.from = it; return d; }));
    });
    // делим проект на платы и дальше работаем только с выбранной
    var BD = splitBoards(entries, ipcDocs, odbDocs, specDocs), cur = BD.cur;
    errs += BD.errs;
    if (cur) { entries = cur.entries; ipcDocs = cur.ipc; odbDocs = cur.odb; specDocs = cur.spec; }
    renderBoards(BD);
    var GF = cur && cur.gf ? cur.gf : gerberFacts(entries);
    var job = GF.job, copper = GF.copper, outlines = GF.outlines, drills = GF.drills, G = GF.G, pnpA = GF.pnpAsm;
    var ipc = null; ipcDocs.forEach(function (d) { if (!ipc && d._p) ipc = d._p; });
    // ODB++: в архиве бывает несколько копий одного проекта (папка, .tgz, .zip) — берём первую, что прочиталась
    var odb = null; odbDocs.forEach(function (d) { if (!odb && d._p) odb = d._p; });
    // файл требований к плате: первое значение каждого поля
    var spec = null;
    specDocs.forEach(function (d) {
      var s = d._p || parseSpec(d.text); spec = spec || {};
      Object.keys(s).forEach(function (k) { if (k !== 'count' && spec[k] == null) spec[k] = s[k]; });
    });
    // подписи файлов в списке
    store.forEach(function (it) {
      if (isArc(it) && it.status === 'ok' && !it.ipc.length && !it.odb.some(function (d) { return d._p; })) {
        var f = it.entries.filter(function (e) { return e.cls && e.cls !== 'skip' && e.cls !== 'gerber?' && e.cls !== 'pnp'; });
        var cu = it.entries.filter(function (e) { return e.cls === 'copper'; }).length, hasJob = it.entries.some(function (e) { return e.cls === 'job'; }), pp = it.entries.some(function (e) { return e.cls === 'pnp'; });
        var nb = BD.list.filter(function (b) { return b.bucket === 'a' + it.id; }).length;
        it.note = f.length ? 'Gerber: ' + f.length + ' ' + plural(f.length, 'файл', 'файла', 'файлов') + (nb > 1 ? ', ' + nb + ' ' + plural(nb, 'плата', 'платы', 'плат') : cu ? ', медных слоёв ' + cu : '') + (hasJob ? ', есть .gbrjob' : '') + (pp ? ', есть Pick&Place' : '') : 'Gerber и сверловка не найдены';
      }
      if (isArc(it) && it.status === 'ok' && it.spec.length) it.note += ' · есть требования к плате';
      if (it.kind === 'job' && it.status === 'ok' && !it.note) it.note = 'параметры платы и стек';
    });
    gerberPads = GF.pads;
    gerberBottom = GF.padsBot;
    ipcAsm = ipc && ipc.asm ? ipc.asm : odb && odb.asm ? odb.asm : pnpA || null;
    asmSrc = ipc && ipc.asm ? 'IPC-2581' : odb && odb.asm ? 'ODB++' : 'Pick&Place';
    if (ipc && ipc.asm) ipc.sides = ipc.asm.sides;
    if (odb && odb.asm) odb.sides = odb.asm.sides;
    if (gerberPads) G.sides = gerberBottom > 0 ? 2 : 1;

    parsed = store.length > 0;
    var items = [];
    function put(field, state, label, value, src, note, badge) { items.push({ field: field, state: state, label: label, value: value, src: src, note: note }); setBadge(field, state, src, badge); if (state !== 'need' || field === 'finish' || field === 'silk') fromFiles[field] = 1; }
    var srcs = [ipc, job, null].filter(Boolean);
    function cands(field) {
      var c = [];
      var weak = field === 'thick' || field === 'mat'; // в ODB++ — атрибуты САПР, часто значения по умолчанию
      if (ipc && ipc[field] != null) c.push({ v: ipc[field], src: 'IPC-2581' });
      if (odb && odb[field] != null && !weak) c.push({ v: odb[field], src: 'ODB++' });
      if (spec && spec[field] != null) c.push({ v: spec[field], src: 'ТЗ' });
      if (odb && odb[field] != null && weak) c.push({ v: odb[field], src: 'ODB++', weak: true });
      if (job && job[field] != null) c.push({ v: job[field], src: '.gbrjob' });
      if (field === 'sides' && pnpA) c.push({ v: pnpA.sides, src: 'Pick&Place' });
      if (G[field] != null) c.push({ v: G[field], check: field === 'size' ? G.sizeNote : null, src: { size: 'контура Gerber', layers: 'медных слоёв Gerber', track: 'апертур, оценка', hole: 'сверловки', sides: 'слоя пасты Gerber' }[field] });
      return c;
    }
    function sizeTxt(v) { return fmtMM(Math.max(v.w, v.h)) + ' × ' + fmtMM(Math.min(v.w, v.h)) + ' мм'; }
    var same = {
      size: function (a, b) { return Math.abs(Math.max(a.w, a.h) - Math.max(b.w, b.h)) < 0.6 && Math.abs(Math.min(a.w, a.h) - Math.min(b.w, b.h)) < 0.6; },
      layers: function (a, b) { return a === b; },
      sides: function (a, b) { return a === b; },
      thick: function (a, b) { return Math.abs(a - b) < 0.06; },
      cuout: function (a, b) { return Math.abs(a - b) < 4; },
      hole: function (a, b) { return Math.abs(a.min - b.min) < 0.03; },
      track: function (a, b) { return Math.abs(a - b) < 0.015; },
      mat: function (a, b) { return matMap(a) === matMap(b); },
      mask: function (a, b) { return colorKey(a) === colorKey(b); },
      silk: function (a, b) { return colorKey(a) === colorKey(b); },
      qty: function (a, b) { return a === b; }
    };
    var txt = {
      sides: function (v) { return v === 2 ? 'две стороны' : 'одна сторона'; }, size: sizeTxt, layers: String, thick: function (v) { return fmtMM(v) + ' мм'; }, cuout: function (v) { return v + ' мкм'; }, cuin: function (v) { return v + ' мкм'; },
      hole: function (v) { return fmtMM(v.min) + ' мм' + (v.hits ? ' · ' + fmt(v.hits) + ' отв.' : ''); }, track: function (v) { return fmtMM(v) + ' мм'; },
      qty: function (v) { return fmt(v) + ' шт'; },
      mask: function (v) { return optText('mask', colorKey(v)) || v; },
      silk: function (v) { return optText('silk', colorKey(v)) || v; },
      mat: function (v) { var m = matMap(v), tg = /tg\s*[-:=]?\s*(\d{3})/i.exec(String(v)); return m ? firstPart(optText('mat', m)) + (tg ? ', Tg ' + tg[1] : '') : v; }
    };
    function optText(key, value) { var o = value ? el(key).querySelector('option[value="' + value + '"]') : null; return o ? o.textContent.trim() : ''; }
    // ближайший пункт списка; если он отличается от значения из файла — вернёт его подпись
    function pick(key, v, eps) {
      var s = el(key), o = nearest(s, v); if (!o) return false;
      s.value = o.value;
      return Math.abs(parseFloat(o.value) - v) > eps + 1e-9 ? o.textContent.trim() : true;
    }
    var apply = {
      sides: function (v) { el('sides').value = String(v); },
      size: function (v) { el('len').value = decIn(Math.round(Math.max(v.w, v.h) * 10) / 10); el('wid').value = decIn(Math.round(Math.min(v.w, v.h) * 10) / 10); },
      layers: function (v) { var r = pick('layers', v, 0); syncInner(); return r; },
      thick: function (v) { return pick('thick', v, 0.03); },
      cuout: function (v) { return pick('cuout', v, 2); },
      cuin: function (v) { return pick('cuin', v, 2); },
      hole: function (v) { el('hole').value = decIn(Math.round(v.min * 100) / 100); },
      track: function (v) { el('track').value = decIn(Math.round(v * 1000) / 1000); },
      mat: function (v) { var m = matMap(v); if (!m) return false; el('mat').value = m; },
      mask: function (v) { var c = colorKey(v); if (!c) return false; el('mask').value = c; },
      silk: function (v) { var c = colorKey(v); if (!c || !el('silk').querySelector('[value="' + c + '"]')) return false; el('silk').value = c; },
      qty: function (v) { var q = el('qty'); q.value = Math.max(v, parseFloat(q.min) || 1); }
    };
    var labels = { sides: 'Расположение компонентов', size: 'Габариты', layers: 'Число слоёв', thick: 'Толщина платы', cuout: 'Медь наружных слоёв', cuin: 'Медь внутренних слоёв', mat: 'Материал', mask: 'Цвет маски', silk: 'Цвет маркировки', track: 'Мин. ширина дорожки', hole: 'Мин. отверстие', qty: 'Тираж' };
    function resolve(field) {
      var c = cands(field), label = labels[field];
      if (!c.length && field === 'silk') { // как с HASL: подставляем белую и просим уточнить
        el('silk').value = 'white';
        put(field, 'check', label, 'не указан → белая', null, 'уточните выбор', 'уточните');
        return fieldNote(field, 'В файлах цвет не указан — подставили белую.');
      }
      if (!c.length) return put(field, 'need', label, field === 'track' || field === 'hole' || field === 'size' || field === 'layers' ? 'не найдено в файлах' : 'нет в файлах', null);
      var top = c[0];
      var r = apply[field](top.v);
      if (r === false) return put(field, 'need', label, top.v + ' — выберите из списка', null);
      var f = txt[field] || String, near = typeof r === 'string' ? (field === 'layers' ? r + ' ' + plural(+r, 'слой', 'слоя', 'слоёв') : r) : null; // «1,6 мм», «35 мкм» — единица в тексте пункта
      var where = /Gerber|сверлов|апертур/.test(top.src) ? 'По Gerber' : 'В ' + top.src.split(',')[0];
      var nearMsg = near ? where + ' ' + f(top.v) + ' — в списке нет, считаем по ' + near + '.' : '';
      if (same[field]) {
        var diff = c.slice(1).filter(function (x) { return !same[field](top.v, x.v); });
        if (diff.length) {
          put(field, 'conflict', label, [top].concat(diff).map(function (x) { return x.src.split(',')[0] + ': ' + f(x.v); }).join(' · '), null);
          // «В ТЗ 1,5 мм → считаем по 1,6 мм. В ODB++ 0,39 мм — похоже на значение САПР по умолчанию.»
          var nm = function (x) { return /Gerber|сверлов|апертур|пасты|контура/.test(x.src) ? 'Gerber' : x.src.split(',')[0]; };
          var fv = function (v) { return field === 'layers' ? v + ' ' + plural(v, 'слой', 'слоя', 'слоёв') : f(v); };
          return fieldNote(field, (near ? 'В ' + nm(top) + ' ' + fv(top.v) + ' → считаем по ' + near : 'Считаем по ' + nm(top) + ': ' + fv(top.v)) + '. ' +
            diff.map(function (x) { return 'В ' + nm(x) + ' ' + fv(x.v) + (x.weak ? ' — похоже на значение САПР по умолчанию' : ''); }).join('; ') + '.');
        }
      }
      if (top.weak) { put(field, 'check', label, f(top.v) + (near ? ' → ' + near : ''), null, 'из ODB++ — проверьте: если стек слоёв в проекте не заполнен, САПР пишет значение по умолчанию'); return fieldNote(field, nearMsg); }
      if (top.check) { put(field, 'check', label, f(top.v), null, 'из ' + top.src + ' — ' + top.check); return fieldNote(field, 'Габариты по контуру Gerber: ' + top.check + '.'); }
      if (near) { put(field, 'check', label, f(top.v) + ' → ' + near, null, 'из ' + top.src + ' — в списке нет, считаем по ближайшему', 'ближайшее'); return fieldNote(field, nearMsg); }
      put(field, 'ok', label, f(top.v), top.src);
    }
    if (store.length && (entries.length || ipc || odb || spec)) {
      if (spec && spec.qty && BD.list.length < 2) resolve('qty'); // в комплекте тираж — число комплектов
      ['size', 'layers', 'thick', 'cuout'].forEach(resolve);
      if (parseInt(val('layers'), 10) >= 4) resolve('cuin');
      resolve('mat');
      // покрытие: из IPC-2581 или .gbrjob, иначе HASL с подсветкой
      (function () {
        var cf = $('#calc-finish-confirm'), c = [];
        if (ipc && ipc.finish) c.push({ v: ipc.finish, src: 'IPC-2581' });
        if (spec && spec.finish) c.push({ v: spec.finish, src: 'ТЗ' });
        if (job && job.finish) c.push({ v: job.finish, src: '.gbrjob' });
        var hit = null, top = c[0];
        if (top) hit = finishMap.filter(function (p) { return p[0].test(top.v); })[0];
        if (hit) { el('finish').value = hit[1]; finishPending = false; cf.hidden = true; put('finish', 'ok', 'Финишное покрытие', firstPart(selText('finish')), top.src); return; }
        el('finish').value = 'hasl'; finishPending = true;
        cf.firstChild.textContent = top ? 'В файле указано «' + top.v + '» — подставили HASL. ' : 'В файлах покрытие не указано — подставили HASL. ';
        cf.hidden = false;
        put('finish', 'need', 'Финишное покрытие', (top ? 'не распознано' : 'не указано') + ' → HASL', null);
      })();
      resolve('mask'); resolve('silk'); resolve('track'); resolve('hole');
      if (cands('sides').length) resolve('sides');
      if (ipcAsm) items.push({ field: 'asm', state: 'ok', label: 'Компоненты — для монтажа', value: fmt(ipcAsm.total) + ' шт', src: asmSrc });
      else if (gerberPads) items.push({ field: 'asm', state: 'ok', label: 'SMD-площадок — для оценки монтажа', value: '≈ ' + fmt(gerberPads), src: 'слоя пасты' });
      else items.push({ field: 'asm', state: 'need', label: 'Данные для монтажа', value: 'не найдены', src: null });
    } else { finishPending = false; $('#calc-finish-confirm').hidden = true; if (DEF && !man.finish) resetKey('finish'); }

    // шапка списка файлов
    var fields = items.filter(function (i) { return i.field !== 'asm' && i.field !== 'qty'; });
    var ok = fields.filter(function (i) { return i.state === 'ok'; }).length, conf = fields.filter(function (i) { return i.state === 'conflict'; }).length, need = fields.length - ok;
    var nOk = store.filter(function (it) { return it.status === 'ok'; }).length;
    if (store.length) {
      if (!fields.length) setHead('err', 'Не удалось получить данные из файлов', errs ? 'Проверьте формат: нужны IPC-2581, ODB++, Gerber со сверловкой или .gbrjob' : '');
      else {
        var nBad = store.length - nOk, pre = nBad ? 'Не удалось прочитать ' + nBad + ' ' + plural(nBad, 'файл', 'файла', 'файлов') + '. ' : '';
        setHead(conf ? 'err' : need ? 'warn' : 'ok', 'Заполнено ' + ok + ' из ' + fields.length + ' ' + plural(fields.length, 'параметра', 'параметров', 'параметров'),
          pre + (conf ? 'Источники расходятся в ' + conf + ' ' + plural(conf, 'поле', 'полях', 'полях') + ' — отмечены ниже' : need ? need + ' ' + plural(need, 'поле требует', 'поля требуют', 'полей требуют') + ' внимания — отмечены ниже' : 'Проверьте значения ниже'));
      }
    }
    $('#calc-files').classList.toggle('is-done', fields.length > 0);
    renderFiles();
    if (busyCount) setHead('busy', 'Разбираем файлы…', '');

    // отчёт
    var src = []; if (ipc) src.push('IPC-2581'); if (odb) src.push('ODB++'); if (spec) src.push('требования к плате (ТЗ)'); if (job) src.push('.gbrjob'); if (pnpA) src.push('Pick&Place'); if (copper.length || outlines.length || drills.length) src.push('Gerber');
    var rf = $('#calc-report-files'); rf.textContent = src.length ? 'Источники: ' + src.join(', ') + '.' : '';
    if (src.length > 1) { rf.appendChild(document.createElement('br')); rf.appendChild(document.createTextNode('При расхождении приоритет у IPC-2581, затем ODB++, ТЗ, .gbrjob и Gerber.')); }
    var list = $('#calc-report-list'); list.innerHTML = '';
    var order = { conflict: 0, err: 1, need: 2, check: 2, ok: 3 };
    items.sort(function (a, b) { return order[a.state] - order[b.state]; }).forEach(function (i) {
      var li = document.createElement('li');
      var note = i.note ? i.note : i.src ? 'из ' + i.src : i.state === 'conflict' ? 'выберите верное значение вручную' : i.field === 'finish' ? 'уточните выбор' : 'заполните вручную';
      li.innerHTML = '<span class="ic ic--' + i.state + '" aria-hidden="true"></span><span><span class="lbl"></span> <span class="src"></span></span><span class="v"></span>';
      li.querySelector('.lbl').textContent = i.label; li.querySelector('.src').textContent = note; li.querySelector('.v').textContent = i.value;
      if (i.state === 'ok') { li.className = 'is-ok'; li.hidden = !reportOkOpen; }
      list.appendChild(li);
    });
    var nOkRows = items.filter(function (i) { return i.state === 'ok'; }).length;
    if (nOkRows) {
      var tl = document.createElement('li'); tl.className = 'cx-report__more';
      var tb = document.createElement('button'); tb.type = 'button'; tb.className = 'cx-report__toggle';
      var setT = function () { tb.textContent = reportOkOpen ? 'Скрыть значения, взятые из файлов' : (nOkRows < items.length ? 'Ещё ' : 'Все ') + nOkRows + ' ' + plural(nOkRows, 'значение взято', 'значения взяты', 'значений взяты') + ' из файлов — показать'; tb.setAttribute('aria-expanded', reportOkOpen ? 'true' : 'false'); };
      tb.addEventListener('click', function () { reportOkOpen = !reportOkOpen; $$('#calc-report-list li.is-ok').forEach(function (x) { x.hidden = !reportOkOpen; }); setT(); });
      setT(); tl.appendChild(tb); list.appendChild(tl);
    }
    $('#calc-report').hidden = !items.length;
    var bad = items.filter(function (i) { return i.state !== 'ok'; }).length;
    $('#calc-report-sum').textContent = 'Что взято из файлов: ' + items.filter(function (i) { return i.state === 'ok'; }).length + ' из ' + items.length + (bad ? ' · ' + bad + ' ' + plural(bad, 'требует', 'требуют', 'требуют') + ' внимания' : '');
    if (!reportShown && items.length) $('#calc-report').open = true; // при первой загрузке файлов — раскрыт, дальше как оставил пользователь
    reportShown = items.length > 0;
    if (asmFilled) asmInit();
    asmFilled = false;
    updatePasteHint();
    if (ipcAsm) { // данные о компонентах из IPC-2581, ODB++ или Pick&Place: подставляем сразу
      applyAsm();
      // подробная оценка — если корпуса известны; по Pick&Place без посадочных мест остаёмся в быстрой
      if (!ipcAsm.est || ipcAsm.unknown <= ipcAsm.total * 0.3) { $('input[name="asm"][value="detail"]').checked = true; }
      else { $('input[name="asm"][value="quick"]').checked = true; }
      syncAsm();
    }
    // «Ещё параметры» раскрываем, если внутри есть поле, требующее внимания
    var hidden = items.some(function (i) { return (i.field === 'track' || i.field === 'hole' || i.field === 'sides') && i.state !== 'ok'; });
    if (hidden) $('#calc-more').open = true;
    // плата уже открывалась — возвращаем правки клиента поверх значений из файлов
    if (cur && BD.list.length > 1 && bstate[cur.name] && !filling) { var st = bstate[cur.name]; st.qty = val('qty') || st.qty; restoreValues(st); }
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
    return Promise.resolve(new File([xml], 'controller.cvg', { type: 'application/xml' }));
  }
  /* Демо-проект «Контроллер освещения»: комплект из трёх плат в тех форматах, в которых их обычно присылают.
     — controller.cvg — IPC-2581: основная плата, 4 слоя, монтаж с двух сторон. Заполняется почти всё, без замечаний.
     — power-odb.zip + power.txt — ODB++ и ТЗ блока питания. Здесь нарочно несостыковки, как в жизни:
       медь в САПР 70 мкм, а ТЗ осталось со старой ревизии (1 oz) — «расхождение»; толщина в ТЗ 1,5 мм — такой
       нет в списке, считаем по 1,6; в ODB++ толщина 0,39 мм — стек не заполнен, САПР записала значение по умолчанию;
       дроссель в нестандартном корпусе — монтаж его не распознал.
     — sensor-gerber.zip — Gerber X2 из KiCad, сверловка, .gbrjob и Pick&Place: плата датчика, две в комплекте.
       Покрытие в файлах не указано — HASL с «уточните»; монтаж — оценка по Pick&Place (числа с «~»). */
  function demoProject() {
    var files = [];
    return demoIPC().then(function (ipc) {
      files.push(new File([ipc], 'controller.cvg', { type: 'application/xml' }));
      if (typeof JSZip === 'undefined') return files;
      return Promise.all([demoODB(), demoGerber()]).then(function (z) {
        files.push(z[0]);
        files.push(new File([
          'Требования к плате: power (блок питания 24 → 12 / 5 / 3,3 В), ред. 1\n' +
          'Количество: 50 шт\n' +
          'Слоёв: 2\n' +
          'Размер: 60 x 40 мм\n' +
          'Толщина: 1,5 мм\n' +
          'Медь: 1 oz\n' +
          'Материал: FR-4, Tg 150\n' +
          'Покрытие: HASL бессвинцовый\n' +
          'Маска: чёрная\n' +
          'Маркировка: белая\n' +
          'Мин. отверстие: 0,4 мм\n'], 'power.txt', { type: 'text/plain' }));
        files.push(z[1]);
        return files;
      });
    });
  }
  // ODB++ блока питания: 2 слоя, 60 × 40 мм, медь 70 мкм, 22 компонента сверху (5 выводных)
  function demoODB() {
    var z = new JSZip(), R = 'odb/', S = R + 'steps/pcb/', L = S + 'layers/';
    var lay = function (row, type, name) { return 'LAYER {\n   ROW=' + row + '\n   CONTEXT=BOARD\n   TYPE=' + type + '\n   NAME=' + name + '\n   POLARITY=POSITIVE\n}\n\n'; };
    z.file(R + 'matrix/matrix', 'STEP {\n   COL=1\n   NAME=PCB\n}\n\n' + lay(1, 'COMPONENT', 'COMP_+_TOP') + lay(2, 'SILK_SCREEN', 'SST') + lay(3, 'SOLDER_MASK', 'SMT') +
      lay(4, 'SIGNAL', 'TOP') + lay(5, 'SIGNAL', 'BOTTOM') + lay(6, 'SOLDER_MASK', 'SMB') + lay(7, 'DRILL', 'DRILL') + lay(8, 'COMPONENT', 'COMP_+_BOT'));
    z.file(R + 'misc/info', 'UNITS=MM\nJOB_NAME=power\nODB_VERSION_MAJOR=8\nODB_VERSION_MINOR=1\nODB_SOURCE=Altium Designer\nSAVE_APP=Altium Designer 24 (демо)\n');
    z.file(R + 'misc/attrlist', 'UNITS=MM\n.board_thickness = 0.39\n'); // стек слоёв не заполнен — значение по умолчанию
    z.file(L + 'top/attrlist', 'UNITS=MM\n.copper_weight = 70\n');
    z.file(L + 'bottom/attrlist', 'UNITS=MM\n.copper_weight = 70\n');
    z.file(S + 'profile', 'UNITS=MM\n#\nS P 0\nOB 0 0 I\nOS 60 0\nOS 60 40\nOS 0 40\nOS 0 0\nOE\nSE\n');
    var feat = function (syms, body) { return 'UNITS=MM\n#\n' + syms.map(function (s, i) { return '$' + i + ' ' + s; }).join('\n') + '\n#\n' + body.join('\n') + '\n'; };
    z.file(L + 'top/features', feat(['r250', 'r1000', 'rect1800x1200'], [
      'L 8 30 22 30 0 P 0', 'L 22 30 22 18 0 P 0', 'L 30 12 52 12 1 P 0', 'L 52 12 52 28 1 P 0', 'L 12 8 28 8 0 P 0',
      'P 14 20 2 P 0 0', 'P 18 20 2 P 0 0', 'P 34 26 2 P 0 0', 'P 38 26 2 P 0 0']));
    z.file(L + 'bottom/features', feat(['r400', 'r1500'], ['L 5 5 55 5 1 P 0', 'L 5 35 55 35 1 P 0', 'L 20 10 40 10 0 P 0']));
    z.file(L + 'drill/tools', 'UNITS=MM\nTHICKNESS=0\nUSER_PARAMS=\n' +
      [[1, 'VIA', 400, 450], [2, 'PLATED', 1000, 1100], [3, 'PLATED', 1300, 1400]].map(function (t) {
        return 'TOOLS {\n   NUM=' + t[0] + '\n   TYPE=' + t[1] + '\n   FINISH_SIZE=' + t[2] + '\n   DRILL_SIZE=' + t[3] + '\n}\n';
      }).join(''));
    var holes = []; for (var i = 0; i < 18; i++) holes.push('P ' + (6 + i * 2.8).toFixed(1) + ' 22 0 P 0 0');
    for (i = 0; i < 9; i++) holes.push('P ' + (8 + i * 5) + ' 34 1 P 0 0');
    holes.push('P 4 8 2 P 0 0', 'P 4 13.08 2 P 0 0', 'P 56 8 2 P 0 0', 'P 56 13.08 2 P 0 0');
    z.file(L + 'drill/features', 'UNITS=MM\n#\n$0 r400\n$1 r1000\n$2 r1300\n#\n' + holes.join('\n') + '\n');
    // корпуса: [название, выводов, выводных]
    var pk = [['TerminalBlock_2P_P5.08mm', 2, 2], ['TO-220-5_Vertical', 5, 5], ['L_Bourns_SRR1260', 2, 0], ['D_SOD-123F', 2, 0], ['CP_Radial_D10.0mm_P5.00mm', 2, 2],
      ['C_1206_3216Metric', 2, 0], ['R_0805_2012Metric', 2, 0], ['SOT-223-3_TabPin2', 4, 0], ['LED_0805_2012Metric', 2, 0], ['Fuse_1812_4532Metric', 2, 0]];
    z.file(S + 'eda/data', 'UNITS=MM\nHDR Altium Designer\nLYR TOP BOTTOM\n#\n' + pk.map(function (p, k) {
      var s = 'PKG ' + p[0] + ' 2.54 -3 -3 3 3 ;\n';
      for (var n = 1; n <= p[1]; n++) s += 'PIN ' + n + ' ' + (n <= p[2] ? 'T' : 'S') + ' ' + (n * 2.54).toFixed(2) + ' 0 0 E Y\n';
      return s;
    }).join(''));
    var cmp = [], put = function (pkg, ref, part, x, y) { cmp.push('CMP ' + pkg + ' ' + x + ' ' + y + ' 0 N ' + ref + ' ' + part + ' ;0=' + (pk[pkg][2] ? 2 : 1)); };
    put(0, 'J1', 'TB-2P-IN', 4, 10); put(0, 'J2', 'TB-2P-OUT', 56, 10); put(1, 'U1', 'LM2596T-ADJ', 20, 22); put(2, 'L1', 'SRR1260-470M', 32, 20);
    put(3, 'D1', 'SS34', 26, 14); put(4, 'C1', '470uF_35V', 12, 30); put(4, 'C2', '330uF_25V', 44, 30); put(7, 'U2', 'AMS1117-3.3', 48, 14); put(8, 'LED1', 'GREEN', 54, 34); put(9, 'F1', '2A', 8, 18);
    ['10uF', '10uF', '100nF', '100nF', '22uF', '100nF'].forEach(function (v, k) { put(5, 'C' + (k + 3), v, 30 + k * 3, 6); });
    ['10k', '1k', '4k7', '1k', '330R', '0R'].forEach(function (v, k) { put(6, 'R' + (k + 1), v, 30 + k * 3, 34); });
    z.file(L + 'comp_+_top/components', 'UNITS=MM\n#\n@0 .comp_mount_type\n#\n' + cmp.join('\n') + '\n');
    z.file(L + 'comp_+_bot/components', 'UNITS=MM\n#\n');
    return z.generateAsync({ type: 'blob' }).then(function (b) { return new File([b], 'power-odb.zip', { type: 'application/zip' }); });
  }
  // Gerber X2 (KiCad 8) датчика: 2 слоя, 30 × 20 мм, 11 компонентов сверху, сверловка, .gbrjob, Pick&Place
  function demoGerber() {
    var z = new JSZip(), c = function (v) { return String(Math.round(v * 1e6)); }, xy = function (x, y) { return 'X' + c(x) + 'Y' + c(y); };
    var head = function (fn) {
      return '%TF.GenerationSoftware,KiCad,Pcbnew,8.0.4*%\n%TF.CreationDate,2026-10-01T12:00:00+03:00*%\n%TF.ProjectId,sensor,73656e736f72,1.2*%\n' +
        '%TF.SameCoordinates,Original*%\n%TF.FileFunction,' + fn + '*%\n%TF.FilePolarity,Positive*%\n%FSLAX46Y46*%\n' +
        'G04 Gerber Fmt 4.6, Leading zero omitted, Abs format (unit mm)*\n%MOMM*%\n%LPD*%\nG01*\n';
    };
    // компоненты: обозначение, номинал, посадочное место, x, y, площадки [dx, dy]
    var two = [[-0.5, 0], [0.5, 0]];
    var parts = [
      ['C1', '100n', 'C_0402_1005Metric', 6, 6, two], ['C2', '100n', 'C_0402_1005Metric', 9, 6, two], ['C3', '1u', 'C_0402_1005Metric', 12, 6, two], ['C4', '10u', 'C_0603_1608Metric', 15, 6, two],
      ['R1', '4k7', 'R_0402_1005Metric', 18, 6, two], ['R2', '4k7', 'R_0402_1005Metric', 21, 6, two], ['R3', '1k', 'R_0402_1005Metric', 24, 6, two],
      ['D1', 'LED', 'LED_0603_1608Metric', 24, 14, two],
      ['U1', 'BME280', 'Bosch_LGA-8_2.5x2.5mm_P0.65mm_ClockwisePinNumbering', 15, 13, [[-0.97, -0.33], [-0.97, 0.33], [-0.33, 0.97], [0.33, 0.97], [0.97, 0.33], [0.97, -0.33], [0.33, -0.97], [-0.33, -0.97]]],
      ['U2', 'TLV75533', 'SOT-23-5', 8, 13, [[-0.95, -1.1], [0, -1.1], [0.95, -1.1], [0.95, 1.1], [-0.95, 1.1]]],
      ['J1', 'Conn_01x04', 'PinHeader_1x04_P2.54mm_Vertical', 3, 10, null]
    ];
    var pads = []; parts.forEach(function (p) { if (p[5]) p[5].forEach(function (d) { pads.push(xy(p[3] + d[0], p[4] + d[1]) + 'D03*'); }); });
    var tht = [[3, 6.19], [3, 8.73], [3, 11.27], [3, 13.81]], vias = [[10, 16], [14, 16], [20, 10], [26, 8], [27, 16], [6, 16]];
    var cuTop = head('Copper,L1,Top') + '%ADD10C,0.200000*%\n%ADD11R,0.600000X0.500000*%\n%ADD12C,1.700000*%\n%ADD13C,0.600000*%\nD11*\n' + pads.join('\n') + '\nD12*\n' +
      tht.map(function (p) { return xy(p[0], p[1]) + 'D03*'; }).join('\n') + '\nD13*\n' + vias.map(function (p) { return xy(p[0], p[1]) + 'D03*'; }).join('\n') +
      '\nD10*\n' + [[[4, 10], [8, 13]], [[8, 13], [15, 13]], [[15, 13], [24, 14]], [[6, 6], [24, 6]], [[10, 16], [20, 10]]].map(function (s) { return xy(s[0][0], s[0][1]) + 'D02*\n' + xy(s[1][0], s[1][1]) + 'D01*'; }).join('\n') + '\nM02*\n';
    var cuBot = head('Copper,L2,Bot') + '%ADD10C,0.300000*%\n%ADD12C,1.700000*%\n%ADD13C,0.600000*%\nD12*\n' + tht.map(function (p) { return xy(p[0], p[1]) + 'D03*'; }).join('\n') +
      '\nD13*\n' + vias.map(function (p) { return xy(p[0], p[1]) + 'D03*'; }).join('\n') + '\nD10*\n' + xy(2, 2) + 'D02*\n' + xy(28, 2) + 'D01*\n' + xy(28, 18) + 'D01*\n' + xy(2, 18) + 'D01*\nM02*\n';
    var edge = head('Profile,NP') + '%ADD10C,0.050000*%\nD10*\n' + xy(0, 0) + 'D02*\n' + xy(30, 0) + 'D01*\n' + xy(30, 20) + 'D01*\n' + xy(0, 20) + 'D01*\n' + xy(0, 0) + 'D01*\nM02*\n';
    var paste = head('Paste,Top') + '%ADD11R,0.550000X0.450000*%\nD11*\n' + pads.join('\n') + '\nM02*\n';
    var mask = function (side) { return head('Soldermask,' + side) + '%ADD11R,0.700000X0.600000*%\n%ADD12C,1.900000*%\nD11*\n' + (side === 'Top' ? pads.join('\n') + '\n' : '') + 'D12*\n' + tht.map(function (p) { return xy(p[0], p[1]) + 'D03*'; }).join('\n') + '\nM02*\n'; };
    var silk = head('Legend,Top') + '%ADD10C,0.150000*%\nD10*\n' + xy(1.5, 4.8) + 'D02*\n' + xy(4.5, 4.8) + 'D01*\n' + xy(4.5, 15.2) + 'D01*\n' + xy(1.5, 15.2) + 'D01*\n' + xy(1.5, 4.8) + 'D01*\nM02*\n';
    var drl = 'M48\n; DRILL file {KiCad 8.0.4} date 2026-10-01T12:00:00+0300\n; FORMAT={-:-/ absolute / metric / decimal}\nFMAT,2\nMETRIC\nT1C0.300\nT2C1.000\n%\nG90\nG05\nT1\n' +
      vias.map(function (p) { return 'X' + p[0] + 'Y' + p[1]; }).join('\n') + '\nT2\n' + tht.map(function (p) { return 'X' + p[0] + 'Y' + p[1]; }).join('\n') + '\nM30\n';
    var job = { Header: { GenerationSoftware: { Vendor: 'KiCad', Application: 'Pcbnew', Version: '8.0.4' }, CreationDate: '2026-10-01T12:00:00+03:00' },
      GeneralSpecs: { ProjectId: { Name: 'sensor', GUID: '73656e736f72', Revision: '1.2' }, Size: { X: 30, Y: 20 }, LayerNumber: 2, BoardThickness: 1.6, Finish: 'None' },
      DesignRules: [{ Layers: 'Outer', PadToPad: 0.2, PadToTrack: 0.2, TrackToTrack: 0.2, MinLineWidth: 0.2 }],
      MaterialStackup: [{ Type: 'Legend', Color: 'White', Name: 'Top Silk Screen' }, { Type: 'SolderPaste', Name: 'Top Solder Paste' }, { Type: 'SolderMask', Color: 'Green', Thickness: 0.01, Name: 'Top Solder Mask' },
        { Type: 'Copper', Thickness: 0.035, Name: 'F.Cu' }, { Type: 'Dielectric', Thickness: 1.51, Material: 'FR4', Name: 'F.Cu/B.Cu' }, { Type: 'Copper', Thickness: 0.035, Name: 'B.Cu' },
        { Type: 'SolderMask', Color: 'Green', Thickness: 0.01, Name: 'Bottom Solder Mask' }] };
    var pos = 'Ref,Val,Package,PosX,PosY,Rot,Side\n' + parts.map(function (p) { return ['"' + p[0] + '"', '"' + p[1] + '"', '"' + p[2] + '"', p[3].toFixed(4), (-p[4]).toFixed(4), '0.0000', 'top'].join(','); }).join('\n') + '\n';
    var D = 'sensor/';
    z.file(D + 'sensor-F_Cu.gbr', cuTop); z.file(D + 'sensor-B_Cu.gbr', cuBot); z.file(D + 'sensor-Edge_Cuts.gbr', edge);
    z.file(D + 'sensor-F_Paste.gbr', paste); z.file(D + 'sensor-F_Mask.gbr', mask('Top')); z.file(D + 'sensor-B_Mask.gbr', mask('Bot'));
    z.file(D + 'sensor-F_Silkscreen.gbr', silk); z.file(D + 'sensor.drl', drl); z.file(D + 'sensor-job.gbrjob', JSON.stringify(job, null, 2));
    z.file(D + 'sensor-all-pos.csv', pos);
    return z.generateAsync({ type: 'blob' }).then(function (b) { return new File([b], 'sensor-gerber.zip', { type: 'application/zip' }); });
  }
  function scrollToFiles() { $('#calc-drop').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); }
  /* Демо-проект заменяет загруженные файлы: если среди них есть файлы клиента — сначала спрашиваем. */
  var demoNames = {};
  function loadDemo(make) {
    store = []; renderFiles();
    make().then(function (fs) {
      fs = [].concat(fs); demoNames = {};
      fs.forEach(function (f) { demoNames[f.name] = true; });
      // комплект: датчиков — два на изделие; сначала открываем плату с расхождениями
      perSetMap = { sensor: 2 }; offMap = {}; solo = false; wantBoard = 'power';
      handleFiles(fs); scrollToFiles();
    });
  }
  var demoWarn = $('#calc-demo-warn');
  $('#calc-demo-ipc').addEventListener('click', function () {
    var own = store.filter(function (it) { return !demoNames[it.name]; });
    if (!own.length) { demoWarn.hidden = true; loadDemo(demoProject); return; }
    $('#calc-demo-warn-text').textContent = (own.length === 1 ? 'Вы уже загрузили файл ' + own[0].name : 'Вы уже загрузили свои файлы (' + own.length + ')') +
      '. Демо-проект заменит ' + (own.length === 1 ? 'его' : 'их') + ', а поля калькулятора заполнятся данными примера.';
    $('#calc-demo-cancel').textContent = own.length === 1 ? 'Оставить мой файл' : 'Оставить мои файлы';
    demoWarn.hidden = false;
    var dd = demoWarn.closest('details'); if (dd) dd.open = true; // вопрос — внутри спойлера «Демо-проект»
    $('#calc-demo-replace').focus();
  });
  $('#calc-demo-replace').addEventListener('click', function () { demoWarn.hidden = true; loadDemo(demoProject); });
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
  // «~» перед числом — значение оценено (по пасте Gerber, по названиям посадочных мест, среднее по группе), а не взято из файла как есть
  function markEst(k, on) { var e = el(k), w = e && e.closest('.cx-num'); if (w) w.classList.toggle('is-est', !!on); }
  function estKeys() { return $$('.cx-num.is-est input').map(function (i) { return i.dataset.key; }); }
  function setEst(keys) { $$('.cx-num.is-est').forEach(function (w) { w.classList.remove('is-est'); }); (keys || []).forEach(function (k) { markEst(k, true); }); }
  $$('.cx-num input').forEach(function (i) { i.addEventListener('input', function () { markEst(i.dataset.key, false); }); });
  function asmInit() {
    setEst([]);
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
  $('#calc-asm-reset').addEventListener('click', function () { asmInit(); asmFilled = false; asmEst = false; syncApply(); updatePasteHint(); recalc(); });
  var asmEst = false; // в блоке 3 — оценка (по пасте Gerber или по Pick&Place без точных корпусов), а не поштучный учёт
  function applyAsm() {
    asmEst = ipcAsm ? !!ipcAsm.est : gerberPads > 0;
    if (ipcAsm) {
      var a = ipcAsm;
      var gn = function (g) { return a.groups[g] ? a.groups[g].n : 0; };
      el('q_smd').value = a.smd - gn('bga') - gn('qfn') - gn('qfp'); el('q_dip').value = a.thtPins; el('q_bga').value = gn('bga'); el('q_qfn').value = gn('qfn') + gn('qfp');
      asmKeys.forEach(function (k) { setBadge(k, 'ok', asmSrc + (a.est && k !== 'q_smd' ? ', оценка' : '')); });
      setEst([]);
      if (a.est) markEst('q_dip', a.thtPins > 0); // выводы DIP — по названиям посадочных мест
      if (a.noFp) markEst('q_smd', true);        // без посадочных мест всё, кроме разъёмов, считаем простыми SMD
      $$('.cx-table input[type="number"]').forEach(function (i) { i.value = 0; i.classList.remove('is-need'); });
      Object.keys(a.groups).forEach(function (g) {
        var m = groupMap[g]; if (!m) return;
        var gi = a.groups[g], c = el(m[0]); c.value = gi.n; c.classList.remove('is-need');
        if (a.noFp) markEst(m[0], true);
        // выводов на корпус: в группе бывают разные корпуса — тогда это среднее, отмечаем «~»
        if (m[1]) { var p = el(m[1]); p.value = Math.round(gi.pins / gi.n); p.classList.remove('is-need'); markEst(m[1], a.est || Object.keys(gi.k || {}).length > 1); }
      });
    } else {
      el('q_smd').value = Math.round(gerberPads / 2.4);
      setBadge('q_smd', 'ok', 'файлов, оценка');
      setEst(['q_smd']);
      ['q_dip', 'q_bga', 'q_qfn'].forEach(function (k) { setBadge(k, 'need'); });
    }
    asmFilled = true; syncApply(); updatePasteHint(); recalc();
  }
  function updatePasteHint() {
    var n = $('#calc-asm-note'), why = $('#calc-asm-why');
    var mode = asmMode();
    if ((!gerberPads && !ipcAsm) || mode === 'none' || (mode === 'detail' && !ipcAsm)) { n.hidden = true; return; }
    n.hidden = false;
    var a = ipcAsm, srcEst = !a || !!a.est; // данные о монтаже — оценка, а не поштучный учёт
    // строка «Ориентировочно» — сразу под заголовком, перед «Подробнее»
    var w = $('#calc-asm-est'), more = n.querySelector('.cx-note__more');
    if (!w) { w = document.createElement('p'); w.id = 'calc-asm-est'; w.className = 'cx-note__est'; }
    if (w.nextSibling !== more) n.insertBefore(w, more);
    n.classList.toggle('is-est', srcEst); w.hidden = !srcEst;
    if (srcEst) w.innerHTML = '<b>Ориентировочно:</b> ' + (!a ? 'компоненты не считались поштучно — их число оценено по площадкам пасты.'
      : a.noFp ? 'число компонентов точное, корпуса и тип монтажа оценены по позиционным обозначениям.'
      : 'число компонентов точное, тип монтажа и выводы оценены по посадочным местам.') + ' Оценённые числа в полях — со знаком <span class="cx-tilde">~</span>.';
    why.innerHTML = '';
    var P = function (label, html) { var p = document.createElement('p'); p.innerHTML = '<b>' + label + '</b> ' + html; why.appendChild(p); return p; };
    var sides = function (top, bot) { return bot ? 'верх — ' + fmt(top) + ', низ — ' + fmt(bot) : 'одна сторона'; };
    if (a) {
      $('#calc-asm-title').innerHTML = 'В файле ' + asmSrc + ': <b>' + fmt(a.total) + ' ' + plural(a.total, 'компонент', 'компонента', 'компонентов') + '</b> — SMD ' + fmt(a.smd) + ' шт, DIP ' + fmt(a.tht) + ' шт (' + (a.est ? '~' : '') + fmt(a.thtPins) + ' ' + plural(a.thtPins, 'вывод', 'вывода', 'выводов') + ')' + (a.sides === 2 ? ', на двух сторонах' : '') + '.';
      var gs = Object.keys(a.groups).map(function (g) { return groupName[g] + ' — ' + a.groups[g].n; });
      if (a.est) {
        P('Точно:', fmt(a.total) + ' ' + plural(a.total, 'компонент', 'компонента', 'компонентов') + ' (' + sides(a.top, a.bot) + ')' + (a.uniq ? ', уникальных позиций — ' + fmt(a.uniq) : '') + '. Это из файла Pick&Place.');
        P('Оценено:', a.noFp
          ? 'посадочных мест в файле нет, поэтому разъёмы (X, J, P…) посчитаны как DIP, а все остальные компоненты — как простые SMD.'
          : 'тип монтажа (SMD или DIP) и число выводов — по названиям посадочных мест.' + (gs.length ? ' Распознанные группы: ' + gs.join(', ') + '.' : ''));
        P('Проверьте:', a.noFp ? 'микросхемы в корпусах BGA, QFN и QFP — укажите их число в полях ниже или в подробной оценке.' : 'выводы DIP-компонентов и корпуса BGA, QFN, QFP.' + (a.unknown ? ' Не распознано ' + fmt(a.unknown) + ' ' + plural(a.unknown, 'корпус', 'корпуса', 'корпусов') + ' — добавьте их в подходящие группы.' : ''));
        P('Точнее:', 'IPC-2581 или ODB++ — в них есть тип каждого вывода.');
      } else {
        P('Точно:', 'компоненты, стороны и тип выводов взяты из посадочных мест и BOM в файле (' + sides(a.top, a.bot) + ').');
        if (gs.length) P('Группы корпусов', 'для подробной оценки распознаны по названиям посадочных мест: ' + gs.join(', ') + '.');
        if (a.unknown) P('Не распознано:', fmt(a.unknown) + ' ' + plural(a.unknown, 'корпус', 'корпуса', 'корпусов') + ' — их нет в подробной таблице, добавьте вручную в подходящие группы.');
      }
    } else {
      var est = Math.round(gerberPads / 2.4);
      $('#calc-asm-title').innerHTML = 'В ваших файлах <b>≈ ' + fmt(gerberPads) + ' SMD-площадок</b> — это около ' + fmt(est) + ' ' + plural(est, 'компонента', 'компонентов', 'компонентов') + (gerberBottom ? ', на двух сторонах' : '') + '.';
      P('Из файлов:', '≈ ' + fmt(gerberPads) + ' площадок на слое пасты' + (gerberBottom ? ' (низ — ' + fmt(gerberBottom) + ')' : '') + '. Отдельных компонентов в Gerber нет.');
      P('Как оценили:', 'в среднем 2,4 площадки на компонент — большинство деталей на плате резисторы и конденсаторы с двумя площадками. На платах с крупными микросхемами компонентов может быть заметно меньше.');
      P('Не видно по Gerber:', 'выводы DIP-компонентов и корпуса BGA, QFN, QFP — укажите их в полях ниже или переключитесь на подробную оценку.');
      P('Точнее:', 'Pick&Place даст точное число компонентов, IPC-2581 или ODB++ — ещё и тип каждого вывода.');
    }
    $('#calc-to-detail').hidden = mode === 'detail';
    syncApply();
  }



  /* --- Определение типа файла --- */
  function classify(e) { return e._c || (e._c = classifyRaw(e)); }
  function classifyRaw(e) {
    var n = e.name.toLowerCase(), t = e.text;
    if (/\.gbrjob$/.test(n)) return { type: 'job' };
    if (/\.(csv|pos|txt)$/.test(n) && isPnP(t)) return { type: 'pnp' };
    var ff = /%TF\.FileFunction,([^*]+)\*%/.exec(t);
    if (ff) {
      var p = ff[1].split(',');
      if (p[0] === 'Copper') return { type: 'copper', layer: parseInt(String(p[1]).replace(/\D/g, ''), 10), side: p[2] };
      if (p[0] === 'Profile') return { type: 'outline', score: 3 };
      if (p[0] === 'Paste') return { type: 'paste', side: p[1] };
      if (p[0] === 'Soldermask') return { type: 'mask' };
      if (p[0] === 'Legend') return { type: 'silk' };
      if (/Plated|NonPlated/.test(p[0])) return { type: 'drill' };
    }
    if (/^M48/m.test(t) || /\.(drl|xln|exc)$/.test(n)) return { type: 'drill' };
    if (/edge[._]cuts|\.gko$|outline|profile|\.gml$/.test(n)) return { type: 'outline', score: 2 };
    if (/\.gm\d*$/.test(n)) return { type: 'outline', score: /\.gm1$/.test(n) ? 1.5 : 1 }; // механические слои Altium
    if (/[fb][._]paste|\.gtp$|\.gbp$/.test(n)) return { type: 'paste' };
    if (/[fb][._]mask|\.gts$|\.gbs$/.test(n)) return { type: 'mask' };
    if (/silk|\.gto$|\.gbo$/.test(n)) return { type: 'silk' };
    if (/f[._]cu|\.gtl$/.test(n)) return { type: 'copper', side: 'Top' };
    if (/b[._]cu|\.gbl$/.test(n)) return { type: 'copper', side: 'Bot' };
    if (/in\d+[._]cu|\.g\d+$|\.gp\d+$/.test(n)) return { type: 'copper', side: 'Inr' };
    if (/%FS[LT][AI]X\d\dY\d\d\*%/.test(t)) return { type: 'gerber?' };
    return { type: 'skip' };
  }

  /* --- Габариты по контуру --- */
  // Крайние координаты Gerber, мм. clip — учитывать только точки внутри рамки { x0, y0, x1, y1 }.
  function gerberExt(t, clip) {
    var fs = /%FS([LT])[AI]X(\d)(\d)Y(\d)(\d)\*%/.exec(t);
    if (!fs) return null;
    var inch = /%MOIN\*%/.test(t), dec = parseInt(fs[3], 10), intd = parseInt(fs[2], 10), trail = fs[1] === 'T', k = inch ? 25.4 : 1;
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
      if (m[1] !== undefined) x = cv(m[1]) * k;
      if (m[2] !== undefined) y = cv(m[2]) * k;
      if (clip && (x < clip.x0 || x > clip.x1 || y < clip.y0 || y > clip.y1)) return;
      minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y); n++;
    });
    return n < 2 ? null : { x0: minX, y0: minY, x1: maxX, y1: maxY };
  }
  function gerberBBox(t) { var e = gerberExt(t); return e ? { w: e.x1 - e.x0, h: e.y1 - e.y0 } : null; }
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
  var colorRu = [[/зел/, 'green'], [/ч[её]рн/, 'black'], [/син|голуб/, 'blue'], [/красн/, 'red'], [/бел/, 'white'], [/ж[её]лт/, 'yellow']];
  function colorKey(v) {
    var s = String(v).toLowerCase(), w = (/[a-z]+/.exec(s) || [])[0];
    if (colorMap[s]) return colorMap[s];
    var en = Object.keys(colorMap).filter(function (k) { return s.indexOf(k) >= 0; })[0]; if (en) return en;
    var ru = colorRu.filter(function (p) { return p[0].test(s); })[0]; return ru ? ru[1] : w && colorMap[w] || null;
  }
  // High Tg — от Tg 170; «FR-4 TG150» — обычный FR-4 (mid-Tg)
  function matMap(s) { s = String(s).toLowerCase(); if (/fr-?4/.test(s)) { var tg = /tg\s*[-:=]?\s*(\d{3})/.exec(s); return tg ? (+tg[1] >= 170 ? 'fr4tg' : 'fr4') : /tg/.test(s) ? 'fr4tg' : 'fr4'; } if (/poly|pi\b/.test(s)) return 'pi'; if (/alu/.test(s)) return 'alu'; if (/rogers|ptfe|ro\d/.test(s)) return 'hf'; return null; }

  var gerberPads = 0, gerberBottom = 0;

  /* ================= Расчёт (ДЕМО-ставки) ================= */
  var R = {
    prepPcb: 4500, prepPerLayer: 900,
    shipK: 0.1, shipFix: 3500, // доставка и таможня: доля от стоимости + постоянная часть на отправку (брокер, сборы)
    dm2: { 1: 380, 2: 520, 4: 1150, 6: 1750, 8: 2350, 10: 3000, 12: 3650, 14: 4300, 16: 5000, 18: 5800, 20: 6600, 22: 7400, 24: 8200 },
    thick: { 0.4: 1.15, 0.6: 1.08, 0.8: 1.03, 1.0: 1, 1.2: 1, 1.6: 1, 2.0: 1.08, 2.4: 1.15, 3.2: 1.3 },
    cu: { 18: 0.97, 35: 1, 70: 1.25, 105: 1.5 },
    finish: { hasl: 1, enig: 1.25, iag: 1.18, enepig: 1.4, flash: 1.3, hard: 1.6, other: 1.2 },
    mat: { fr4: 1, fr4tg: 1.2, alu: 1.35 },
    mask: { green: 1, black: 1.03, blue: 1.03, red: 1.03, white: 1.05, yellow: 1.05, other: 1.05 },
    asmSetupSide: 9000, feeder: 180, joint: { chip: 0.9, fine: 1.4, qfn: 2.2, bga: 3.2 }, tht: 6, conn: 45, fw: 60, coat: 0.6, axi: 6000
  };
  function feeders(n) { return n ? Math.min(n, Math.round(6 + n * 0.2)) : 0; } // оценка числа уникальных позиций
  function shipCost(goods) { return goods * R.shipK + R.shipFix; }
  function volumeK(area) { if (area < 2) return 1.6; if (area < 10) return 1.25; if (area < 50) return 1; if (area < 200) return 0.85; return 0.72; }

  /* Проверка заполненности — до любого расчёта (и локального, и на сервере). */
  function precheck() {
    var qty = num('qty'), L = num('len'), W = num('wid');
    var res = { ok: false, lines: [], warn: [], engineer: false };
    if (!(qty > 0) || !(L > 0) || !(W > 0)) { res.reason = 'Укажите тираж и габариты платы, чтобы увидеть оценку.'; return res; }
    var empty = ['thick', 'cuout', 'mat', 'finish', 'mask', 'silk'].filter(function (k) { return !val(k); });
    if (parseInt(val('layers'), 10) >= 4 && !val('cuin')) empty.push('cuin');
    if (empty.length) { res.reason = 'Заполните отмеченные поля — после этого покажем оценку.'; return res; }
    res.ok = true;
    return res;
  }

  /* Старая формула (демо-ставки) — пока в панели не опубликована первая версия формулы. */
  function compute() {
    var qty = num('qty'), L = num('len'), W = num('wid');
    var res = precheck();
    if (!res.ok) return res;
    res.ok = false;
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
    res.notes = [];
    var asmEmpty = (m === 'quick' && !['q_smd', 'q_dip', 'q_bga', 'q_qfn'].some(function (k) { return num(k) > 0; })) ||
      (m === 'detail' && !$$('.cx-table input[type="number"]').some(function (i) { return parseFloat(i.value) > 0 && /^d_/.test(i.dataset.key); }));
    if (m !== 'none' && asmEmpty) {
      res.lines.push({ name: 'Монтаж', text: 'заполните блок 3', soft: true });
      res.lines.push({ name: 'Компоненты', text: 'по BOM', soft: true });
      asm = 0; axi = false;
    } else if (m !== 'none') {
      res.lines.push({ name: 'Монтаж' + (sides === 2 ? ', 2 стороны' : '') + (asmEst ? ' — оценка' : ''), v: asm });
      if (asmEst) res.notes.push('Монтаж посчитан ориентировочно: компоненты не учтены поштучно — проверьте блок 3.');
      if (axi) res.lines.push({ name: 'Рентген-контроль BGA / QFN', v: R.axi });
      res.lines.push({ name: 'Компоненты', text: 'по BOM', soft: true });
    }
    res.lines.push({ name: 'Проверка по ПМИ', text: 'входит', incl: true });
    res.lines.push({ name: 'Приёмка ОТК на фабрике', text: 'входит', incl: true });
    var goods = res.lines.reduce(function (a, l) { return a + (l.v || 0); }, 0);
    var ship = shipCost(goods);
    res.goods = goods; res.ship = ship;
    res.lines.push({ name: 'Доставка и таможня', v: ship });
    res.total = goods + ship;
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
  /* Расчёт на сервере (плагин панели, pcb-panel/v1/calc/quote): уходят только значения полей калькулятора —
     файлы проекта остаются в браузере. Включается, когда в панели опубликована формула (window.PCBCalcServer). */
  var SRV = window.PCBCalcServer || null, srvTimer = 0, srvSeq = 0, srvPending = false;
  function recalcSetServer() {
    var sets = num('qty');
    var jobs = eachBoard(function (b) { var p = precheck(); return { name: b.name, n: perSet(b.name), pre: p, body: fieldData() }; });
    var notReady = jobs.filter(function (j) { return !j.pre.ok; });
    if (!(sets > 0) || !jobs.length || notReady.length) { // до сервера: не хватает данных — объясняем, какой плате
      srvSeq++; srvPending = false;
      renderResult(combineSet(notReady.map(function (j) { var r = j.pre; r.name = j.name; r.n = j.n; return r; }), sets));
      return;
    }
    srvPending = true; emit();
    S.state.textContent = 'Пересчитываем…';
    clearTimeout(srvTimer);
    // сервер умеет комплект (pcb-panel 0.8.4, флаг set): один запрос, доставка, таможня, минимальная сумма,
    // НДС и округление — один раз на заказ; тираж платы сервер считает сам: комплекты × n
    if (SRV.set && jobs.length <= 20) {
      srvTimer = setTimeout(function () {
        var seq = ++srvSeq;
        fetch(SRV.url, { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ set: { sets: sets, boards: jobs.map(function (j) { return { name: j.name, n: j.n, data: j.body }; }) } }) })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
          .then(function (r) { if (seq === srvSeq) { srvPending = false; renderResult(fromServerSet(r, jobs, sets)); } })
          .catch(function () {
            if (seq !== srvSeq) return;
            srvPending = false;
            renderResult({ ok: false, lines: [], warn: [], reason: 'Не удалось получить оценку — проверьте соединение и измените любое поле, чтобы повторить.' });
          });
      }, 250);
      return;
    }
    srvTimer = setTimeout(function () {
      var seq = ++srvSeq;
      Promise.all(jobs.map(function (j) {
        return fetch(SRV.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(j.body) })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
          .then(function (r) { r.name = j.name; r.n = j.n; if (!r.qty) r.qty = +j.body.qty; return r; });
      })).then(function (list) { if (seq === srvSeq) { srvPending = false; renderResult(combineSet(list, sets)); } })
        .catch(function () {
          if (seq !== srvSeq) return;
          srvPending = false;
          renderResult({ ok: false, lines: [], warn: [], reason: 'Не удалось получить оценку — проверьте соединение и измените любое поле, чтобы повторить.' });
        });
    }, 250);
  }
  // Ответ сервера по комплекту — к виду combineSet(): открытая плата в строках, пометка оценочного монтажа
  // (asm_est сервер не получает — это знание калькулятора)
  function fromServerSet(r, jobs, sets) {
    r = r || {};
    r.set = true; r.lines = r.lines || []; r.warn = r.warn || []; r.notes = r.notes || []; r.boards = r.boards || [];
    if (!r.ok) { if (!r.reason) r.reason = 'Не удалось получить оценку — измените любое поле, чтобы повторить.'; return r; }
    var c = curBoard();
    r.lines.forEach(function (l) { if (l.board) l.cur = !!(c && c.name === l.board); });
    r.boards.forEach(function (b) { if (b.qty == null) b.qty = sets * (b.n || 1); });
    if (r.qty == null) r.qty = sets;
    var est = jobs.filter(function (j) { return j.body.asm_est; }).map(function (j) { return '«' + j.name + '»'; });
    if (est.length) r.notes.unshift('Монтаж посчитан ориентировочно — компоненты не учтены поштучно: ' + est.join(', ') + '. Проверьте блок 3 у ' + (est.length > 1 ? 'этих плат.' : 'этой платы.'));
    return r;
  }
  /* сообщить форме заявки, что расчёт изменился или пересчитывается */
  function emit() { try { document.dispatchEvent(new CustomEvent('pcb:calc', { detail: { pending: srvPending } })); } catch (e) { /* без формы */ } }
  function recalc() {
    if (silent) return;
    if (isMulti()) { var c = curBoard(); if (c && !analysing) bstate[c.name] = fieldData(); }
    if (isSet()) {
      if (!SRV) { renderResult(computeSet()); return; }
      return recalcSetServer();
    }
    if (!SRV) { renderResult(compute()); return; }
    var pre = precheck();
    if (!pre.ok) { srvSeq++; srvPending = false; renderResult(pre); return; }
    srvPending = true; emit();
    S.state.textContent = 'Пересчитываем…';
    clearTimeout(srvTimer);
    srvTimer = setTimeout(function () {
      var seq = ++srvSeq;
      fetch(SRV.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(fieldData()) })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (r) { if (seq === srvSeq) { srvPending = false; renderResult(r); } })
        .catch(function () {
          if (seq !== srvSeq) return;
          srvPending = false;
          renderResult({ ok: false, lines: [], warn: [], reason: 'Не удалось получить оценку — проверьте соединение и измените любое поле, чтобы повторить.' });
        });
    }, 250);
  }
  function currentRes() { return lastRes || (SRV ? { ok: false, lines: [], warn: [], reason: 'Оценка ещё считается' } : isSet() ? computeSet() : compute()); }
  function renderResult(r) {
    lastRes = r;
    setTimeout(emit, 0); // после отрисовки итога
    saveSoon();
    if (r.ok) track({ goal: 'calc_result' });
    renderBoardSums(r.set ? r : null);
    var sl = $('.cx-sum__label'); if (sl) sl.textContent = isSet() ? 'Ориентировочная стоимость комплекта' : 'Ориентировочная стоимость партии';
    if (used || parsed) root.classList.remove('is-fresh');
    S.br.innerHTML = ''; S.warn.hidden = true; S.per.textContent = ''; S.state.textContent = ''; S.term.innerHTML = '';
    S.total.classList.remove('is-muted');
    if (!r.ok) {
      S.total.textContent = 'Нужны данные'; S.total.classList.add('is-muted'); S.mini.textContent = '—';
      S.state.textContent = r.reason; renderSummaryText(r); return;
    }
    if (r.engineer) {
      S.total.textContent = 'Расчёт инженером'; S.total.classList.add('is-muted'); S.mini.textContent = 'расчёт инженером';
      S.state.textContent = r.set ? 'Для всех плат комплекта автоматическая оценка была бы неточной — рассчитаем вручную по вашей заявке.' : 'Для выбранных параметров автоматическая оценка была бы неточной — рассчитаем вручную по вашей заявке.';
    } else {
      S.total.textContent = fmt(r.lo) + ' – ' + fmtR(r.hi);
      S.mini.textContent = 'от ' + fmtR(r.lo);
      S.per.textContent = r.set ? '≈ ' + fmtR(r.per) + ' за комплект · ' + fmt(r.qty) + ' ' + plural(r.qty, 'комплект', 'комплекта', 'комплектов') + (r.partial ? ' · без плат с расчётом инженером' : '') + (r.vat && r.vat.show ? ' · в т. ч. НДС ' + String(r.vat.pct).replace('.', ',') + ' %' : '') : '≈ ' + fmtR(r.per) + ' за плату · ' + fmt(r.qty) + ' шт' + (r.vat && r.vat.show ? ' · в т. ч. НДС ' + String(r.vat.pct).replace('.', ',') + ' %' : '');
    }
    r.lines.forEach(function (l) {
      var li = document.createElement('li');
      if (l.soft) li.className = 'is-soft'; if (l.incl) li.className = 'is-incl'; if (l.cur) li.className = 'is-cur';
      if (l.sub && l.sub.length) { S.br.appendChild(boardLine(l)); return; }
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

  // строка платы в итоге комплекта: название — кнопка спойлера, под ней статьи этой платы
  var subOpen = {};
  function boardLine(l) {
    var li = document.createElement('li'); li.className = 'is-board' + (l.cur ? ' is-cur' : '') + (subOpen[l.board] ? ' is-open' : '');
    var b = document.createElement('button'); b.type = 'button'; b.className = 'cx-break__board';
    b.setAttribute('aria-expanded', subOpen[l.board] ? 'true' : 'false');
    var n = document.createElement('span'), v = document.createElement('span');
    n.textContent = l.name; v.textContent = l.text; b.appendChild(n); b.appendChild(v);
    var ul = document.createElement('ul'); ul.className = 'cx-break__sub'; ul.hidden = !subOpen[l.board];
    l.sub.forEach(function (s) {
      var x = document.createElement('li'); if (s.soft) x.className = 'is-soft';
      x.innerHTML = '<span></span><span></span>'; x.children[0].textContent = s.name; x.children[1].textContent = s.text ? s.text : '≈ ' + fmtR(s.v);
      ul.appendChild(x);
    });
    b.addEventListener('click', function () { subOpen[l.board] = !subOpen[l.board]; ul.hidden = !subOpen[l.board]; li.classList.toggle('is-open', subOpen[l.board]); b.setAttribute('aria-expanded', subOpen[l.board] ? 'true' : 'false'); });
    li.appendChild(b); li.appendChild(ul);
    return li;
  }
  function selText(key) { var e = el(key); return e && e.selectedOptions ? e.selectedOptions[0].textContent : ''; }
  /* Текстовая сводка всех заполненных полей — для формы заявки (кнопка «Прикрепить данные из калькулятора»). */
  var inSummary = false;
  function buildSetSummary(r) {
    var sets = num('qty'), L = [];
    var on = boards.filter(function (b) { return inSet(b.name); });
    L.push('Комплект: ' + (sets > 0 ? fmt(sets) + ' ' + plural(sets, 'комплект', 'комплекта', 'комплектов') : 'число комплектов не указано') + '; плат в заказе — ' + on.length + ' из ' + boards.length);
    inSummary = true;
    try {
      eachBoard(function (b) {
        var x = r && r.boards ? r.boards.filter(function (y) { return y.name === b.name; })[0] : null;
        L.push('');
        L.push('Плата «' + b.name + '»' + (inSet(b.name) ? ' — ' + perSet(b.name) + ' шт в комплекте' + (sets > 0 ? ', всего ' + fmt(sets * perSet(b.name)) + ' шт' : '') : ' — не входит в заказ'));
        buildSummary(null).split('\n').forEach(function (s) { if (!/^(Тираж|Файлы проекта|Оценка калькулятора)/.test(s)) L.push('  ' + s); });
        if (x && x.ok) L.push('  Оценка платы: ' + (x.engineer ? 'расчёт инженером' : r.ship != null && x.goods != null ? '≈ ' + fmtR(x.goods) + ' без доставки (≈ ' + fmtR(x.goods / x.qty) + ' за плату)' : fmt(x.lo) + ' – ' + fmtR(x.hi) + ' (≈ ' + fmtR(x.per) + ' за плату)'));
      }, true);
    } finally { inSummary = false; }
    L.push('');
    if (r && r.ok && r.ship != null) L.push('Доставка и таможня одной партией: ≈ ' + fmtR(r.ship) + (r.saving > 1 ? ' (экономия ≈ ' + fmtR(r.saving) + ' против заказа плат по отдельности)' : ''));
    if (r && r.ok) {
      L.push('Оценка калькулятора за комплект: ' + (r.engineer ? 'расчёт инженером' : fmt(r.lo) + ' – ' + fmtR(r.hi) + ' (≈ ' + fmtR(r.per) + ' за комплект' + (r.vat && r.vat.show ? ', в т. ч. НДС ' + String(r.vat.pct).replace('.', ',') + ' %' : '') + ')'));
      (r.notes || []).forEach(function (t) { L.push(t); });
      if (r.warn && r.warn.length) L.push('Требует расчёта инженером: ' + r.warn.join(', '));
      if (r.term) L.push('Срок: ' + r.term);
    } else if (r && r.reason) L.push('Оценка калькулятора: нет — ' + r.reason.charAt(0).toLowerCase() + r.reason.slice(1));
    var okFiles = store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; });
    if (okFiles.length) L.push('Файлы проекта: ' + okFiles.join(', ') + ' — приложены к заявке');
    return L.join('\n');
  }
  function buildSummary(r) {
    if (isSet() && !inSummary) return buildSetSummary(r);
    var L = [], lc = function (t) { return t ? t.charAt(0).toLowerCase() + t.slice(1) : t; };
    if (isMulti() && solo && !inSummary) { // «Посчитать отдельно»: одна плата, но менеджер видит весь проект
      var cb = boardsInfo.filter(function (b) { return b.current; })[0];
      L.push('Плата «' + (cb ? cb.name : '') + '» посчитана отдельно — одна из ' + boardsInfo.length + ' плат проекта');
      L.push('Все платы проекта: ' + boardsInfo.map(function (b) { return b.name + ' (' + [b.size ? b.size + ' мм' : '', b.layers ? b.layers + ' сл.' : '', b.comps ? b.comps + ' комп.' : ''].filter(Boolean).join(', ') + ')'; }).join('; '));
    }
    var has = function (k) { return String(val(k)).trim() !== ''; };
    var dec = function (k) { return String(val(k)).trim().replace('.', ','); };
    L.push('Тираж: ' + (val('qty') || '—') + ' шт');
    L.push('Габариты: ' + (dec('len') || '—') + ' × ' + (dec('wid') || '—') + ' мм');
    L.push('Слоёв: ' + val('layers') + (has('thick') ? ', толщина ' + selText('thick') : ''));
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
      var ap = estKeys(), q = function (k) { return (ap.indexOf(k) >= 0 ? '~' : '') + (val(k) || 0); };
      L.push('Монтаж (быстрая оценка): простые SMD — ' + q('q_smd') + ' корп., DIP — ' + q('q_dip') + ' выв. всего' +
        ', BGA — ' + q('q_bga') + ' корп., QFN, QFP и др. — ' + q('q_qfn') + ' корп.');
    } else {
      var rows = $$('.cx-table tbody tr').map(function (tr) {
        var ins = tr.querySelectorAll('input'), n = ins[0] ? +ins[0].value || 0 : 0;
        if (!n) return null;
        var pins = ins[1] ? +ins[1].value || 0 : 0, ap = function (i) { return i && i.closest('.cx-num.is-est') ? '~' : ''; };
        return tr.querySelector('th').textContent.trim() + ' — ' + ap(ins[0]) + n + ' шт' + (pins ? ' × ' + ap(ins[1]) + pins + ' выв.' : '');
      }).filter(Boolean);
      L.push('Монтаж (подробная оценка)' + (rows.length ? ':' : ': корпуса не указаны'));
      rows.forEach(function (t) { L.push('  · ' + t); });
    }
    if (m !== 'none') L.push('Проверка по ПМИ: входит в заказ');
    // оценка
    if (r && r.ok) {
      L.push('Оценка калькулятора: ' + (r.engineer ? 'расчёт инженером' : fmt(r.lo) + ' – ' + fmtR(r.hi) + ' (≈ ' + fmtR(r.per) + ' за плату' + (r.vat && r.vat.show ? ', в т. ч. НДС ' + String(r.vat.pct).replace('.', ',') + ' %' : '') + ')'));
      if (!r.engineer && r.lines && r.lines.length) L.push('Из чего складывается: ' + r.lines.map(function (l) { return l.name + ' ' + (l.text || '≈ ' + fmtR(l.v)); }).join('; '));
      if (r.warn && r.warn.length) L.push('Требует расчёта инженером: ' + r.warn.join(', '));
      if (r.term) L.push('Срок: ' + r.term);
    } else if (r && r.reason) L.push('Оценка калькулятора: нет — ' + lc(r.reason));
    var okFiles = store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; });
    if (okFiles.length) L.push('Файлы проекта: ' + okFiles.join(', ') + ' — приложены к заявке');
    return L.join('\n');
  }
  function renderSummaryText(r) {
    var out = $('#calc-summary-text'); if (!out) return;
    summaryText = buildSummary(r); out.textContent = summaryText;
  }
  var summaryText = '';

  /* Значения полей калькулятора — для расчёта на сервере и для заявки. Без файлов и их содержимого. */
  function fieldData() {
    var d = {};
    ['qty', 'len', 'wid', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel'].forEach(function (k) { d[k] = DEC.indexOf(k) >= 0 ? decOut(val(k)) : val(k); });
    if (el('cuin').disabled) delete d.cuin;
    d.special = $$('.cx-checks input:checked').map(function (i) { return i.dataset.key; });
    d.asm = asmMode();
    d.asm_values = {};
    $$('[data-key^="q_"], [data-key^="d_"], [data-key^="p_"]').forEach(function (i) { if (+i.value) d.asm_values[i.dataset.key] = +i.value; });
    d.finish_pending = finishPending;
    if (asmEst && d.asm !== 'none') d.asm_est = true;
    return d;
  }

  /* Для формы заявки (assets/js/request-form.js): сводка и загруженные файлы. */
  /* Короткая сводка для плашки «Данные прикреплены» в форме заявки */
  function firstPart(t) { return String(t || '').split(' (')[0].trim(); }
  function getBrief() {
    var r = currentRes(), parts = [], lc = function (t) { return t ? t.charAt(0).toLowerCase() + t.slice(1) : t; };
    var n = parseInt(val('layers'), 10);
    if (n) parts.push(n + ' ' + plural(n, 'слой', 'слоя', 'слоёв'));
    var L = String(val('len')).trim().replace('.', ','), W = String(val('wid')).trim().replace('.', ',');
    if (L && W) parts.push(L + ' × ' + W + ' мм');
    if (isSet()) { // комплект: число комплектов и состав
      parts = [];
      if (val('qty')) parts.push(fmt(num('qty')) + ' ' + plural(num('qty'), 'комплект', 'комплекта', 'комплектов'));
      parts.push('платы: ' + boards.filter(function (b) { return inSet(b.name); }).map(function (b) { return b.name + (perSet(b.name) > 1 ? ' × ' + perSet(b.name) : ''); }).join(', '));
    } else {
    if (isMulti()) parts.unshift('плата «' + (curBoard() || {}).name + '» отдельно');
    if (val('qty')) parts.push(fmt(num('qty')) + ' шт');
    if (val('mat') && val('mat') !== 'fr4') parts.push(firstPart(selText('mat')));
    if (val('finish')) parts.push(finishPending ? 'покрытие не уточнено' : firstPart(selText('finish')));
    var m = asmMode();
    parts.push(m === 'none' ? 'без монтажа' : (val('sides') === '2' ? 'монтаж с двух сторон' : 'монтаж с одной стороны'));
    }
    var est = null;
    if (srvPending) est = { pending: true };
    else if (r && r.ok && r.engineer) est = { engineer: true, text: 'рассчитает инженер' };
    else if (r && r.ok) est = { text: fmt(r.lo) + ' – ' + fmtR(r.hi), vat: r.vat && r.vat.show ? 'в т. ч. НДС ' + String(r.vat.pct).replace('.', ',') + ' %' : '' };
    else est = { none: true, text: r && r.reason ? r.reason : 'оценки нет' };
    return {
      params: parts.join(' · '),
      estimate: est,
      files: store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; })
    };
  }
  /* «отпечаток» расчёта: значения полей и файлы — чтобы понять, изменился ли расчёт после прикрепления */
  function fingerprint() {
    return JSON.stringify([fieldData(), isMulti() ? [bstate, perSetMap, offMap, solo, boardKey] : null, store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name + ':' + it.size; })]);
  }

  window.PCBCalc = {
    getSummary: function () { return buildSummary(currentRes()); },
    getBrief: getBrief,
    fingerprint: fingerprint,
    isPending: function () { return srvPending || busyCount > 0; },
    isUsed: function () { return used; },
    isRestoring: function () { return restoring; },
    /* структурированные данные расчёта — для аналитики заявок (/panel) */
    getData: function () {
      var r = currentRes(), d = fieldData();
      if (r && r.version) d.calc_version = r.version; // версия формулы панели, по которой клиент увидел оценку
      if (r && r.ok) {
        d.estimate = r.engineer ? 'engineer' : { lo: Math.round(r.lo), hi: Math.round(r.hi), per: Math.round(r.per) };
        if (!r.engineer && r.vat && r.vat.show) d.estimate.vat = r.vat.pct; // «в т. ч. НДС N %» — в панели и письме
        d.term = r.term; d.warn = r.warn;
      }
      d.files = store.filter(function (it) { return it.status === 'ok'; }).map(function (it) { return it.name; });
      if (isMulti()) {
        d.board = boardsInfo.filter(function (b) { return b.current; }).map(function (b) { return b.name; })[0];
        d.boards = boardsInfo.map(function (b) { return { name: b.name, size: b.size, layers: b.layers, comps: b.comps }; });
        if (solo) d.solo = true;
      }
      if (isSet()) {
        d.board = boardsInfo.filter(function (b) { return b.current; }).map(function (b) { return b.name; })[0];
        d.boards = boardsInfo.map(function (b) { return { name: b.name, size: b.size, layers: b.layers, comps: b.comps }; });
        // комплект: параметры и оценка каждой платы; поля верхнего уровня — открытой платы (как раньше)
        d.set = { sets: num('qty') || 0, boards: eachBoard(function (b, st) {
          var x = r && r.boards ? r.boards.filter(function (y) { return y.name === b.name; })[0] : null, o = { name: b.name, n: perSet(b.name), on: inSet(b.name), data: fieldData() };
          if (x && x.ok) o.estimate = x.engineer ? 'engineer' : { lo: Math.round(x.lo), hi: Math.round(x.hi), per: Math.round(x.per) };
          if (x && x.ok && !x.engineer && x.goods != null) o.estimate.goods = Math.round(x.goods); // без доставки — она общая на комплект
          return o;
        }, true) };
        if (r && r.ok && r.ship != null) { d.set.ship = Math.round(r.ship); d.set.saving = Math.round(r.saving || 0); }
      }
      return d;
    },
    getFiles: function () { return store.filter(function (it) { return it.status === 'ok' && it.file; }).map(function (it) { return it.file; }); }
  };

  /* ================= Восстановление расчёта из заявки (/panel) =================
     Панель кладёт в localStorage 'pcb_calc_restore' { n: номер заявки, data: PCBCalc.getData() из заявки,
     files: [{ name, url }] } и открывает страницу с #pcb-restore. Файлы скачиваются по ссылкам панели —
     это работает только у вошедшего сотрудника. Пакет живёт 5 минут и удаляется сразу после чтения. */
  var DATA_KEYS = ['qty', 'len', 'wid', 'layers', 'thick', 'cuout', 'cuin', 'mat', 'finish', 'mask', 'silk', 'track', 'hole', 'sides', 'panel'];
  function restoreNote(p, missed) {
    var d = p.data || {}, box = document.createElement('div');
    box.className = 'cx-restore';
    box.setAttribute('role', 'status');
    box.style.cssText = 'box-sizing:border-box;max-width:min(1290px,90vw);margin:16px auto 0;padding:12px 16px;border-radius:8px;background:var(--color-gold-light,#FBF3DF);color:#6B5418;font-size:14px;line-height:1.5;box-shadow:inset 3px 0 0 var(--color-copper,#C0703A)';
    var est = d.estimate && d.estimate.lo ? ' В заявке оценка была ' + fmt(d.estimate.lo) + '–' + fmtR(d.estimate.hi) + '.' : (d.estimate === 'engineer' ? ' В заявке был расчёт инженером.' : '');
    box.textContent = 'Расчёт из заявки ' + (p.n || '') + ' — открыт из панели.' + est + (missed ? ' Файлы скачать не удалось — показаны только параметры.' : '') + ' Изменения здесь в заявку не сохраняются.';
    var head = $('#calc-head'); // под полосой «Онлайн-калькулятор», в потоке страницы — не липнет к шапке
    root.insertBefore(box, head ? head.nextSibling : root.firstChild);
  }
  /* src — подпись бейджа («из заявки»); без src (черновик) изменённое после разбора файлов поле — «вручную» */
  function restoreValues(d, src) {
    var mark = function (k) { if (src) setBadge(k, 'ok', src); else if (parsed || asmKeys.indexOf(k) >= 0) setBadge(k, 'manual'); };
    DATA_KEYS.forEach(function (k) {
      var e = el(k); if (!e || d[k] === undefined || d[k] === null) return;
      var dv = DEC.indexOf(k) >= 0 ? decIn(d[k]) : d[k];
      if (String(e.value) !== String(dv)) {
        e.value = dv;
        if (badgeMap[k] || k === 'len' || k === 'wid') mark(k === 'len' || k === 'wid' ? 'size' : k);
      }
    });
    if (Array.isArray(d.special)) $$('.cx-checks input').forEach(function (i) { i.checked = d.special.indexOf(i.dataset.key) >= 0; });
    if (d.asm) { var r = $('input[name="asm"][value="' + d.asm + '"]'); if (r) r.checked = true; }
    var av = d.asm_values || {};
    $$('[data-key^="q_"], [data-key^="d_"], [data-key^="p_"]').forEach(function (i) {
      var v = av[i.dataset.key] || 0;
      if (+i.value === v) return;
      i.value = v; i.classList.remove('is-need'); markEst(i.dataset.key, false);
      if (asmKeys.indexOf(i.dataset.key) >= 0) mark(i.dataset.key);
    });
    if (d.finish_pending === false) { finishPending = false; $('#calc-finish-confirm').hidden = true; }
    if (d.asm_est !== undefined || d.asm) asmEst = !!d.asm_est;
    syncInner(); syncAsm(); recalc();
  }
  function restoreFromPanel() {
    if (location.hash !== '#pcb-restore') return;
    var raw = null, p = null;
    try { raw = localStorage.getItem('pcb_calc_restore'); localStorage.removeItem('pcb_calc_restore'); } catch (e) { /* хранилище закрыто */ }
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { /* без очистки адреса */ }
    try { p = JSON.parse(raw); } catch (e) { p = null; }
    if (!p || !p.data || Date.now() - (p.t || 0) > 5 * 60 * 1000) return;
    var files = Array.isArray(p.files) ? p.files : [];
    if (!files.length) { restoreValues(p.data, 'заявки'); restoreNote(p, false); return; }
    setHead('busy', 'Загружаем файлы из заявки…', '');
    Promise.all(files.map(function (f) {
      return fetch(f.url, { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok || r.redirected) throw new Error('file'); // не вошёл — WordPress уводит на форму входа
        return r.blob();
      }).then(function (b) { return new File([b], f.name, { type: b.type }); });
    })).then(function (list) {
      restoreApply = function () { keepStates = false; restoreValues(p.data, 'заявки'); };
      wantBoard = p.data.board || null;
      if (p.data.set && Array.isArray(p.data.set.boards)) {
        bstate = {}; perSetMap = {}; offMap = {}; keepStates = true;
        p.data.set.boards.forEach(function (b) { bstate[b.name] = b.data || {}; perSetMap[b.name] = b.n || 1; if (b.on === false) offMap[b.name] = 1; if (b.data && p.data.set.sets) bstate[b.name].qty = p.data.set.sets; });
      }
      handleFiles(list);
      restoreNote(p, false);
    }).catch(function () { restoreValues(p.data, 'заявки'); restoreNote(p, true); });
  }

  /* ================= Черновик в браузере (form-draft.js) ================= */
  var D = window.PCBDraft || null, saveTimer = 0, noSave = false;
  function saveNow() {
    clearTimeout(saveTimer); saveTimer = 0;
    if (!D || restoring || noSave || !used) return;
    D.set('calc', {
      data: fieldData(), used: true, set: isMulti() ? { states: bstate, n: perSetMap, off: offMap, solo: solo, sets: solo ? setQty : val('qty') } : null, board: (boardsInfo.filter(function (b) { return b.current; })[0] || {}).name || null,
      files: store.map(function (it) { return { file: it.file }; }),
      demo: Object.keys(demoNames), approx: estKeys()
    });
  }
  function saveSoon() { if (D && !restoring && !noSave && used) { clearTimeout(saveTimer); saveTimer = setTimeout(saveNow, 300); } }
  // уход со страницы — сохранить сразу, не дожидаясь паузы
  window.addEventListener('pagehide', function () { if (saveTimer) saveNow(); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && saveTimer) saveNow(); });
  function draftNote(v, lost) {
    var box = document.createElement('div');
    box.className = 'cx-restore cx-restore--draft';
    box.setAttribute('role', 'status');
    var when = new Date(v.t).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
    var t = document.createElement('span');
    t.textContent = 'Восстановили ваш расчёт от ' + when + ' — он хранится только в этом браузере.' +
      (lost ? ' Файлы сохранить не удалось — загрузите их заново.' : '') + ' ';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'cx-restore__btn'; b.textContent = 'Начать заново';
    b.addEventListener('click', resetAll);
    box.appendChild(t); box.appendChild(b);
    var head = $('#calc-head');
    root.insertBefore(box, head ? head.nextSibling : root.firstChild);
  }
  function restoreDraft() {
    var finish = function () { restoring = false; emit(); };
    if (!D) { finish(); return; }
    D.get('calc').then(function (v) {
      if (!v || !v.data) { finish(); return; }
      used = true;
      (v.demo || []).forEach(function (n) { demoNames[n] = true; });
      var all = v.files || [], files = all.map(function (x) { return x && x.file; }).filter(function (f) { return typeof Blob !== 'undefined' && f instanceof Blob; });
      draftNote(v, files.length < all.length);
      if (!files.length) { restoring = false; restoreValues(v.data); return; }
      restoreApply = function () { restoring = false; keepStates = false; restoreValues(v.data); setEst(v.approx); }; // после разбора файлов — значения клиента поверх
      wantBoard = v.board || null;
      if (v.set) { bstate = v.set.states || {}; perSetMap = v.set.n || {}; offMap = v.set.off || {}; solo = !!v.set.solo; setQty = v.set.sets || ''; keepStates = true; }
      handleFiles(files, true);
    }).catch(finish);
  }
  /* после отправки заявки (request-form.js) черновик удалён — не сохранять, пока клиент снова что-то не изменит */
  document.addEventListener('pcb:draft-cleared', function () { clearTimeout(saveTimer); saveTimer = 0; });

  /* поля формы заявки (этап 4, #request) на расчёт не влияют */
  function isCalcField(t) { return t.matches('input,select') && !t.closest('#request'); }
  root.addEventListener('input', function (e) { if (isCalcField(e.target)) { used = true; recalc(); } });
  root.addEventListener('change', function (e) { if (isCalcField(e.target)) { used = true; recalc(); } });

  $('#calc-cta').addEventListener('click', function () {
    root.classList.remove('is-fresh');
    try { document.dispatchEvent(new CustomEvent('pcb:calc-attach', { detail: { source: 'cta' } })); } catch (e) { /* без формы */ }
    if (stages) stages.open('request');
    $('#request').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    sumBox.classList.remove('is-open'); $('#calc-summary-toggle').setAttribute('aria-expanded', 'false'); root.classList.remove('is-sum-open');
  });
  var sumBox = $('#calc-summary');
  $('#calc-summary-toggle').addEventListener('click', function () {
    var o = sumBox.classList.toggle('is-open'); this.setAttribute('aria-expanded', o ? 'true' : 'false');
    root.classList.toggle('is-sum-open', o); if (o && help) help.close();
  });

  root.classList.add('is-fresh'); // снимается при первом действии клиента или загрузке файлов
  root.addEventListener('input', function () { root.classList.remove('is-fresh'); }, true);
  root.addEventListener('change', function () { root.classList.remove('is-fresh'); }, true);
  /* ================= Сворачиваемые этапы — как этапы цикла на главной =================
     Клик по номеру, заголовку или подзаголовку этапа (или по круглой кнопке справа) сворачивает этап до шапки.
     У свёрнутого вместо подзаголовка — сводка: этап 1 — карточки (файлы, платы, заполнено, проверить, расхождения)
     и выбор платы; этап 2 — краткая спецификация с отметками полей и стоимостью плат; этап 3 — режим, группы
     корпусов, доли паяных соединений и стоимость монтажа. Клик по карточке или строке ведёт к полю. Какие этапы свёрнуты —
     помнит браузер посетителя (localStorage). Ссылки на этап, «Отправить на точный расчёт» и новые файлы,
     требующие внимания, этап раскрывают. */
  var openAfterParse = false;
  var stages = (function () {
    var KEY = 'pcb_calc_folded', saved = [];
    var list = $$('.cx-stage').filter(function (x) { return x.id && x.id !== 'request' && x.querySelector('.cx-stage__title'); }); // этап 4 — завершение, не сворачивается
    try { saved = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { saved = []; }
    function save() {
      try { localStorage.setItem(KEY, JSON.stringify(list.filter(function (x) { return x.classList.contains('is-collapsed'); }).map(function (x) { return x.id; }))); } catch (e) { /* хранилище закрыто */ }
    }
    var H = esc;
    function badgeState(k) { var b = $('.cx-badge[data-for="' + k + '"]'); return b ? b.dataset.state || '' : ''; }
    function dot(keys) {
      var st = keys.map(badgeState);
      return st.indexOf('conflict') >= 0 || st.indexOf('err') >= 0 ? '<i class="cx-dot cx-dot--conf" title="расхождение"></i>'
        : st.some(function (s) { return s === 'need' || s === 'check'; }) ? '<i class="cx-dot cx-dot--need" title="проверьте"></i>' : '';
    }
    // вклад этапа в цену — из строк расчёта (у комплекта — из строк каждой платы)
    function cost(re) {
      var r = lastRes; if (!r || !r.ok || r.engineer) return 0;
      var sum = 0, add = function (l) { if (re.test(l.name || '') && l.v > 0) sum += l.v; };
      // комплект: сводка — по открытой плате, значит и стоимость — её
      (r.lines || []).forEach(function (l) { if (l.sub) { if (l.cur) l.sub.forEach(add); } else add(l); });
      return sum;
    }
    function costHtml(label, v) { return v > 0 ? '<div class="cx-fold__cost" title="' + label + '"><span class="cx-fold__cost-l">' + label + '</span><b>≈ ' + fmtR(v) + '</b></div>' : ''; }

    /* Этап 1: карточки — файлы (и платы), заполнено, проверить, расхождения */
    function sumFiles() {
      if (!store.length) return '';
      var ok = store.filter(function (it) { return it.status === 'ok' && it.kind !== 'other'; });
      var n = { ok: 0, need: 0, conf: 0, all: 0 };
      Object.keys(badgeMap).forEach(function (k) {
        if (asmKeys.indexOf(k) >= 0 || k === 'qty') return;
        var s = badgeState(k); if (!s) return;
        n.all++;
        if (s === 'ok' || s === 'manual') n.ok++; else if (s === 'conflict' || s === 'err') n.conf++; else n.need++;
      });
      var names = ok.slice(0, 2).map(function (it) { return it.name; }).join(', ') + (ok.length > 2 ? ' и ещё ' + (ok.length - 2) : '');
      var c = '<button type="button" class="cx-fcard" data-go="files"><b>' + ok.length + '</b><span>' + plural(ok.length, 'файл', 'файла', 'файлов') + '</span><small>' + H(names) + '</small></button>';
      var nb = boardsInfo.length;
      if (nb > 1) c += '<button type="button" class="cx-fcard" data-go="boards"><b>' + nb + '</b><span>' + plural(nb, 'плата', 'платы', 'плат') + '</span><small>' + (solo ? 'посчитана отдельно' : isSet() && val('qty') ? 'комплектов: ' + H(val('qty')) : 'комплект') + '</small></button>';
      if (n.all) c += '<button type="button" class="cx-fcard is-ok" data-go="report"><b>' + n.ok + ' из ' + n.all + '</b><span>заполнено</span><small>из файлов</small></button>';
      if (n.need) c += '<button type="button" class="cx-fcard is-need" data-go="need"><b>' + n.need + '</b><span>проверить</span><small>' + plural(n.need, 'поле', 'поля', 'полей') + ' в этапе 2</small></button>';
      if (n.conf) c += '<button type="button" class="cx-fcard is-conf" data-go="conf"><b>' + n.conf + '</b><span>' + plural(n.conf, 'расхождение', 'расхождения', 'расхождений') + '</span><small>источники не совпали</small></button>';
      return '<div class="cx-fcards">' + c + '</div>';
    }
    /* Этап 2: краткая спецификация в две колонки, точка — поле требует внимания */
    function sumBoard() {
      var rows = [], lc = function (t) { return t ? t.charAt(0).toLowerCase() + t.slice(1) : t; };
      var row = function (label, value, keys) { if (value) rows.push('<button type="button" class="cx-spec__row" data-field="' + keys[0] + '"><span>' + label + '</span><b>' + H(value) + '</b>' + dot(keys) + '</button>'); };
      var q = val('qty'), cb = curBoard();
      row('Тираж', q ? (isSet() && cb ? fmt(q * perSet(cb.name)) + ' шт · ' + q + ' компл.' : q + ' шт') : '', ['qty']);
      var L = String(val('len')).trim(), W = String(val('wid')).trim();
      row('Габариты', L && W ? L + ' × ' + W + ' мм' : '—', ['size']);
      var n = parseInt(val('layers'), 10);
      row('Слои', (n ? n + ' ' + plural(n, 'слой', 'слоя', 'слоёв') : '') + (val('thick') ? ' · ' + selText('thick') : ''), ['layers', 'thick']);
      row('Медь', val('cuout') ? selText('cuout') + (!el('cuin').disabled && val('cuin') ? ' / ' + selText('cuin') + ' внутр.' : '') : '—', ['cuout', 'cuin']);
      row('Материал', val('mat') ? firstPart(selText('mat')) : '—', ['mat']);
      row('Покрытие', finishPending ? 'HASL, не уточнено' : firstPart(selText('finish')), ['finish']);
      row('Маска / марк.', [val('mask') ? lc(selText('mask')) : '—', val('silk') ? lc(selText('silk')) : '—'].join(' / '), ['mask', 'silk']);
      var tr = String(val('track')).trim(), ho = String(val('hole')).trim();
      if (tr || ho) row('Дорожка / отв.', (tr || '—') + ' / ' + (ho || '—') + ' мм', ['track', 'hole']);
      var sp = $$('.cx-checks input:checked').map(function (i) { return lc(i.parentNode.textContent.trim()); });
      var head = boardsInfo.length > 1 && cb ? '<p class="cx-fold__board">Плата «' + H(cb.name) + '»</p>' : '';
      return head + '<div class="cx-spec">' + rows.join('') + '</div>' + (sp.length ? '<p class="cx-fold__note">Особые требования: ' + H(sp.join(', ')) + '</p>' : '');
    }
    /* Этап 3: режим и источник, чипы групп корпусов, полоска долей паяных соединений */
    var GROUP = [ // ключ, название, цвет полоски
      ['chip', 'Чипы, SOT, SOIC', '#14543E'], ['fine', 'QFP', '#6FA58C'], ['qfn', 'QFN', '#D8B45A'], ['bga', 'BGA', '#C0703A'], ['tht', 'DIP и разъёмы', '#9DB8AB']
    ];
    function sumAsm() {
      var m = asmMode(), ap = estKeys(), t = function (k) { return ap.indexOf(k) >= 0 ? '~' : ''; }, g = function (k) { return num(k) || 0; };
      if (m === 'none') return '<p class="cx-fold__mode"><b>Без монтажа</b> — только платы</p>';
      var any = $$('[data-key^="q_"], [data-key^="d_"]').some(function (i) { return +i.value > 0; });
      var src = asmFilled ? (ipcAsm ? 'из ' + asmSrc + (ipcAsm.est ? ', оценка' : '') : 'оценка по пасте Gerber') : any ? 'вручную' : 'не заполнено';
      var chips = [], J = { chip: 0, fine: 0, qfn: 0, bga: 0, tht: 0 };
      var chip = function (grp, text) { var c = GROUP.filter(function (x) { return x[0] === grp; })[0]; chips.push('<span class="cx-chip"><i style="background:' + c[2] + '"></i>' + text + '</span>'); };
      if (m === 'quick') {
        if (g('q_smd')) { chip('chip', 'Простые SMD · ' + t('q_smd') + fmt(g('q_smd')) + ' корп.'); J.chip += g('q_smd') * 2.4; }
        if (g('q_qfn')) { chip('qfn', 'QFN, QFP · ' + t('q_qfn') + fmt(g('q_qfn')) + ' корп.'); J.qfn += g('q_qfn') * 40; }
        if (g('q_bga')) { chip('bga', 'BGA · ' + t('q_bga') + fmt(g('q_bga')) + ' корп.'); J.bga += g('q_bga') * 200; }
        if (g('q_dip')) { chip('tht', 'DIP · ' + t('q_dip') + fmt(g('q_dip')) + ' выв.'); J.tht += g('q_dip'); }
      } else {
        [['d_0201', '0201', 'chip', 2], ['d_0402', '0402', 'chip', 2], ['d_0603', '0603+', 'chip', 2], ['d_sot', 'SOT', 'chip', 3], ['d_soic', 'SOIC', 'chip', 'p_soic'],
         ['d_qfp', 'QFP', 'fine', 'p_qfp'], ['d_qfn', 'QFN', 'qfn', 'p_qfn'], ['d_bga', 'BGA', 'bga', 'p_bga'], ['d_tht', 'DIP', 'tht', 'p_tht'], ['d_conn', 'Разъёмы', 'tht', 2]].forEach(function (d) {
          var n = g(d[0]); if (!n) return;
          var p = typeof d[3] === 'string' ? g(d[3]) : d[3];
          chip(d[2], d[1] + ' · ' + t(d[0]) + fmt(n) + (typeof d[3] === 'string' && p ? ' × ' + t(d[3]) + fmt(p) + ' выв.' : ''));
          J[d[2]] += n * p;
        });
      }
      var tot = Object.keys(J).reduce(function (s, k) { return s + J[k]; }, 0), bar = '';
      if (tot > 0) {
        bar = '<div class="cx-jbar" role="img" aria-label="Доли паяных соединений по группам корпусов">' + GROUP.filter(function (x) { return J[x[0]] > 0; }).map(function (x) {
          var pc = Math.round(J[x[0]] / tot * 100);
          return '<i style="flex-grow:' + J[x[0]] + ';background:' + x[2] + '" title="' + x[1] + ' — ' + pc + ' % паяных соединений"></i>';
        }).join('') + '</div><p class="cx-fold__note">Паяные соединения на плату: ' + (ap.length ? '~' : '≈ ') + fmt(tot) + ' — по ним в основном считается монтаж</p>';
      }
      return '<p class="cx-fold__mode"><b>' + (m === 'quick' ? 'Быстрая оценка' : 'Подробная оценка') + '</b> · ' + src + '</p>' +
        (chips.length ? '<div class="cx-chips">' + chips.join('') + '</div>' : '') + bar;
    }
    function fill(x) {
      var sm = x.querySelector('.cx-stage__sum'), main = sm.querySelector('.cx-fold'), body = '', right = '';
      if (x.id === 'calc-files') body = sumFiles();
      else if (x.id === 'calc-board') { body = sumBoard(); right = costHtml('Стоимость плат', cost(/^(Подготовка производства|Производство плат)/)); }
      else if (x.id === 'calc-assembly') { body = sumAsm(); right = costHtml('Стоимость монтажа', cost(/^(Монтаж|Рентген)/)); }
      main.innerHTML = body ? '<div class="cx-fold__body">' + body + '</div>' + right : '';
      x.classList.toggle('has-cost', !!(body && right));
      var tabs = sm.querySelector('.cx-btabs'), has = !!body || (tabs && !tabs.hidden);
      x.classList.toggle('has-sum', has);
    }
    function fold(x, c, quiet) {
      x.classList.toggle('is-collapsed', c);
      var btn = x.querySelector('.cx-stage__toggle'), title = x.querySelector('.cx-stage__title').textContent.trim();
      btn.setAttribute('aria-expanded', c ? 'false' : 'true');
      btn.setAttribute('aria-label', (c ? 'Развернуть' : 'Свернуть') + ' этап «' + title + '»');
      x.querySelector('.cx-stage__sum').hidden = !c;
      if (c) fill(x);
      if (!quiet) save();
    }
    function open(id) {
      var t = id && document.getElementById(id), x = t && t.closest('.cx-stage');
      if (x && x.classList.contains('is-collapsed')) fold(x, false);
    }
    // перейти к полю: раскрыть этап (и «Ещё параметры»), прокрутить, поставить курсор
    function goField(f) {
      if (!f) return;
      open(f.closest('.cx-stage').id);
      var more = f.closest('details'); if (more) more.open = true;
      var c = f.matches('input,select') ? f : f.querySelector('input:not([readonly]):not([type="hidden"]),select');
      (c || f).scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
      if (c) setTimeout(function () { c.focus({ preventScroll: true }); }, 350);
    }
    function go(what) {
      var b = $('#calc-board');
      if (what === 'need') goField($('.cx-field.is-need', b));
      else if (what === 'conf') goField($('.cx-field.is-conflict', b));
      else if (what === 'report') { open('calc-files'); var r = $('#calc-report'); if (r) { r.open = true; r.scrollIntoView({ block: 'center' }); } }
      else if (what === 'boards') { open('calc-files'); $('#calc-boards').scrollIntoView({ block: 'center' }); }
      else open('calc-files');
    }
    list.forEach(function (x) {
      var body = x.querySelector('.cx-stage__body') || x, title = x.querySelector('.cx-stage__title'), lead = body.querySelector('.cx-stage__lead');
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'cx-stage__toggle'; btn.setAttribute('aria-controls', x.id);
      var sm = document.createElement('div'); sm.className = 'cx-stage__sum'; sm.hidden = true;
      sm.innerHTML = '<div class="cx-fold"></div>' + (x.id === 'calc-files' ? '<div class="cx-btabs cx-btabs--fold" id="calc-btabs-1" role="group" aria-label="Плата для расчёта" hidden></div>' : '');
      (lead || title).after(sm);
      x.insertBefore(btn, x.firstChild);
      x.classList.add('cx-stage--fold');
      x.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('.cx-stage__sum [data-go]')) { go(t.closest('[data-go]').dataset.go); return; }
        if (t.closest('.cx-spec__row')) { var k = t.closest('.cx-spec__row').dataset.field; goField(el(k === 'size' ? 'len' : k)); return; }
        if (t.closest('.cx-stage__sum .cx-btabs')) return; // выбор платы — своим обработчиком
        if (!t.closest('.cx-stage__toggle, .cx-stage__num, .cx-stage__title, .cx-stage__lead, .cx-stage__sum')) return;
        if (window.getSelection && String(window.getSelection()).length) return; // выделяли текст — не сворачиваем
        fold(x, !x.classList.contains('is-collapsed'));
      });
      fold(x, saved.indexOf(x.id) >= 0, true);
    });
    document.addEventListener('pcb:calc', function () { list.forEach(function (x) { if (x.classList.contains('is-collapsed')) fill(x); }); });
    // ссылки на этапы внутри страницы и адрес с #этапом
    root.addEventListener('click', function (e) { var a = e.target.closest('a[href^="#"]'); if (a && a.getAttribute('href').length > 1) open(a.getAttribute('href').slice(1)); });
    if (location.hash.length > 1) open(decodeURIComponent(location.hash.slice(1)));
    window.addEventListener('hashchange', function () { open(decodeURIComponent(location.hash.slice(1))); });
    return { open: open };
  })();

  /* ================= Итог внизу на широком экране =================
     Кнопка «Перенести вниз» в итоге: этапы — во всю ширину (поля этапа 2 — в три колонки), итог — полосой снизу:
     сумма, цена за единицу, «Отправить на точный расчёт», «Подробнее» (расшифровка над полосой справа),
     «Вернуть справа». Выбор помнит браузер посетителя. До 1200 px итог и так полоса снизу — кнопки не видно. */
  (function () {
    var KEY = 'pcb_calc_dock', sum = $('#calc-summary'), dockBtn = $('.cx-sum__dock'), bar = $('.cx-sum__bar');
    if (!sum || !dockBtn || !bar) return;
    var more = $('.cx-sum__bar-more', bar);
    function set(on) {
      root.classList.toggle('is-docked', on);
      if (!on && sum.classList.contains('is-open')) $('#calc-summary-toggle').click();
      try { if (on) localStorage.setItem(KEY, '1'); else localStorage.removeItem(KEY); } catch (e) { /* хранилище закрыто */ }
    }
    try { if (localStorage.getItem(KEY)) root.classList.add('is-docked'); } catch (e) { /* без запоминания */ }
    dockBtn.addEventListener('click', function () { set(true); });
    $('.cx-sum__undock', bar).addEventListener('click', function () { set(false); });
    more.addEventListener('click', function () { $('#calc-summary-toggle').click(); });
    $('.cx-sum__bar-cta', bar).addEventListener('click', function () { $('#calc-cta').click(); });
    function sync() {
      $('#calc-bar-total').textContent = $('#calc-total').textContent;
      $('#calc-bar-per').textContent = $('#calc-per').textContent;
      var o = sum.classList.contains('is-open'); more.setAttribute('aria-expanded', o ? 'true' : 'false'); more.classList.toggle('is-open', o);
    }
    document.addEventListener('pcb:calc', sync);
    $('#calc-summary-toggle').addEventListener('click', function () { setTimeout(sync, 0); });
    // клик мимо раскрытой расшифровки — свернуть
    document.addEventListener('click', function (e) {
      if (root.classList.contains('is-docked') && sum.classList.contains('is-open') && !sum.contains(e.target)) $('#calc-summary-toggle').click();
    });
    sync();
  })();
  // новые файлы: этапы, где появилось что проверить, раскрываем
  function openAttention() {
    if (!openAfterParse) return; openAfterParse = false;
    if ($$('.cx-field.is-need, .cx-field.is-conflict', $('#calc-board')).length) stages.open('calc-board');
    if (!$('#calc-asm-note').hidden) stages.open('calc-assembly');
  }

  asmInit(); syncInner(); syncAsm(); takeDefaults(); recalc();
  // расчёт из заявки (/panel) открывает сотрудник — черновик не подставляем и не сохраняем
  var fromPanel = location.hash === '#pcb-restore';
  restoreFromPanel();
  if (fromPanel) { noSave = true; restoring = false; emit(); } else restoreDraft();

})();

