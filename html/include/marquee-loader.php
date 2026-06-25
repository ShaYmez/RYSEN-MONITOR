<?php
/**
 * Load and merge marquee content from upgrade defaults and preserved config.
 *
 * Priority:
 * 1. include/marquee-defaults.php (updated on upgrade)
 * 2. config/marquee.php overrides (preserved; custom text wins for matching keys)
 * 3. New languages in defaults are seeded automatically when missing from config
 */

require_once __DIR__ . '/marquee-defaults.php';

$marquee_overrides = array();
$marquee_content = null;

$config_marquee = __DIR__ . '/../config/marquee.php';
if (is_readable($config_marquee)) {
    include $config_marquee;
}

if (is_array($marquee_content) && count($marquee_content) > 0) {
    $marquee_content = array_merge($default_marquee_content, $marquee_content);
} else {
    $marquee_content = array_merge($default_marquee_content, $marquee_overrides);
}

/**
 * Return marquee line content for a language code
 *
 * @param string $lang Two-letter language code
 * @return array Marquee lines keyed line1, line2, ...
 */
function get_marquee_for_language($lang) {
    global $marquee_content;

    if (!isset($marquee_content[$lang])) {
        $lang = 'en';
    }

    return $marquee_content[$lang];
}

/**
 * Resolve active marquee language from session
 *
 * @return string Two-letter language code
 */
function resolve_marquee_language() {
    global $marquee_content;

    $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';
    if (!isset($marquee_content[$lang])) {
        $lang = 'en';
    }

    return $lang;
}

$current_lang = resolve_marquee_language();
$marquee = get_marquee_for_language($current_lang);

?>
