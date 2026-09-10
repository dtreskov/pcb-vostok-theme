<?php


/**
 * ID формы Fluent Forms
 */
const CONTACT_FORM_ID = 3;


/**
 * Проверка номера телефона.
 *
 * Допускаются:
 * - цифры
 * - пробелы
 * - +
 * - -
 * - ( )
 */
function my_validate_phone(string $phone): bool
{
    $phone = trim($phone);

    if ($phone === '') {
        return true;
    }

    // Проверка допустимых символов
    if (!preg_match('/^[0-9+\s\-\(\)]+$/', $phone)) {
        return false;
    }

    // Проверяем количество цифр
    $digits = preg_replace('/\D/', '', $phone);

    return strlen($digits) >= 5;
}


/**
 * Проверка адреса электронной почты.
 */
function my_validate_email(string $email): bool
{
    $email = trim($email);

    if ($email === '') {
        return true;
    }

    // Пробелы недопустимы
    if (preg_match('/\s/', $email)) {
        return false;
    }

    // Базовая проверка PHP
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    [$local, $domain] = explode('@', $email, 2);

    if ($local === '' || $domain === '') {
        return false;
    }

    // Дополнительная проверка домена
    if (
        str_starts_with($domain, '.') ||
        str_ends_with($domain, '.') ||
        strpos($domain, '..') !== false ||
        strpos($domain, '.') === false
    ) {
        return false;
    }

    // Минимальная проверка доменной зоны
    $tld = substr(strrchr($domain, '.'), 1);

    if (strlen($tld) < 2) {
        return false;
    }

    return true;
}


/**
 * Основная валидация Fluent Forms.
 */
add_filter(
    'fluentform/validation_errors',
    function ($errors, $formData, $form, $fields) {

        if ($form->id != CONTACT_FORM_ID) {
            return $errors;
        }

        $email = trim($formData['email_text'] ?? '');
        $phone = trim($formData['phone_text'] ?? '');
        $method = $formData['dropdown'] ?? 'email';
        $description = trim($formData['description'] ?? '');


        /**
         * Проверка основного текста заявки.
         */
        if ($description === '') {

            $errors['description'] = [
                'Опишите задачу или укажите назначение прикрепленных файлов. Это поможет нам правильно обработать заявку.'
            ];
        }


        /**
         * Проверяем заполненные контактные поля.
         */
        if ($email !== '' && !my_validate_email($email)) {

            $errors['email_text'] = [
                'Проверьте адрес электронной почты. Возможно, в нем есть опечатка.'
            ];
        }


        if ($phone !== '' && !my_validate_phone($phone)) {

            $errors['phone_text'] = [
                'Проверьте номер телефона. Возможно, в нем есть опечатка.'
            ];
        }


        /**
         * Проверяем обязательность контакта
         * в зависимости от выбранного способа связи.
         */
        switch ($method) {

            case 'email':

                if ($email === '') {

                    $errors['email_text'] = [
                        'Вы выбрали обратную связь по электронной почте. Укажите адрес электронной почты.'
                    ];
                }

                break;


            case 'phone':

                if ($phone === '') {

                    $errors['phone_text'] = [
                        'Вы выбрали обратную связь по телефону. Укажите номер телефона.'
                    ];
                }

                break;


            case 'process':
            case 'other':

                if ($email === '' && $phone === '') {

                    $message =
                        'Укажите хотя бы один способ связи: телефон или адрес электронной почты.';

                    $errors['email_text'] = [
                        $message
                    ];

                    $errors['phone_text'] = [
                        $message
                    ];
                }

                break;
        }


        return $errors;
    },
    10,
    4
);
