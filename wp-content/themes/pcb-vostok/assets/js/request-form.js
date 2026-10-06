/**
 * Форма заявки (#request) — главная и страница калькулятора.
 *
 * Разметка: inc/request-form.html (шорткод [pcb_request_form]).
 * — Файлы: выбор и перетаскивание, список, ограничения (10 файлов, 50 МБ вместе,
 *   без исполняемых файлов), удаление.
 * — Проверка почты и согласия перед отправкой.
 * — Страница калькулятора: «Прикрепить данные из калькулятора» — берёт сводку
 *   и загруженные файлы у calculator.js (window.PCBCalc), дописывает сводку
 *   в описание после текста клиента; повторное нажатие обновляет её.
 * — Отправка: FormData на data-endpoint (POST /wp-json/pcb/v1/request, inc/request-handler.php):
 *   поля, файлы, токен SmartCaptcha (smart-token), данные калькулятора (calc_data), страница и источник перехода.
 */
(function () {
  'use strict';

  var MAX_FILES = 10;
  var MAX_TOTAL = 50 * 1048576;
  var BLOCKED = /\.(exe|msi|bat|cmd|com|scr|ps1|vbs|js|jar|sh|php|phtml|dll)$/i;
  var CALC_MARK = '— Данные из онлайн-калькулятора —';
  var MAIL = 'info@pcb-vostok.ru';

  function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ'; }
  function ext(n) { var m = /\.([a-z0-9]+)$/i.exec(n); return m ? m[1].toUpperCase().slice(0, 5) : 'FILE'; }
  function plural(k, a, b, c) { var m = k % 10, h = k % 100; return (m === 1 && h !== 11) ? a : (m >= 2 && m <= 4 && (h < 12 || h > 14)) ? b : c; }
  function files_(k) { return k + ' ' + plural(k, 'файл', 'файла', 'файлов'); }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function init(form) {
    var $ = function (s) { return form.querySelector(s); };
    var drop = $('[data-drop]'), input = $('[data-file]'), list = $('[data-files]');
    var status = $('[data-files-status]'), total = $('[data-files-total]');
    var alertBox = $('[data-alert]'), submit = $('[data-submit]'), submitLabel = $('[data-submit-label]');
    var message = $('[data-message]'), success = $('[data-success]');
    var items = []; // { file, calc, bad }

    /* ---------- файлы ---------- */
    function validate() {
      var sum = 0, n = 0;
      items.forEach(function (it) {
        it.bad = '';
        if (BLOCKED.test(it.file.name)) { it.bad = 'Такой тип файлов не принимаем — упакуйте проект в архив'; return; }
        n++;
        if (n > MAX_FILES) { it.bad = 'Больше ' + MAX_FILES + ' файлов — упакуйте их в один архив'; return; }
        if (sum + it.file.size > MAX_TOTAL) { it.bad = 'Вместе больше 50 МБ — пришлите этот файл на почту ' + MAIL; return; }
        sum += it.file.size;
      });
    }

    function render() {
      validate();
      list.innerHTML = '';
      var sum = 0, bad = 0;
      items.forEach(function (it, i) {
        sum += it.file.size; if (it.bad) bad++;
        var li = document.createElement('li');
        li.className = 'request-file' + (it.bad ? ' is-bad' : '');
        li.innerHTML = '<span class="request-file__ext"></span><span class="request-file__name"></span><span class="request-file__size"></span>' +
          '<button class="request-file__rm" type="button" aria-label="Убрать файл"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
          (it.bad ? '<span class="request-file__msg"></span>' : '') + '<span class="request-file__bar"><i></i></span>';
        li.querySelector('.request-file__ext').textContent = ext(it.file.name);
        var nm = li.querySelector('.request-file__name'); nm.textContent = it.file.name; nm.title = it.file.name;
        if (it.calc) { var t = document.createElement('span'); t.className = 'request-file__tag'; t.textContent = 'из калькулятора'; nm.appendChild(t); }
        li.querySelector('.request-file__size').textContent = fmt(it.file.size);
        if (it.bad) li.querySelector('.request-file__msg').textContent = it.bad;
        li.querySelector('.request-file__rm').addEventListener('click', function () {
          var wasCalc = items[i].calc; items.splice(i, 1);
          if (wasCalc && !items.some(function (x) { return x.calc; })) setCalcState(false);
          render();
        });
        list.appendChild(li);
      });
      form.classList.toggle('has-files', items.length > 0);
      form.classList.toggle('has-errors', bad > 0);
      total.innerHTML = items.length ? files_(items.length) + ', <b>' + fmt(sum) + '</b> из 50 МБ' : '';
      if (status) {
        status.innerHTML = '';
        if (items.length) {
          status.appendChild(document.createTextNode(bad ? (bad === 1 ? 'Один файл не подходит' : files_(bad) + ' не подходят') : files_(items.length) + ' — приложено к заявке'));
          var s = document.createElement('span'); s.textContent = files_(items.length) + ', ' + fmt(sum) + ' из 50 МБ'; status.appendChild(s);
        }
      }
    }

    function addFiles(fileList, calc) {
      [].forEach.call(fileList, function (f) {
        items = items.filter(function (it) { return !(it.file.name === f.name && it.file.size === f.size); });
        items.push({ file: f, calc: !!calc, bad: '' });
      });
      render();
    }

    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    drop.addEventListener('dragleave', function (e) { if (!drop.contains(e.relatedTarget)) drop.classList.remove('is-over'); });
    drop.addEventListener('drop', function (e) {
      e.preventDefault(); drop.classList.remove('is-over');
      if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });

    /* ---------- калькулятор ---------- */
    var calcBtn = $('[data-calc-attach]'), help = $('[data-calc-help]'), calcAttached = false;
    function setCalcState(on) {
      calcAttached = on;
      if (!calcBtn) return;
      calcBtn.classList.toggle('is-applied', on);
      $('[data-calc-label]').textContent = calcBtn.getAttribute(on ? 'data-on' : 'data-off');
    }
    /* «?» и спойлер «Что приложить к заявке» раскрываются под одной строкой — открыт только один */
    var attach = $('.request-attach details');
    function setHelp(open) {
      if (!help) return;
      document.getElementById(help.getAttribute('aria-controls')).hidden = !open;
      help.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (help) help.addEventListener('click', function () {
      var open = help.getAttribute('aria-expanded') !== 'true';
      setHelp(open);
      if (open && attach) attach.open = false;
    });
    if (attach) attach.addEventListener('toggle', function () { if (attach.open) setHelp(false); });
    if (calcBtn) calcBtn.addEventListener('click', function () {
      var api = window.PCBCalc;
      if (!api) return;
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
    function fieldState(name, bad) { var f = $('[data-field="' + name + '"]'); if (f) f.classList.toggle('is-invalid', bad); }
    function showAlert(text) { alertBox.textContent = text; alertBox.hidden = !text; }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (form.classList.contains('is-sending')) return;
      var email = form.elements.email, agree = form.elements.consent;
      var badEmail = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
      var badAgree = !agree.checked;
      fieldState('email', badEmail); fieldState('agree', badAgree);
      var badFiles = items.some(function (it) { return it.bad; });
      if (badEmail || badAgree || badFiles) {
        showAlert(badFiles && !badEmail && !badAgree ? 'Уберите файлы, которые не подходят, или пришлите их на почту ' + MAIL + '.' : 'Проверьте отмеченные поля — заявка пока не отправлена.');
        (badEmail ? email : badAgree ? agree : drop).focus();
        return;
      }
      var endpoint = form.getAttribute('data-endpoint');
      if (!endpoint) { showAlert('Отправка формы пока не подключена. Напишите нам на почту ' + MAIL + '.'); return; }
      showAlert('');

      var data = new FormData(form);
      data.delete('files[]');
      items.forEach(function (it) { data.append('files[]', it.file, it.file.name); });
      data.append('page', location.href);
      data.append('referrer', document.referrer || '');
      if (calcAttached && window.PCBCalc && window.PCBCalc.getData) {
        try { data.append('calc_data', JSON.stringify(window.PCBCalc.getData())); } catch (_) {}
      }

      form.classList.add('is-sending'); submit.disabled = true;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint);
      xhr.upload.addEventListener('progress', function (ev) {
        if (!ev.lengthComputable) return;
        var p = Math.round(ev.loaded / ev.total * 100);
        submit.style.setProperty('--p', p + '%'); submitLabel.textContent = p < 100 ? 'Отправляем… ' + p + '%' : 'Отправляем…';
        [].forEach.call(list.querySelectorAll('.request-file__bar i'), function (b) { b.style.width = p + '%'; });
      });
      xhr.onload = function () {
        var ok = xhr.status >= 200 && xhr.status < 300, res = {};
        try { res = JSON.parse(xhr.responseText); } catch (_) {}
        done();
        // токен капчи одноразовый — сбрасываем виджет после любой попытки
        if (window.smartCaptcha) try { window.smartCaptcha.reset(); } catch (_) {}
        if (ok && res.success) {
          success.querySelector('h3').textContent = res.number ? 'Заявка ' + res.number + ' отправлена' : 'Заявка отправлена';
          $('[data-success-text]').textContent = 'Изучим файлы и свяжемся с вами по почте ' + email.value.trim() + '. Подтверждение мы отправили туда же.';
          form.classList.add('is-done'); success.hidden = false;
          success.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
        } else {
          showAlert(res.message || 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам на почту ' + MAIL + '.');
        }
      };
      xhr.onerror = function () { done(); showAlert('Не удалось отправить заявку — проверьте соединение и попробуйте ещё раз.'); };
      xhr.send(data);
      function done() { form.classList.remove('is-sending'); submit.disabled = false; submit.style.removeProperty('--p'); submitLabel.textContent = 'Отправить заявку'; }
    });

    ['input', 'change'].forEach(function (ev) {
      form.addEventListener(ev, function (e) {
        if (e.target === form.elements.email) fieldState('email', false);
        if (e.target === form.elements.consent) fieldState('agree', false);
      });
    });

    $('[data-again]').addEventListener('click', function () {
      form.reset(); items = []; render(); setCalcState(false); showAlert('');
      success.querySelector('h3').textContent = 'Заявка отправлена';
      message.style.height = '';
      form.classList.remove('is-done'); success.hidden = true;
    });

    render();
  }

  function boot() { [].forEach.call(document.querySelectorAll('[data-request-form]'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
