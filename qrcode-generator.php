<?php
/**
 * Plugin Name: QR Code Generator con Logo
 * Plugin URI:  https://example.com
 * Description: Genera QR code personalizzati con logo centrale
 * Version:     1.1.0
 * Author:      Il Tuo Nome
 * License:     GPL2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Impedisci accesso diretto
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Verifica versione PHP ───────────────────────────────────────────────────
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>QR Code Generator</strong>: richiede PHP 7.4 o superiore. '
           . 'Versione attuale: ' . PHP_VERSION
           . '</p></div>';
    } );
    return;
}

// ─── Autoload Composer ───────────────────────────────────────────────────────
// Caricato subito, a livello di file, prima che PHP analizzi i "use" della classe.
$autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>QR Code Generator</strong>: dipendenze Composer mancanti. '
           . 'Esegui <code>composer install</code> nella cartella del plugin.'
           . '</p></div>';
    } );
    return;
}
require_once $autoload;

// ─── Namespace imports ───────────────────────────────────────────────────────
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Writer\PngWriter;
use claviska\SimpleImage;

// ─── Costanti ────────────────────────────────────────────────────────────────
define( 'QRCODE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QRCODE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QRCODE_VERSION',    '1.1.0' );

// ─── Hook attivazione / disattivazione ───────────────────────────────────────
register_activation_hook( __FILE__, array( 'QRCode_Generator_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'QRCode_Generator_Plugin', 'on_deactivate' ) );

// ─── Classe principale ───────────────────────────────────────────────────────
class QRCode_Generator_Plugin {

    /** MIME type ammessi per il logo */
    private const ALLOWED_MIME = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

    /** Numero massimo di richieste AJAX per IP/sessione in 60 secondi */
    private const RATE_LIMIT = 10;

    public function __construct() {
        add_action( 'admin_menu',       array( $this, 'add_admin_menu' ) );
        add_shortcode( 'qrcode',            array( $this, 'qrcode_shortcode' ) );
        add_shortcode( 'qrcode_generator',  array( $this, 'qrcode_generator_shortcode' ) );
        add_action( 'wp_ajax_generate_qrcode',        array( $this, 'ajax_generate_qrcode' ) );
        add_action( 'wp_ajax_nopriv_generate_qrcode', array( $this, 'ajax_generate_qrcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Pulizia notturna dei QR code vecchi (> 30 giorni)
        add_action( 'qrcode_cleanup_event', array( $this, 'cleanup_old_files' ) );
    }

    // ── Attivazione ──────────────────────────────────────────────────────────

    public static function on_activate() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'QR Code Generator richiede PHP 7.4 o superiore.' );
        }
        if ( ! file_exists( QRCODE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Esegui "composer install" prima di attivare il plugin.' );
        }
        // Crea cartella upload
        $upload = wp_upload_dir();
        wp_mkdir_p( $upload['basedir'] . '/qrcodes' );

        // Pianifica pulizia se non già schedulata
        if ( ! wp_next_scheduled( 'qrcode_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'qrcode_cleanup_event' );
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook( 'qrcode_cleanup_event' );
    }

    // ── Admin menu ───────────────────────────────────────────────────────────

    public function add_admin_menu() {
        add_menu_page(
            'QR Code Generator',
            'QR Code',
            'manage_options',
            'qrcode-generator',
            array( $this, 'admin_page' ),
            'dashicons-grid-view',
            30
        );
    }

    // ── Frontend assets ──────────────────────────────────────────────────────

    public function enqueue_frontend_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }
        if ( ! has_shortcode( $post->post_content, 'qrcode_generator' ) ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_localize_script( 'jquery', 'qrcode_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            // FIX: nonce dedicato per il frontend
            'nonce'    => wp_create_nonce( 'qrcode_frontend' ),
        ) );

        wp_add_inline_style( 'wp-block-library', $this->frontend_css() );
    }

    private function frontend_css() {
        return '
            .qrcode-generator-form{max-width:600px;margin:0 auto;padding:20px;background:#f9f9f9;border-radius:8px}
            .qrcode-form-group{margin-bottom:20px}
            .qrcode-form-group label{display:block;margin-bottom:8px;font-weight:bold}
            .qrcode-form-group input[type="text"],.qrcode-form-group input[type="number"]{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box}
            .qrcode-form-group input[type="file"]{width:100%}
            .qrcode-description{font-size:.9em;color:#666;margin-top:5px}
            .qrcode-submit-btn{background:#0073aa;color:#fff;padding:12px 30px;border:none;border-radius:4px;cursor:pointer;font-size:16px}
            .qrcode-submit-btn:hover{background:#005177}
            .qrcode-submit-btn:disabled{background:#ccc;cursor:not-allowed}
            .qrcode-result{margin-top:30px;padding:20px;background:#fff;border-radius:8px;text-align:center}
            .qrcode-result img{max-width:100%;height:auto;margin:20px 0}
            .qrcode-download-btn{display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;margin-top:10px}
            .qrcode-download-btn:hover{background:#005177}
            .qrcode-error{color:red;padding:15px;background:#ffebee;border-radius:4px;margin-top:20px}
            .qrcode-shortcode-box{background:#f0f0f0;padding:10px;border-radius:4px;margin-top:15px;font-family:monospace}
        ';
    }

    // ── Pagina admin ─────────────────────────────────────────────────────────

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Generatore QR Code con Logo</h1>

            <form id="qrcode-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="qr_url">URL/Testo</label></th>
                        <td>
                            <input type="text" id="qr_url" name="qr_url" class="regular-text"
                                   placeholder="https://example.com" required>
                            <p class="description">Inserisci l'URL o il testo per il QR code</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="qr_logo">Logo</label></th>
                        <td>
                            <input type="file" id="qr_logo" name="qr_logo" accept="image/png,image/jpeg,image/gif,image/webp">
                            <p class="description">PNG, JPG, GIF o WebP — max 2 MB (opzionale)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="qr_size">Dimensione (px)</label></th>
                        <td>
                            <input type="number" id="qr_size" name="qr_size" value="300" min="100" max="1000">
                            <p class="description">100 – 1000 pixel</p>
                        </td>
                    </tr>
                </table>

                <?php wp_nonce_field( 'qrcode_generate', 'qrcode_nonce' ); ?>

                <p class="submit">
                    <button type="button" id="generate-btn" class="button button-primary">Genera QR Code</button>
                </p>
            </form>

            <div id="qrcode-result" style="margin-top:30px;display:none">
                <h2>QR Code Generato</h2>
                <div id="qrcode-preview"></div>
                <p><a id="qrcode-download" class="button" download="qrcode.png">Scarica QR Code</a></p>
                <p><strong>Shortcode:</strong> <code id="qrcode-shortcode"></code></p>
            </div>
            <div id="qrcode-error" style="display:none;color:red;margin-top:20px"></div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#generate-btn').on('click', function(){
                var formData = new FormData();
                formData.append('action',        'generate_qrcode');
                formData.append('qr_url',        $('#qr_url').val());
                formData.append('qr_size',       $('#qr_size').val());
                formData.append('qrcode_nonce',  $('#qrcode_nonce').val());
                var logo = $('#qr_logo')[0].files[0];
                if (logo) formData.append('qr_logo', logo);

                $(this).prop('disabled', true).text('Generazione in corso...');
                $('#qrcode-error').hide();

                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: formData, processData: false, contentType: false,
                    success: function(r){
                        if(r.success){
                            $('#qrcode-preview').html('<img src="'+r.data.url+'" alt="QR Code">');
                            $('#qrcode-download').attr('href', r.data.url);
                            $('#qrcode-shortcode').text('[qrcode url="'+$('#qr_url').val()+'"]');
                            $('#qrcode-result').show();
                        } else {
                            $('#qrcode-error').text(r.data).show();
                        }
                    },
                    error: function(){ $('#qrcode-error').text('Errore di connessione. Riprova.').show(); },
                    complete: function(){ $('#generate-btn').prop('disabled', false).text('Genera QR Code'); }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX handler ─────────────────────────────────────────────────────────

    public function ajax_generate_qrcode() {
        $is_frontend = isset( $_POST['frontend'] ) && $_POST['frontend'] === '1';

        if ( $is_frontend ) {
            // FIX: il frontend ora usa il proprio nonce, non bypassa la verifica
            if ( ! check_ajax_referer( 'qrcode_frontend', 'qrcode_nonce', false ) ) {
                wp_send_json_error( 'Verifica di sicurezza fallita.' );
            }
            // FIX: rate limiting per richieste pubbliche
            if ( ! $this->check_rate_limit() ) {
                wp_send_json_error( 'Troppe richieste. Attendi un momento.' );
            }
        } else {
            // Richiesta admin: nonce + capability
            if ( ! check_ajax_referer( 'qrcode_generate', 'qrcode_nonce', false ) ) {
                wp_send_json_error( 'Verifica di sicurezza fallita.' );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permessi insufficienti.' );
            }
        }

        $url  = sanitize_text_field( wp_unslash( $_POST['qr_url'] ?? '' ) );
        $size = min( 1000, max( 100, intval( $_POST['qr_size'] ?? 300 ) ) );

        if ( empty( $url ) ) {
            wp_send_json_error( 'URL o testo mancante.' );
        }

        try {
            $result = $this->generate_qrcode( $url, $size );
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Errore nella generazione: ' . esc_html( $e->getMessage() ) );
        }
    }

    // ── Rate limiting (transient per IP) ─────────────────────────────────────

    private function check_rate_limit(): bool {
        $ip  = $this->get_client_ip();
        $key = 'qrcode_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT ) {
            return false;
        }
        set_transient( $key, $count + 1, 60 );
        return true;
    }

    private function get_client_ip(): string {
        foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
            }
        }
        return '0.0.0.0';
    }

    // ── Generazione QR code ──────────────────────────────────────────────────

    private function generate_qrcode( string $url, int $size = 300 ): array {
        $upload_dir  = wp_upload_dir();
        $qrcode_dir  = $upload_dir['basedir'] . '/qrcodes';

        if ( ! file_exists( $qrcode_dir ) ) {
            wp_mkdir_p( $qrcode_dir );
            // Impedisci directory listing
            file_put_contents( $qrcode_dir . '/.htaccess', "Options -Indexes\n" );
        }

        // FIX: caching – se esiste già un QR code per questo URL+size, restituiscilo
        $cache_key  = 'qrcode_' . md5( $url . '_' . $size );
        $cached     = get_transient( $cache_key );
        if ( $cached && file_exists( $cached['path'] ) ) {
            return $cached;
        }

        $builder = Builder::create()
            ->writer( new PngWriter() )
            ->writerOptions( array() )
            ->data( $url )
            ->encoding( new Encoding( 'UTF-8' ) )
            ->errorCorrectionLevel( new ErrorCorrectionLevelHigh() )
            ->size( $size )
            ->margin( 10 );

        // ── Logo (se presente) ────────────────────────────────────────────
        $logo_tmp_path = null;
        if ( isset( $_FILES['qr_logo'] ) && $_FILES['qr_logo']['error'] === UPLOAD_ERR_OK ) {
            $file = $_FILES['qr_logo'];

            // FIX: verifica dimensione (max 2 MB)
            if ( $file['size'] > 2 * 1024 * 1024 ) {
                throw new \RuntimeException( 'Il logo supera il limite di 2 MB.' );
            }

            // FIX: verifica MIME reale (non solo l'estensione dichiarata dal client)
            $finfo     = new finfo( FILEINFO_MIME_TYPE );
            $real_mime = $finfo->file( $file['tmp_name'] );
            if ( ! in_array( $real_mime, self::ALLOWED_MIME, true ) ) {
                throw new \RuntimeException( 'Tipo di file non consentito per il logo.' );
            }

            // FIX: logoPath() vuole un percorso file, non un data URI.
            // Processiamo il logo con SimpleImage e lo salviamo in un file temporaneo.
            $logo_reader = new SimpleImage();
            $logo_reader->fromFile( $file['tmp_name'] )->bestFit( 100, 100 );

            $logo_builder = new SimpleImage();
            $logo_builder
                ->fromNew( 110, 110 )
                ->roundedRectangle( 0, 0, 110, 110, 10, 'white', 'filled' )
                ->overlay( $logo_reader );

            $logo_tmp_path = tempnam( sys_get_temp_dir(), 'qrlogo_' ) . '.png';
            $logo_builder->toFile( $logo_tmp_path, 'image/png' );

            $builder
                ->logoPath( $logo_tmp_path )
                ->logoResizeToWidth( 100 );
        }

        // ── Build e salvataggio ───────────────────────────────────────────
        $filename = 'qrcode_' . md5( $url . '_' . $size ) . '.png';
        $filepath = $qrcode_dir . '/' . $filename;

        $result = $builder->build();
        $result->saveToFile( $filepath );

        // Pulisci il file temporaneo del logo
        if ( $logo_tmp_path && file_exists( $logo_tmp_path ) ) {
            @unlink( $logo_tmp_path );
        }

        $data = array(
            'path'     => $filepath,
            'url'      => $upload_dir['baseurl'] . '/qrcodes/' . $filename,
            'filename' => $filename,
        );

        // Salva in cache per 24 ore
        set_transient( $cache_key, $data, DAY_IN_SECONDS );

        return $data;
    }

    // ── Pulizia file vecchi ──────────────────────────────────────────────────

    public function cleanup_old_files() {
        $upload_dir = wp_upload_dir();
        $qrcode_dir = $upload_dir['basedir'] . '/qrcodes';
        if ( ! is_dir( $qrcode_dir ) ) {
            return;
        }
        $max_age = 30 * DAY_IN_SECONDS;
        foreach ( glob( $qrcode_dir . '/qrcode_*.png' ) as $file ) {
            if ( filemtime( $file ) < time() - $max_age ) {
                @unlink( $file );
            }
        }
    }

    // ── Shortcode [qrcode_generator] ────────────────────────────────────────

    public function qrcode_generator_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'title'     => 'Genera il tuo QR Code',
            'show_logo' => 'yes',
        ), $atts );

        // Nonce per questo specifico shortcode render
        $nonce = wp_create_nonce( 'qrcode_frontend' );

        ob_start();
        ?>
        <div class="qrcode-generator-wrapper">
            <div class="qrcode-generator-form">
                <?php if ( ! empty( $atts['title'] ) ) : ?>
                    <h2><?php echo esc_html( $atts['title'] ); ?></h2>
                <?php endif; ?>

                <form id="qrcode-frontend-form" method="post" enctype="multipart/form-data">
                    <div class="qrcode-form-group">
                        <label for="qr_url_frontend">URL o Testo</label>
                        <input type="text" id="qr_url_frontend" name="qr_url"
                               placeholder="https://esempio.com" required>
                        <p class="qrcode-description">Inserisci l'URL o il testo per il QR code</p>
                    </div>

                    <?php if ( $atts['show_logo'] === 'yes' ) : ?>
                    <div class="qrcode-form-group">
                        <label for="qr_logo_frontend">Logo (opzionale)</label>
                        <input type="file" id="qr_logo_frontend" name="qr_logo"
                               accept="image/png,image/jpeg,image/gif,image/webp">
                        <p class="qrcode-description">PNG, JPG, GIF o WebP — max 2 MB</p>
                    </div>
                    <?php endif; ?>

                    <div class="qrcode-form-group">
                        <label for="qr_size_frontend">Dimensione (pixel)</label>
                        <input type="number" id="qr_size_frontend" name="qr_size"
                               value="300" min="100" max="1000">
                        <p class="qrcode-description">Tra 100 e 1000 pixel</p>
                    </div>

                    <div class="qrcode-form-group">
                        <button type="submit" id="generate-btn-frontend" class="qrcode-submit-btn">
                            Genera QR Code
                        </button>
                    </div>
                </form>

                <div id="qrcode-result-frontend" class="qrcode-result" style="display:none">
                    <h3>Il tuo QR Code è pronto!</h3>
                    <div id="qrcode-preview-frontend"></div>
                    <a id="qrcode-download-frontend" class="qrcode-download-btn" download="qrcode.png">
                        Scarica QR Code
                    </a>
                    <div class="qrcode-shortcode-box">
                        <strong>Shortcode:</strong><br>
                        <code id="qrcode-shortcode-frontend"></code>
                    </div>
                </div>

                <div id="qrcode-error-frontend" class="qrcode-error" style="display:none"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#qrcode-frontend-form').on('submit', function(e){
                e.preventDefault();
                var formData = new FormData();
                formData.append('action',       'generate_qrcode');
                formData.append('qr_url',       $('#qr_url_frontend').val());
                formData.append('qr_size',      $('#qr_size_frontend').val());
                formData.append('frontend',     '1');
                // FIX: nonce dedicato generato server-side per questo shortcode
                formData.append('qrcode_nonce', '<?php echo esc_js( $nonce ); ?>');
                var logo = $('#qr_logo_frontend')[0].files[0];
                if (logo) formData.append('qr_logo', logo);

                $('#generate-btn-frontend').prop('disabled', true).text('Generazione in corso...');
                $('#qrcode-error-frontend').hide();
                $('#qrcode-result-frontend').hide();

                $.ajax({
                    url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                    type: 'POST', data: formData, processData: false, contentType: false,
                    success: function(r){
                        if(r.success){
                            $('#qrcode-preview-frontend').html('<img src="'+r.data.url+'" alt="QR Code">');
                            $('#qrcode-download-frontend').attr('href', r.data.url);
                            $('#qrcode-shortcode-frontend').text('[qrcode url="'+$('#qr_url_frontend').val()+'"]');
                            $('#qrcode-result-frontend').slideDown();
                        } else {
                            $('#qrcode-error-frontend').text(r.data).slideDown();
                        }
                    },
                    error: function(){ $('#qrcode-error-frontend').text('Errore di connessione. Riprova.').slideDown(); },
                    complete: function(){ $('#generate-btn-frontend').prop('disabled', false).text('Genera QR Code'); }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode [qrcode] con caching ───────────────────────────────────────

    public function qrcode_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'url'  => '',
            'size' => 300,
        ), $atts );

        if ( empty( $atts['url'] ) ) {
            return '<p class="qrcode-error">Errore: parametro url mancante.</p>';
        }

        try {
            $qrcode = $this->generate_qrcode(
                esc_url_raw( $atts['url'] ),
                intval( $atts['size'] )
            );
            return '<img src="' . esc_url( $qrcode['url'] ) . '" alt="QR Code" class="qrcode-image" loading="lazy">';
        } catch ( \Exception $e ) {
            // Non esporre dettagli in frontend
            return '<p class="qrcode-error">Errore nella generazione del QR code.</p>';
        }
    }
}

// ── Avvia il plugin ───────────────────────────────────────────────────────────
new QRCode_Generator_Plugin();
