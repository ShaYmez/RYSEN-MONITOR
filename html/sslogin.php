<?php
// Initialize secure session settings before starting session
require_once "error_handler.php";
require_once "ssconfunc.php";
require_once "include/functions.php";
initSecureSession();
session_start();

// Already logged in — skip the login form when returning from other pages
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isSelfcareLoggedIn()) {
    checkSessionTimeout();
    if (isSelfcareLoggedIn()) {
        header('Location: ssmain.php');
        exit();
    }
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

$showClaimForm = false;
$claimRadioId = null;
$claimCallsign = '';

// ============================================
// Handle Login Form Submission
// ============================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $action = $_POST['action'] ?? 'login';

    $rateCheck = checkRateLimit($clientIP);
    if (!$rateCheck['allowed']) {
        $waitMinutes = ceil($rateCheck['wait_time'] / 60);
        $errorMsg = "<span>Too many failed attempts. Please try again in {$waitMinutes} minutes.</span>";
        error_log("Login attempt blocked for IP $clientIP - rate limited");
    } elseif ($action === 'ipsc_claim') {
        $claimRadioId = (int) ($_POST['claim_int_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if ($claimRadioId < 1) {
            $errorMsg = "<span>Invalid device. Please start again from the login screen.</span>";
        } elseif (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
            $errorMsg = "<span>Session expired. Please try again.</span>";
        } elseif ($password !== $passwordConfirm) {
            $showClaimForm = true;
            $claimRow = getIpscClaimRowByLogin((string) $claimRadioId);
            $claimCallsign = $claimRow ? trim($claimRow['callsign']) : '';
            $errorMsg = "<span>Passwords do not match.</span>";
        } else {
            $claimResult = claimIpscPassword($claimRadioId, $password);
            if ($claimResult === true) {
                clearLoginAttempts($clientIP);
                $row = getDevDetails($claimRadioId);
                if ($row) {
                    establishIpscSession($row);
                }
                try {
                    logLoginSuccess((string) $claimRadioId);
                    logPasswordChange($claimRadioId);
                } catch (Exception $e) {
                    error_log("Claim audit logging failed: " . $e->getMessage());
                }
                header("Location: ssmain.php");
                exit();
            }

            $showClaimForm = true;
            $claimRow = getIpscClaimRowByLogin((string) $claimRadioId);
            $claimCallsign = $claimRow ? trim($claimRow['callsign']) : '';
            $errorMsg = "<span>" . htmlspecialchars(is_string($claimResult) ? $claimResult : "Could not set password.") . "</span>";
        }
    } else {
        $username = normalizeLoginUsername($_POST['callsign'] ?? '');
        $password = $_POST['password'] ?? '';
        $isValid = true;

        if (!isValidLoginUsername($username)) {
            error_log("Invalid login username format: " . substr($username, 0, 10));
            $errorMsg = "<span>Invalid callsign or radio ID format.</span>";
            $isValid = false;
        }

        if ($isValid && $password === '') {
            $claimRow = getIpscClaimRowByLogin($username);
            if ($claimRow) {
                $showClaimForm = true;
                $claimRadioId = (int) $claimRow['int_id'];
                $claimCallsign = trim($claimRow['callsign']);
            } else {
                $hint = explainIpscClaimFailureByLogin($username);
                if ($hint) {
                    $errorMsg = "<span>" . htmlspecialchars($hint) . "</span>";
                } else {
                    recordFailedLogin($clientIP);
                    $errorMsg = "<span>Invalid callsign, radio ID, or password. Please try again.</span>";
                    error_log("Authentication failed for $username");
                }
            }
        } elseif ($isValid) {
            if (strlen($password) > 100 || strlen($password) < 1) {
                $errorMsg = "<span>Invalid credentials format.</span>";
                $isValid = false;
            }
        }

        if ($isValid && !$showClaimForm) {
            $authResult = authenticateUser($username, $password);
            if ($authResult === true) {
                clearLoginAttempts($clientIP);
                header("Location: ssmain.php");
                exit();
            }

            recordFailedLogin($clientIP);
            if (is_string($authResult)) {
                $errorMsg = "<span>" . htmlspecialchars($authResult) . "</span>";
                error_log("Authentication failed for $username: $authResult");
            } else {
                $errorMsg = "<span>Invalid callsign, radio ID, or password. Please try again.</span>";
                error_log("Authentication failed for $username");
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html>
  <head>
    <?php include 'elements/header.php';?>
  </head>
  
  <body class="hold-transition dark-mode layout-top-nav layout-navbar-fixed text-sm">
    <div class="wrapper">
      <?php if ($display_preloader): ?>
      <div class="preloader flex-column justify-content-center align-items-center">
        <img class="animation__wobble" src="img/Logo_mini.png" alt="" height="60" width="60">
      </div>
      <?php endif; ?>
      
      <?php include 'elements/navbar.php';?>
      <?php include_once 'include/version.php';?>
      
      <div class="content-wrapper">
        <div class="content-header">
          <div class="container">
            <div class="row mb-2 justify-content-center">
              <div class="col-sm-auto">
                <p style="font-size: 8px; text-align: right; margin-right: 3px">Dashboard Version: <?php echo DASH; ?></p>
                <img src="img/systemx-wide-banner.png" alt="System X" width="100%">
              </div>
            </div>
          </div>
        </div>
        
        <div class="content">
          <div class="container">
            <div class="row justify-content-center">
              <div class="login-box">
                <div class="login-logo">
                  <a href="#">Selfcare</a>
                </div>
                
                <div class="card">
                  <div class="card-body login-card-body">
                    <?php if (isset($errorMsg)): ?>
                    <p class="text-center">
                      <?php echo $errorMsg; ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($showClaimForm): ?>
                    <p class="text-center mt-3">
                      Set up selfcare for
                      <b><?php echo htmlspecialchars($claimCallsign); ?></b>
                      (<?php echo (int) $claimRadioId; ?>)
                    </p>
                    <form action="sslogin.php" method="post">
                      <input type="hidden" name="action" value="ipsc_claim">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                      <input type="hidden" name="claim_int_id" value="<?php echo (int) $claimRadioId; ?>">

                      <div class="input-group mb-3 mt-3">
                        <input type="password" class="form-control" name="password" placeholder="New password" minlength="6" maxlength="100" required autofocus>
                        <div class="input-group-append">
                          <div class="input-group-text">
                            <i class="fas fa-lock"></i>
                          </div>
                        </div>
                      </div>

                      <div class="input-group mb-3">
                        <input type="password" class="form-control" name="password_confirm" placeholder="Confirm password" minlength="6" maxlength="100" required>
                        <div class="input-group-append">
                          <div class="input-group-text">
                            <i class="fas fa-lock"></i>
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-8">
                          <a href="sslogin.php" class="btn btn-default btn-block">Cancel</a>
                        </div>
                        <div class="col-4">
                          <button type="submit" class="btn btn-primary btn-block">Save</button>
                        </div>
                      </div>
                    </form>
                    <?php else: ?>
                    <form action="sslogin.php" method="post">
                      <div class="input-group mb-3 mt-4">
                        <input type="text" class="form-control" name="callsign" placeholder="" id="sslog_call" required
                          autocapitalize="characters" spellcheck="false" autocomplete="username">
                        <div class="input-group-append">
                          <div class="input-group-text" data-toggle="tooltip" data-placement="top"
                            title="Enter your callsign or DMR radio ID. Works for hotspots and IPSC repeaters.">
                            <i class="fas fa-broadcast-tower"></i>
                          </div>
                        </div>
                      </div>
                      
                      <div class="input-group mb-3">
                        <input type="password" class="form-control" name="password" placeholder="" id="sslog_pass" autocomplete="current-password">
                        <div class="input-group-append">
                          <div class="input-group-text">
                            <i class="fas fa-lock"></i>
                          </div>
                        </div>
                      </div>
                      
                      <div class="row">
                        <div class="col-8"></div>
                        <div class="col-4">
                          <button type="submit" class="btn btn-primary btn-block" id="sslog_login"></button>
                        </div>
                      </div>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="row justify-content-center mb-5">
              <div class="login-box mt-5 col-8">
                <div class="card">
                  <div class="card-body">
                    <b><p class="text-center" id="sslog_use"></p></b>
                    <span id="sslog_instruc"></span><br><br>
                    
                    <span>Pi-star:</span>
                    <img src="img/pi-star_pass.png" alt="" width="100%" class="mt-1"><br><br>
                    
                    <span>WPSD:</span>
                    <img src="img/wpsd_pass.png" alt="" width="100%" class="mb-4">
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
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var loginField = document.getElementById('sslog_call');
        if (!loginField) {
          return;
        }
        loginField.addEventListener('input', function () {
          var start = loginField.selectionStart;
          var end = loginField.selectionEnd;
          loginField.value = loginField.value.toUpperCase();
          loginField.setSelectionRange(start, end);
        });
      });
    </script>
  </body>
</html>
