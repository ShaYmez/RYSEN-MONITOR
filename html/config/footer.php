<?php
/**
 * Footer Configuration
 * PROTECTED FILE - Customizations preserved during upgrades
 * 
 * Configure footer content, credits, and links
 */

// Organization footer info (year updates automatically)
define('FOOTER_COPYRIGHT_YEAR', date('Y'));
define('FOOTER_MAIN_LINK', 'https://freestar.network/systemx-dmr');
define('FOOTER_MAIN_TEXT', 'System X');

// Credits (do not remove - required by license)
$credits = array(
    array('name' => 'Cort', 'callsign' => 'N0MJS', 'title' => 'HBlink Creator. First line of code!', 'url' => 'https://github.com/n0mjs710'),
    array('name' => 'Jonathan', 'callsign' => 'G4KLX', 'title' => 'MMDVM Developer', 'url' => 'https://github.com/G4KLX'),
    array('name' => 'Bruno', 'callsign' => 'CS8ABG', 'title' => 'Initial dashboard design', 'url' => 'https://github.com/CS8ABG/FDMR-Monitor'),
    array('name' => 'Simon', 'callsign' => 'G7RZU', 'title' => 'Official FDMR Peer Server', 'url' => 'https://github.com/hacknix'),
    array('name' => 'Shane', 'callsign' => 'M0VUB', 'title' => 'RYSEN Master+ / System X', 'url' => 'https://github.com/shaymez/RYSEN'),
);

// Additional footer links (customize per organization)
$footer_links = array(
    // Example:
    // array('text' => 'Support', 'url' => 'https://example.com/support'),
);

?>
