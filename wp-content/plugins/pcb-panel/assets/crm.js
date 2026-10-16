/**
 * CRM-карточка заявки: сохранение статуса, услуги, заметок и удаление через REST панели.
 * После успешной правки страница перезагружается — разметку и историю рисует сервер.
 */
(function () {
  'use strict';
  var CFG = window.PCBCrm || {};
  var msg = document.querySelector('.crm-msg');

  function say(text, isError) {
    if (!msg) return;
    msg.textContent = text;
    msg.classList.toggle('is-error', !!isError);
    msg.hidden = false;
    clearTimeout(say.t);
    say.t = setTimeout(function () { msg.hidden = true; }, isError ? 6000 : 2500);
  }

  function call(id, action, data) {
    return fetch(CFG.rest + id + '/' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.message || 'Не удалось сохранить (' + r.status + ')');
        return j;
      });
    });
  }

  // статус «Отказ» показывает поле причины
  [].forEach.call(document.querySelectorAll('form[data-crm="status"]'), function (f) {
    var sel = f.querySelector('select[name="status"]'), reason = f.querySelector('.crm-reason');
    if (sel && reason) sel.addEventListener('change', function () { reason.hidden = sel.value !== 'lost'; });
  });

  [].forEach.call(document.querySelectorAll('form[data-crm]'), function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      [].forEach.call(f.elements, function (el) { if (el.name) data[el.name] = el.value; });
      if (f.getAttribute('data-crm') === 'note' && !String(data.text || '').trim()) { say('Напишите текст заметки', true); return; }
      f.classList.add('is-busy');
      call(f.getAttribute('data-id'), f.getAttribute('data-crm'), data)
        .then(function () { say('Сохранено'); location.reload(); })
        .catch(function (err) { f.classList.remove('is-busy'); say(err.message, true); });
    });
  });

  // удаление: окно с выбором «файлы в архив» / «удалить вместе с файлами»
  var btn = document.querySelector('[data-crm-delete]'), dlg = document.getElementById('crm-delete-dialog');
  if (btn && dlg && dlg.showModal) {
    btn.addEventListener('click', function () { dlg.showModal(); });
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
