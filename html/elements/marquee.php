<?php
/**
 * Marquee Element Component
 * Displays scrolling marquee with organization-specific content
 * Customizable via config/marquee.php
 */

include_once __DIR__ . '/../include/marquee-loader.php';
include_once __DIR__ . '/../config/footer.php';
include_once __DIR__ . '/../include/copyright.php';
include_once __DIR__ . '/../include/version.php';
?>
<marquee class="MyMarquee" id="my_marquee" data-current-lang="<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>" direction="left" behavior="6" scrollamount="2" onmouseover="this.stop()" onmouseout="this.start()">
     <?php foreach ($marquee as $line): ?>
     <div><?php echo $line; ?></div>
     <?php endforeach; ?>
     <div>DMR Server Software RYSEN Master+ <?php echo VERSION; ?>. System X Server &copy; <?php echo FOOTER_COPYRIGHT_YEAR; ?>. All Rights Reserved.</div>
</marquee>
<!-- <?php echo SYSTEMX_COPYRIGHT_LINE; ?> -->
