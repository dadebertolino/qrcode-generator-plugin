<?php
/**
 * Plugin Name: QR Code Generator con Logo
 * Plugin URI:  https://www.davidebertolino.it/progetti/qrcode-generator/
 * Description: Genera QR code personalizzati con logo centrale
 * Version:     1.2.0
 * Author:      Davide Bertolino
 * Author URI:  https://www.davidebertolino.it
 * License:     GPL2
 * Text Domain: qrcode-generator
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
$dbqr_autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( ! file_exists( $dbqr_autoload ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>QR Code Generator</strong>: dipendenze Composer mancanti. '
           . 'Esegui <code>composer install</code> nella cartella del plugin.'
           . '</p></div>';
    } );
    return;
}
require_once $dbqr_autoload;

// ─── Namespace imports ───────────────────────────────────────────────────────
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Writer\PngWriter;
use claviska\SimpleImage;

// ─── Costanti ────────────────────────────────────────────────────────────────
define( 'DBQR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBQR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DBQR_VERSION',    '1.2.0' );

// ─── Hook attivazione / disattivazione ───────────────────────────────────────
register_activation_hook( __FILE__, array( 'DBQR_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'DBQR_Plugin', 'on_deactivate' ) );

// ─── Classe principale ───────────────────────────────────────────────────────
class DBQR_Plugin {

    /** MIME type ammessi per il logo */
    private const ALLOWED_MIME = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

    /** Numero massimo di richieste AJAX per IP/sessione in 60 secondi */
    private const RATE_LIMIT = 10;

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_shortcode( 'dbqr_code',          array( $this, 'dbqr_code_shortcode' ) );
        add_shortcode( 'dbqr_generator',     array( $this, 'dbqr_generator_shortcode' ) );
        // Retrocompatibilità shortcode vecchi
        add_shortcode( 'qrcode',             array( $this, 'dbqr_code_shortcode' ) );
        add_shortcode( 'qrcode_generator',   array( $this, 'dbqr_generator_shortcode' ) );
        add_action( 'wp_ajax_dbqr_generate',        array( $this, 'ajax_generate' ) );
        add_action( 'wp_ajax_nopriv_dbqr_generate',  array( $this, 'ajax_generate' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend_assets' ) );

        // Pulizia notturna dei QR code vecchi (> 30 giorni)
        add_action( 'dbqr_cleanup_event', array( $this, 'cleanup_old_files' ) );
    }

    // ── Attivazione ──────────────────────────────────────────────────────────

    public static function on_activate() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( 'QR Code Generator richiede PHP 7.4 o superiore.', 'qrcode-generator' ) );
        }
        if ( ! file_exists( DBQR_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( 'Esegui "composer install" prima di attivare il plugin.', 'qrcode-generator' ) );
        }
        // Crea cartella upload
        $upload = wp_upload_dir();
        wp_mkdir_p( $upload['basedir'] . '/qrcodes' );

        // Pianifica pulizia se non già schedulata
        if ( ! wp_next_scheduled( 'dbqr_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'dbqr_cleanup_event' );
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook( 'dbqr_cleanup_event' );
    }

    // ── Admin menu ───────────────────────────────────────────────────────────

    public function add_admin_menu() {
        add_menu_page(
            __( 'QR Code Generator', 'qrcode-generator' ),
            __( 'QR Code', 'qrcode-generator' ),
            'manage_options',
            'dbqr-generator',
            array( $this, 'admin_page' ),
            'dashicons-grid-view',
            30
        );
    }

    // ── Admin assets ─────────────────────────────────────────────────────────

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'dbqr-generator' ) === false ) {
            return;
        }
        wp_enqueue_script(
            'dbqr-admin',
            DBQR_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            DBQR_VERSION,
            true
        );
        wp_localize_script( 'dbqr-admin', 'dbqr_admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dbqr_admin' ),
        ) );
    }

    // ── Frontend assets ──────────────────────────────────────────────────────

    public function enqueue_frontend_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }
        if ( ! has_shortcode( $post->post_content, 'dbqr_generator' )
          && ! has_shortcode( $post->post_content, 'qrcode_generator' ) ) {
            return;
        }

        wp_enqueue_style(
            'dbqr-frontend',
            DBQR_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            DBQR_VERSION
        );

        wp_enqueue_script(
            'dbqr-frontend',
            DBQR_PLUGIN_URL . 'assets/js/frontend.js',
            array( 'jquery' ),
            DBQR_VERSION,
            true
        );
        wp_localize_script( 'dbqr-frontend', 'dbqr_front', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dbqr_frontend' ),
        ) );
    }

    // ── Pagina admin ─────────────────────────────────────────────────────────

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Generatore QR Code con Logo', 'qrcode-generator' ); ?></h1>

            <form id="dbqr-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dbqr-url"><?php esc_html_e( 'URL/Testo', 'qrcode-generator' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="dbqr-url" name="qr_url" class="regular-text"
                                   placeholder="https://example.com"
                                   required
                                   aria-required="true"
                                   aria-describedby="dbqr-url-desc">
                            <p class="description" id="dbqr-url-desc">
                                <?php esc_html_e( 'Inserisci l\'URL o il testo per il QR code', 'qrcode-generator' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dbqr-logo"><?php esc_html_e( 'Logo', 'qrcode-generator' ); ?></label>
                        </th>
                        <td>
                            <input type="file" id="dbqr-logo" name="qr_logo"
                                   accept="image/png,image/jpeg,image/gif,image/webp"
                                   aria-describedby="dbqr-logo-desc">
                            <p class="description" id="dbqr-logo-desc">
                                <?php esc_html_e( 'PNG, JPG, GIF o WebP — max 2 MB (opzionale)', 'qrcode-generator' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dbqr-size"><?php esc_html_e( 'Dimensione (px)', 'qrcode-generator' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="dbqr-size" name="qr_size" value="300"
                                   min="100" max="1000"
                                   aria-describedby="dbqr-size-desc">
                            <p class="description" id="dbqr-size-desc">
                                <?php esc_html_e( '100 – 1000 pixel', 'qrcode-generator' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php wp_nonce_field( 'dbqr_admin', 'dbqr_nonce' ); ?>

                <p class="submit">
                    <button type="button" id="dbqr-generate-btn" class="button button-primary">
                        <?php esc_html_e( 'Genera QR Code', 'qrcode-generator' ); ?>
                    </button>
                </p>
            </form>

            <div id="dbqr-result" style="margin-top:30px;display:none" role="region"
                 aria-live="polite" aria-label="<?php esc_attr_e( 'Risultato QR Code', 'qrcode-generator' ); ?>">
                <h2><?php esc_html_e( 'QR Code Generato', 'qrcode-generator' ); ?></h2>
                <div id="dbqr-preview"></div>
                <p><a id="dbqr-download" class="button" download="qrcode.png">
                    <?php esc_html_e( 'Scarica QR Code', 'qrcode-generator' ); ?>
                </a></p>
                <p><strong><?php esc_html_e( 'Shortcode:', 'qrcode-generator' ); ?></strong>
                    <code id="dbqr-shortcode"></code>
                </p>
            </div>
            <div id="dbqr-error" style="display:none;color:red;margin-top:20px" role="alert" aria-live="assertive"></div>
        </div>
        <?php
    }

    // ── AJAX handler ─────────────────────────────────────────────────────────

    public function ajax_generate() {
        $is_frontend = isset( $_POST['frontend'] ) && $_POST['frontend'] === '1';

        if ( $is_frontend ) {
            if ( ! check_ajax_referer( 'dbqr_frontend', 'dbqr_nonce', false ) ) {
                wp_send_json_error( __( 'Verifica di sicurezza fallita.', 'qrcode-generator' ) );
            }
            if ( ! $this->check_rate_limit() ) {
                wp_send_json_error( __( 'Troppe richieste. Attendi un momento.', 'qrcode-generator' ) );
            }
        } else {
            if ( ! check_ajax_referer( 'dbqr_admin', 'dbqr_nonce', false ) ) {
                wp_send_json_error( __( 'Verifica di sicurezza fallita.', 'qrcode-generator' ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( __( 'Permessi insufficienti.', 'qrcode-generator' ) );
            }
        }

        $url  = sanitize_text_field( wp_unslash( $_POST['qr_url'] ?? '' ) );
        $size = min( 1000, max( 100, intval( $_POST['qr_size'] ?? 300 ) ) );

        if ( empty( $url ) ) {
            wp_send_json_error( __( 'URL o testo mancante.', 'qrcode-generator' ) );
        }

        try {
            $result = $this->generate_qrcode( $url, $size );
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            wp_send_json_error(
                __( 'Errore nella generazione: ', 'qrcode-generator' ) . esc_html( $e->getMessage() )
            );
        }
    }

    // ── Rate limiting (transient per IP) ─────────────────────────────────────

    private function check_rate_limit(): bool {
        $ip  = $this->get_client_ip();
        $key = 'dbqr_rl_' . md5( $ip );
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
            file_put_contents( $qrcode_dir . '/.htaccess', "Options -Indexes\n" );
        }

        // Caching — se esiste già un QR code per questo URL+size, restituiscilo
        $cache_key  = 'dbqr_cache_' . md5( $url . '_' . $size );
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

            if ( $file['size'] > 2 * 1024 * 1024 ) {
                throw new \RuntimeException(
                    __( 'Il logo supera il limite di 2 MB.', 'qrcode-generator' )
                );
            }

            $finfo     = new finfo( FILEINFO_MIME_TYPE );
            $real_mime = $finfo->file( $file['tmp_name'] );
            if ( ! in_array( $real_mime, self::ALLOWED_MIME, true ) ) {
                throw new \RuntimeException(
                    __( 'Tipo di file non consentito per il logo.', 'qrcode-generator' )
                );
            }

            $logo_reader = new SimpleImage();
            $logo_reader->fromFile( $file['tmp_name'] )->bestFit( 100, 100 );

            $logo_builder = new SimpleImage();
            $logo_builder
                ->fromNew( 110, 110 )
                ->roundedRectangle( 0, 0, 110, 110, 10, 'white', 'filled' )
                ->overlay( $logo_reader );

            $logo_tmp_path = tempnam( sys_get_temp_dir(), 'dbqr_logo_' ) . '.png';
            $logo_builder->toFile( $logo_tmp_path, 'image/png' );

            $builder
                ->logoPath( $logo_tmp_path )
                ->logoResizeToWidth( 100 );
        }

        // ── Build e salvataggio ───────────────────────────────────────────
        $filename = 'dbqr_' . md5( $url . '_' . $size ) . '.png';
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
        foreach ( glob( $qrcode_dir . '/dbqr_*.png' ) as $file ) {
            if ( filemtime( $file ) < time() - $max_age ) {
                @unlink( $file );
            }
        }
    }

    // ── Shortcode [dbqr_generator] ──────────────────────────────────────────

    public function dbqr_generator_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'title'     => __( 'Genera il tuo QR Code', 'qrcode-generator' ),
            'show_logo' => 'yes',
        ), $atts );

        $nonce = wp_create_nonce( 'dbqr_frontend' );

        ob_start();
        ?>
        <div class="dbqr-wrapper">
            <div class="dbqr-form">
                <?php if ( ! empty( $atts['title'] ) ) : ?>
                    <h2><?php echo esc_html( $atts['title'] ); ?></h2>
                <?php endif; ?>

                <div id="dbqr-frontend-form">
                    <div class="dbqr-form-group">
                        <label for="dbqr-url-frontend">
                            <?php esc_html_e( 'URL o Testo', 'qrcode-generator' ); ?>
                        </label>
                        <input type="text" id="dbqr-url-frontend" name="qr_url"
                               placeholder="https://esempio.com"
                               required
                               aria-required="true"
                               aria-describedby="dbqr-url-frontend-desc">
                        <p class="dbqr-description" id="dbqr-url-frontend-desc">
                            <?php esc_html_e( 'Inserisci l\'URL o il testo per il QR code', 'qrcode-generator' ); ?>
                        </p>
                    </div>

                    <?php if ( $atts['show_logo'] === 'yes' ) : ?>
                    <div class="dbqr-form-group">
                        <label for="dbqr-logo-frontend">
                            <?php esc_html_e( 'Logo (opzionale)', 'qrcode-generator' ); ?>
                        </label>
                        <input type="file" id="dbqr-logo-frontend" name="qr_logo"
                               accept="image/png,image/jpeg,image/gif,image/webp"
                               aria-describedby="dbqr-logo-frontend-desc">
                        <p class="dbqr-description" id="dbqr-logo-frontend-desc">
                            <?php esc_html_e( 'PNG, JPG, GIF o WebP — max 2 MB', 'qrcode-generator' ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="dbqr-form-group">
                        <label for="dbqr-size-frontend">
                            <?php esc_html_e( 'Dimensione (pixel)', 'qrcode-generator' ); ?>
                        </label>
                        <input type="number" id="dbqr-size-frontend" name="qr_size"
                               value="300" min="100" max="1000"
                               aria-describedby="dbqr-size-frontend-desc">
                        <p class="dbqr-description" id="dbqr-size-frontend-desc">
                            <?php esc_html_e( 'Tra 100 e 1000 pixel', 'qrcode-generator' ); ?>
                        </p>
                    </div>

                    <div class="dbqr-form-group">
                        <button type="button" id="dbqr-generate-btn-frontend" class="dbqr-submit-btn">
                            <?php esc_html_e( 'Genera QR Code', 'qrcode-generator' ); ?>
                        </button>
                    </div>
                </div>

                <div id="dbqr-result-frontend" class="dbqr-result" style="display:none"
                     role="region" aria-live="polite"
                     aria-label="<?php esc_attr_e( 'Risultato QR Code', 'qrcode-generator' ); ?>">
                    <h3><?php esc_html_e( 'Il tuo QR Code è pronto!', 'qrcode-generator' ); ?></h3>
                    <div id="dbqr-preview-frontend"></div>
                    <a id="dbqr-download-frontend" class="dbqr-download-btn" download="qrcode.png">
                        <?php esc_html_e( 'Scarica QR Code', 'qrcode-generator' ); ?>
                    </a>
                    <div class="dbqr-shortcode-box">
                        <strong><?php esc_html_e( 'Shortcode:', 'qrcode-generator' ); ?></strong><br>
                        <code id="dbqr-shortcode-frontend"></code>
                    </div>
                </div>

                <div id="dbqr-error-frontend" class="dbqr-error" style="display:none"
                     role="alert" aria-live="assertive"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode [dbqr_code] con caching ────────────────────────────────────

    public function dbqr_code_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'url'  => '',
            'size' => 300,
        ), $atts );

        if ( empty( $atts['url'] ) ) {
            return '<p class="dbqr-error">'
                 . esc_html__( 'Errore: parametro url mancante.', 'qrcode-generator' )
                 . '</p>';
        }

        try {
            $qrcode = $this->generate_qrcode(
                esc_url_raw( $atts['url'] ),
                intval( $atts['size'] )
            );
            return '<img src="' . esc_url( $qrcode['url'] ) . '" alt="QR Code" class="dbqr-image" loading="lazy">';
        } catch ( \Exception $e ) {
            return '<p class="dbqr-error">'
                 . esc_html__( 'Errore nella generazione del QR code.', 'qrcode-generator' )
                 . '</p>';
        }
    }
}

// ── Avvia il plugin ───────────────────────────────────────────────────────────
new DBQR_Plugin();
