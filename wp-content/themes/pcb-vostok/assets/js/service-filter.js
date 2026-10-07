/* =================================
   Фильтр услуг в «Производственном цикле» (#capabilities)

   Разметка (задаётся в 20-homepage.html):
   - кнопки селектора:   button[data-svc-select="key|pcb|ecb"][aria-pressed]
   - абзацы-расшифровки: [data-svc-desc="key|pcb|ecb"] (неактивные — hidden)
   - карточки сетки:     .cycle-grid > .cycle-step.svc-key.svc-pcb.svc-ecb
                         — классы svc-<услуга>: в какие услуги входит этап.
                         Классы, а не data-атрибут: этап — блок core/group,
                         Gutenberg сохраняет его className, но не data-*.
                         (data-svc="key pcb" тоже понимается — для разметки вне блоков)
   Этап, не входящий в выбранную услугу, получает класс is-svc-off
   (стили — в cycle.css). После смены услуги анимация цикла
   перезапускается событием cycle:restart (обрабатывает cycle.js).
   ================================= */
(function () {
    'use strict';

    function init(sec) {
        var grid = sec.querySelector('.cycle-grid');
        var btns = [].slice.call(sec.querySelectorAll('[data-svc-select]'));
        if (!grid || !btns.length) return;
        var descs = [].slice.call(sec.querySelectorAll('[data-svc-desc]'));
        var steps = [].slice.call(grid.children).filter(function (n) { return n.classList && n.classList.contains('cycle-step'); });
        var cur = null;

        function apply(svc, restart) {
            if (svc === cur) return;
            cur = svc;
            btns.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.svcSelect === svc ? 'true' : 'false'); });
            descs.forEach(function (d) { d.hidden = d.dataset.svcDesc !== svc; });
            steps.forEach(function (s) {
                var on = s.dataset.svc ? s.dataset.svc.split(/\s+/).indexOf(svc) >= 0
                                       : s.classList.contains('svc-' + svc);
                s.classList.toggle('is-svc-off', !on);
            });
            if (restart) grid.dispatchEvent(new CustomEvent('cycle:restart'));
        }

        btns.forEach(function (b) {
            b.addEventListener('click', function () { apply(b.dataset.svcSelect, true); });
        });

        var start = btns.filter(function (b) { return b.getAttribute('aria-pressed') === 'true'; })[0] || btns[0];
        apply(start.dataset.svcSelect, false);
    }

    function boot() { [].forEach.call(document.querySelectorAll('#capabilities'), init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
