(function($) {

    /** Help file download script **/

    $('.ucmm-wpbrigade-log-file').on('click', function(event) {

        event.preventDefault();

        $.ajax({

            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ucmm_help',
                'help_nonce': mc_api.help_nonce
            },
            beforeSend: function() {
                $('.ucmm-log-file-sniper').show();
            },
            success: function(response) {

                $('.ucmm-log-file-sniper').hide();
                $('.ucmm-log-file-text').show();

                if (!window.navigator.msSaveOrOpenBlob) { // If msSaveOrOpenBlob() is supported, then so is msSaveBlob().
                    $('<a />', {
                            "download": 'under-construction-log.txt',
                            "href": 'data:text/plain;charset=utf-8,' + encodeURIComponent(response),
                        }).appendTo("body")
                        .click(function() {
                            $(this).remove()
                        })[0].click()
                } else {
                    var blobObject = new Blob([response]);
                    window.navigator.msSaveBlob(blobObject, 'under-construction-log.txt');
                }

                setTimeout(function() {
                    $(".ucmm-log-file-text").fadeOut()
                }, 3000);
            }
        });

    });

    /** Help page sysinfo copy button **/

    var ucmmCopyResetTimeout = null;

    function ucmmCopyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function(resolve, reject) {
            var $textarea = $('.ucmm-help-sysinfo__textarea');

            if (!$textarea.length) {
                reject();
                return;
            }

            var textarea = $textarea[0];
            var previousFocus = document.activeElement;

            textarea.focus();
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject();
                }
            } catch (error) {
                reject(error);
            } finally {
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    previousFocus.focus();
                }
            }
        });
    }

    function ucmmResetCopyButton($btn) {
        var copyLabel = mc_api.copyLabel || 'Copy';

        $btn.removeClass('is-copied');
        $btn.find('.ucmm-help-sysinfo-copy__label').text(copyLabel);
        $btn.find('.ucmm-help-sysinfo-copy__tooltip').text(copyLabel);
        $btn.attr('aria-label', mc_api.copyAriaLabel || copyLabel);
    }

    $('.ucmm-help-sysinfo-copy').on('click', function(event) {
        event.preventDefault();

        var $btn = $(this);
        var text = $('.ucmm-help-sysinfo__textarea').val();

        if (!text) {
            return;
        }

        if (ucmmCopyResetTimeout) {
            clearTimeout(ucmmCopyResetTimeout);
            ucmmCopyResetTimeout = null;
        }

        ucmmCopyToClipboard(text).then(function() {
            var copiedLabel = mc_api.copiedLabel || 'Copied!';

            $btn.addClass('is-copied');
            $btn.find('.ucmm-help-sysinfo-copy__label').text(copiedLabel);
            $btn.find('.ucmm-help-sysinfo-copy__tooltip').text(copiedLabel);
            $btn.attr('aria-label', copiedLabel);

            ucmmCopyResetTimeout = setTimeout(function() {
                ucmmResetCopyButton($btn);
                ucmmCopyResetTimeout = null;
            }, 2000);
        });
    });

})(jQuery);