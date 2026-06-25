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
require_once $dashboard . '/include/marquee-defaults.php';

$custom_lang_keys = array();
$marquee_overrides = array();
$marquee_content = null;
$config_file = $dashboard . '/config/marquee.php';

if (is_readable($config_file)) {
    include $config_file;
    if (is_array($marquee_content) && count($marquee_content) > 0) {
        $custom_lang_keys = array_keys($marquee_content);
    } elseif (count($marquee_overrides) > 0) {
        $custom_lang_keys = array_keys($marquee_overrides);
    }
}

$supported = array_keys($default_languages);
$missing_defaults = array();
$missing_marquee = array();

foreach ($supported as $code) {
    if (!isset($default_marquee_content[$code])) {
        $missing_defaults[] = $code;
    }
}

if (is_array($marquee_content) && count($marquee_content) > 0) {
    $merged = array_merge($default_marquee_content, $marquee_content);
} else {
    $merged = array_merge($default_marquee_content, $marquee_overrides);
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
