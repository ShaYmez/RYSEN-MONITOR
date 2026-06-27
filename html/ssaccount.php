<?php
require_once "ssconfunc.php";
require_once "include/functions.php";
initSecureSession();
session_start();
checkSessionTimeout();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['int_ids']) || !isIpscSession()) {
    header("Location: sslogin.php");
    exit();
}

$radioId = (int) $_SESSION['int_ids'][0];
$callsign = $_SESSION['user_id'];
$successMsg = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $errorMsg = "Session expired. Please refresh and try again.";
    } elseif (!verifyDeviceOwnership($radioId)) {
        $errorMsg = "Access denied.";
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            $errorMsg = "New passwords do not match.";
        } else {
            $result = changeIpscPassword($radioId, $currentPassword, $newPassword);
            if ($result === true) {
                try {
                    logPasswordChange($radioId);
                } catch (Exception $e) {
                    error_log("Password change audit logging failed: " . $e->getMessage());
                }
                $successMsg = "Password updated successfully.";
            } else {
                $errorMsg = is_string($result) ? $result : "Could not update password.";
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
        <?php include 'elements/navbar.php';?>

        <div class="content-wrapper">
            <div class="content-header">
                <div class="container">
                    <div class="row mb-2 justify-content-center">
                        <div class="col-sm-auto">
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
                                <a href="ssmain.php">Selfcare Account</a>
                            </div>

                            <div class="card">
                                <div class="card-body login-card-body">
                                    <p class="text-center mb-3">
                                        <b><?php echo escapeHtml($callsign); ?></b>
                                        (<?php echo $radioId; ?> IPSC)
                                    </p>

                                    <?php if ($successMsg): ?>
                                    <p class="text-center text-success"><?php echo escapeHtml($successMsg); ?></p>
                                    <?php endif; ?>

                                    <?php if (isset($errorMsg)): ?>
                                    <p class="text-center text-danger"><?php echo escapeHtml($errorMsg); ?></p>
                                    <?php endif; ?>

                                    <form action="ssaccount.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">

                                        <div class="input-group mb-3 mt-3">
                                            <input type="password" class="form-control" name="current_password" placeholder="Current password" required>
                                            <div class="input-group-append">
                                                <div class="input-group-text"><i class="fas fa-lock"></i></div>
                                            </div>
                                        </div>

                                        <div class="input-group mb-3">
                                            <input type="password" class="form-control" name="new_password" placeholder="New password" minlength="6" maxlength="100" required>
                                            <div class="input-group-append">
                                                <div class="input-group-text"><i class="fas fa-lock"></i></div>
                                            </div>
                                        </div>

                                        <div class="input-group mb-3">
                                            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" minlength="6" maxlength="100" required>
                                            <div class="input-group-append">
                                                <div class="input-group-text"><i class="fas fa-lock"></i></div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-6">
                                                <a href="ssmain.php" class="btn btn-default btn-block">Back</a>
                                            </div>
                                            <div class="col-6">
                                                <button type="submit" class="btn btn-primary btn-block">Update</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
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
</body>
</html>
