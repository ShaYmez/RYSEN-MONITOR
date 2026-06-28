<?php
/**
 * RYSEN-MONITOR / System X copyright notices (shipped with upgrades).
 *
 * Copyright (C) 2020-YYYY Shane Daley, M0VUB <shane@freestar.network>
 */

if (!defined('SYSTEMX_COPYRIGHT_HOLDER')) {
    define('SYSTEMX_COPYRIGHT_HOLDER', 'Shane Daley, M0VUB <shane@freestar.network>');
}

if (!defined('SYSTEMX_COPYRIGHT_START')) {
    define('SYSTEMX_COPYRIGHT_START', '2020');
}

if (!defined('SYSTEMX_COPYRIGHT_YEAR')) {
    define('SYSTEMX_COPYRIGHT_YEAR', date('Y'));
}

if (!defined('SYSTEMX_COPYRIGHT_LINE')) {
    define(
        'SYSTEMX_COPYRIGHT_LINE',
        'Copyright (C) ' . SYSTEMX_COPYRIGHT_START . '-' . SYSTEMX_COPYRIGHT_YEAR . ' ' . SYSTEMX_COPYRIGHT_HOLDER
    );
}
