<?php
if (!function_exists('rysen_ui_mode_class')) {
    function rysen_ui_mode_class() {
        $mode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';
        if ($mode === 'light' || $mode === 'light-mode') {
            return 'light-mode';
        }
        return 'dark-mode';
    }
}

if (!function_exists('rysen_mode_query')) {
    function rysen_mode_query() {
        $mode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';
        if (in_array($mode, array('light', 'light-mode', 'dark', 'dark-mode'), true)) {
            return '?mode=' . rawurlencode($mode);
        }
        return '';
    }
}
