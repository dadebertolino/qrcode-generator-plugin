<?php
/**
 * Smoke test senza WordPress: carica il plugin con funzioni WP minime simulate e
 * genera due QR code reali (senza e con logo), verificando che siano PNG validi.
 *
 * Uso: php tests/smoke-test.php   (dopo composer install)
 */

error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) {
    // Gli avvisi di deprecazione delle librerie vengono mostrati ma non fanno fallire il test.
    $tipo = in_array($no, array(E_DEPRECATED, E_USER_DEPRECATED), true) ? 'DEPRECATO' : 'ERRORE';
    fwrite(STDERR, "$tipo: $msg ($file:$line)\n");
    if ($tipo === 'ERRORE') {
        exit(1);
    }
    return true;
});

$tmp = sys_get_temp_dir() . '/dbqr-smoke-' . getmypid();
@mkdir($tmp, 0777, true);

define('ABSPATH', $tmp . '/');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['dbqr_transients'] = array();
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/qrcode-generator/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function __($s) { return $s; }
function esc_html__($s) { return $s; }
function wp_upload_dir() { global $tmp; return array('basedir' => $tmp, 'baseurl' => 'https://example.test/uploads'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function get_transient($k) { return $GLOBALS['dbqr_transients'][$k] ?? false; }
function set_transient($k, $v) { $GLOBALS['dbqr_transients'][$k] = $v; return true; }

require dirname(__DIR__) . '/qrcode-generator.php';

$plugin   = new DBQR_Plugin();
$generate = new ReflectionMethod($plugin, 'generate_qrcode');
if (PHP_VERSION_ID < 80100) {
    $generate->setAccessible(true);
}

function check_png($path, $label) {
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        fwrite(STDERR, "FALLITO: $label non è un PNG valido ($path)\n");
        exit(1);
    }
    echo "ok  $label: {$info[0]}x{$info[1]} px, " . filesize($path) . " byte\n";
}

// 1) Senza logo
$r = $generate->invoke($plugin, 'https://www.example.org/pagina', 300);
check_png($r['path'], 'QR senza logo');

// 2) Con logo (PNG generato al volo)
$logo = $tmp . '/logo.png';
$img  = imagecreatetruecolor(200, 120);
imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
imagepng($img, $logo);
$_FILES['qr_logo'] = array('name' => 'logo.png', 'tmp_name' => $logo, 'size' => filesize($logo), 'error' => UPLOAD_ERR_OK);
$r = $generate->invoke($plugin, 'https://www.example.org/con-logo', 400);
check_png($r['path'], 'QR con logo');

echo 'PHP ' . PHP_VERSION . ": smoke test superato\n";
