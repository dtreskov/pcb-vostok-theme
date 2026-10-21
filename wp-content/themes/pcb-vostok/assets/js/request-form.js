/**
 * Форма заявки (#request) — главная и этап 4 страницы калькулятора.
 *
 * Разметка собрана из блоков Gutenberg прямо в страницах (_gutenberg/pages/20-homepage.html,
 * 709-calculator.html): группы, абзацы, «Детали» и небольшие HTML-блоки с полями.
 * Поля связаны с пустым скрытым <form id="rq-form"> атрибутом form="rq-form",
 * поэтому их можно раскладывать блоками как угодно. Хуки — только классы:
 *   .request-form          корневая группа формы (контекст — .request--calc у секции калькулятора)
 *   .request-field         группа поля; ошибка поля — .request-err внутри HTML-блока
 *   .request-drop          зона загрузки (HTML-блок с <input type="file"> + абзацы
 *                          .request-drop__idle / __more / __over / __hint)
 *   .request-captcha       контейнер виджета SmartCaptcha
 *   .request-agree         группа согласия: HTML-блок с чекбоксом + абзац с текстом
 *   .request-success       группа «Заявка отправлена»; в текстах можно писать {номер} и {почта}
 *   .request-calc__btn / __q / __more, details.request-attach — только на калькуляторе
 *
 * Калькулятор (только этап 4 страницы калькулятора): если клиент пользовался калькулятором (PCBCalc.isUsed()),
 * расчёт и файлы калькулятора прикреплены к заявке сами. Пилюля — «Расчёт и файлы прикреплены» (или «Расчёт
 * прикреплён к заявке», «Файлы калькулятора прикреплены»); плашка — «В заявку уйдёт:» две галочки: «Предварительный расчёт» (сводка PCBCalc.getBrief() вживую, под спойлером — полный текст PCBCalc.getSummary()) и «Файлы из
 * калькулятора» (стоят в списке файлов с пометкой «из калькулятора»). Снимок PCBCalc.getData() берётся в момент
 * отправки (calc_data → _pcb_calc). Сняты обе галочки — ничего не прикрепляется; пилюля «Прикрепить данные из
 * калькулятора» и «Отправить на точный расчёт» в итоге калькулятора (событие 'pcb:calc-attach') возвращают.
 * Описание задачи — только слова клиента.
 * Файл, который уже есть в калькуляторе, в форму второй раз не добавляется — под зоной сказано почему.
 * Перед отправкой — мягкий вопрос (не блокирует): пустое описание.
 * Отправка: FormData на window.PCBRequest.endpoint (плагин pcb-panel, includes/requests/handler.php).
 *
 * Черновик: поля (кроме согласия), файлы и прикреплённый расчёт сохраняются в браузере (window.PCBDraft,
 * form-draft.js, запись 'form') и восстанавливаются на главной и в калькуляторе — с плашкой
 * «Восстановили незаконченную заявку · Очистить форму». На главной расчёт и файлы калькулятора не
 * показываются, но и не теряются. После успешной отправки черновики формы и калькулятора удаляются.
 */
