/**
 * QR Code Generator — Frontend JS
 * Version: 1.2.0
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $btn   = $('#dbqr-generate-btn-frontend');
        var $error = $('#dbqr-error-frontend');

        $btn.on('click', function () {
            var formData = new FormData();
            formData.append('action',     'dbqr_generate');
            formData.append('qr_url',     $('#dbqr-url-frontend').val());
            formData.append('qr_size',    $('#dbqr-size-frontend').val());
            formData.append('frontend',   '1');
            formData.append('dbqr_nonce', dbqr_front.nonce);

            var logoEl = document.getElementById('dbqr-logo-frontend');
            if (logoEl && logoEl.files[0]) {
                formData.append('qr_logo', logoEl.files[0]);
            }

            $btn.prop('disabled', true).text('Generazione in corso...');
            $error.hide();
            $('#dbqr-result-frontend').hide();

            $.ajax({
                url: dbqr_front.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (r) {
                    if (r.success) {
                        $('#dbqr-preview-frontend').html(
                            '<img src="' + r.data.url + '" alt="QR Code">'
                        );
                        $('#dbqr-download-frontend').attr('href', r.data.url);
                        $('#dbqr-shortcode-frontend').text(
                            '[dbqr_code url="' + $('#dbqr-url-frontend').val() + '"]'
                        );
                        $('#dbqr-result-frontend').slideDown(function () {
                            $(this).trigger('focus');
                        });
                    } else {
                        $error.text(r.data).slideDown(function () {
                            $(this).trigger('focus');
                        });
                    }
                },
                error: function () {
                    $error.text('Errore di connessione. Riprova.').slideDown(function () {
                        $(this).trigger('focus');
                    });
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Genera QR Code');
                }
            });
        });
    });
})(jQuery);
