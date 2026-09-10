document.addEventListener('DOMContentLoaded', () => {

    const calculator = document.querySelector('.calculator-body');

    if (!calculator) {
        return;
    }

    const result = document.querySelector(
        '.calculator-result-value'
    );

    if (!result) {
        return;
    }


    /*
     * =================================
     * Calculation settings
     * =================================
     */

    const coefficients = {

        layers: {
            2: 1,
            4: 1.35,
            6: 1.7,
            8: 2.1,
            10: 2.5,
            12: 3,
            16: 3.6,
            20: 4.2,
            24: 5
        },

        boardThickness: {
            0.2: 1.2,
            0.4: 1.1,
            0.6: 1.05,
            0.8: 1,
            1: 1,
            1.2: 1.05,
            1.6: 1.1,
            2: 1.2,
            2.4: 1.3,
            2.6: 1.35,
            2.8: 1.4,
            3: 1.45,
            3.2: 1.5
        },

        traceThickness: {
            18: 1,
            35: 1.05,
            50: 1.1,
            70: 1.2,
            105: 1.35
        },

        finishMaterial: {
            first: 1,
            second: 1.3,
            third: 1.6
        }

    };


    /*
     * =================================
     * Base price
     * =================================
     */

    const BASE_PRICE = 5000;


    /*
     * =================================
     * Read calculator parameters
     * =================================
     */

    function getParameters() {

        const parameters = {};

        const fields = calculator.querySelectorAll(
            '[data-parameter]'
        );

        fields.forEach(field => {

            const parameter = field.dataset.parameter;

            if (field.type === 'number') {
                parameters[parameter] =
                    parseFloat(field.value);
            } else {
                parameters[parameter] =
                    field.value;
            }

        });

        return parameters;
    }


    /*
     * =================================
     * Area coefficient
     * =================================
     */

    function getAreaCoefficient(length, width) {

        const area = length * width;

        return Math.max(
            area / 10000,
            0.5
        );
    }


    /*
     * =================================
     * Quantity coefficient
     * =================================
     */

    function getQuantityCoefficient(quantity) {

        if (quantity <= 5) {
            return 1.5;
        }

        if (quantity <= 20) {
            return 1.2;
        }

        if (quantity <= 50) {
            return 1;
        }

        if (quantity <= 100) {
            return 0.85;
        }

        if (quantity <= 500) {
            return 0.7;
        }

        return 0.6;
    }


    /*
     * =================================
     * Calculate price
     * =================================
     */

    function calculatePrice(parameters) {

        const layersCoefficient =
            coefficients.layers[
                parameters.layers
            ] || 1;

        const thicknessCoefficient =
            coefficients.boardThickness[
                parameters.board_thickness
            ] || 1;

        const traceCoefficient =
            coefficients.traceThickness[
                parameters.trace_thickness
            ] || 1;

        const finishCoefficient =
            coefficients.finishMaterial[
                parameters.finish_material
            ] || 1;

        const areaCoefficient =
            getAreaCoefficient(
                parameters.length,
                parameters.width
            );

        const quantityCoefficient =
            getQuantityCoefficient(
                parameters.quantity
            );


        let price =
            BASE_PRICE *
            layersCoefficient *
            thicknessCoefficient *
            traceCoefficient *
            finishCoefficient *
            areaCoefficient *
            quantityCoefficient;


        /*
         * Temporary quantity calculation.
         */

        price *= parameters.quantity;


        return Math.round(price);
    }


    /*
     * =================================
     * Format price
     * =================================
     */

    function formatPrice(price) {

        return new Intl.NumberFormat(
            'ru-RU'
        ).format(price) + ' ₽';

    }


    /*
     * =================================
     * Update result
     * =================================
     */

    function updateCalculator() {

        const parameters = getParameters();


        /*
         * Do not calculate until
         * required numeric fields
         * contain valid values.
         */

        if (
            !Number.isFinite(parameters.length) ||
            !Number.isFinite(parameters.width) ||
            !Number.isFinite(parameters.quantity) ||
            parameters.length <= 0 ||
            parameters.width <= 0 ||
            parameters.quantity <= 0
        ) {

            result.textContent = '—';

            return;
        }


        const price =
            calculatePrice(parameters);

        result.textContent =
            formatPrice(price);
    }


    /*
     * =================================
     * Automatic recalculation
     * =================================
     */

    const fields = calculator.querySelectorAll(
        '[data-parameter]'
    );

    fields.forEach(field => {

        field.addEventListener(
            'input',
            updateCalculator
        );

        field.addEventListener(
            'change',
            updateCalculator
        );

    });


    /*
     * =================================
     * Initial calculation
     * =================================
     */

    updateCalculator();

});