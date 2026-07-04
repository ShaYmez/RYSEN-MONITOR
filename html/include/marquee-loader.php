<?php
/**
 * Load and merge marquee content from upgrade defaults and preserved config.
 *
 * Custom sysop text: set $marquee_lines once in config/marquee.php and optionally
 * $marquee_language (what you wrote it in). When the dashboard language changes,
 * lines are auto-translated and cached under html/cache/marquee/.
 *
 * Legacy per-language $marquee_overrides / $marquee_content still supported.
 */

require_once __DIR__ . '/marquee-defaults.php';
require_once __DIR__ . '/language-support.php';
require_once __DIR__ . '/marquee-translate.php';

$marquee_overrides = array();
$marquee_content = null;
$marquee_lines = array();
$marquee_lines_by_lang = array();
$marquee_language = 'en';
$marquee_auto_translate = true;
$marquee_translate_email = '';

$config_marquee = __DIR__ . '/../config/marquee.php';
if (is_readable($config_marquee)) {
    include $config_marquee;
}

$marquee_source_language = map_language_code($marquee_language);
$marquee_source_lines = array();

foreach ($marquee_lines as $line) {
    if (!is_string($line)) {
        continue;
    }
    $trimmed = trim($line);
    if ($trimmed !== '') {
        $marquee_source_lines[] = $trimmed;
    }
}

if (!is_bool($marquee_auto_translate)) {
    $marquee_auto_translate = true;
}

/**
 * Whether a marquee fragment is already HTML / entity-encoded (upgrade defaults, legacy config).
 *
 * @param string $text
 * @return bool
 */
function marquee_line_is_preformatted($text) {
    return (bool) preg_match('/&(?:nbsp|#\d+|amp|lt|gt|[a-z]+);|<(?:a|img|br)\b/i', $text);
}

/**
 * Prepare one marquee line for output (plain sysop text → escaped; legacy HTML unchanged).
 *
 * @param string $text
 * @return string
 */
function format_marquee_line($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (marquee_line_is_preformatted($text)) {
        return $text;
    }

    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convert a list of plain-text lines to line1, line2, … keys.
 *
 * @param array $lines
 * @return array
 */
function marquee_assoc_from_lines(array $lines) {
    $out = array();
    $index = 1;

    foreach ($lines as $line) {
        if (!is_string($line)) {
            continue;
        }
        $formatted = format_marquee_line($line);
        if ($formatted === '') {
            continue;
        }
        $out['line' . $index] = $formatted;
        $index++;
    }

    return $out;
}

/**
 * Replace all scrolling line keys on a language block.
 *
 * @param array $langBlock
 * @param array $lines
 * @return array
 */
function marquee_replace_lang_lines(array $langBlock, array $lines) {
    foreach (array_keys($langBlock) as $key) {
        if (strpos($key, 'line') === 0) {
            unset($langBlock[$key]);
        }
    }

    return array_merge($langBlock, $lines);
}

/**
 * Resolve plain-text lines for a target language from custom sysop config.
 *
 * @param string $lang
 * @return array|null Null when custom source lines are not configured
 */
function marquee_resolve_custom_lines_for_language($lang) {
    global $marquee_source_lines, $marquee_source_language, $marquee_auto_translate;
    global $marquee_lines_by_lang, $marquee_translate_email;

    if (count($marquee_source_lines) === 0) {
        return null;
    }

    $lang = map_language_code($lang);

    if (isset($marquee_lines_by_lang[$lang]) && is_array($marquee_lines_by_lang[$lang])) {
        $pinned = array();
        foreach ($marquee_lines_by_lang[$lang] as $line) {
            if (is_string($line) && trim($line) !== '') {
                $pinned[] = trim($line);
            }
        }
        if (count($pinned) > 0) {
            return $pinned;
        }
    }

    if (!$marquee_auto_translate || $lang === $marquee_source_language) {
        return $marquee_source_lines;
    }

    return marquee_translate_lines(
        $marquee_source_lines,
        $marquee_source_language,
        $lang,
        $marquee_translate_email
    );
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

    $lang = map_language_code($lang);
    $customLines = marquee_resolve_custom_lines_for_language($lang);

    if ($customLines !== null) {
        return marquee_assoc_from_lines($customLines);
    }

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
    $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';

    return map_language_code($lang);
}

$current_lang = resolve_marquee_language();
$marquee = get_marquee_for_language($current_lang);

?>
