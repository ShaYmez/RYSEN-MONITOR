<?php
// Initialize secure session settings before starting session
require_once "ssconfunc.php";
initSecureSession();
session_start();

include_once "include/functions.php";
checkSessionTimeout();

// ============================================
// Authentication Check
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['int_ids'])) {
    error_log("Access denied to ssmain.php - user not authenticated. Session user_id: " . 
              (isset($_SESSION['user_id']) ? 'set' : 'not set') . 
              ", int_ids: " . (isset($_SESSION['int_ids']) ? 'set' : 'not set'));
    header("Location: sslogin.php");
    exit();
}

$callsign = $_SESSION['user_id'];
$int_ids = $_SESSION['int_ids'];

// ============================================
// Device Selection
// ============================================
$selint_id = handleDeviceSelection();

if ($selint_id === null) {
    die("No device selected");
}

// Verify user owns the selected device
if (!verifyDeviceOwnership($selint_id)) {
    error_log("Access denied - user " . $_SESSION['user_id'] . " does not own device $selint_id");
    die("Access denied: You do not own this device. Please contact administrator if this is an error.");
}

// ============================================
// Handle Configuration Update
// ============================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['genText'])) {
    // Verify CSRF token for configuration updates (still required for actual changes)
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        error_log("CSRF token validation failed for configuration update");
        die("Security error: Invalid or missing CSRF token. Please refresh the page and try again.");
    }
    
    $options = sanitizeOptions($_POST['genText']);
    
    if (!empty($options)) {
        $result = updateDevOptions($selint_id, $options);
        
        if ($result) {
            redirectToSelf();
        } else {
            $errorMsg = "Failed to update options. Please check database connection.";
            error_log("Failed to update device options for int_id: $selint_id");
        }
    } else {
        $errorMsg = "Invalid options format.";
        error_log("Invalid options format submitted: " . substr($_POST['genText'], 0, 50));
    }
}

// ============================================
// Get Device Details
// ============================================
$devDetails = getDevDetails($selint_id);

if (!$devDetails) {
    error_log("Device not found for int_id: $selint_id");
    die("Device not found. Please contact administrator.");
}

// Parse device options
$options = parseDeviceOptions($devDetails['options']);

// Extract individual options with defaults
$dialValue = getDeviceOption($devDetails['options'], 'DIAL', 0);
$voiceValue = getDeviceOption($devDetails['options'], 'VOICE', '-1');
$langValue = getDeviceOption($devDetails['options'], 'LANG', 'en_GB');
$singleValue = getDeviceOption($devDetails['options'], 'SINGLE', '-1');
$stickyValue = getDeviceOption($devDetails['options'], 'STICKY', '-1');
$timerValue = getDeviceOption($devDetails['options'], 'TIMER', 0);
$ts1Values = getDeviceOption($devDetails['options'], 'TS1', []);
$ts2Values = getDeviceOption($devDetails['options'], 'TS2', []);

// Ensure arrays
if (!is_array($ts1Values)) {
    $ts1Values = empty($ts1Values) ? [] : [$ts1Values];
}
if (!is_array($ts2Values)) {
    $ts2Values = empty($ts2Values) ? [] : [$ts2Values];
}

// ============================================
// Preloader Display Logic
// ============================================
if (!isset($_SESSION['preloader_displayed'])) {
    $_SESSION['preloader_displayed'] = true;
    $display_preloader = true;
} else {
    $display_preloader = false;
}

// Generate CSRF token for forms
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Page Title -->
    <title>System X DMR Global | Selfcare | <?php echo "$callsign"; ?></title>
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="./apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="./favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="./favicon-16x16.png">
    <link rel="manifest" href="./site.webmanifest">
    <link rel="mask-icon" href="./safari-pinned-tab.svg" color="#5bbad5">
    <meta name="msapplication-TileColor" content="#2b5797">
    <meta name="theme-color" content="#ffffff">
    <!-- Site Description -->
    <meta name="description" content="SystemX Dashboard">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="plugins/adminlte/css/adminlte.min.css">
