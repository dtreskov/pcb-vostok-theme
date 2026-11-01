document.addEventListener("DOMContentLoaded", () => {

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    /*
     * Раскрытие спойлеров (этапы, FAQ): анимация высоты .details-content
     * в обе стороны. Повторный клик посреди анимации разворачивает её.
     * Браузер открывает <details> мгновенно, поэтому клик по заголовку
     * перехватывается и open ставится/снимается в нужный момент.
     */
    const ACC = ".faq-grid details, .section--stage .details-link";

    function setDetails(details, open) {

        const content = details.querySelector(":scope > .details-content");

        if (!content || reduceMotion.matches) {
            details.open = open;
            return;
        }

        const from = details.open ? content.getBoundingClientRect().height : 0;

        if (details._anim) {
            details._anim.cancel();
        }

        details._want = open;

        if (open) {
            details.open = true;
        }

        const to = open ? content.scrollHeight : 0;

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

        setDetails(details, !isOpen);

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