(function () {
  'use strict';

  var CFG = window.PCBRequest || {};
  var MAX_FILES = 10;
  var MAX_TOTAL = 50 * 1048576;
  var BLOCKED = /\.(exe|msi|bat|cmd|com|scr|ps1|vbs|js|jar|sh|php|phtml|dll)$/i;
  var MAIL = CFG.mail || 'info@pcb-vostok.ru';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var uid = 0;

  /* Яндекс Метрика — только если посетитель согласился на аналитические cookie (cookie-consent.js).
     ClientID уходит вместе с заявкой (связь заявки с визитами в CRM), код заявки после отправки —
     в параметры посетителя Метрики (pcb_request), без контактов. См. README плагина pcb-panel. */
  function ymOn() { var C = window.PCBConsent; return !!(C && C.metrika && typeof window.ym === 'function' && C.get && C.get() === 'all'); }
  function ymClientId(cb) {
    if (!ymOn()) { cb(''); return; }
    var done = false, finish = function (v) { if (!done) { done = true; cb(v ? String(v) : ''); } };
    try { window.ym(window.PCBConsent.metrika, 'getClientID', finish); } catch (e) { finish(''); }
    setTimeout(function () { finish(''); }, 800); // Метрика не ответила — отправляем без неё
  }
  function ymRequest(code) { if (code && ymOn()) { try { window.ym(window.PCBConsent.metrika, 'userParams', { pcb_request: code }); } catch (e) { /* без Метрики */ } } }

  /* шаги для /panel/analytics — обрабатывает site-goals.js */
  function track(d) { try { document.dispatchEvent(new CustomEvent('pcb:track', { detail: d })); } catch (e) { /* без статистики */ } }

  function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ'; }
  function ext(n) { var m = /\.([a-z0-9]+)$/i.exec(n); return m ? m[1].toUpperCase().slice(0, 5) : 'FILE'; }
  function plural(k, a, b, c) { var m = k % 10, h = k % 100; return (m === 1 && h !== 11) ? a : (m >= 2 && m <= 4 && (h < 12 || h > 14)) ? b : c; }
  function nfiles(k) { return k + ' ' + plural(k, 'файл', 'файла', 'файлов'); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function id(prefix) { return prefix + '-' + (++uid); }

  /* ---------- SmartCaptcha: скрипт грузится с ?render=onload, виджеты рендерим сами ---------- */
  var captchaQueue = [];
  window.pcbCaptchaReady = function () { captchaQueue.splice(0).forEach(function (fn) { fn(); }); };
  function whenCaptcha(fn) { if (window.smartCaptcha && window.smartCaptcha.render) fn(); else captchaQueue.push(fn); }

  function init(root) {
    var $ = function (s) { return root.querySelector(s); };
    var form = $('form.request-form__el') || document.getElementById(root.getAttribute('data-form') || 'rq-form');
    if (!form) return;
    var isCalc = !!root.closest('.request--calc');

    var drop = $('.request-drop'), input = drop && drop.querySelector('input[type="file"]');
    var message = root.querySelector('textarea[name="message"]');
    var email = root.querySelector('input[name="email"]'), agree = root.querySelector('input[name="consent"]');
    var submit = root.querySelector('[type="submit"]');
    var success = $('.request-success'), again = $('.request-again');
    var agreeBox = $('.request-agree');

    /* ---------- достраиваем служебные элементы ---------- */
    var submitLabel = submit.querySelector('[data-submit-label]') || submit;
    var submitText = submitLabel.textContent;
    var filebox = el('div', 'request-filebox');
    var head = el('div', 'request-files__head'), status = el('p', 'request-files__status');
    head.appendChild(el('span', 'request-files__badge')); head.appendChild(status);
    var list = el('ul', 'request-files');
    filebox.appendChild(head); filebox.appendChild(list);
    var total = el('p', 'request-total');
    // заявка без файлов и без описания бесполезна: нужно хотя бы одно из двух
    var CONTENT_MSG = isCalc ? 'Приложите файлы, опишите задачу или прикрепите данные калькулятора' : 'Приложите файлы или опишите задачу — хотя бы одно из двух';
    var contentErr = el('span', 'request-err', CONTENT_MSG);
    var filesField = drop && drop.closest('.request-field'), msgField = message && message.closest('.request-field');
    if (drop) { drop.after(filebox, total, contentErr); }

    if (drop) {
      drop.setAttribute('role', 'button'); drop.setAttribute('tabindex', '0');
      var hint = drop.querySelector('.request-drop__hint');
      if (hint) { hint.id = hint.id || id('rq-drop-hint'); drop.setAttribute('aria-describedby', hint.id); }
      drop.setAttribute('aria-label', 'Приложить файлы');
    }

    var alertBox = el('div', 'request-alert'); alertBox.setAttribute('role', 'alert'); alertBox.hidden = true;
    var actions = $('.request-actions'); if (actions) actions.before(alertBox); else root.appendChild(alertBox);

    // мягкий вопрос перед отправкой: текст + два действия; не блокирует — второй вариант отправляет как есть
    var ask = el('div', 'request-ask'); ask.setAttribute('role', 'alertdialog'); ask.hidden = true;
    var askText = el('p', 'request-ask__text'), askActs = el('div', 'request-ask__acts');
    ask.appendChild(askText); ask.appendChild(askActs);
    alertBox.after(ask);
    function askShow(text, yes, no) {
      askText.textContent = text; askActs.innerHTML = '';
      [yes, no].forEach(function (b, i) {
        var btn = el('button', 'request-ask__btn' + (i ? ' request-ask__btn--ghost' : ''), b[0]); btn.type = 'button';
        btn.addEventListener('click', function () { askHide(); b[1](); });
        askActs.appendChild(btn);
      });
      ask.hidden = false; showAlert('');
      ask.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
      askActs.firstChild.focus({ preventScroll: true });
    }
    function askHide() { ask.hidden = true; }

    // согласие: абзац рядом с чекбоксом — его подпись; клик по тексту (не по ссылке) переключает чекбокс
    var agreeErr = el('span', 'request-err', 'Без согласия отправить заявку нельзя');
    if (agreeBox) {
      var agreeText = agreeBox.querySelector('p');
      if (agreeText) {
        agreeText.id = agreeText.id || id('rq-agree');
        agree.setAttribute('aria-labelledby', agreeText.id);
        agreeText.addEventListener('click', function (e) { if (!e.target.closest('a')) { agree.checked = !agree.checked; agree.dispatchEvent(new Event('change', { bubbles: true })); } });
        agreeText.after(agreeErr);
      } else agreeBox.appendChild(agreeErr);
    }

    // тексты «успеха» — шаблоны с {номер} и {почта}
    var successTpl = success ? [].map.call(success.querySelectorAll('h2,h3,h4,p'), function (n) { return [n, n.innerHTML]; }) : [];

    /* ---------- капча ---------- */
    var captchaBox = $('.request-captcha'), captchaId = null;
    if (captchaBox) {
      if (CFG.sitekey) {
        whenCaptcha(function () { captchaId = window.smartCaptcha.render(captchaBox, { sitekey: CFG.sitekey, hl: 'ru' }); });
      } else {
        captchaBox.classList.add('request-captcha--stub');
        if (!captchaBox.textContent.trim()) captchaBox.textContent = 'Антиробот-проверка (SmartCaptcha) — подключится с ключом';
      }
    }
    function captchaToken() { try { return captchaId != null ? window.smartCaptcha.getResponse(captchaId) : ''; } catch (_) { return ''; } }
    function captchaReset() { try { if (captchaId != null) window.smartCaptcha.reset(captchaId); } catch (_) {} }

    /* ---------- файлы ---------- */
    var items = []; // { file, calc, bad }
    function validate() {
      var sum = 0, n = 0;
      items.forEach(function (it) {
        it.bad = '';
        if (BLOCKED.test(it.file.name)) { it.bad = 'Такой тип файлов не принимаем — упакуйте проект в архив'; return; }
        n++;
        if (n > MAX_FILES) { it.bad = 'Больше ' + MAX_FILES + ' файлов — упакуйте их в один архив'; return; }
        if (sum + it.file.size > MAX_TOTAL) { it.bad = 'Вместе больше 50 МБ — пришлите этот файл на почту ' + MAIL + ' или ссылкой'; return; }
        sum += it.file.size;
      });
    }
    function render() {
      validate();
      list.innerHTML = '';
      var sum = 0, bad = 0;
      items.forEach(function (it, i) {
        sum += it.file.size; if (it.bad) bad++;
        var li = el('li', 'request-file' + (it.bad ? ' is-bad' : '') + (it.calc ? ' is-calc' : ''));
        li.appendChild(el('span', 'request-file__ext', ext(it.file.name)));
        var nm = el('span', 'request-file__name'); nm.title = it.file.name;
        nm.appendChild(el('span', 'request-file__n', it.file.name));
        if (it.calc) nm.appendChild(el('span', 'request-file__tag', 'из калькулятора'));
        li.appendChild(nm);
        li.appendChild(el('span', 'request-file__size', fmt(it.file.size)));
        var rm = el('button', 'request-file__rm'); rm.type = 'button'; rm.setAttribute('aria-label', 'Убрать файл');
        rm.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        rm.addEventListener('click', function () {
          var it2 = items[i]; items.splice(i, 1);
          if (it2.calc) { excl[fkey(it2.file)] = true; renderCard(); } // файл калькулятора — не отправлять, в калькуляторе остаётся
          render();
        });
        li.appendChild(rm);
        if (it.bad) li.appendChild(el('span', 'request-file__msg', it.bad));
        var bar = el('span', 'request-file__bar'); bar.appendChild(el('i')); li.appendChild(bar);
        list.appendChild(li);
      });
      root.classList.toggle('has-files', items.length > 0);
      if (items.length) setContentState(false);
      root.classList.toggle('has-errors', bad > 0);
      total.innerHTML = items.length ? nfiles(items.length) + ', <b>' + fmt(sum) + '</b> из 50 МБ' : '';
      saveSoon(); dropContext();
      status.textContent = '';
      if (items.length) {
        status.appendChild(document.createTextNode(bad ? (bad === 1 ? 'Один файл не подходит' : nfiles(bad) + ' не подходят') : nfiles(items.length) + ' — приложено к заявке'));
        status.appendChild(el('span', null, nfiles(items.length) + ', ' + fmt(sum) + ' из 50 МБ'));
      }
    }
    function fkey(f) { return f.name + '|' + f.size; }
    function addFiles(fileList) {
      var api = isCalc ? API() : null, inCalc = {}, dup = [];
      if (api) api.getFiles().forEach(function (f) { inCalc[fkey(f)] = true; });
      [].forEach.call(fileList, function (f) {
        if (inCalc[fkey(f)]) { dup.push(f); return; } // тот же файл уже в калькуляторе — второй раз не добавляем
        items = items.filter(function (it) { return fkey(it.file) !== fkey(f); });
        items.push({ file: f, calc: false, bad: '' });
      });
      showDup(dup);
      render();
    }
    if (drop && input) {
      drop.addEventListener('click', function (e) { if (e.target !== input) input.click(); });
      drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
      input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
      ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
      drop.addEventListener('dragleave', function (e) { if (!drop.contains(e.relatedTarget)) drop.classList.remove('is-over'); });
      drop.addEventListener('drop', function (e) {
        e.preventDefault(); drop.classList.remove('is-over');
        if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
      });
    }

    /* ---------- калькулятор ---------- */
    var calcBtn = $('.request-calc__btn'), help = $('.request-calc__q'), more = $('.request-calc__more'), attach = $('details.request-attach');
    var calcRow = $('.request-calc');
    var API = function () { return window.PCBCalc || null; };
    var noData = false;   // снята галочка «Расчёт» — calc_data не отправляется
    var noFiles = false;  // снята галочка «Файлы из калькулятора»
    var forced = false;   // приложил кнопкой или из итога калькулятора, даже не меняя полей
    var excl = {};        // файлы калькулятора, убранные из заявки крестиком (в калькуляторе остаются)
    var ack = {};         // ответы на мягкие вопросы перед отправкой
    var waiters = [];     // ждут окончания расчёта (отправка во время «оценка считается»)

    var card = null;
    var calcLabel = calcBtn && (calcBtn.querySelector('[data-calc-label]') || calcBtn);
    var calcText = calcLabel ? calcLabel.textContent : '';
    if (calcRow) {
      // плашка — тело вкладки под пилюлей: сводка расчёта вживую; открывается стрелкой или самой пилюлей
      card = el('div', 'request-calc__card'); card.hidden = true; card.setAttribute('role', 'status'); card.setAttribute('aria-live', 'polite');
      card.id = id('rq-calc-card');
      card.innerHTML =
        '<p class="request-calc__head">В заявку уйдёт:</p>' +
        '<div class="request-calc__opt" data-opt="data">' +
          '<label class="request-calc__chk"><input type="checkbox" data-part="data" checked> <b>Предварительный расчёт</b></label>' +
          '<p class="request-calc__params"></p>' +
          '<p class="request-calc__est"><span class="request-calc__k">Оценка</span> <b class="request-calc__sum"></b> <span class="request-calc__vat"></span></p>' +
          '<details class="request-calc__full"><summary>Все данные расчёта</summary><div class="request-calc__text"></div></details>' +
        '</div>' +
        '<div class="request-calc__opt" data-opt="files">' +
          '<label class="request-calc__chk"><input type="checkbox" data-part="files" checked> <b>Файлы из калькулятора</b></label>' +
          '<p class="request-calc__fl"></p>' +
        '</div>' +
        '<p class="request-calc__links"><button type="button" class="request-calc__link" data-act="edit">Изменить в калькуляторе</button></p>';
      calcRow.appendChild(card);
      var full = card.querySelector('.request-calc__full');
      full.addEventListener('toggle', function () { if (full.open) fullText(); });
      card.addEventListener('change', function (e) {
        var part = e.target.getAttribute && e.target.getAttribute('data-part'); if (!part) return;
        if (part === 'data') noData = !e.target.checked;
        if (part === 'files') { noFiles = !e.target.checked; if (!noFiles) excl = {}; }
        var keepOpen = on();
        syncCalc();
        if (!keepOpen || !on()) { setCard(false); if (calcBtn) calcBtn.focus(); } // сняты обе — расчёт не прикладывается
      });
      card.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]'); if (!b) return;
        var act = b.getAttribute('data-act');
        if (act === 'edit') {
          var t = document.getElementById('calc-files') || document.getElementById('calculator');
          if (t) t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        }
      });
    }
    // из калькулятора что-то уходит в заявку: клиент считал (или приложил явно) и не снял обе галочки
    function on() {
      var api = API();
      return !!(card && api && (forced || api.isUsed()) && (!noData || !noFiles));
    }
    function withData() { return on() && !noData; }
    // полный текст расчёта — под спойлером «Все данные расчёта»; строку о файлах не повторяем (у них своя галочка)
    function fullText() {
      var api = API(); if (!api || !full || !full.open) return;
      card.querySelector('.request-calc__text').textContent = String(api.getSummary() || '')
        .split('\n').filter(function (l) { return !/^Файлы проекта:/.test(l); }).join('\n');
    }
    // файлы калькулятора в списке формы — по текущему состоянию калькулятора
    function syncCalc() {
      if (!card) return;
      var api = API(); if (api && api.isRestoring()) return;
      var want = on() && !noFiles ? api.getFiles().filter(function (f) { return !excl[fkey(f)]; }) : [];
      var keys = {}; want.forEach(function (f) { keys[fkey(f)] = true; });
      items = want.map(function (f) { return { file: f, calc: true, bad: '' }; })
        .concat(items.filter(function (it) { return !it.calc && !keys[fkey(it.file)]; }));
      render(); renderCard();
    }
    function setCalc(yes, open) {
      noData = noFiles = !yes;
      if (dupNote) dupNote.hidden = true;
      if (yes) { forced = true; excl = {}; track({ goal: 'calc_attach' }); setHelp(false); }
      syncCalc();
      setCard(yes && open !== false);
    }
    var cardOpen = false;
    function setCard(open) {
      var yes = on();
      cardOpen = !!(open && yes);
      if (card) card.hidden = !cardOpen;
      if (yes && help) help.setAttribute('aria-expanded', cardOpen ? 'true' : 'false');
      if (cardOpen && attach) attach.open = false;
    }

    function renderCard() {
      saveSoon(); dropContext();
      if (!card) return;
      var yes = on(), api = API();
      root.classList.toggle('has-calc', yes);
      if (calcBtn) {
        calcBtn.classList.toggle('is-applied', yes);
        calcLabel.textContent = !yes ? calcText : noData ? 'Файлы калькулятора прикреплены' : noFiles || !api.getFiles().length ? 'Расчёт прикреплён к заявке' : 'Расчёт и файлы прикреплены';
      }
      if (help) {
        help.classList.toggle('is-fold', yes);   // «?» → стрелка спойлера
        help.setAttribute('aria-label', yes ? 'Показать или скрыть, что уйдёт в заявку' : 'Что делает кнопка');
        help.setAttribute('aria-controls', yes ? card.id : (more && more.id) || '');
        if (!yes) help.setAttribute('aria-expanded', more && more.classList.contains('is-open') ? 'true' : 'false');
      }
      if (!yes) { card.hidden = true; cardOpen = false; return; }
      card.hidden = !cardOpen;
      card.querySelector('[data-part="data"]').checked = !noData;
      card.querySelector('[data-part="files"]').checked = !noFiles;
      card.querySelector('[data-opt="data"]').classList.toggle('is-off', noData);
      card.querySelector('[data-opt="files"]').classList.toggle('is-off', noFiles);
      fullText();
      var br = api.getBrief() || {}, est = br.estimate || {}, pend = api.isPending();
      card.classList.toggle('is-pending', pend);
      card.querySelector('.request-calc__params').textContent = br.params || '';
      var sum = card.querySelector('.request-calc__sum'), vat = card.querySelector('.request-calc__vat');
      sum.textContent = pend || est.pending ? 'считается…' : (est.text || '—');
      sum.classList.toggle('is-muted', !!(pend || est.pending || est.none || est.engineer));
      vat.textContent = pend ? '' : (est.vat || '');
      var names = items.filter(function (it) { return it.calc; }).map(function (it) { return it.file.name; });
      var inCalc = api.getFiles().map(function (f) { return f.name; });
      card.querySelector('.request-calc__fl').textContent = noFiles ? (inCalc.length ? inCalc.join(', ') + ' — не прикрепляются' : 'в калькуляторе нет файлов')
        : names.length ? names.join(', ') : (inCalc.length ? 'убраны из заявки в списке файлов' : 'в калькуляторе нет файлов');
    }
    // калькулятор пересчитал или изменился — сводка и файлы вживую
    document.addEventListener('pcb:calc', onCalc);
    function onCalc() {
      var api = API(); if (!api || api.isRestoring()) return; // калькулятор ещё восстанавливает черновик
      syncCalc();
      if (!api.isPending()) waiters.splice(0).forEach(function (fn) { fn(); });
    }

    /* Две зоны загрузки на калькуляторе (этап 1 и форма). Если в калькуляторе есть файлы: подпись зоны формы —
       «Другие файлы к заявке», над зоной — что из калькулятора уже приложено (или что расчёт убран). */
    var dropLabel = isCalc && filesField ? filesField.querySelector('.request-label') : null;
    var dropLabelText = dropLabel ? dropLabel.textContent : '';
    var dropNote = null, dupNote = null;
    if (isCalc && drop) {
      dropNote = el('p', 'request-drop__calc'); dropNote.hidden = true; drop.before(dropNote);
      dupNote = el('p', 'request-drop__dup'); dupNote.hidden = true; dupNote.setAttribute('role', 'status'); drop.after(dupNote);
    }
    function onBtn(text, fn) { var b = el('button', 'request-drop__act', text); b.type = 'button'; b.addEventListener('click', fn); return b; }
    function dropContext() {
      if (!dropNote) return;
      var api = API(), names = api ? api.getFiles().map(function (f) { return f.name; }) : [];
      if (dropLabel) dropLabel.textContent = names.length ? 'Другие файлы к заявке' : dropLabelText;
      dropNote.hidden = !names.length;
      if (!names.length) return;
      dropNote.textContent = '';
      if (on()) {
        var sent = items.filter(function (it) { return it.calc; }).map(function (it) { return it.file.name; });
        if (sent.length) {
          // имена файлов калькулятора — в плашке и в списке ниже, здесь не повторяем
          dropNote.appendChild(document.createTextNode('Файлы из калькулятора уже в заявке. Здесь добавьте то, чего в них нет, — например BOM или чертёж.'));
        } else {
          dropNote.appendChild(document.createTextNode('Файлы из калькулятора в заявку не входят. '));
          dropNote.appendChild(onBtn('Прикрепить', function () { noFiles = false; excl = {}; syncCalc(); }));
        }
      } else {
        dropNote.appendChild(document.createTextNode('Расчёт и файлы из калькулятора ('));
        dropNote.appendChild(el('b', null, names.join(', ')));
        dropNote.appendChild(document.createTextNode(') в заявку не входят. '));
        dropNote.appendChild(onBtn('Прикрепить', function () { setCalc(true, false); }));
      }
    }
    // файлы, которые уже есть в калькуляторе, — не добавлены; объясняем почему
    function showDup(dup) {
      if (!dupNote) return;
      dupNote.hidden = !dup.length; dupNote.textContent = '';
      if (!dup.length) return;
      var names = dup.map(function (f) { return '«' + f.name + '»'; }).join(', '), one = dup.length === 1;
      if (on() && !noFiles) {
        dupNote.appendChild(document.createTextNode(names + (one ? ' уже в заявке' : ' уже в заявке') + ' — из калькулятора, второй раз не добавляем.'));
      } else {
        dupNote.appendChild(document.createTextNode(names + (one ? ' загружен' : ' загружены') + ' в калькулятор — второй раз не добавляем. '));
        dupNote.appendChild(onBtn(on() ? 'Прикрепить файлы из калькулятора' : 'Прикрепить вместе с расчётом', function () {
          dupNote.hidden = true; if (on()) { noFiles = false; excl = {}; syncCalc(); } else setCalc(true);
        }));
      }
    }

    function setHelp(open) {
      if (!help || !more) return;
      more.classList.toggle('is-open', open);
      if (!on()) help.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (help) {
      if (more) { more.id = more.id || id('rq-calc-help'); help.setAttribute('aria-controls', more.id); }
      help.setAttribute('aria-expanded', 'false');
      // расчёт не приложен — «?» с пояснением; приложен — стрелка, открывает и закрывает плашку
      help.addEventListener('click', function () {
        var open = help.getAttribute('aria-expanded') !== 'true';
        if (on()) setCard(open);
        else { setHelp(open); if (open && attach) attach.open = false; }
      });
    }
    if (attach) attach.addEventListener('toggle', function () { if (attach.open) { setHelp(false); setCard(false); } });
    // «Отправить на точный расчёт» в итоге калькулятора — приложить (если был убран) и раскрыть плашку
    document.addEventListener('pcb:calc-attach', function () {
      if (!card || !API() || root.classList.contains('is-done')) return;
      if (!on() || noData) setCalc(true); else setCard(true);
    });
    // пилюля: приложен — показать/скрыть плашку; нет — приложить
    if (calcBtn) calcBtn.addEventListener('click', function () { if (on()) setCard(!cardOpen); else setCalc(true); });

    /* ---------- проверка и отправка ---------- */
    function fieldState(node, bad) { var f = node && (node.closest('.request-field') || node.closest('.request-agree')); if (f) f.classList.toggle('is-invalid', bad); }
    function showAlert(text) { alertBox.textContent = text; alertBox.hidden = !text; }
    function setContentState(bad) {
      if (filesField) filesField.classList.toggle('is-invalid', bad);
      if (msgField) msgField.classList.toggle('is-invalid', bad);
    }
    function setSuccess(number) {
      successTpl.forEach(function (p) {
        var h = p[1].replace(/\{почта\}/g, email.value.trim().replace(/[<>&"]/g, ''));
        h = number ? h.replace(/\{номер\}/g, number) : h.replace(/\s*\{номер\}/g, '');
        p[0].innerHTML = h;
      });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); submitFlow(); });

    function submitFlow() {
      if (root.classList.contains('is-sending')) return;
      askHide();
      var badEmail = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
      var badAgree = !agree.checked;
      fieldState(email, badEmail); fieldState(agree, badAgree);
      var badFiles = items.some(function (it) { return it.bad; });
      var hasMsg = !!(message && message.value.trim());
      var withCalc = isCalc && withData();
      var badContent = !items.length && !hasMsg && !withCalc;
      setContentState(badContent);
      if (badEmail || badAgree || badFiles || badContent) {
        showAlert(badFiles && !badEmail && !badAgree && !badContent ? 'Уберите файлы, которые не подходят, или пришлите их на почту ' + MAIL + '.' :
          badContent && !badEmail && !badAgree ? CONTENT_MSG + '.' : 'Проверьте отмеченные поля — заявка пока не отправлена.');
        (badEmail ? email : badContent ? (message || drop) : badAgree ? agree : drop).focus();
        return;
      }
      if (!CFG.endpoint) { showAlert('Отправка формы пока не подключена. Напишите нам на почту ' + MAIL + '.'); return; }
      showAlert('');

      // мягкий вопрос; ответ запоминается до следующего изменения
      var api = withCalc ? API() : null;
      if (!hasMsg && !ack.desc) {
        return askShow('Описание задачи пустое. Пара слов о проекте — сроки, назначение, особые требования — поможет быстрее подготовить расчёт.',
          ['Дополнить описание', function () { message.focus(); message.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' }); }],
          ['Отправить без описания', function () { ack.desc = true; submitFlow(); }]);
      }

      root.classList.add('is-sending'); submit.disabled = true;
      // оценка ещё считается — дождаться (не дольше 10 с), чтобы в заявку не ушла прошлая цифра
      if (api && api.isPending()) {
        submitLabel.textContent = 'Ждём оценку…';
        var go = function () { clearTimeout(t); if (!root.classList.contains('is-sending')) return; buildAndSend(); };
        var t = setTimeout(function () { waiters = waiters.filter(function (f) { return f !== go; }); go(); }, 10000);
        waiters.push(go);
        return;
      }
      buildAndSend();
    }

    function buildAndSend() {
      if (isCalc) syncCalc(); // файлы калькулятора — на момент отправки
      var api = isCalc && withData() ? API() : null;
      var data = new FormData(form);
      data.delete('files[]');
      items.forEach(function (it) { data.append('files[]', it.file, it.file.name); });
      data.set('source', isCalc ? 'calc' : 'home');
      data.append('page', location.href);
      data.append('referrer', document.referrer || '');
      var token = captchaToken(); if (token) data.append('smart-token', token);
      if (api) { // снимок расчёта — в момент отправки, то, что клиент видит в итоге
        try { data.append('calc_data', JSON.stringify(api.getData())); } catch (_) {}
      }
      ymClientId(function (cid) { if (cid) data.append('ym_client_id', cid); send(data); });
    }

    function send(data) {
      var bars = function (p) { [].forEach.call(list.querySelectorAll('.request-file__bar i'), function (b) { b.style.width = p + '%'; }); };
      var xhr = new XMLHttpRequest();
      xhr.open('POST', CFG.endpoint);
      xhr.upload.addEventListener('progress', function (ev) {
        if (!ev.lengthComputable) return;
        var p = Math.round(ev.loaded / ev.total * 100);
        submit.style.setProperty('--p', p + '%'); submitLabel.textContent = p < 100 ? 'Отправляем… ' + p + '%' : 'Отправляем…'; bars(p);
      });
      var label = submitText;
      function done() { root.classList.remove('is-sending'); submit.disabled = false; submit.style.removeProperty('--p'); submitLabel.textContent = label; }
      xhr.onload = function () {
        var ok = xhr.status >= 200 && xhr.status < 300, res = {};
        try { res = JSON.parse(xhr.responseText); } catch (_) {}
        done(); captchaReset();
        if (ok && res.success) {
          track({ goal: isCalc ? 'request_calc' : 'request_home' });
          ymRequest(res.number);
          setSuccess(res.number || '');
          root.classList.add('is-done');
          clearDraft(true);
          if (success) success.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
        } else {
          showAlert(res.message || 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам на почту ' + MAIL + '.');
        }
      };
      xhr.onerror = function () { done(); captchaReset(); showAlert('Не удалось отправить заявку — проверьте соединение и попробуйте ещё раз.'); };
      xhr.send(data);
    }

    ['input', 'change'].forEach(function (ev) {
      root.addEventListener(ev, function (e) {
        if (e.target === email) fieldState(email, false);
        if (e.target === agree) fieldState(agree, false);
        if (e.target === message && message.value.trim()) setContentState(false);
        if (e.target.form === form) saveSoon();
      });
    });

    if (again) again.addEventListener('click', function () {
      root.classList.remove('is-done');
      form.reset(); items = []; noData = noFiles = forced = false; excl = {}; carry = null; ack = {}; syncCalc(); render(); renderCard(); showAlert(''); askHide(); showDup([]);
      if (message) message.style.height = '';
      successTpl.forEach(function (p) { p[0].innerHTML = p[1]; });
    });

    /* ---------- черновик в браузере (form-draft.js) ---------- */
    var D = window.PCBDraft || null, draftReady = false, saveTimer = 0;
    var carry = null; // на главной: снятые галочки расчёта — не показываем, но храним для калькулятора
    var SKIP = { consent: 1, pcb_hp_check: 1, 'smart-token': 1, 'files[]': 1 };
    function draftFields() {
      return [].filter.call(form.elements, function (f) {
        return f.name && !SKIP[f.name] && !/^(file|submit|button|hidden|reset)$/.test(f.type);
      });
    }
    function draftData() {
      var v = {}, dirty = false;
      draftFields().forEach(function (f) {
        if (f.type === 'radio') { if (f.checked) v[f.name] = f.value; if (f.checked !== f.defaultChecked) dirty = true; }
        else if (f.type === 'checkbox') { v[f.name] = f.checked; if (f.checked !== f.defaultChecked) dirty = true; }
        else { v[f.name] = f.value; if (f.value !== f.defaultValue) dirty = true; }
      });
      // свои файлы; файлы калькулятора хранит черновик калькулятора
      var files = items.filter(function (it) { return !it.calc; }).map(function (it) { return { file: it.file }; });
      var c = isCalc ? { noData: noData, noFiles: noFiles, forced: forced, excl: excl } : (carry || null);
      var cdirty = !!(c && (c.noData || c.noFiles || c.forced || (c.excl && Object.keys(c.excl).length)));
      return dirty || files.length || cdirty ? { fields: v, files: files, calc: c } : null;
    }
    function saveNow() {
      clearTimeout(saveTimer); saveTimer = 0;
      if (!D || !draftReady || root.classList.contains('is-done')) return;
      var v = draftData();
      if (v) D.set('form', v); else D.del('form');
    }
    function saveSoon() {
      if (!D || !draftReady) return;
      clearTimeout(saveTimer); saveTimer = setTimeout(saveNow, 300);
    }
    window.addEventListener('pagehide', function () { if (saveTimer) saveNow(); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && saveTimer) saveNow(); });

    var note = null;
    function hideNote() { if (note) { note.remove(); note = null; } }
    function clearDraft(sent) {
      clearTimeout(saveTimer); saveTimer = 0; hideNote();
      if (!D) return;
      D.del('form');
      if (sent) { D.del('calc'); try { document.dispatchEvent(new CustomEvent('pcb:draft-cleared')); } catch (_) {} }
    }
    function showNote(v, lost) {
      note = el('div', 'request-draft'); note.setAttribute('role', 'status');
      var when = new Date(v.t).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
      note.appendChild(el('span', 'request-draft__text', 'Восстановили незаконченную заявку от ' + when + '.' +
        (lost ? ' Файлы сохранить не удалось — приложите их заново.' : '')));
      var b = el('button', 'request-draft__btn', 'Очистить форму'); b.type = 'button';
      b.addEventListener('click', function () {
        form.reset(); items = []; noData = noFiles = forced = false; excl = {}; carry = null; ack = {};
        if (message) message.style.height = '';
        [].forEach.call(root.querySelectorAll('.is-invalid'), function (n) { n.classList.remove('is-invalid'); });
        showAlert(''); askHide(); showDup([]); syncCalc(); render(); renderCard();
        clearDraft(false);
      });
      note.appendChild(b);
      root.insertBefore(note, root.firstChild);
    }
    function restoreDraft(v) {
      var f = v.fields || {};
      draftFields().forEach(function (n) {
        if (!(n.name in f)) return;
        if (n.type === 'radio') n.checked = n.value === f[n.name];
        else if (n.type === 'checkbox') n.checked = !!f[n.name];
        else n.value = f[n.name] == null ? '' : f[n.name];
      });
      if (message && message.value) message.dispatchEvent(new Event('input', { bubbles: true })); // высота поля по тексту
      var all = v.files || [], ok = all.filter(function (x) { return x && typeof Blob !== 'undefined' && x.file instanceof Blob; });
      var mine = ok.filter(function (x) { return !x.calc; }); // calc: true — черновики до 2026-10-22, файлы калькулятора берём из него самого
      items = mine.map(function (x) { return { file: x.file, calc: false, bad: '' }; });
      var c = v.calc || null;
      if (isCalc && card) {
        noData = !!(c && (c.noData || c.off)); noFiles = !!(c && (c.noFiles || c.off)); forced = !!(c && c.forced); excl = (c && c.excl) || {};
      } else carry = c;
      render(); renderCard();
      showNote(v, ok.length < all.length);
      onCalc(); // файлы калькулятора — сразу или когда он восстановит свой черновик
    }
    if (D) {
      D.get('form').then(function (v) {
        if (v && !root.classList.contains('is-done')) restoreDraft(v);
      }).catch(function () {}).then(function () { draftReady = true; });
    }

    render();
    onCalc(); // калькулятор мог закончить восстановление раньше формы
  }

  function boot() { [].forEach.call(document.querySelectorAll('.request-form'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