</head>

<body class="hold-transition dark-mode layout-top-nav layout-navbar-fixed text-sm">
    <div class="wrapper">
        <?php if ($display_preloader): ?>
        <div class="preloader flex-column justify-content-center align-items-center">
            <!-- Preload small icon -->
            <img class="animation__wobble" src="img/Logo_mini.png" alt="" height="60" width="60">
        </div>
        <?php endif; ?>
        <?php include 'elements/navbar.php';?>
        <!-- Background image -->
        <!-- <div class="content-wrapper" style="background-image: url('img/bk.jpg'); background-attachment: fixed;"> -->
        <div class="content-wrapper">
            <div class="content-header">
                <div class="container">
                    <div class="row mb-2 justify-content-center">
                        <div class="col-sm-auto">
                            <!-- Header logo -->
                            <img src="img/systemx-wide-banner.png" alt="SystemX" width="100%">
                        </div>
                    </div>
                </div>
            </div>
            <div class="content">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header border-transparent">
                                    <h3 class="card-title">
                                        <?php echo "<b>" . escapeHtml($callsign) . "</b>  "; ?>
                                        <?php if (count($int_ids) === 1): ?>
                                        <?php echo '   (' . escapeHtml($selint_id) . ')'; ?>
                                        <?php endif; ?>
                                    </h3>
                                    <div class="card-tools">
                                        <a href="sslogout.php" class="btn btn-tool">
                                        <i class="fas fa-sign-out-alt"></i> <b><span id="calc_lout"></span></b>
                                        </a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (isset($errorMsg)): ?>
                                        <p>
                                            <?php echo $errorMsg; ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="blur-content">
                                        <div class="row justify-content-center">
                                            <div class="col-4 text-center mb-4">
                                                <h1>Selfcare<h1>
                                                    <h4><?php echo escapeHtml($callsign); ?></h4>
                                            </div>
                                        </div>
                                        
                                        <!-- Hidden Configuration Inputs -->
                                        <input type="hidden" id="mode-status" value="<?php echo escapeHtml($devDetails['mode']); ?>">
                                        <input type="hidden" id="device-id" value="<?php echo escapeHtml($selint_id); ?>">
                                        <input type="hidden" id="device-modified" value="<?php echo escapeHtml($devDetails['modified']); ?>">
                                        
                                        <div class="row justify-content-center">
                                            <div class="form-group col-2">
                                                <div class="row justify-content-center">
                                                    <?php if (count($int_ids) !== 1): ?>
                                                        <p class="mb-1"><b><span id="calc_dev"></span></b></p>
                                                        <form method="post">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                                            <select class="form-control form-control-sm" name="int_id" onchange="this.form.submit()">
                                                                <?php foreach ($int_ids as $int_id): ?>
                                                                <option value="<?= escapeHtml($int_id) ?>" <?= (isset($_SESSION['selected_int_id']) && $_SESSION['selected_int_id'] === $int_id) ? 'selected' : '' ?>><?= escapeHtml($int_id) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </form>
                                                    <?php endif; ?>
                                                    <span class="mt-3"><?php if ($devDetails['mode']== 4) { echo "SIMPLEX" ; } else { echo "DUPLEX" ; } ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row justify-content-center">
                                            <div id="timeslot1col" class="col-5">
                                                <table class="table table-sm border align-middle">
                                                    <thead class="bg-danger">
                                                        <tr>
                                                            <th colspan="3" style="text-align: center;" class="align-middle">Time Slot 1&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpts1"></i></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="timeslotTable">
                                                        <?php foreach ($ts1Values as $index => $value): ?>
                                                        <tr>
                                                            <td class="align-middle text-nowrap">TG <?php echo $index + 1; ?>:</td>
                                                            <td><input type="number" class="form-control form-control-sm" min="0" step="1" value="<?php echo $value; ?>"></td>
                                                            <td><button class="btn" onclick="window.selfcare.removeRow(this)"><i class="fas fa-times text-danger"></i></button></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <div class="row justify-content-center">
                                                    <button class="btn btn-primary btn-xs" onclick="window.selfcare.addRow('timeslotTable')" id="calc_addts1"></button>
                                                </div>
                                            </div>
                                            <div id="timeslot2col" class="col-5">
                                                <table class="table table-sm border align-middle">
                                                    <thead class="bg-warning">
                                                        <tr>
                                                            <th colspan="3" style="text-align: center;" class="align-middle">Time Slot 2&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpts2"></i></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="timeslotTable2">
                                                        <?php foreach ($ts2Values as $index => $value): ?>
                                                        <tr>
                                                            <td class="align-middle text-nowrap">TG <?php echo $index + 1; ?>:</td>
                                                            <td><input type="number" class="form-control form-control-sm" min="0" step="1" value="<?php echo $value; ?>"></td>
                                                            <td><button class="btn" onclick="window.selfcare.removeRow(this)"><i class="fas fa-times text-danger"></i></button></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <div class="row justify-content-center">
                                                    <button class="btn btn-primary btn-xs" onclick="window.selfcare.addRow('timeslotTable2')" id="calc_addts2"></button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row justify-content-center">
                                            <div class="col-8">
                                                <table class="table table-sm border align-middle mt-4">
                                                    <thead class="bg-success">
                                                        <tr>
                                                            <th colspan="2" style="text-align: center;" class="align-middle">Functions&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpfun"></i></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td class="align-middle text-nowrap"><span id="calc_dialtg"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpdtg"></i></td>
                                                            <td><input type="number" class="form-control form-control-sm" min="0" step="1" id="dialTGInput" value="<?php echo $dialValue; ?>" oninput="window.selfcare.toggleTimeslot2(this.value)"></td>
                                                        </tr>
                                                        <tr>
                                                            <td class="align-middle text-nowrap"><span id="calc_voice"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpvoice"></i></td>
                                                            <td>
                                                                <select class="form-control form-control-sm" id="voiceSelect">
                                                                    <option value="-1" <?php if ($voiceValue=='-1' ) { echo "selected" ; }; ?> id="calc_voicesrv"></option>
                                                                    <option value="0" <?php if ($voiceValue=='0' ) { echo "selected" ; }; ?> id="calc_voiceoff"></option>
                                                                    <option value="1" <?php if ($voiceValue=='1' ) { echo "selected" ; }; ?> id="calc_voiceon"></option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr id="languagerow">
                                                            <td class="align-middle text-nowrap"><span id="calc_lang"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlplang"></i></td>
                                                            <td>
                                                                <select class="form-control form-control-sm" id="languageselect">
                                                                    <option value="en_GB" <?php if ($langValue=='en_GB' ) { echo "selected" ; }; ?>>English (en_GB)</option>
                                                                    <option value="en_US" <?php if ($langValue=='en_US' ) { echo "selected" ; }; ?>>English (en_US)</option>
                                                                    <option value="es_ES" <?php if ($langValue=='es_ES' ) { echo "selected" ; }; ?>>Spanish (es_ES)</option>
                                                                    <option value="fr_FR" <?php if ($langValue=='fr_FR' ) { echo "selected" ; }; ?>>French (fr_FR)</option>
                                                                    <option value="de_DE" <?php if ($langValue=='de_DE' ) { echo "selected" ; }; ?>>German (de_DE)</option>
                                                                    <option value="dk_DK" <?php if ($langValue=='dk_DK' ) { echo "selected" ; }; ?>>Danish (dk_DK)</option>
                                                                    <option value="it_IT" <?php if ($langValue=='it_IT' ) { echo "selected" ; }; ?>>Italian (it_IT)</option>
                                                                    <option value="no_NO" <?php if ($langValue=='no_NO' ) { echo "selected" ; }; ?>>Norwegian (no_NO)</option>
                                                                    <option value="pl_PL" <?php if ($langValue=='pl_PL' ) { echo "selected" ; }; ?>>Polish (pl_PL)</option>
                                                                    <option value="se_SE" <?php if ($langValue=='se_SE' ) { echo "selected" ; }; ?>>Swedish (se_SE)</option>
                                                                    <option value="pt_PT" <?php if ($langValue=='pt_PT' ) { echo "selected" ; }; ?>>Portuguese (pt_PT)</option>
                                                                    <option value="cy_GB" <?php if ($langValue=='cy_GB' ) { echo "selected" ; }; ?>>Welsh (cy_GB)</option>
                                                                    <option value="el_GR" <?php if ($langValue=='el_GR' ) { echo "selected" ; }; ?>>Greek (el_GR)</option>
                                                                    <option value="CW" <?php if ($langValue=='CW' ) { echo "selected" ; }; ?>>CW</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="align-middle text-nowrap"><span 
                                                                    id="calc_smode"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpsmode"></i></td>
                                                            <td>
                                                                <select class="form-control form-control-sm" id="singleModeSelect">
                                                                    <option value="-1" <?php if ($singleValue=='-1' ) { echo "selected" ; }; ?> id="calc_smodesrv"></option>
                                                                    <option value="0" <?php if ($singleValue=='0' ) { echo "selected" ; }; ?> id="calc_smodeoff"></option>
                                                                    <option value="1" <?php if ($singleValue=='1' ) { echo "selected" ; }; ?> id="calc_smodeon"></option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="align-middle text-nowrap">
                                                                <span id="calc_sticky"></span>&nbsp;&nbsp;&nbsp;
                                                                <i class="far fa-question-circle" 
                                                                   data-bs-toggle="tooltip" 
                                                                   data-bs-placement="top" 
                                                                   data-bs-html="true"
                                                                   title="Sticky Talkgroup: When enabled, your radio stays on the TG you key up on until you manually switch. When disabled, TG returns to default after timeout.">
                                                                </i>
                                                            </td>
                                                            <td>
                                                                <select class="form-control form-control-sm" id="stickySelect">
                                                                    <option value="-1" <?php if ($stickyValue=='-1') { echo "selected"; }; ?> id="calc_stickysrv"></option>
                                                                    <option value="0" <?php if ($stickyValue=='0') { echo "selected"; }; ?> id="calc_stickyoff"></option>
                                                                    <option value="1" <?php if ($stickyValue=='1') { echo "selected"; }; ?> id="calc_stickyon"></option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="align-middle text-nowrap"><span id="calc_tgto"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="" id="calchlpstgto"></i></td>
                                                            <td><input type="number" class="form-control form-control-sm" min="0" step="1" id="timeoutInput" value="<?php echo $timerValue; ?>"></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="row justify-content-center mb-3">
                                            <div class="col-6">
                                                <div class="row justify-content-center">
                                                    <p class="mb-1"><b>RAW Options:</b></p>
                                                </div>
                                                <div class="row justify-content-center">
                                                    <textarea class="form-control text-sm form-control-sm" id="genText" rows="2" readonly></textarea>
                                                    <form method="post" id="saveChangesForm" style="display: none;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                                        <textarea name="genText" id="genTextHidden"></textarea>
                                                    </form>
                                                    
                                                </div>
                                                <div class="row justify-content-center mt-4 mb-4">
                                                    <button class="btn btn-primary" onclick="window.selfcare.saveChanges()" id="calc_save"></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="spinner text-center mb-5" style="display: <?php echo $devDetails['modified'] === '1' ? 'block' : 'none'; ?>">
                                        <i class="fas fa-2x fa-sync-alt fa-spin"></i><br><br>
                                        <span class="mt-2" id="calc_wait"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <br>
                    </div>
                </div>
            </div>
        </div>
        <footer class="main-footer text-sm">
            <?php include 'elements/footer.php';?>
        </footer>
    </div>
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="scripts/mode.js"></script>
    <script src="plugins/adminlte/js/adminlte.min.js"></script>
    <script src="scripts/monitor.js"></script>
    <script src="scripts/selfcare.js"></script>
</body>

</html>
