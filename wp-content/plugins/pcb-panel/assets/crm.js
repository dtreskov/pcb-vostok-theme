/**
 * CRM: карточка заявки, окно письма, профиль.
 * Все правки — через REST панели (window.PCBCrm.rest + nonce). После успешной правки карточка
 * перезагружается: разметку и историю рисует сервер.
 *   form[data-crm="status|service|note|contacts"]  JSON-запрос в /requests/<id>/<действие>
 *   form[data-crm-upload="files|email"]            multipart-запрос (файлы)
 *   form[data-crm-me="signature"]                  /me/signature
 *   [data-crm-flag][data-note]                     «Важно» у заметки
 *   [data-crm-toggle="<id>"]                       показать/скрыть форму
 *   [data-crm-window]                              ссылка в отдельном окне (письмо)
 *   [data-crm-delete]                              окно удаления
 */
(function () {
  'use strict';
  var CFG = window.PCBCrm || {};
  var msg = document.querySelector('.crm-msg');
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };

  function say(text, isError) {
    if (!msg) return;
    msg.textContent = text;
    msg.classList.toggle('is-error', !!isError);
    msg.hidden = false;
    clearTimeout(say.t);
    say.t = setTimeout(function () { msg.hidden = true; }, isError ? 7000 : 2500);
  }

  function request(url, body, isForm) {
    var headers = { 'X-WP-Nonce': CFG.nonce };
    if (!isForm) headers['Content-Type'] = 'application/json';
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: isForm ? body : JSON.stringify(body || {}) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok) throw new Error(j.message || 'Не удалось выполнить (' + r.status + ')');
          return j;
        });
      });
  }
  function call(id, action, data, isForm) { return request(CFG.rest + id + '/' + action, data, isForm); }

  function busy(f, on) { f.classList.toggle('is-busy', on); }

  // показать/скрыть формы
  $$('[data-crm-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.getElementById(b.getAttribute('data-crm-toggle'));
      if (f) { f.hidden = !f.hidden; if (!f.hidden) { var i = f.querySelector('input:not([type=file]),textarea'); if (i) i.focus(); } }
    });
  });

  // статус «Отказ» показывает поле причины
  $$('form[data-crm="status"]').forEach(function (f) {
    var sel = f.querySelector('select[name="status"]'), reason = f.querySelector('.crm-reason');
    if (sel && reason) sel.addEventListener('change', function () { reason.hidden = sel.value !== 'lost'; });
  });

  // JSON-формы
  $$('form[data-crm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      [].forEach.call(f.elements, function (el) {
        if (!el.name) return;
        if (el.type === 'checkbox') { if (el.checked) data[el.name] = el.value; return; }
        data[el.name] = el.value;
      });
      if (f.getAttribute('data-crm') === 'note' && !String(data.text || '').trim()) { say('Напишите текст заметки', true); return; }
      busy(f, true);
      call(f.getAttribute('data-id'), f.getAttribute('data-crm'), data)
        .then(function () { say('Сохранено'); location.reload(); })
        .catch(function (err) { busy(f, false); say(err.message, true); });
    });
  });

  // «Важно» у заметки
  $$('[data-crm-flag]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      call(b.getAttribute('data-id'), 'note-flag', { note: b.getAttribute('data-note'), flag: b.getAttribute('data-crm-flag') })
        .then(function () { location.reload(); })
        .catch(function (err) { b.disabled = false; say(err.message, true); });
    });
  });

  // зона выбора файлов: перетаскивание и список выбранного
  function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ'; }
  $$('.crm-drop').forEach(function (drop) {
    var input = drop.querySelector('input[type=file]'), list = drop.parentNode.querySelector('.crm-picked');
    function render() {
      if (!list) return;
      list.innerHTML = '';
      [].forEach.call(input.files, function (f) {
        var li = document.createElement('li'); li.textContent = f.name + ' — ' + fmt(f.size); list.appendChild(li);
      });
    }
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; render(); }
    });
  });

  // multipart-формы: файлы заявки и письмо
  $$('form[data-crm-upload]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var kind = f.getAttribute('data-crm-upload'), data = new FormData(f);
      if (kind === 'files' && !f.querySelector('input[type=file]').files.length) { say('Выберите файлы', true); return; }
      busy(f, true);
      call(f.getAttribute('data-id'), kind, data, true)
        .then(function () {
          if (kind === 'email') {
            f.hidden = true;
            var ok = document.querySelector('.crm-sent'); if (ok) ok.hidden = false;
            try { if (window.opener && !window.opener.closed) window.opener.location.reload(); } catch (_) { /* другое окно */ }
          } else { say('Файлы добавлены'); location.reload(); }
        })
        .catch(function (err) { busy(f, false); say(err.message, true); });
    });
  });

  // профиль: подпись
  $$('form[data-crm-me]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      busy(f, true);
      request(CFG.me + f.getAttribute('data-crm-me'), { signature: f.elements.signature.value })
        .then(function () { busy(f, false); say('Подпись сохранена'); })
        .catch(function (err) { busy(f, false); say(err.message, true); });
    });
  });

  // письмо — отдельным окном; если браузер не дал открыть окно, обычный переход по ссылке
  $$('[data-crm-window]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var w = window.open(a.href, 'pcb-reply', 'width=780,height=900,resizable=yes,scrollbars=yes');
      if (w) { e.preventDefault(); w.focus(); }
    });
  });
  $$('[data-crm-close]').forEach(function (b) {
    b.addEventListener('click', function () {
      window.close();
      setTimeout(function () { history.length > 1 ? history.back() : (location.href = location.href.replace(/reply\/?$/, '')); }, 150);
    });
  });

  // копирование по клику (ClientID, код заявки)
  $$('.crm-copy').forEach(function (c) {
    c.addEventListener('click', function () {
      if (navigator.clipboard) navigator.clipboard.writeText(c.textContent.trim()).then(function () { say('Скопировано'); });
    });
  });

  // удаление: окно с выбором «файлы в архив» / «удалить вместе с файлами»
  var del = document.querySelector('[data-crm-delete]'), dlg = document.getElementById('crm-delete-dialog');
  if (del && dlg && dlg.showModal) {
    del.addEventListener('click', function () { dlg.showModal(); });
    dlg.addEventListener('close', function () {
      var mode = dlg.returnValue;
      if (mode !== 'archive' && mode !== 'purge') return;
      if (mode === 'purge' && !confirm('Файлы будут удалены безвозвратно. Продолжить?')) return;
      call(dlg.querySelector('form').getAttribute('data-id'), 'delete', { mode: mode })
        .then(function (res) { location.href = res.redirect || '../'; })
        .catch(function (err) { say(err.message, true); });
    });
  }
})();
