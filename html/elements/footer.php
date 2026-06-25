<?php
/**
 * Footer Element Component
 * Renders the page footer with credits and organization info
 * Includes configuration from config/footer.php
 */

// Load footer configuration and version
include_once __DIR__ . '/../config/footer.php';
include_once __DIR__ . '/../include/version.php';
?>
<div class="text-center d-none d-sm-inline">
   <div>
        &copy; <?php echo FOOTER_COPYRIGHT_YEAR; ?> <a target="_blank" href="<?php echo FOOTER_MAIN_LINK; ?>"><?php echo FOOTER_MAIN_TEXT; ?></a>
        <br>
        &copy; 2016-<?php echo FOOTER_COPYRIGHT_YEAR; ?> | Pioneered by Cort <a title="HBlink Creator. First line of code!" href="https://github.com/n0mjs710">N0MJS</a> | Jonathan <a title="MMDVM Developer" href="https://github.com/G4KLX">G4KLX</a> | Bruno <a title="CS8ABG Dash Variant" href="https://github.com/CS8ABG/FDMR-Monitor.git">CS8ABG</a> v23.07.23ss | Simon <a title="Official FDMR Peer Server" href="https://github.com/hacknix">G7RZU</a> v1.3 | Shane <a title="Official RYSEN Master+ / SystemX derivative Server" href="https://github.com/shaymez/RYSEN">M0VUB</a> <?php echo VERSION; ?></div>
   </div>
</div>
<!-- Credits: N0MJS 2016-2024 -->
<!-- Credits: G4KLX 2013-2024 -->
<!-- Credits: SP2ONG 2019-2022 -->
<!-- Credits: OA4DOA 2023 -->
<!-- Credits: CS8ABG 2024 -->
<!-- Credits: G7RZU 2024 -->
<!-- Credits: M0VUB 2024 -->
<!-- THIS COPYRIGHT NOTICE MUST BE DISPLAYED AS A CONDITION OF THE LICENCE GRANT FOR THIS SOFTWARE. ALL DERIVATEIVES WORKS MUST CARRY THIS NOTICE -->
