/**
 * CRM: карточка заявки, окно письма, профиль.
 * Все правки — через REST панели (window.PCBCrm.rest + nonce). После успешной правки карточка
 * перезагружается: разметку и историю рисует сервер.
 *   form[data-crm="status|service|note|contacts"]  JSON-запрос в /requests/<id>/<действие>
 *   form[data-crm-upload="files|email"]            multipart-запрос (файлы)
 *   form[data-crm-me="signature"]                  /me/signature
 *   [data-crm-flag][data-note]                     «Важно» у заметки
 *   [data-crm-note-del][data-note]                 удалить заметку
 *   .crm-scroll                                    тень снизу, только пока есть что прокрутить
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
  $$('form[data-crm="status"], form[data-crm="work"]').forEach(function (f) {
    var sel = f.querySelector('select[name="status"]'), reason = f.querySelector('.crm-reason');
    if (sel && reason) sel.addEventListener('change', function () { reason.hidden = sel.value !== 'lost'; });
  });

  // JSON-формы
  $$('form[data-crm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!f.checkValidity()) { f.reportValidity(); return; } // формат почты и телефона — до отправки
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

  // удалить заметку (автор или администратор)
  $$('[data-crm-note-del]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!confirm('Удалить заметку? Текст удалится насовсем, в истории останется отметка об удалении.')) return;
      b.disabled = true;
      call(b.getAttribute('data-id'), 'note-delete', { note: b.getAttribute('data-note') })
        .then(function () { location.reload(); })
        .catch(function (err) { b.disabled = false; say(err.message, true); });
    });
  });

  // тень внизу прокручиваемых списков — только если не всё помещается и прокрутили не до конца
  function shade(el) { el.classList.toggle('is-more', el.scrollHeight - el.clientHeight - el.scrollTop > 4); }
  function shadeAll() { $$('.crm-scroll').forEach(shade); }
  $$('.crm-scroll').forEach(function (el) { el.addEventListener('scroll', function () { shade(el); }, { passive: true }); });
  $$('details').forEach(function (d) { d.addEventListener('toggle', shadeAll); });
  addEventListener('resize', shadeAll);
  shadeAll();

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
      if (!f.checkValidity()) { f.reportValidity(); return; }
      var data = {};
      [].forEach.call(f.elements, function (el) { if (el.name) data[el.name] = el.value; });
      request(CFG.me + f.getAttribute('data-crm-me'), data)
        .then(function () { busy(f, false); say('Сохранено'); if (f.getAttribute('data-crm-me') === 'name') location.reload(); })
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

  /* «Показать в калькуляторе»: пакет — в localStorage (тот же сайт), калькулятор забирает его по #pcb-restore */
  $$('[data-calc-open]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation(); // кнопка в <summary> — не сворачивать блок
      try {
        var p = JSON.parse(b.dataset.payload); p.t = Date.now();
        localStorage.setItem('pcb_calc_restore', JSON.stringify(p));
      } catch (e) { say('Браузер не дал сохранить данные для калькулятора (закрыто хранилище сайта).', true); return; }
      window.open(b.dataset.calcOpen + '#pcb-restore', '_blank');
    });
  });

  /* ---------- база клиентов (0.6.0) ---------- */

  function api(path, data, method) {
    var opts = { method: method || 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } };
    if (data && opts.method === 'POST') { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
    return fetch(CFG.api + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || 'Не удалось выполнить (' + r.status + ')'); e.data = j.data || {}; throw e; }
        return j;
      });
    });
  }
  function fieldErrors(f, errs) {
    $$('.crm-err', f).forEach(function (s) { s.hidden = true; s.textContent = ''; });
    $$('[data-field].is-bad', f).forEach(function (l) { l.classList.remove('is-bad'); });
    Object.keys(errs || {}).forEach(function (k) {
      var l = f.querySelector('[data-field="' + k + '"]'); if (!l) return;
      l.classList.add('is-bad'); var s = l.querySelector('.crm-err'); if (s) { s.textContent = errs[k]; s.hidden = false; }
    });
    var first = f.querySelector('[data-field].is-bad input, [data-field].is-bad textarea'); if (first) first.focus();
  }

  // формы клиентов: JSON в /pcb-panel/v1/<data-api>
  $$('form[data-api]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!f.checkValidity()) { f.reportValidity(); return; }
      var miss = f.querySelector('input[type=hidden][required]');
      if (miss && !miss.value) { say('Выберите из списка', true); return; }
      if (f.dataset.confirm && !confirm(f.dataset.confirm)) return;
      var data = {};
      [].forEach.call(f.elements, function (el) {
        if (!el.name) return;
        if (el.type === 'checkbox') { if (el.checked) data[el.name] = el.value; return; }
        data[el.name] = el.value;
      });
      busy(f, true);
      api(f.dataset.api, data)
        .then(function (res) { say('Сохранено'); if (res.redirect) location.href = res.redirect; else location.reload(); })
        .catch(function (err) { busy(f, false); fieldErrors(f, err.data.fields); say(err.message, true); if (err.data.redirect && confirm(err.message + ' Открыть?')) location.href = err.data.redirect; });
    });
  });

  // кнопки-действия: очередь «Проверить», убрать почту/телефон
  $$('[data-api-click]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
      b.disabled = true;
      api(b.dataset.apiClick, JSON.parse(b.dataset.body || '{}'))
        .then(function (res) { if (res.redirect) location.href = res.redirect; else location.reload(); })
        .catch(function (err) { b.disabled = false; say(err.message, true); });
    });
  });

  // выбор компании / контакта с поиском
  $$('.crm-pick').forEach(function (box) {
    var input = box.querySelector('input[type=search]'), hidden = box.querySelector('input[type=hidden]'), list = box.querySelector('.crm-pick__list'), t;
    function close() { list.hidden = true; list.innerHTML = ''; }
    input.addEventListener('input', function () {
      clearTimeout(t);
      if (!input.value.trim()) { hidden.value = hidden.required ? '' : '0'; close(); return; }
      t = setTimeout(function () {
        api('clients/search?type=' + box.dataset.pick + '&q=' + encodeURIComponent(input.value.trim()), null, 'GET').then(function (rows) {
          list.innerHTML = '';
          rows.filter(function (r) { return String(r.id) !== box.dataset.exclude; }).forEach(function (r) {
            var li = document.createElement('li'), btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = r.label;
            btn.addEventListener('click', function () { hidden.value = r.id; input.value = r.label.split(' · ')[0]; close(); });
            li.appendChild(btn); list.appendChild(li);
          });
          if (!list.children.length) { var li = document.createElement('li'); li.className = 'crm-pick__none'; li.textContent = 'Не найдено'; list.appendChild(li); }
          list.hidden = false;
        }).catch(function () { close(); });
      }, 250);
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) close(); });
  });

  // автозаполнение по ИНН (DaData, только организации): заполняет пустые поля, занятые — по подтверждению
  $$('[data-dadata]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.closest('form'), inn = f.elements.inn.value.replace(/\D/g, '');
      if (inn.length !== 10) { say('Автозаполнение — только для организаций: ИНН из 10 цифр. Реквизиты ИП введите вручную.', true); return; }
      b.disabled = true;
      api('clients/dadata?inn=' + inn, null, 'GET').then(function (d) {
        b.disabled = false;
        var busyFields = Object.keys(d).filter(function (k) { return k[0] !== '_' && f.elements[k] && f.elements[k].value && f.elements[k].value !== d[k] && d[k]; });
        var overwrite = !busyFields.length || confirm('Заменить уже заполненные поля данными ЕГРЮЛ? (' + busyFields.length + ')');
        Object.keys(d).forEach(function (k) {
          if (k[0] === '_' || !f.elements[k] || !d[k]) return;
          if (!f.elements[k].value || overwrite) f.elements[k].value = d[k];
        });
        var st = { LIQUIDATED: 'ликвидирована', LIQUIDATING: 'ликвидируется', BANKRUPT: 'банкрот', REORGANIZING: 'реорганизуется' }[d._status];
        say(st ? 'Внимание: по данным ЕГРЮЛ компания ' + st + '. Поля заполнены — проверьте и сохраните.' : 'Поля заполнены по ЕГРЮЛ — проверьте и сохраните.', !!st);
      }).catch(function (err) { b.disabled = false; say(err.message, true); });
    });
  });
  $$('[data-copy-addr]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.closest('form'), v = f.elements.addr_legal.value;
      if (!v) { say('Сначала заполните юридический адрес', true); return; }
      f.elements.addr_actual.value = v; f.elements.addr_postal.value = v;
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
