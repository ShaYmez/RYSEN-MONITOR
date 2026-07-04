#!/usr/bin/env php
<?php
/**
 * Report dashboard language pack merge after upgrade.
 *
 * Usage: php seed-language-pack.php /var/www/html/dashboard
 *
 * Verifies every language in include/languages.php has marquee defaults.
 * Reports languages supplied from upgrade defaults when absent from preserved config.
 */

$dashboard = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
if ($dashboard === '' || !is_dir($dashboard)) {
    fwrite(STDERR, "Usage: php seed-language-pack.php /var/www/html/dashboard\n");
    exit(1);
}

require_once $dashboard . '/include/languages.php';
require_once $dashboard . '/include/marquee-loader.php';

$supported = array_keys($default_languages);
$merged = $marquee_content;
$missing_defaults = array();
$missing_marquee = array();

$config_file = $dashboard . '/config/marquee.php';
$custom_lang_keys = array();
$config_marquee_lines = array();
$config_marquee_lines_by_lang = array();
$config_marquee_overrides = array();
$config_marquee_content = null;

if (is_readable($config_file)) {
    include $config_file;
    $config_marquee_lines = isset($marquee_lines) ? $marquee_lines : array();
    $config_marquee_lines_by_lang = isset($marquee_lines_by_lang) ? $marquee_lines_by_lang : array();
    $config_marquee_overrides = isset($marquee_overrides) ? $marquee_overrides : array();
    $config_marquee_content = isset($marquee_content) ? $marquee_content : null;

    if (is_array($config_marquee_lines) && count($config_marquee_lines) > 0) {
        $custom_lang_keys = $supported;
    } elseif (is_array($config_marquee_lines_by_lang) && count($config_marquee_lines_by_lang) > 0) {
        $custom_lang_keys = array_keys($config_marquee_lines_by_lang);
    } elseif (is_array($config_marquee_content) && count($config_marquee_content) > 0) {
        $custom_lang_keys = array_keys($config_marquee_content);
    } elseif (count($config_marquee_overrides) > 0) {
        $custom_lang_keys = array_keys($config_marquee_overrides);
    }
}

foreach ($supported as $code) {
    if (!isset($default_marquee_content[$code])) {
        $missing_defaults[] = $code;
    }
}

foreach ($supported as $code) {
    if (!isset($merged[$code])) {
        $missing_marquee[] = $code;
    }
}

if (count($missing_defaults) > 0) {
    echo 'MISSING_DEFAULTS=' . implode(',', $missing_defaults) . "\n";
}

if (count($missing_marquee) > 0) {
    echo 'MISSING_MARQUEE=' . implode(',', $missing_marquee) . "\n";
    exit(1);
}

$newly_available = array_values(array_diff($supported, $custom_lang_keys));
if (count($newly_available) > 0) {
    echo 'SEEDED_LANGS=' . implode(',', $newly_available) . "\n";
}

exit(0);
