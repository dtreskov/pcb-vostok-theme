/* Вкладка SEO: счётчики длины, превью сниппета и карточки мессенджера на лету, загрузка картинок,
   группы минус-слов (Семантика) и кнопки «скопировать».
   Сохранение форм — общий обработчик form[data-api] из crm.js. */
(function () {
  'use strict';
  var CFG = window.PCBCrm || {};
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  function say(t, bad) {
    var m = document.querySelector('.crm-msg'); if (!m) return;
    m.textContent = t; m.classList.toggle('is-error', !!bad); m.hidden = false;
    clearTimeout(say.t); say.t = setTimeout(function () { m.hidden = true; }, bad ? 7000 : 2500);
  }

  // счётчики и превью
  $$('[data-seo-src]').forEach(function (el) {
    var kind = el.dataset.seoSrc, form = el.form;
    var cnt = form.querySelector('[data-seo-count="' + kind + '"]');
    function update() {
      var v = el.value.trim(), shown = v || el.dataset.default || '';
      if (cnt) {
        var n = shown.length, min = +cnt.dataset.min, max = +cnt.dataset.max, lim = +cnt.dataset.limit;
        var st = !n || n > lim ? 'bad' : (n >= min && n <= max ? 'ok' : 'warn');
        var hint = !n ? 'пусто' : n > lim ? 'поисковик обрежет' : n < min ? 'коротко' : n > max ? 'длинновато' : 'хорошо';
        cnt.className = 'seo-count seo-chip seo-chip--' + st;
        cnt.textContent = n + ' зн. · ' + hint + (v ? '' : (el.dataset.default ? ' (по шаблону)' : ''));
      }
      $$('[data-seo-pv="' + kind + '"]').forEach(function (pv) {
        var t = shown || pv.dataset.empty || '';
        pv.textContent = kind === 'title' && t.length > 70 ? t.slice(0, 68) + '…' : (kind === 'description' && t.length > 170 ? t.slice(0, 168) + '…' : t);
      });
    }
    el.addEventListener('input', update); update();
  });

  // картинки: загрузка в медиатеку, id — в скрытое поле формы
  $$('[data-seo-upload]').forEach(function (input) {
    var box = input.closest('.seo-img'), id = box.querySelector('[data-seo-img-id]'),
        reset = box.querySelector('[data-seo-img-reset]'), note = box.querySelector('[data-seo-img-note]');
    var scope = box.closest('.crm-card') || document;
    function previews() { var own = box.querySelector('[data-seo-pv="img"]'); return own ? [own] : $$('[data-seo-pv="img"]', scope); }
    input.addEventListener('change', function () {
      if (!input.files.length) return;
      var fd = new FormData(); fd.append('file', input.files[0]);
      box.classList.add('is-busy');
      fetch(CFG.api + 'seo/image', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce }, body: fd })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Не удалось загрузить'); return j; }); })
        .then(function (j) {
          id.value = j.id;
          previews().forEach(function (p) { p.src = j.url; });
          if (reset) reset.hidden = false;
          var warn = Math.abs(j.w / j.h - 1200 / 630) > 0.05 && box.dataset.field !== 'logo_id';
          if (note) note.textContent = 'Загружено ' + j.w + '×' + j.h + (warn ? ' — пропорции не 1200×630, мессенджер обрежет края' : '') + '. Нажмите «Сохранить».';
          say('Картинка загружена — сохраните форму');
        })
        .catch(function (e) { say(e.message, true); })
        .then(function () { box.classList.remove('is-busy'); input.value = ''; });
    });
    if (reset) reset.addEventListener('click', function () {
      id.value = 0; reset.hidden = true;
      previews().forEach(function (p) { p.src = reset.dataset.url; });
      if (note) note.textContent = 'Будет стандартная картинка. Нажмите «Сохранить».';
    });
  });
  // счётчик знаков у полей анкеты разметки
  $$('[data-seo-len]').forEach(function (el) {
    var out = el.parentNode.querySelector('[data-seo-len-out]'); if (!out) return;
    var max = +out.dataset.seoLenOut;
    function upd() { var n = el.value.trim().length; out.textContent = n + ' / ' + max + ' зн.'; out.classList.toggle('is-over', n > max * 0.9); }
    el.addEventListener('input', upd); upd();
  });

  // группы минус-слов: добавить и убрать (сохраняется вместе с формой)
  var groups = document.querySelector('[data-seo-groups]');
  function bindDel(g) {
    var b = g.querySelector('[data-seo-group-del]');
    if (b) b.addEventListener('click', function () {
      var ta = g.querySelector('textarea');
      if (ta.value.trim() && !confirm('Убрать группу со всеми словами? Изменение сохранится кнопкой «Сохранить».')) return;
      g.remove();
    });
  }
  if (groups) {
    $$('[data-seo-group]', groups).forEach(bindDel);
    var add = document.querySelector('[data-seo-group-add]');
    if (add) add.addEventListener('click', function () {
      var n = 0;
      $$('input[name^="g_"]', groups).forEach(function (i) { var m = /^g_(\d+)_name$/.exec(i.name); if (m) n = Math.max(n, +m[1] + 1); });
      var g = document.createElement('div');
      g.className = 'seo-group'; g.setAttribute('data-seo-group', '');
      g.innerHTML = '<input type="text" maxlength="60" aria-label="Название группы" placeholder="Название группы">' +
        '<textarea rows="4" class="seo-mono" aria-label="Слова группы" placeholder="по слову в строке"></textarea>' +
        '<button class="crm-link" type="button" data-seo-group-del>Убрать группу</button>';
      g.querySelector('input').name = 'g_' + n + '_name';
      g.querySelector('textarea').name = 'g_' + n + '_words';
      groups.appendChild(g); bindDel(g); g.querySelector('input').focus();
    });
  }

  // «скопировать»: текст элемента по селектору
  $$('[data-seo-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.dataset.seoCopy); if (!el) return;
      var t = el.textContent.trim();
      var done = function () { say('Скопировано'); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done, function () { fallback(t); done(); });
      else { fallback(t); done(); }
    });
  });
  function fallback(t) {
    var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove();
  }
})();
