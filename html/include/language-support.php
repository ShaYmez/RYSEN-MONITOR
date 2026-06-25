<?php
/**
 * Dashboard language support (updated on upgrade)
 * Canonical language list: include/languages.php
 *
 * Priority order for init_session_language():
 * 1. Menu system preference (/etc/rysen/.systemx_lang)
 * 2. PHP session variable
 * 3. Browser detection (Accept-Language)
 * 4. Default to English
 */

require_once __DIR__ . '/languages.php';

/**
 * Supported dashboard language codes from include/languages.php
 *
 * @return array
 */
function get_supported_language_codes() {
    global $default_languages;

    return array_keys($default_languages);
}

/**
 * Get system language from menu preference file
 *
 * @return string|null Language code or null if not set
 */
function get_menu_language() {
    $lang_file = '/etc/rysen/.systemx_lang';

    if (file_exists($lang_file) && is_readable($lang_file)) {
        $lang = trim(file_get_contents($lang_file));
        if (!empty($lang)) {
            return map_language_code($lang);
        }
    }

    return null;
}

/**
 * Detect language from browser Accept-Language header
 *
 * @return string Language code detected from browser
 */
function detect_browser_language() {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return 'en';
    }

    $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported = get_supported_language_codes();

    if (in_array($lang, $supported, true)) {
        return $lang;
    }

    return 'en';
}

/**
 * Get current system language with priority handling
 *
 * @return string Language code to use
 */
function get_system_language() {
    $menu_lang = get_menu_language();
    if ($menu_lang !== null) {
        return $menu_lang;
    }

    if (isset($_SESSION['lang']) && !empty($_SESSION['lang'])) {
        return map_language_code($_SESSION['lang']);
    }

    $browser_lang = detect_browser_language();
    if ($browser_lang !== 'en') {
        return $browser_lang;
    }

    return 'en';
}

/**
 * Map language codes (for backwards compatibility)
 *
 * @param string $code Language code
 * @return string Mapped language code
 */
function map_language_code($code) {
    global $default_languages;

    $code = strtolower(trim($code));
    if (strlen($code) > 2) {
        $code = substr($code, 0, 2);
    }

    return isset($default_languages[$code]) ? $code : 'en';
}

/**
 * Initialize session language
 */
function init_session_language() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['lang']) || empty($_SESSION['lang'])) {
        $_SESSION['lang'] = get_system_language();
    }
}

?>
