<?php
/**
 * Navbar Custom Items Configuration
 * PROTECTED FILE - Customizations preserved during upgrades
 * 
 * Add custom menu items or modify existing items per organization
 * This file is included by elements/navbar.php
 */

// Define custom navbar items (added after core items)
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
        'icon' => 'fab fa-telegram-plane',
        'url' => 'https://twitter.com/freestarnetwork/',
        'title' => 'Telegram'
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
