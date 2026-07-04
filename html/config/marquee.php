<?php
/**
 * Dashboard Marquee Configuration
 * PROTECTED FILE - Customizations preserved during upgrades
 *
 * Write your scrolling text once in $marquee_lines (normal spaces, no &nbsp;).
 * Set $marquee_language to the language you wrote it in (en, el, es, …).
 * When users change the dashboard language, the marquee is auto-translated
 * and cached under html/cache/marquee/ — no manual copies per language.
 *
 * Optional $marquee_translate_email — your email for a higher MyMemory API quota.
 * Set $marquee_auto_translate = false to show the same text in every language.
 *
 * Legacy $marquee_overrides / $marquee_content remain supported for manual HTML.
 */

$marquee_language = 'en';

$marquee_lines = array(
    // 'Welcome to My Network System X Master. Connect using "MyNetwork" in DMR Master.',
    // 'This server is maintained by our team. Hosted in London, UK.',
);

// $marquee_translate_email = 'sysop@example.com';
// $marquee_auto_translate = true;

$marquee_lines_by_lang = array();

$marquee_overrides = array();

?>
