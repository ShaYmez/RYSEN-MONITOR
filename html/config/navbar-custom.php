<?php
/**
 * Navbar Custom Items Configuration
 * PROTECTED FILE - Customizations preserved during upgrades
 * 
 * Add custom menu items or modify existing items per organization
 * This file is included by elements/navbar.php
 */

// Network / organisation link in the Info dropdown (item 7 — enabled by default)
$info_network_link = array(
    'url' => 'https://freestar.network/systemx-dmr',
    'id' => 'nav_freestar',  // label from translations.json; use 'label' for fixed text
    'target' => '_self',
);

// Extra Info dropdown links (only shown when at least one entry has label + url)
$info_dropdown_items = array(
    // Example:
    // array(
    //     'label' => 'Network Wiki',
    //     'url' => 'https://wiki.example.com',
    //     'target' => '_blank',
    //     'id' => 'nav_wiki',  // optional — add matching key in translations.json for i18n
    // ),
);

// Define custom top-level navbar items (added after the Info dropdown)
$custom_navbar_items = array(
    // Example: Add a custom link
    // array(
    //     'label' => 'My Custom Page',
    //     'url' => 'index.php?p=custom',
    //     'icon' => 'fa-star',  // optional
    //     'target' => '_self'   // optional
    // ),
);

// Social media links (customize per organization)
$social_links = array(
    array(
        'icon' => 'fas fa-fire-extinguisher',
        'url' => 'https://hosepipe.freestar.network',
        'title' => 'HosePipe.'
    ),
    array(
        'icon' => 'fab fa-discord',
        'url' => 'https://discord.com/invite/TD5tKyqFPR',
        'title' => 'Discord'
    ),
    array(
        'icon' => 'fab fa-facebook',
        'url' => 'https://www.facebook.com/groups/5075067229200822/',
        'title' => 'Facebook'
    ),
);

// Available languages (customize labels/order if needed)
// New languages from upgrades are merged from include/languages.php automatically
$available_languages = array(
    'en' => 'EN',
    'es' => 'ES',
    'pt' => 'PT',
    'fr' => 'FR',
    'it' => 'IT',
    'nl' => 'NL',
    'de' => 'DE',
    'ru' => 'RU',
    'ja' => 'JA',
    'ar' => 'AR',
    'zh' => 'ZH',
    'el' => 'EL',
);

?>
