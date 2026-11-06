document.addEventListener("DOMContentLoaded", () => {

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    /*
     * Раскрытие спойлеров (этапы, FAQ): анимация высоты .details-content
     * в обе стороны. Повторный клик посреди анимации разворачивает её.
     * Браузер открывает <details> мгновенно, поэтому клик по заголовку
     * перехватывается и open ставится/снимается в нужный момент.
     *
     * На время анимации привязка прокрутки (scroll anchoring) отключена:
     * иначе, если заголовок частично ушёл за верх экрана, браузер держит
     * на месте содержимое ниже и спойлер «растёт вверх». Если после клика
     * блок (этап или карточка FAQ) виден не целиком, экран один раз мягко
     * подводится к его верху под шапку — без постоянного scroll-snap.
     */
    const ACC = ".faq-grid details, .section--stage .details-link";

    const root = document.documentElement;

    let anchorLocks = 0;

    function lockAnchor(on) {
        anchorLocks = Math.max(0, anchorLocks + (on ? 1 : -1));
        root.style.overflowAnchor = anchorLocks ? "none" : "";
    }

    function snapTo(details, delta) {

        const block = details.closest(".section--stage") || details.closest(".faq-item") || details;
        const rect = block.getBoundingClientRect();
        const offset = parseFloat(getComputedStyle(root).scrollPaddingTop) || 0;
        const gap = block === details.closest(".section--stage") ? 0 : 16;

        /* блок уже целиком на экране (с учётом будущей высоты) — не двигаем */
        if (rect.top >= offset && rect.bottom + delta <= window.innerHeight) {
            return;
        }

        /* при закрытии подводим, только если верх блока ушёл за шапку */
        if (delta < 0 && rect.top >= offset) {
            return;
        }

        window.scrollTo({
            top: window.scrollY + rect.top - offset - gap,
            behavior: reduceMotion.matches ? "auto" : "smooth"
        });

    }

    function setDetails(details, open, snap) {

        const content = details.querySelector(":scope > .details-content");

        if (!content || reduceMotion.matches) {
            const before = content && details.open ? content.getBoundingClientRect().height : 0;
            details.open = open;
            if (snap && content) {
                snapTo(details, (open ? content.getBoundingClientRect().height : 0) - before);
            }
            return;
        }

        const from = details.open ? content.getBoundingClientRect().height : 0;

        if (details._anim) {
            details._anim.cancel();
        } else {
            lockAnchor(true);
        }

        details._want = open;

        if (open) {
            details.open = true;
        }

        const to = open ? content.scrollHeight : 0;

        if (snap) {
            snapTo(details, to - from);
        }

        const anim = content.animate(
            [
                { height: from + "px", opacity: open && !from ? 0 : 1 },
                { height: to + "px", opacity: open ? 1 : 0 }
            ],
            {
                duration: open ? 320 : 240,
                easing: open ? "cubic-bezier(.2, .7, .3, 1)" : "cubic-bezier(.4, 0, .8, .6)"
            }
        );

        details._anim = anim;

        anim.onfinish = () => {
            if (!open) {
                details.open = false;
            }
            details._anim = null;
            details._want = undefined;
            lockAnchor(false);
        };

    }

    document.addEventListener("click", event => {

        const summary = event.target.closest("summary");

        if (!summary) {
            return;
        }

        const details = summary.parentElement;

        if (!details.matches(ACC)) {
            return;
        }

        event.preventDefault();

        const isOpen = details._want !== undefined ? details._want : details.open;

        setDetails(details, !isOpen, true);

    });


    /*
     * FAQ: закрытые вопросы в одном ряду — по высоте самого высокого
     * (--faq-sum-h на карточке; открытый ответ соседей не растягивает)
     */
    function equalizeFaq() {

        document.querySelectorAll(".faq-grid").forEach(grid => {

            const items = [...grid.querySelectorAll(".faq-item")];

            items.forEach(item => item.style.removeProperty("--faq-sum-h"));

            const rows = new Map();

            items.forEach(item => {
                const top = Math.round(item.offsetTop);
                if (!rows.has(top)) {
                    rows.set(top, []);
                }
                rows.get(top).push(item);
            });

            rows.forEach(row => {

                let max = 0;

                row.forEach(item => {
                    const summary = item.querySelector("summary");
                    if (summary) {
                        max = Math.max(max, summary.getBoundingClientRect().height);
                    }
                });

                row.forEach(item => item.style.setProperty("--faq-sum-h", Math.ceil(max) + "px"));

            });

        });

    }

    let faqTimer;

    window.addEventListener("resize", () => {
        clearTimeout(faqTimer);
        faqTimer = setTimeout(equalizeFaq, 120);
    });

    equalizeFaq();

    if (document.fonts) {
        document.fonts.ready.then(equalizeFaq);
    }


    /*
     * Открытие details после появления блока в области просмотра
     */
    function observeDetailsOpening(section, details) {

        const observer = new IntersectionObserver(
            (entries, observer) => {

                entries.forEach(entry => {

                    if (entry.isIntersecting) {

                        details.open = false;

                        requestAnimationFrame(() => {

                            setDetails(details, true);

                        });


                        observer.disconnect();

                    }

                });

            },
            {
                threshold: 0.3
            }
        );


        observer.observe(section);

    }



    /*
     * Открытие details при переходе по якорю
     */
    function openDetailsByHash() {

        const hash = window.location.hash;

        if (!hash) {
            return;
        }


        const section = document.querySelector(hash);

        if (!section) {
            return;
        }


        const details = section.querySelector("details");

        if (!details) {
            return;
        }



        // Закрываем остальные details
        document
            .querySelectorAll("details[open]")
            .forEach(item => {

                if (item !== details) {
                    item.open = false;
                }

            });



        observeDetailsOpening(section, details);

    }



    /*
     * Клик по кнопке "Подробнее"
     */
    document
        .querySelectorAll('a[href^="#"]')
        .forEach(link => {

            link.addEventListener("click", () => {

                setTimeout(() => {

                    openDetailsByHash();

                }, 50);

            });

        });



    /*
     * Открытие при загрузке страницы:
     * site.ru/#manufacturing
     */
    openDetailsByHash();


});