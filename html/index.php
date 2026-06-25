<?php
// Initialize secure session settings before starting session
require_once "ssconfunc.php";
initSecureSession();
session_start();

require_once __DIR__ . '/include/language-support.php';
init_session_language();

// ============================================
// Preloader Display Logic
// ============================================
if (!isset($_SESSION['preloader_displayed'])) {
    $_SESSION['preloader_displayed'] = true;
    $display_preloader = true;
} else {
    $display_preloader = false;
}
?>
<!DOCTYPE html>
<html>
  <head>
    <?php include 'elements/header.php';?>
  </head>
  
  <body class="hold-transition dark-mode layout-top-nav layout-navbar-fixed text-sm" data-current-lang="<?php echo htmlspecialchars(isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en', ENT_QUOTES, 'UTF-8'); ?>">
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
                <a href="?p=home"><img src="img/logo.png" alt="System X" width="100%"></a>
                <br />
                <br />
                <div>
                  <!-- Marquee -->
                  <?php include_once 'elements/marquee.php'; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Main Content -->
        <div class="content">
          <?php
            // Page routing with basic validation
            $page = isset($_GET['p']) ? $_GET['p'] : 'home';
            
            // Basic sanitization - allow only alphanumeric and underscores
            $page = preg_replace('/[^a-zA-Z0-9_]/', '', $page);
            
            // Include the requested page
            $pagePath = 'include/' . $page . '.php';
            if (file_exists($pagePath)) {
                include $pagePath;
            } else {
                include 'include/home.php';
            }
          ?>
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
