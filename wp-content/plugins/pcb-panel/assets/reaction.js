/**
 * «Реакция на заявку» (README §3.6): превью автоответа с примером данных, подстановки, список копий,
 * «полное письмо» — только для домена компании, пробные письма, сохранение — POST crm/reaction.
 */
(function () {
  'use strict';
  var C = window.PCBReaction;
  var form = document.getElementById('rq-form');
  if (!C || !form) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var msg = $('.crm-msg');
  var subj = form.elements.auto_subject, body = form.elements.auto_body, on = form.elements.auto_on;

  function say(t, err) {
    msg.textContent = t; msg.classList.toggle('is-error', !!err); msg.hidden = false;
    clearTimeout(say.t); say.t = setTimeout(function () { msg.hidden = true; }, err ? 7000 : 3000);
  }
  function post(url, data) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'Ошибка ' + r.status); return j; }); });
  }
  // те же правила, что pcb_rq_fill() на сервере
  function fill(t, v) {
    t = String(t || '');
    if (!String(v['имя'] || '').trim()) t = t.replace(/,\s*\{имя\}/g, '');
    ['имя', 'код', 'услуга', 'сайт'].forEach(function (k) { t = t.split('{' + k + '}').join(v[k] || ''); });
    return t;
  }
  function preview() {
    $('#rq-pv-subject').textContent = fill(subj.value, C.sample);
    $('#rq-pv-body').textContent = fill(body.value, C.sample);
    $('#rq-preview').classList.toggle('is-off', !on.checked);
    $('#rq-pv-off').hidden = on.checked;
    $('.rq-auto').classList.toggle('is-off', !on.checked);
    $('#rq-nocode').hidden = !on.checked || (subj.value + body.value).indexOf('{код}') !== -1;
  }
  [subj, body, on].forEach(function (el) { el.addEventListener('input', preview); el.addEventListener('change', preview); });
  preview();

  // вставка подстановки в поле, где стоит курсор (по умолчанию — текст)
  var last = body;
  [subj, body].forEach(function (el) { el.addEventListener('focus', function () { last = el; }); });
  $$('.rq-var').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function () {
      if (!C.canEdit) return;
      var el = last, s = el.selectionStart || el.value.length, e = el.selectionEnd || s;
      el.value = el.value.slice(0, s) + b.dataset.var + el.value.slice(e);
      el.focus(); el.selectionStart = el.selectionEnd = s + b.dataset.var.length; preview();
    });
  });
  if ($('#rq-defaults')) $('#rq-defaults').onclick = function () {
    subj.value = C.defaults.auto_subject; body.value = C.defaults.auto_body; preview();
    say('Текст по умолчанию — в полях; сохраните, чтобы применить');
  };

  // копии: «полное письмо» доступно только для домена компании
  function domainOk(e) { var d = String(e).toLowerCase().split('@')[1] || ''; return C.domains.indexOf(d) !== -1; }
  function syncItem(li) {
    var em = $('input[type=email]', li), cb = $('.rq-full input', li), ok = domainOk(em.value);
    if (!ok) cb.checked = false;
    cb.disabled = !ok || !C.canEdit;
    $('.rq-full', li).title = ok ? 'Контакты клиента, описание и ссылки на файлы — как в основном ящике' : 'Только для адресов домена компании: ' + C.domains.join(', ');
  }
  $$('.rq-item').forEach(syncItem);
  var list = $('#rq-list');
  list.addEventListener('input', function (e) { var li = e.target.closest('.rq-item'); if (li) syncItem(li); });
  list.addEventListener('click', function (e) {
    if (e.target.closest('.rq-del')) { e.target.closest('.rq-item').remove(); return; }
  });
  if ($('#rq-add')) $('#rq-add').onclick = function () {
    var li = document.createElement('li'); li.className = 'rq-item';
    li.innerHTML = '<input type="email" aria-label="Адрес копии" placeholder="manager@pcb-vostok.ru">' +
      '<label class="rq-full"><input type="checkbox"> полное письмо</label>' +
      '<button type="button" class="crm-link" data-test="copy">пробное</button><button type="button" class="rq-del" aria-label="Убрать адрес">×</button>';
    list.appendChild(li); syncItem(li); $('input', li).focus();
  };

  // пробные письма
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-test]');
    if (!b) return;
    var copy = b.dataset.test === 'copy';
    var to = copy ? $('input[type=email]', b.closest('.rq-item')).value.trim() : $('#rq-test-to').value.trim();
    if (!to) { say('Укажите адрес', true); return; }
    b.disabled = true;
    post(C.api + '/test', copy ? { what: 'copy', to: to } : { what: 'auto', to: to, subject: subj.value, body: body.value })
      .then(function (j) { say(j.message); }).catch(function (er) { say(er.message, true); })
      .then(function () { b.disabled = false; });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var copies = $$('.rq-item').map(function (li) { return { email: $('input[type=email]', li).value.trim(), full: $('.rq-full input', li).checked }; })
      .filter(function (c) { return c.email; });
    var btn = $('button[type=submit]', form); btn.disabled = true;
    post(C.api, { auto_on: on.checked, auto_subject: subj.value, auto_body: body.value, main: form.elements.main.value, copies: copies })
      .then(function () { say('Сохранено'); setTimeout(function () { location.reload(); }, 600); })
      .catch(function (er) { btn.disabled = false; say(er.message, true); });
  });
})();
