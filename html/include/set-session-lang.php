<?php
/**
 * Set Session Language API (updated on upgrade)
 * Updates PHP session language for marquee display.
 *
 * NOTE: User-facing only — does NOT update /etc/rysen/.systemx_lang
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/language-support.php';
require_once __DIR__ . '/marquee-loader.php';
require_once __DIR__ . '/../config/footer.php';
require_once __DIR__ . '/copyright.php';
require_once __DIR__ . '/version.php';

$valid_languages = get_supported_language_codes();

/**
 * Build JSON payload for marquee content
 *
 * @param string $lang Language code
 * @return array
 */
function build_language_response($lang) {
    $lines = array_values(get_marquee_for_language($lang));
    $footer = 'DMR Server Software RYSEN Master+ ' . VERSION . '. System X Server &copy; ' . FOOTER_COPYRIGHT_YEAR . '. ' . SYSTEMX_COPYRIGHT_LINE . '. All Rights Reserved.';

    return array(
        'status' => 'success',
        'lang' => $lang,
        'lines' => $lines,
        'footer' => $footer,
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lang = isset($_GET['lang']) ? trim($_GET['lang']) : (isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en');
    $lang = map_language_code($lang);

    if (!in_array($lang, $valid_languages, true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Invalid language code'));
        exit;
    }

    http_response_code(200);
    echo json_encode(build_language_response($lang));
    exit;
}

if (isset($_POST['lang']) && !empty($_POST['lang'])) {
    $lang = map_language_code(trim($_POST['lang']));

    if (in_array($lang, $valid_languages, true)) {
        $_SESSION['lang'] = $lang;
        http_response_code(200);
        echo json_encode(build_language_response($lang));
    } else {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Invalid language code'));
    }
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'message' => 'Missing language parameter'));
