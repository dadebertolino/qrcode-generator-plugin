/**
 * QR Code Generator — Admin JS
 * Version: 1.2.0
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $btn   = $('#dbqr-generate-btn');
        var $error = $('#dbqr-error');

        $btn.on('click', function () {
            var formData = new FormData();
            formData.append('action',     'dbqr_generate');
            formData.append('qr_url',     $('#dbqr-url').val());
            formData.append('qr_size',    $('#dbqr-size').val());
            formData.append('dbqr_nonce', dbqr_admin.nonce);

            var logo = $('#dbqr-logo')[0].files[0];
            if (logo) {
                formData.append('qr_logo', logo);
            }

            $btn.prop('disabled', true).text('Generazione in corso...');
            $error.hide();

            $.ajax({
                url: dbqr_admin.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (r) {
                    if (r.success) {
                        $('#dbqr-preview').html(
                            '<img src="' + r.data.url + '" alt="QR Code">'
                        );
                        $('#dbqr-download').attr('href', r.data.url);
                        $('#dbqr-shortcode').text(
                            '[dbqr_code url="' + $('#dbqr-url').val() + '"]'
                        );
                        $('#dbqr-result').show();
                        // Focus management: sposta il focus sul risultato
                        $('#dbqr-result').trigger('focus');
                    } else {
                        $error.text(r.data).show();
                        $error.trigger('focus');
                    }
                },
                error: function () {
                    $error.text('Errore di connessione. Riprova.').show();
                    $error.trigger('focus');
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Genera QR Code');
                }
            });
        });
    });
})(jQuery);
