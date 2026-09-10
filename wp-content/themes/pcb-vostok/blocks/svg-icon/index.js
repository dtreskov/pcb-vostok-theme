(function () {

    const {
        registerBlockType
    } = wp.blocks;

    const {
        useState
    } = wp.element;

    const {
        useBlockProps,
        InspectorControls
    } = wp.blockEditor;

    const {
        PanelBody,
        Button,
        Popover,
        TextControl
    } = wp.components;

    const icons = window.svgIconData?.icons || [];
    const iconsUrl = window.svgIconData?.url || '';

    registerBlockType('custom/svg-icon', {

        edit: function ({ attributes, setAttributes }) {

            const name = attributes.name || '';

            const [isOpen, setIsOpen] = useState(false);
            const [search, setSearch] = useState('');

            const selectedIcon = icons.find(
                icon => icon.name === name
            );

            const filteredIcons = icons.filter(
                icon =>
                    icon.name
                        .toLowerCase()
                        .includes(search.toLowerCase())
            );

            const blockProps = useBlockProps({
                className: 'svg-icon-block'
            });

            return wp.element.createElement(
                wp.element.Fragment,
                null,

                wp.element.createElement(
                    InspectorControls,
                    null,

                    wp.element.createElement(
                        PanelBody,
                        {
                            title: 'SVG Icon',
                            initialOpen: true
                        },

                        wp.element.createElement(
                            Button,
                            {
                                variant: 'secondary',
                                onClick: function () {
                                    setIsOpen(!isOpen);
                                }
                            },
                            selectedIcon
                                ? selectedIcon.name
                                : 'Выбрать иконку'
                        )
                    )
                ),

                wp.element.createElement(
                    'div',
                    blockProps,

                    wp.element.createElement(
                        Button,
                        {
                            className: 'svg-icon-picker-button',
                            onClick: function () {
                                setIsOpen(!isOpen);
                            }
                        },

                        selectedIcon
                            ? wp.element.createElement(
                                'img',
                                {
                                    src:
                                        iconsUrl +
                                        selectedIcon.name +
                                        '.svg',
                                    alt: selectedIcon.name
                                }
                            )
                            : 'Выбрать иконку'
                    ),

                    isOpen &&
                        wp.element.createElement(
                            Popover,
                            {
                                placement: 'bottom-start',
                                onClose: function () {
                                    setIsOpen(false);
                                }
                            },

                            wp.element.createElement(
                                'div',
                                {
                                    className: 'svg-icon-picker'
                                },

                                wp.element.createElement(
                                    TextControl,
                                    {
                                        placeholder: 'Поиск...',
                                        value: search,
                                        onChange: setSearch
                                    }
                                ),

                                wp.element.createElement(
                                    'div',
                                    {
                                        className: 'svg-icon-grid'
                                    },

                                    filteredIcons.length
                                        ? filteredIcons.map(
                                            function (icon) {

                                                const isSelected =
                                                    name === icon.name;

                                                return wp.element.createElement(
                                                    Button,
                                                    {
                                                        key: icon.name,
                                                        className:
                                                            'svg-icon-option' +
                                                            (
                                                                isSelected
                                                                    ? ' is-selected'
                                                                    : ''
                                                            ),
                                                        onClick: function () {

                                                            setAttributes({
                                                                name:
                                                                    icon.name
                                                            });

                                                            setIsOpen(false);
                                                            setSearch('');
                                                        }
                                                    },

                                                    wp.element.createElement(
                                                        'img',
                                                        {
                                                            src:
                                                                iconsUrl +
                                                                icon.name +
                                                                '.svg',
                                                            alt: icon.name
                                                        }
                                                    ),

                                                    wp.element.createElement(
                                                        'span',
                                                        null,
                                                        icon.name
                                                    )
                                                );
                                            }
                                        )
                                        :
                                        wp.element.createElement(
                                            'div',
                                            {
                                                className:
                                                    'svg-icon-no-results'
                                            },
                                            'Иконки не найдены'
                                        )
                                )
                            )
                        )
                )
            );
        },

        save: function () {
            return null;
        }
    });

})();