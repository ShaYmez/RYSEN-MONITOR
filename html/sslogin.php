<?php
// Initialize secure session settings before starting session
require_once "error_handler.php";
require_once "ssconfunc.php";
initSecureSession();
session_start();

// ============================================
// Preloader Display Logic
// ============================================
if (!isset($_SESSION['preloader_displayed'])) {
    $_SESSION['preloader_displayed'] = true;
    $display_preloader = true;
} else {
    $display_preloader = false;
}

// ============================================
// Handle Login Form Submission
// ============================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get client IP
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Check rate limit before processing (very lenient for hotspot users)
    $rateCheck = checkRateLimit($clientIP);
    if (!$rateCheck['allowed']) {
        $waitMinutes = ceil($rateCheck['wait_time'] / 60);
        $errorMsg = "<span>Too many failed attempts. Please try again in {$waitMinutes} minutes.</span>";
        error_log("Login attempt blocked for IP $clientIP - rate limited");
    } else {
        $username = $_POST['callsign'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Input validation
        $isValid = true;
        
        // Callsign (MMDVM) or radio ID (IPSC, all digits)
        if (ctype_digit($username)) {
            if (!preg_match('/^[1-9][0-9]{0,8}$/', $username)) {
                error_log("Invalid radio ID format: " . substr($username, 0, 10));
                $errorMsg = "<span>Invalid radio ID format.</span>";
                $isValid = false;
            }
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_-]{0,18}[a-zA-Z0-9])?$/', $username)) {
            error_log("Invalid username format: " . substr($username, 0, 10));
            $errorMsg = "<span>Invalid username format. Must start and end with letter or number (1-20 characters).</span>";
            $isValid = false;
        }
        
        // Validate password length
        if ($isValid && (strlen($password) > 100 || strlen($password) < 1)) {
            $errorMsg = "<span>Invalid credentials format.</span>";
            $isValid = false;
        }

        if ($isValid) {
            $authResult = authenticateUser($username, $password);
            if ($authResult === true) {
                clearLoginAttempts($clientIP);
                header("Location: ssmain.php");
                exit();
            } else {
                recordFailedLogin($clientIP);
                // Show specific error if authentication returned one
                if (is_string($authResult)) {
                    $errorMsg = "<span>" . htmlspecialchars($authResult) . "</span>";
                    error_log("Authentication failed for $username: $authResult");
                } else {
                    if (ctype_digit($username)) {
                        $errorMsg = "<span>Invalid radio ID or password. Please try again.</span>";
                    } else {
                        $errorMsg = "<span>Invalid callsign or password. Please try again.</span>";
                    }
                    error_log("Authentication failed for $username");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
  <head>
    <?php include 'elements/header.php';?>
  </head>
  
  <body class="hold-transition dark-mode layout-top-nav layout-navbar-fixed text-sm">
    <div class="wrapper">
      <!-- Preloader -->
      <?php if ($display_preloader): ?>
      <div class="preloader flex-column justify-content-center align-items-center">
        <img class="animation__wobble" src="img/Logo_mini.png" alt="" height="60" width="60">
      </div>
      <?php endif; ?>
      
      <!-- Navigation -->
      <?php include 'elements/navbar.php';?>
      <?php include_once 'include/version.php';?>
      
      <!-- Main Content Wrapper -->
      <div class="content-wrapper">
        
        <!-- Content Header -->
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
        
        <!-- Main Content -->
        <div class="content">
          <div class="container">
            
            <!-- Login Card -->
            <div class="row justify-content-center">
              <div class="login-box">
                <div class="login-logo">
                  <a href="#">Selfcare</a>
                </div>
                
                <div class="card">
                  <div class="card-body login-card-body">
                    
                    <!-- Error Message -->
                    <?php if (isset($errorMsg)): ?>
                    <p class="text-center">
                      <?php echo $errorMsg; ?>
                    </p>
                    <?php endif; ?>

                    <!-- Login Form -->
                    <form action="sslogin.php" method="post">
                      <div class="input-group mb-3 mt-4">
                        <input type="text" class="form-control" name="callsign" placeholder="" id="sslog_call" required>
                        <div class="input-group-append">
                          <div class="input-group-text">
                            <i class="fas fa-broadcast-tower"></i>
                          </div>
                        </div>
                      </div>
                      
                      <div class="input-group mb-3">
                        <input type="password" class="form-control" name="password" placeholder="" min="6" id="sslog_pass" required>
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
                    
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Instructions Card -->
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
      
      <!-- Footer -->
      <footer class="main-footer text-sm">
        <?php include 'elements/footer.php';?>
      </footer>
    </div>
    
    <!-- Scripts -->
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="scripts/mode.js"></script>
    <script src="plugins/adminlte/js/adminlte.min.js"></script>
    <script src="scripts/monitor.js"></script>
  </body>
</html>