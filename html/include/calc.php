<?php
/**
 * Retired copy of the selfcare form.
 * The live page is ssmain.php. This file stays so an old link does not
 * serve the previous calculator, which no longer matches selfcare.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "This page has been retired. Open selfcare from the dashboard.\n";
exit;
