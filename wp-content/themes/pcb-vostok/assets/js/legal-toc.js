/* =====================================================
   legal-toc.js — подсветка текущего раздела в оглавлении
   страницы «Правовая информация» (/politics).

   По мере прокрутки отмечает в .legal-toc:
   - ссылку на раздел, который сейчас читается (.is-active);
   - ссылку на документ, в который он входит (.is-current).
   Если оглавление прокручивается само (не помещается по высоте),
   активный пункт держится в его видимой области.
   ===================================================== */
(function () {
    'use strict';

    var toc = document.querySelector('.legal-toc');
    if (!toc) {
        return;
    }

    var links = [].slice.call(toc.querySelectorAll('a[href^="#"]'));
    var items = [];

    links.forEach(function (link) {
        var id = decodeURIComponent(link.getAttribute('href').slice(1));
        var target = id && document.getElementById(id);
        if (!target) {
            return;
        }
        var topItem = link.closest('.legal-toc > ol > li');
        items.push({
            link: link,
            target: target,
            docLink: topItem ? topItem.querySelector(':scope > a') : null
        });
    });

    if (!items.length) {
        return;
    }

    function offset() {
        var v = parseFloat(
            getComputedStyle(document.documentElement).getPropertyValue('--anchor-offset')
        );
        return (isNaN(v) ? 60 : v) + 48;
    }

    var current = null;

    function update() {
        var line = offset();
        var active = items[0];

        // Последний заголовок, который уже поднялся до линии чтения.
        items.forEach(function (item) {
            if (item.target.getBoundingClientRect().top <= line) {
                active = item;
            }
        });

        // Долистали до конца страницы — активен последний пункт.
        var doc = document.documentElement;
        if (window.innerHeight + window.scrollY >= doc.scrollHeight - 2) {
            active = items[items.length - 1];
        }

        if (active === current) {
            return;
        }
        current = active;

        links.forEach(function (link) {
            link.classList.remove('is-active', 'is-current');
            link.removeAttribute('aria-current');
        });

        active.link.classList.add('is-active');
        active.link.setAttribute('aria-current', 'location');
        if (active.docLink && active.docLink !== active.link) {
            active.docLink.classList.add('is-current');
        }

        // Держим активный пункт в видимой части прокручиваемого оглавления.
        if (toc.scrollHeight > toc.clientHeight) {
            var t = active.link.offsetTop;
            var b = t + active.link.offsetHeight;
            if (t < toc.scrollTop + 24 || b > toc.scrollTop + toc.clientHeight - 24) {
                toc.scrollTop = t - toc.clientHeight / 3;
            }
        }
    }

    var ticking = false;
    function onScroll() {
        if (ticking) {
            return;
        }
        ticking = true;
        window.requestAnimationFrame(function () {
            ticking = false;
            update();
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    window.addEventListener('hashchange', onScroll);
    update();
})();
