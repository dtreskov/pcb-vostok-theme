/* =================================
   Carousel (pcar) — assets/js/carousel.js, заменяет прежнюю карусель
   Один слайд = вся ширина окна. Стрелки по бокам за полем контента
   (на ширине < 1200px — рядом с пагинацией). Пагинация снизу.
   Сквозная прокрутка: по краям ленты стоят «фантомные» копии
   (последний слайд перед первым и первый после последнего). Лента
   доезжает до копии, а после остановки незаметно перескакивает
   на настоящий слайд — прокрутка идёт дальше, а не отскакивает.
   Свайп и прокрутка — нативные (scroll-snap), мышью — перетаскивание,
   клавиши ← → на сфокусированной ленте.
   Картинки соседних слайдов загружаются и декодируются заранее (warm).
   ================================= */
(function () {
    'use strict';

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function pad(n) { return String(n).padStart(2, '0'); }

    function phantom(node) {
        var c = node.cloneNode(true);
        c.classList.add('pcar__slide--clone');
        c.setAttribute('aria-hidden', 'true');
        c.setAttribute('inert', '');
        [].forEach.call(c.querySelectorAll('a, button, [tabindex]'), function (el) { el.setAttribute('tabindex', '-1'); });
        [].forEach.call(c.querySelectorAll('[id]'), function (el) { el.removeAttribute('id'); });
        return c;
    }

    function initPcar(root) {
        if (root.dataset.ready) return;
        root.dataset.ready = '1';

        var track = root.querySelector('.pcar__track');
        var slides = [].slice.call(track.children);
        var N = slides.length;
        var dots = root.querySelector('.pcar__dots');
        var count = root.querySelector('.pcar__count');
        var prev = root.querySelector('.pcar__arrow--prev');
        var next = root.querySelector('.pcar__arrow--next');
        var loop = N > 1;
        var OFF = loop ? 1 : 0;   /* позиция первого настоящего слайда */
        var cur = -1, drag = null, moved = false;

        slides.forEach(function (s, i) {
            s.setAttribute('role', 'group');
            s.setAttribute('aria-roledescription', 'слайд');
            s.setAttribute('aria-label', (i + 1) + ' из ' + N);
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('aria-label', 'Карточка ' + (i + 1));
            b.addEventListener('click', function () { goPos(i + OFF); });
            dots.appendChild(b);
        });

        if (loop) {
            track.insertBefore(phantom(slides[N - 1]), slides[0]);
            track.appendChild(phantom(slides[0]));
        }

        function width() { return slides[0].offsetWidth || track.clientWidth || 1; }
        function pos() { return Math.round(track.scrollLeft / width()); }
        function real(p) { return ((p - OFF) % N + N) % N; }

        function jump(p) {
            track.style.scrollBehavior = 'auto';
            track.scrollLeft = p * width();
            track.style.scrollBehavior = '';
        }
        function goPos(p) {
            p = Math.max(0, Math.min(N - 1 + OFF * 2, p));
            track.scrollTo({ left: p * width(), behavior: reduce ? 'auto' : 'smooth' });
            if (reduce) settle();
        }
        function step(d) { goPos(pos() + d); }

        function update() {
            var i = real(pos());
            if (i === cur) return;
            cur = i;
            [].forEach.call(dots.children, function (b, n) { b.setAttribute('aria-current', n === i ? 'true' : 'false'); });
            if (count) count.innerHTML = '<b>' + pad(i + 1) + '</b> / ' + pad(N);   /* счётчик необязателен */
            slides.forEach(function (s, n) { s.setAttribute('aria-hidden', n === i ? 'false' : 'true'); });
            if (!loop) { prev.disabled = i <= 0; next.disabled = i >= N - 1; }
            warm((i + 1) % N); warm((i - 1 + N) % N);
        }

        /* заранее загружаем и декодируем картинки соседних слайдов в свободное время,
           чтобы тяжёлая отрисовка SVG не совпадала со свайпом */
        var idleCb = window.requestIdleCallback || function (f) { return setTimeout(f, 200); };
        function warm(n) {
            var s = slides[n];
            if (!s || s.dataset.warm) return;
            s.dataset.warm = '1';
            idleCb(function () {
                [].forEach.call(s.querySelectorAll('img'), function (img) {
                    img.loading = 'eager';
                    if (img.decode) img.decode().catch(function () {});
                });
            });
        }

        /* после остановки на фантоме — перескок на настоящий слайд */
        function settle() {
            if (!loop || drag) return;
            var p = pos();
            if (Math.abs(track.scrollLeft - p * width()) > 2) return;   /* ещё едем */
            if (p <= 0) jump(N);
            else if (p >= N + 1) jump(1);
            update();
        }

        var raf = 0, idle = 0;
        track.addEventListener('scroll', function () {
            if (!raf) raf = requestAnimationFrame(function () { raf = 0; update(); });
            clearTimeout(idle);
            idle = setTimeout(settle, 150);
        }, { passive: true });
        track.addEventListener('scrollend', settle);

        prev.addEventListener('click', function () { step(-1); });
        next.addEventListener('click', function () { step(1); });

        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
        });

        /* перетаскивание мышью */
        track.addEventListener('pointerdown', function (e) {
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            drag = { x: e.clientX, left: track.scrollLeft, from: pos() };
            moved = false;
        });
        window.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var dx = e.clientX - drag.x;
            if (!moved && Math.abs(dx) > 5) { moved = true; root.classList.add('is-dragging'); }
            if (moved) track.scrollLeft = drag.left - dx;
        });
        window.addEventListener('pointerup', function (e) {
            if (!drag) return;
            var dx = e.clientX - drag.x, from = drag.from;
            drag = null;
            root.classList.remove('is-dragging');
            if (moved) goPos(Math.abs(dx) > 60 ? from + (dx < 0 ? 1 : -1) : from);
        });
        track.addEventListener('click', function (e) {
            if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
        }, true);
        track.addEventListener('dragstart', function (e) { e.preventDefault(); });

        /* при изменении ширины остаёмся на той же карточке */
        if ('ResizeObserver' in window) {
            new ResizeObserver(function () { jump((cur < 0 ? 0 : cur) + OFF); }).observe(track);
        }

        jump(OFF);
        update();
    }

    window.PcbCarousel = { init: initPcar };

    function boot() { [].forEach.call(document.querySelectorAll('.pcar'), initPcar); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
