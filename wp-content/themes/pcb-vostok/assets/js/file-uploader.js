document.addEventListener('DOMContentLoaded', function () {

    const uploader = document.getElementById('ff-upload');

    if (!uploader) {
        return;
    }


    /*
     * --------------------------------------------------
     * Элементы интерфейса
     * --------------------------------------------------
     */

    const selectButton =
        document.getElementById('ff-upload-select');

    const dropzone =
        document.getElementById('ff-upload-dropzone');

    const list =
        document.getElementById('ff-upload-list');

    const errorBox =
        document.getElementById('ff-upload-error');

    const summary =
        document.getElementById('ff-upload-summary');


    if (
        !selectButton ||
        !dropzone ||
        !list ||
        !errorBox ||
        !summary
    ) {
        console.error(
            'File uploader: required HTML elements not found.'
        );

        return;
    }


    /*
     * --------------------------------------------------
     * Настройки
     * --------------------------------------------------
     */

    const MAX_FILES = 5;

    const MAX_FILE_SIZE =
        100 * 1024 * 1024;

    const MAX_TOTAL_SIZE =
        200 * 1024 * 1024;

    const ALLOWED_EXTENSIONS = [
        'zip',
        '7z',
        'rar',
        'pdf',
        'doc',
        'docx',
        'txt',
        'rtf'
    ];


    /*
     * --------------------------------------------------
     * Состояние
     * --------------------------------------------------
     */

    let files = [];


    /*
     * --------------------------------------------------
     * Динамический file input
     *
     * Fluent Forms удаляет input[type=file],
     * если он находится внутри Custom HTML.
     *
     * Поэтому создаём его через JS.
     * --------------------------------------------------
     */

    const input =
        document.createElement('input');

    input.type = 'file';
    input.multiple = true;

    input.accept =
        ALLOWED_EXTENSIONS
            .map(function (extension) {
                return '.' + extension;
            })
            .join(',');

    input.style.display = 'none';

    document.body.appendChild(input);


    /*
     * --------------------------------------------------
     * Вспомогательные функции
     * --------------------------------------------------
     */

    function getExtension(filename) {

        const parts =
            filename
                .toLowerCase()
                .split('.');

        if (parts.length < 2) {
            return '';
        }

        return parts.pop();
    }


    function formatSize(bytes) {

        if (bytes < 1024) {
            return bytes + ' Б';
        }

        if (bytes < 1024 * 1024) {
            return (
                bytes / 1024
            ).toFixed(1) + ' КБ';
        }

        return (
            bytes / 1024 / 1024
        ).toFixed(1) + ' МБ';
    }


    function getTotalSize() {

        return files.reduce(
            function (total, item) {
                return total + item.file.size;
            },
            0
        );
    }


    function getFileWord(count) {

        if (
            count % 10 === 1 &&
            count % 100 !== 11
        ) {
            return 'файл';
        }

        if (
            count % 10 >= 2 &&
            count % 10 <= 4 &&
            (
                count % 100 < 10 ||
                count % 100 >= 20
            )
        ) {
            return 'файла';
        }

        return 'файлов';
    }


    function showError(message) {

        errorBox.textContent = message;
        errorBox.hidden = false;
    }


    function clearError() {

        errorBox.textContent = '';
        errorBox.hidden = true;
    }


    function escapeHtml(value) {

        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /*
     * --------------------------------------------------
     * Добавление файлов
     * --------------------------------------------------
     */

    function addFiles(fileList) {

        clearError();

        const selectedFiles =
            Array.from(fileList);


        for (const file of selectedFiles) {

            /*
             * Максимальное количество
             */

            if (files.length >= MAX_FILES) {

                showError(
                    'Можно прикрепить не более ' +
                    MAX_FILES +
                    ' файлов.'
                );

                break;
            }


            /*
             * Расширение
             */

            const extension =
                getExtension(file.name);

            if (
                !ALLOWED_EXTENSIONS.includes(
                    extension
                )
            ) {

                showError(
                    'Файл «' +
                    file.name +
                    '» имеет неподдерживаемый формат.'
                );

                continue;
            }


            /*
             * Размер одного файла
             */

            if (
                file.size >
                MAX_FILE_SIZE
            ) {

                showError(
                    'Файл «' +
                    file.name +
                    '» слишком большой. ' +
                    'Максимальный размер — 100 МБ.'
                );

                continue;
            }


            /*
             * Дубликат
             */

            const alreadyExists =
                files.some(function (item) {

                    return (
                        item.file.name === file.name &&
                        item.file.size === file.size
                    );
                });


            if (alreadyExists) {
                continue;
            }


            /*
             * Общий размер
             */

            if (
                getTotalSize() +
                file.size >
                MAX_TOTAL_SIZE
            ) {

                showError(
                    'Общий размер файлов ' +
                    'не должен превышать 200 МБ.'
                );

                break;
            }


            /*
             * Добавляем файл
             */

            files.push({

                id:
                    window.crypto &&
                    typeof crypto.randomUUID === 'function'
                        ? crypto.randomUUID()
                        : String(
                            Date.now() +
                            Math.random()
                        ),

                file: file
            });
        }


        render();


        /*
         * Сбрасываем input.
         *
         * Это позволяет снова выбрать тот же
         * файл после его удаления.
         */

        input.value = '';
    }


    /*
     * --------------------------------------------------
     * Удаление
     * --------------------------------------------------
     */

    function removeFile(id) {

        files =
            files.filter(function (item) {
                return item.id !== id;
            });

        clearError();

        render();
    }


    /*
     * --------------------------------------------------
     * Отрисовка списка
     * --------------------------------------------------
     */

    function render() {

        list.innerHTML = '';


        files.forEach(function (item) {

            const element =
                document.createElement('div');

            element.className =
                'ff-upload__item';


            element.innerHTML = `

                <div class="ff-upload__item-top">

                    <div class="ff-upload__item-icon">
                        ▣
                    </div>

                    <div class="ff-upload__item-info">

                        <div
                            class="ff-upload__item-name"
                            title="${escapeHtml(item.file.name)}"
                        >
                            ${escapeHtml(item.file.name)}
                        </div>

                        <div class="ff-upload__item-size">
                            ${formatSize(item.file.size)}
                        </div>

                    </div>

                </div>

                <button
                    type="button"
                    class="ff-upload__remove"
                    data-file-id="${item.id}"
                    aria-label="Удалить файл"
                >
                    ×
                </button>
            `;


            list.appendChild(element);
        });


        updateSummary();
    }


    /*
     * --------------------------------------------------
     * Итоговая информация
     * --------------------------------------------------
     */

    function updateSummary() {

        if (!files.length) {

            summary.hidden = true;

            return;
        }


        summary.hidden = false;

        summary.textContent =
            files.length +
            ' ' +
            getFileWord(files.length) +
            ' · ' +
            formatSize(
                getTotalSize()
            );
    }


    /*
     * --------------------------------------------------
     * Кнопка выбора файлов
     * --------------------------------------------------
     */

    selectButton.addEventListener(
        'click',
        function () {

            input.click();
        }
    );


    /*
     * --------------------------------------------------
     * Выбор файлов через диалог
     * --------------------------------------------------
     */

    input.addEventListener(
        'change',
        function () {

            if (!input.files.length) {
                return;
            }

            addFiles(input.files);
        }
    );


    /*
     * --------------------------------------------------
     * Drag & Drop
     * --------------------------------------------------
     */

    dropzone.addEventListener(
        'dragover',
        function (event) {

            event.preventDefault();

            dropzone.classList.add(
                'is-dragover'
            );
        }
    );


    dropzone.addEventListener(
        'dragleave',
        function () {

            dropzone.classList.remove(
                'is-dragover'
            );
        }
    );


    dropzone.addEventListener(
        'drop',
        function (event) {

            event.preventDefault();

            dropzone.classList.remove(
                'is-dragover'
            );

            if (
                !event.dataTransfer ||
                !event.dataTransfer.files.length
            ) {
                return;
            }

            addFiles(
                event.dataTransfer.files
            );
        }
    );


    /*
     * --------------------------------------------------
     * Удаление файла
     * --------------------------------------------------
     */

    list.addEventListener(
        'click',
        function (event) {

            const button =
                event.target.closest(
                    '.ff-upload__remove'
                );

            if (!button) {
                return;
            }

            removeFile(
                button.dataset.fileId
            );
        }
    );


    /*
     * --------------------------------------------------
     * Начальная отрисовка
     * --------------------------------------------------
     */

    render();


    /*
     * --------------------------------------------------
     * Для отладки
     *
     * Временно оставляем доступ к состоянию
     * через консоль.
     * --------------------------------------------------
     */

    window.ffFileUploader = {

        getFiles: function () {
            return files;
        },

        getTotalSize: function () {
            return getTotalSize();
        }

    };

});