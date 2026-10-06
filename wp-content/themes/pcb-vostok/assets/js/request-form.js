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
 * Отправка: FormData на window.PCBRequest.endpoint (inc/request-handler.php).
 */
(function () {
  'use strict';

  var CFG = window.PCBRequest || {};
  var MAX_FILES = 10;
  var MAX_TOTAL = 50 * 1048576;
  var BLOCKED = /\.(exe|msi|bat|cmd|com|scr|ps1|vbs|js|jar|sh|php|phtml|dll)$/i;
  var CALC_MARK = '— Данные из онлайн-калькулятора —';
  var MAIL = CFG.mail || 'info@pcb-vostok.ru';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var uid = 0;

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
    var filebox = el('div', 'request-filebox');
    var head = el('div', 'request-files__head'), status = el('p', 'request-files__status');
    head.appendChild(el('span', 'request-files__badge')); head.appendChild(status);
    var list = el('ul', 'request-files');
    filebox.appendChild(head); filebox.appendChild(list);
    var total = el('p', 'request-total');
    // заявка без файлов и без описания бесполезна: нужно хотя бы одно из двух
    var CONTENT_MSG = 'Приложите файлы или опишите задачу — хотя бы одно из двух';
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
        var li = el('li', 'request-file' + (it.bad ? ' is-bad' : ''));
        li.appendChild(el('span', 'request-file__ext', ext(it.file.name)));
        var nm = el('span', 'request-file__name', it.file.name); nm.title = it.file.name;
        if (it.calc) nm.appendChild(el('span', 'request-file__tag', 'из калькулятора'));
        li.appendChild(nm);
        li.appendChild(el('span', 'request-file__size', fmt(it.file.size)));
        var rm = el('button', 'request-file__rm'); rm.type = 'button'; rm.setAttribute('aria-label', 'Убрать файл');
        rm.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        rm.addEventListener('click', function () {
          var wasCalc = items[i].calc; items.splice(i, 1);
          if (wasCalc && !items.some(function (x) { return x.calc; })) setCalcState(false);
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
      status.textContent = '';
      if (items.length) {
        status.appendChild(document.createTextNode(bad ? (bad === 1 ? 'Один файл не подходит' : nfiles(bad) + ' не подходят') : nfiles(items.length) + ' — приложено к заявке'));
        status.appendChild(el('span', null, nfiles(items.length) + ', ' + fmt(sum) + ' из 50 МБ'));
      }
    }
    function addFiles(fileList, calc) {
      [].forEach.call(fileList, function (f) {
        items = items.filter(function (it) { return !(it.file.name === f.name && it.file.size === f.size); });
        items.push({ file: f, calc: !!calc, bad: '' });
      });
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
    var calcBtn = $('.request-calc__btn'), help = $('.request-calc__q'), more = $('.request-calc__more'), attach = $('details.request-attach'), calcAttached = false;
    var calcLabel = calcBtn && (calcBtn.querySelector('[data-calc-label]') || calcBtn);
    function setCalcState(on) {
      calcAttached = on;
      if (!calcBtn) return;
      calcBtn.classList.toggle('is-applied', on);
      if (calcBtn.getAttribute('data-on')) calcLabel.textContent = calcBtn.getAttribute(on ? 'data-on' : 'data-off');
    }
    function setHelp(open) {
      if (!help || !more) return;
      more.classList.toggle('is-open', open);
      help.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (help && more) {
      more.id = more.id || id('rq-calc-help');
      help.setAttribute('aria-controls', more.id); help.setAttribute('aria-expanded', 'false');
      help.addEventListener('click', function () {
        var open = help.getAttribute('aria-expanded') !== 'true';
        setHelp(open); if (open && attach) attach.open = false;
      });
    }
    if (attach) attach.addEventListener('toggle', function () { if (attach.open) setHelp(false); });
    if (calcBtn) calcBtn.addEventListener('click', function () {
      var api = window.PCBCalc; if (!api || !message) return;
      var own = message.value, k = own.indexOf(CALC_MARK);
      if (k >= 0) own = own.slice(0, k);
      own = own.replace(/\s+$/, '');
      message.value = (own ? own + '\n\n' : '') + CALC_MARK + '\n' + api.getSummary();
      message.style.height = 'auto'; message.style.height = (message.scrollHeight + 4) + 'px';
      items = items.filter(function (it) { return !it.calc; });
      addFiles(api.getFiles(), true);
      setCalcState(true);
    });

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

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (root.classList.contains('is-sending')) return;
      var badEmail = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
      var badAgree = !agree.checked;
      fieldState(email, badEmail); fieldState(agree, badAgree);
      var badFiles = items.some(function (it) { return it.bad; });
      var badContent = !items.length && !(message && message.value.trim());
      setContentState(badContent);
      if (badEmail || badAgree || badFiles || badContent) {
        showAlert(badFiles && !badEmail && !badAgree && !badContent ? 'Уберите файлы, которые не подходят, или пришлите их на почту ' + MAIL + '.' :
          badContent && !badEmail && !badAgree ? CONTENT_MSG + '.' : 'Проверьте отмеченные поля — заявка пока не отправлена.');
        (badEmail ? email : badContent ? (message || drop) : badAgree ? agree : drop).focus();
        return;
      }
      if (!CFG.endpoint) { showAlert('Отправка формы пока не подключена. Напишите нам на почту ' + MAIL + '.'); return; }
      showAlert('');

      var data = new FormData(form);
      data.delete('files[]');
      items.forEach(function (it) { data.append('files[]', it.file, it.file.name); });
      data.set('source', isCalc ? 'calc' : 'home');
      data.append('page', location.href);
      data.append('referrer', document.referrer || '');
      var token = captchaToken(); if (token) data.append('smart-token', token);
      if (calcAttached && window.PCBCalc && window.PCBCalc.getData) {
        try { data.append('calc_data', JSON.stringify(window.PCBCalc.getData())); } catch (_) {}
      }

      root.classList.add('is-sending'); submit.disabled = true;
      var bars = function (p) { [].forEach.call(list.querySelectorAll('.request-file__bar i'), function (b) { b.style.width = p + '%'; }); };
      var xhr = new XMLHttpRequest();
      xhr.open('POST', CFG.endpoint);
      xhr.upload.addEventListener('progress', function (ev) {
        if (!ev.lengthComputable) return;
        var p = Math.round(ev.loaded / ev.total * 100);
        submit.style.setProperty('--p', p + '%'); submitLabel.textContent = p < 100 ? 'Отправляем… ' + p + '%' : 'Отправляем…'; bars(p);
      });
      var label = submitLabel.textContent;
      function done() { root.classList.remove('is-sending'); submit.disabled = false; submit.style.removeProperty('--p'); submitLabel.textContent = label; }
      xhr.onload = function () {
        var ok = xhr.status >= 200 && xhr.status < 300, res = {};
        try { res = JSON.parse(xhr.responseText); } catch (_) {}
        done(); captchaReset();
        if (ok && res.success) {
          setSuccess(res.number || '');
          root.classList.add('is-done');
          if (success) success.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
        } else {
          showAlert(res.message || 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам на почту ' + MAIL + '.');
        }
      };
      xhr.onerror = function () { done(); captchaReset(); showAlert('Не удалось отправить заявку — проверьте соединение и попробуйте ещё раз.'); };
      xhr.send(data);
    });

    ['input', 'change'].forEach(function (ev) {
      root.addEventListener(ev, function (e) {
        if (e.target === email) fieldState(email, false);
        if (e.target === agree) fieldState(agree, false);
        if (e.target === message && message.value.trim()) setContentState(false);
      });
    });

    if (again) again.addEventListener('click', function () {
      form.reset(); items = []; render(); setCalcState(false); showAlert('');
      if (message) message.style.height = '';
      root.classList.remove('is-done');
      successTpl.forEach(function (p) { p[0].innerHTML = p[1]; });
    });

    render();
  }

  function boot() { [].forEach.call(document.querySelectorAll('.request-form'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
