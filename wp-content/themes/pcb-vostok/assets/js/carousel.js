document.addEventListener("DOMContentLoaded", () => {

    document.querySelectorAll(".carousel")
        .forEach(carousel => {


        const slider =
            carousel.querySelector(".carousel-slider");


        if (!slider) {
            return;
        }


        const prev =
            carousel.querySelector(".carousel-prev");


        const next =
            carousel.querySelector(".carousel-next");


        const pagination =
            carousel.querySelector(".carousel-pagination");



        const ANIMATION_TIME = 450;

        const DRAG_START_THRESHOLD = 5;

        const SLIDE_THRESHOLD = 0.2;

        const FAST_SWIPE_DISTANCE = 0.05;

        const FAST_SWIPE_TIME = 250;



        /*
         * Remove old clones
         */

        slider
            .querySelectorAll(".carousel-clone")
            .forEach(el => el.remove());



        const originalCards =
            Array.from(slider.children);



        if (originalCards.length === 0) {
            return;
        }



        /*
         * Pagination
         */

        const dots = [];


        if (
            pagination &&
            originalCards.length > 1
        ) {

            originalCards.forEach(() => {


                const dot =
                    document.createElement("button");


                dot.className =
                    "carousel-dot";


                dot.type =
                    "button";


                pagination.appendChild(dot);


                dots.push(dot);


            });

        }



        /*
         * Create clones
         */

        if (originalCards.length > 1) {


            const firstClone =
                originalCards[0].cloneNode(true);


            const lastClone =
                originalCards[
                    originalCards.length - 1
                ].cloneNode(true);



            firstClone.classList.add(
                "carousel-clone"
            );


            lastClone.classList.add(
                "carousel-clone"
            );



            slider.insertBefore(
                lastClone,
                slider.firstChild
            );


            slider.appendChild(
                firstClone
            );


        }


        /*
        * Disable native dragging
        */


        slider
            .querySelectorAll("img, a, button")
            .forEach(el => {

                el.addEventListener(
                    "dragstart",
                    e => e.preventDefault()
                );

            });


        /*
         * State
         */

        let currentSlide = 0;

        let correcting = false;

        let animating = false;

        let dragging = false;

        let moved = false;

        let startX = 0;

        let startScroll = 0;

        let startTime = 0;



        /*
         * Helpers
         */

        function getCardStep() {


            const cards =
                slider.children;


            if (cards.length < 2) {
                return slider.clientWidth;
            }


            return (
                cards[1].offsetLeft -
                cards[0].offsetLeft
            );

        }




        function normalize(index) {


            const total =
                originalCards.length;


            if (index < 0) {
                return total - 1;
            }


            if (index >= total) {
                return 0;
            }


            return index;

        }




        function updatePagination() {


            dots.forEach(dot => {

                dot.classList.remove(
                    "is-active"
                );

            });



            dots[currentSlide]
                ?.classList.add(
                    "is-active"
                );

        }




        function setSlide(index) {


            currentSlide =
                normalize(index);


            updatePagination();

        }




        /*
         * Initial position
         */

        requestAnimationFrame(() => {


            slider.style.scrollBehavior =
                "auto";


            slider.scrollLeft =
                getCardStep();


            slider.style.scrollBehavior =
                "";


            updatePagination();


        });




        /*
         * Loop correction
         */

        function correctLoopPosition() {


            if (correcting) {
                return;
            }


            const step =
                getCardStep();


            if (!step) {
                return;
            }



            const cards =
                slider.children;


            const index =
                Math.round(
                    slider.scrollLeft / step
                );



            let target = null;



            if (index === 0) {


                target =
                    step * (cards.length - 2);


            }



            if (
                index === cards.length - 1
            ) {


                target =
                    step;


            }



            if (target !== null) {


                correcting = true;


                slider.style.scrollBehavior =
                    "auto";


                slider.scrollLeft =
                    target;



                requestAnimationFrame(() => {


                    slider.style.scrollBehavior =
                        "";


                    correcting = false;


                });


            }

        }




        /*
         * Buttons
         */

        function moveCarousel(direction) {


            if (animating) {
                return;
            }


            animating = true;



            slider.scrollTo({

                left:
                    slider.scrollLeft +
                    direction * getCardStep(),

                behavior:
                    "smooth"

            });



            setTimeout(() => {


                correctLoopPosition();


                setSlide(
                    currentSlide + direction
                );


                animating = false;


            }, 500);


        }




        prev?.addEventListener(
            "click",
            () => moveCarousel(-1)
        );



        next?.addEventListener(
            "click",
            () => moveCarousel(1)
        );





        /*
         * Pagination click
         */

        dots.forEach((dot, index) => {


            dot.addEventListener(
                "click",
                () => {


                    if (animating) {
                        return;
                    }



                    const diff =
                        index - currentSlide;



                    slider.scrollTo({

                        left:
                            slider.scrollLeft +
                            diff * getCardStep(),

                        behavior:
                            "smooth"

                    });



                    setTimeout(() => {


                        correctLoopPosition();


                        setSlide(index);


                    }, 500);



                }
            );


        });





        /*
         * Drag
         */

        slider.addEventListener(
            "pointerdown",
            e => {


                if (
                    e.target.closest(
                        "a,button"
                    )
                ) {
                    return;
                }



                if (
                    e.pointerType === "mouse" &&
                    e.button !== 0
                ) {
                    return;
                }



                slider.setPointerCapture(
                    e.pointerId
                );



                dragging = true;

                moved = false;


                startX =
                    e.clientX;


                startScroll =
                    slider.scrollLeft;


                startTime =
                    performance.now();



                slider.style.scrollBehavior =
                    "auto";


                slider.classList.add(
                    "is-dragging"
                );


            }
        );




        slider.addEventListener(
            "pointermove",
            e => {


                if (!dragging) {
                    return;
                }



                const dx =
                    e.clientX - startX;



                if (
                    Math.abs(dx) >
                    DRAG_START_THRESHOLD
                ) {

                    moved = true;

                }



                if (moved) {


                    e.preventDefault();


                    slider.scrollLeft =
                        startScroll - dx;


                }


            }
        );





        function finishDrag() {


            if (!dragging) {
                return;
            }


            dragging = false;



            slider.classList.remove(
                "is-dragging"
            );



            slider.style.scrollBehavior =
                "";



            if (!moved) {
                return;
            }



            const step =
                getCardStep();



            const delta =
                slider.scrollLeft -
                startScroll;



            let target =
                Math.round(
                    slider.scrollLeft / step
                ) * step;




            if (
                Math.abs(delta) >
                    step * SLIDE_THRESHOLD ||

                (
                    Math.abs(delta) >
                        step * FAST_SWIPE_DISTANCE &&

                    performance.now() -
                    startTime <
                    FAST_SWIPE_TIME
                )

            ) {


                target =
                    delta > 0

                    ?

                    Math.ceil(
                        slider.scrollLeft / step
                    ) * step

                    :

                    Math.floor(
                        slider.scrollLeft / step
                    ) * step;


            }



            slider.scrollTo({

                left:
                    target,

                behavior:
                    "smooth"

            });




            setTimeout(() => {


                correctLoopPosition();



                const index =
                    Math.round(
                        slider.scrollLeft / step
                    );



                setSlide(
                    (
                        index - 1 +
                        originalCards.length
                    )
                    %
                    originalCards.length
                );



            }, ANIMATION_TIME);




            setTimeout(() => {

                moved = false;

            }, 100);



        }




        slider.addEventListener(
            "pointerup",
            finishDrag
        );


        slider.addEventListener(
            "pointercancel",
            finishDrag
        );


        slider.addEventListener(
            "lostpointercapture",
            finishDrag
        );





        /*
         * Resize
         */

        window.addEventListener(
            "resize",
            () => {


                slider.style.scrollBehavior =
                    "auto";


                slider.scrollLeft =
                    getCardStep() *
                    (currentSlide + 1);



                slider.style.scrollBehavior =
                    "";


            }
        );



    });


});