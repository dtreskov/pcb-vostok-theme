document.addEventListener("DOMContentLoaded", () => {

    /* Animate cards row by row, not card by card */
    function setCardAnimationDelays(content) {

        const INITIAL_DELAY = 0.2;
        const ROW_DELAY = 0.12;

        const cards = content.querySelectorAll(
            ".cards-grid .grid-item"
        );

        if (!cards.length) {
            return;
        }

        let rows = [];

        cards.forEach(card => {

            const top = card.offsetTop;

            let row = rows.find(
                item => Math.abs(item.top - top) < 2
            );

            if (!row) {

                row = {
                    top: top,
                    cards: []
                };

                rows.push(row);
            }

            row.cards.push(card);

        });


        rows.forEach((row, index) => {

            row.cards.forEach(card => {

                card.style.setProperty(
                    "--card-delay",
                    `${INITIAL_DELAY + index * ROW_DELAY}s`
                );

            });

        });

    }

    /*
     * Запуск анимации details
     */

    function animateDetails(details) {

        const content =
            details.querySelector(".details-content");

        if (!content) {
            return;
        }


        // Сбрасываем предыдущую анимацию
        content.classList.remove("animate");


        // Принудительно применяем закрытое состояние
        void content.offsetWidth;


        // Определяем ряды карточек
        setCardAnimationDelays(content);


        // Запускаем анимацию со следующего кадра
        requestAnimationFrame(() => {

            requestAnimationFrame(() => {

                content.classList.add("animate");

            });

        });

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

                            details.open = true;

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
     * Повторная анимация при ручном открытии details
     */
    document
        .querySelectorAll("details")
        .forEach(details => {


            details.addEventListener("toggle", () => {

                if (details.open) {

                    animateDetails(details);

                } else {

                    const content =
                        details.querySelector(".details-content");

                    if (content) {
                        content.classList.remove("animate");
                    }

                }

            });


        });



    /*
     * Открытие при загрузке страницы:
     * site.ru/#manufacturing
     */
    openDetailsByHash();


});