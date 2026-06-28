<?php
/**
 * Selfcare Configuration Functions
 *
 * Database connection and user authentication functions for the selfcare module.
 *
 * Copyright (C) 2020-2026 Shane Daley, M0VUB <shane@freestar.network>
 */

// Include audit logger at file level for reliable loading
require_once __DIR__ . '/audit_logger.php';

// Configuration constants for session and rate limiting
// Session timeout: 4 hours for hotspot users (14400 seconds)
if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 14400);
}

// Rate limiting configuration - adjust these constants as needed
if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) {
    define('RATE_LIMIT_MAX_ATTEMPTS', 10);  // Balanced: lenient but provides brute force protection
}
if (!defined('RATE_LIMIT_WINDOW_MINUTES')) {
    define('RATE_LIMIT_WINDOW_MINUTES', 10); // Time window to track attempts
}
if (!defined('RATE_LIMIT_LOCKOUT_MINUTES')) {
    define('RATE_LIMIT_LOCKOUT_MINUTES', 30); // How long to lock out after exceeding max attempts
}

/** Clients.mode value reserved for IPSC repeaters (see doc/ipsc-selfcare-roadmap.md). */
if (!defined('IPSC_DEVICE_MODE')) {
    define('IPSC_DEVICE_MODE', 0);
}

/**
 * Whether a Clients.mode value identifies an IPSC repeater row.
 *
 * @param mixed $mode Clients.mode column value
 * @return bool
 */
function isIpscDeviceMode($mode)
{
    return (int) $mode === IPSC_DEVICE_MODE;
}

/**
 * Verify password against stored hash (PBKDF2 or bcrypt).
 *
 * @param string|null $storedPassword Value from Clients.psswd
 * @param string $password Plain-text password
 * @return bool
 */
function verifyStoredPassword($storedPassword, $password)
{
    if ($storedPassword === null || $storedPassword === '') {
        return false;
    }

    if (hash_pbkdf2("sha256", $password, "RYSEN", 2000) === $storedPassword) {
        return true;
    }

    return password_verify($password, $storedPassword);
}

/**
 * Whether a Clients.psswd value is unset (first-time IPSC claim).
 *
 * @param mixed $storedPassword
 * @return bool
 */
function isStoredPasswordEmpty($storedPassword)
{
    return $storedPassword === null || $storedPassword === '';
}

/**
 * Hash a selfcare password for storage (PBKDF2-SHA256; same as selfcare-admin.sh / php-cli).
 *
 * @param string $password Plain-text password
 * @return string Hex PBKDF2-SHA256 digest
 */
function hashPasswordForStorage($password)
{
    return hash_pbkdf2("sha256", $password, "RYSEN", 2000);
}

/**
 * Normalize login username: trim; uppercase callsigns; leave radio IDs as digits.
 *
 * @param string $username
 * @return string
 */
function normalizeLoginUsername($username)
{
    $username = trim($username);
    if ($username === '') {
        return '';
    }

    if (ctype_digit($username)) {
        return $username;
    }

    return strtoupper($username);
}

/**
 * Validate callsign or radio ID for selfcare login.
 *
 * @param string $username
 * @return bool
 */
function isValidLoginUsername($username)
{
    $username = normalizeLoginUsername($username);
    if ($username === '') {
        return false;
    }

    if (ctype_digit($username)) {
        return (bool) preg_match('/^[1-9][0-9]{0,8}$/', $username);
    }

    return (bool) preg_match('/^[A-Z0-9]([A-Z0-9_-]{0,18}[A-Z0-9])?$/', $username);
}

/**
 * Find Clients rows matching callsign or radio ID.
 *
 * @param string $username Callsign or DMR ID
 * @param bool $loggedInOnly Only return logged_in = 1 rows
 * @return array<int, array>
 */
function findClientsByLogin($username, $loggedInOnly = true)
{
    $username = normalizeLoginUsername($username);
    if ($username === '') {
        return [];
    }

    $conn = connectDatabase();
    if (ctype_digit($username)) {
        $sql = "SELECT int_id, callsign, psswd, mode, logged_in FROM Clients WHERE int_id = ?";
        if ($loggedInOnly) {
            $sql .= " AND logged_in = 1";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return [];
        }
        $radioId = (int) $username;
        $stmt->bind_param("i", $radioId);
    } else {
        $sql = "SELECT int_id, callsign, psswd, mode, logged_in FROM Clients "
            . "WHERE UPPER(TRIM(callsign)) = ?";
        if ($loggedInOnly) {
            $sql .= " AND logged_in = 1";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return [];
        }
        $stmt->bind_param("s", $username);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    $conn->close();

    return $rows;
}

/**
 * IPSC row eligible for first-time selfcare password claim.
 *
 * @param string $username Callsign or DMR radio ID
 * @return array|null Clients row or null
 */
function getIpscClaimRowByLogin($username)
{
    $rows = findClientsByLogin($username, true);
    foreach ($rows as $row) {
        if (
            isIpscDeviceMode($row['mode'])
            && isStoredPasswordEmpty($row['psswd'])
            && (int) $row['logged_in'] === 1
        ) {
            return $row;
        }
    }

    return null;
}

/**
 * @deprecated Use getIpscClaimRowByLogin()
 */
function getIpscClaimRow($radioId)
{
    return getIpscClaimRowByLogin((string) $radioId);
}

/**
 * Set first-time selfcare password for a connected IPSC repeater.
 *
 * @param int $radioId DMR repeater ID
 * @param string $password Plain-text password
 * @return bool|string True on success, error message otherwise
 */
function claimIpscPassword($radioId, $password)
{
    if ($radioId < 1) {
        return false;
    }

    if (strlen($password) < 6 || strlen($password) > 100) {
        return "Password must be 6–100 characters.";
    }

    $row = getIpscClaimRow($radioId);
    if (!$row) {
        return "This repeater cannot be set up right now. Ensure it is online and has no password yet.";
    }

    $psswdHash = hashPasswordForStorage($password);
    $conn = connectDatabase();
    $stmt = $conn->prepare(
        "UPDATE Clients SET psswd = ? WHERE int_id = ? AND mode = ? AND logged_in = 1 "
        . "AND (psswd IS NULL OR psswd = '')"
    );
    if (!$stmt) {
        $conn->close();
        return "Database error. Please contact administrator.";
    }

    $ipscMode = IPSC_DEVICE_MODE;
    $stmt->bind_param("sii", $psswdHash, $radioId, $ipscMode);
    $stmt->execute();
    $updated = $stmt->affected_rows === 1;
    $stmt->close();
    $conn->close();

    if (!$updated) {
        return "Could not set password. The repeater may already be set up or is offline.";
    }

    return true;
}

/**
 * Change selfcare password for an IPSC repeater.
 *
 * @param int $radioId DMR repeater ID
 * @param string $currentPassword Current plain-text password
 * @param string $newPassword New plain-text password
 * @return bool|string True on success, error message otherwise
 */
function changeIpscPassword($radioId, $currentPassword, $newPassword)
{
    if ($radioId < 1) {
        return false;
    }

    if (strlen($newPassword) < 6 || strlen($newPassword) > 100) {
        return "New password must be 6–100 characters.";
    }

    $conn = connectDatabase();
    $stmt = $conn->prepare(
        "SELECT psswd FROM Clients WHERE int_id = ? AND mode = ?"
    );
    if (!$stmt) {
        $conn->close();
        return "Database error. Please contact administrator.";
    }

    $ipscMode = IPSC_DEVICE_MODE;
    $stmt->bind_param("ii", $radioId, $ipscMode);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        return "Database error. Please contact administrator.";
    }

    $result = $stmt->get_result();
    if (!$result || $result->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        return false;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if (!verifyStoredPassword($row['psswd'], $currentPassword)) {
        $conn->close();
        return "Current password is incorrect.";
    }

    $psswdHash = hashPasswordForStorage($newPassword);
    $stmt = $conn->prepare("UPDATE Clients SET psswd = ? WHERE int_id = ? AND mode = ?");
    if (!$stmt) {
        $conn->close();
        return "Database error. Please contact administrator.";
    }

    $stmt->bind_param("sii", $psswdHash, $radioId, $ipscMode);
    $stmt->execute();
    $updated = $stmt->affected_rows === 1;
    $stmt->close();
    $conn->close();

    if (!$updated) {
        return "Could not update password.";
    }

    return true;
}

/**
 * Start a selfcare session for an IPSC repeater.
 *
 * @param array $row Clients row with int_id and callsign
 * @return void
 */
function establishIpscSession($row)
{
    $_SESSION['user_id'] = trim($row['callsign']);
    $_SESSION['int_ids'] = [(int) $row['int_id']];
    $_SESSION['is_ipsc'] = true;
    $_SESSION['last_activity'] = time();
    session_regenerate_id(true);
}

/**
 * Whether the current session is an IPSC repeater sysop.
 *
 * @return bool
 */
function isIpscSession()
{
    return !empty($_SESSION['is_ipsc']);
}

/**
 * User-facing hint when IPSC login with empty password is not claimable.
 *
 * @param string $username Callsign or DMR radio ID
 * @return string|null Message or null if generic login failure applies
 */
function explainIpscClaimFailureByLogin($username)
{
    $username = normalizeLoginUsername($username);
    if ($username === '') {
        return null;
    }

    $loggedInRows = findClientsByLogin($username, true);
    $ipscLoggedIn = array_values(array_filter(
        $loggedInRows,
        static function ($row) {
            return isIpscDeviceMode($row['mode']);
        }
    ));

    if (!empty($ipscLoggedIn)) {
        if (!isStoredPasswordEmpty($ipscLoggedIn[0]['psswd'])) {
            return null;
        }
        return null;
    }

    $anyRows = findClientsByLogin($username, false);
    if (empty($anyRows)) {
        return "Device not found. It must connect to the network before you can set up selfcare.";
    }

    $ipscAny = array_values(array_filter(
        $anyRows,
        static function ($row) {
            return isIpscDeviceMode($row['mode']);
        }
    ));

    if (empty($ipscAny)) {
        return null;
    }

    if (!isStoredPasswordEmpty($ipscAny[0]['psswd'])) {
        return null;
    }

    if ((int) $ipscAny[0]['logged_in'] !== 1) {
        return "Device is not online. Connect it to the network, then sign in with callsign or radio ID and leave the password blank to set up selfcare.";
    }

    return null;
}

/**
 * @deprecated Use explainIpscClaimFailureByLogin()
 */
function explainIpscClaimFailure($radioId)
{
    return explainIpscClaimFailureByLogin((string) $radioId);
}

/**
 * Start a selfcare session for one or more MMDVM devices.
 *
 * @param array<int, array> $rows Clients rows (mode > 0)
 * @return void
 */
function establishMmdvmSession(array $rows)
{
    $_SESSION['user_id'] = trim($rows[0]['callsign']);
    $_SESSION['int_ids'] = array_map(
        static function ($row) {
            return (int) $row['int_id'];
        },
        $rows
    );
    $_SESSION['is_ipsc'] = false;
    $_SESSION['last_activity'] = time();
    session_regenerate_id(true);
}

/**
 * Authenticate user with callsign or radio ID and password.
 *
 * Looks up logged-in device(s), verifies password, then routes by Clients.mode.
 *
 * @param string $username Callsign or DMR radio ID
 * @param string $password User password
 * @return bool|string True if authentication successful, error message string otherwise
 */
function authenticateUser($username, $password)
{
    $username = normalizeLoginUsername($username);
    if ($username === '') {
        return false;
    }

    $rows = findClientsByLogin($username, true);
    if (empty($rows)) {
        logLoginFailure($username, 'device_not_online');
        return false;
    }

    $matched = [];
    foreach ($rows as $row) {
        if (verifyStoredPassword($row['psswd'], $password)) {
            $matched[] = $row;
        }
    }

    if (empty($matched)) {
        logLoginFailure($username);
        return false;
    }

    $ipscRows = array_values(array_filter(
        $matched,
        static function ($row) {
            return isIpscDeviceMode($row['mode']);
        }
    ));
    $mmdvmRows = array_values(array_filter(
        $matched,
        static function ($row) {
            return !isIpscDeviceMode($row['mode']);
        }
    ));

    if (count($matched) === 1) {
        $row = $matched[0];
        if (isIpscDeviceMode($row['mode'])) {
            establishIpscSession($row);
        } else {
            establishMmdvmSession([$row]);
        }

        try {
            logLoginSuccess($username);
        } catch (Exception $e) {
            error_log("Login audit logging failed: " . $e->getMessage());
        }

        return true;
    }

    if (count($ipscRows) === 1 && empty($mmdvmRows)) {
        establishIpscSession($ipscRows[0]);
        try {
            logLoginSuccess($username);
        } catch (Exception $e) {
            error_log("Login audit logging failed: " . $e->getMessage());
        }
        return true;
    }

    if (empty($ipscRows) && !empty($mmdvmRows)) {
        establishMmdvmSession($mmdvmRows);
        try {
            logLoginSuccess($username);
        } catch (Exception $e) {
            error_log("Login audit logging failed: " . $e->getMessage());
        }
        return true;
    }

    if (count($ipscRows) === 1) {
        establishIpscSession($ipscRows[0]);
        try {
            logLoginSuccess($username);
        } catch (Exception $e) {
            error_log("Login audit logging failed: " . $e->getMessage());
        }
        return true;
    }

    logLoginFailure($username, 'ambiguous_login');
    return false;
}

/**
 * @deprecated Use authenticateUser()
 */
function authenticateUserByRadioId($radioId, $password)
{
    return authenticateUser((string) $radioId, $password);
}

/**
 * @deprecated Use authenticateUser()
 */
function authenticateUserByCallsign($username, $password)
{
    return authenticateUser($username, $password);
}

/**
 * Whether the browser has an active selfcare login session.
 *
 * @return bool
 */
function isSelfcareLoggedIn()
{
    return isset($_SESSION['user_id'])
        && $_SESSION['user_id'] !== ''
        && isset($_SESSION['int_ids'])
        && is_array($_SESSION['int_ids'])
        && !empty($_SESSION['int_ids']);
}

/**
 * Check and enforce session timeout
 * 
 * Destroys session and redirects to login if inactive for more than configured timeout.
 * Updates last activity timestamp for active sessions.
 * 
 * @return void
 */
function checkSessionTimeout()
{
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
        session_unset();
        session_destroy();
        header("Location: sslogin.php");
        exit();
    }
    
    $_SESSION['last_activity'] = time();
}

/**
 * Initialize secure session configuration
 * 
 * Sets secure session parameters to prevent common session attacks.
 * Should be called before session_start().
 * Simplified for hotspot device-based authentication.
 * 
 * @return void
 */
function initSecureSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = SESSION_TIMEOUT_SECONDS;
    $isSecure = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (! empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_only_cookies', 1);
}

/**
 * Enforce HTTPS connection
 * 
 * Redirects HTTP requests to HTTPS.
 * Call at the beginning of entry point scripts.
 * 
 * @return void
 */
function enforceHttps()
{
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        if (!headers_sent()) {
            header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
            exit();
        }
    }
}



/**
 * Custom INI file parser that handles comments with special characters
 * 
 * Unlike PHP's parse_ini_file(), this parser properly ignores lines starting with #
 * and handles special characters (;, !, &, etc.) in comments.
 * 
 * Based on the original FDMR-Monitor implementation which has been proven to work
 * with config files containing complex comments.
 * 
 * @param string $path Path to the INI file
 * @return array Parsed configuration array with sections
 */
function conf_parser($path)
{
    if (file_exists($path)) {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            error_log("Failed to open config file: $path");
            return array();
        }
        
        $conf = array();
        $stanza = '';
        
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (strlen($line) == 0 || $line[0] == '#') {
                continue;
            }
            
            $lineLength = strlen($line);
            $first = $line[0];
            $last = $line[$lineLength - 1];
            
            // Check for section headers [SECTION]
            if ($first == '[' && $last == ']') {
                $stanza = substr($line, 1, -1);
            } else {
                // Parse key = value pairs
                $line_exp = explode('=', $line, 2);
                if (count($line_exp) == 2) {
                    $key = trim($line_exp[0]);
                    $value = trim($line_exp[1]);
                    
                    // Convert boolean strings to actual booleans
                    if (in_array(strtolower($value), array("yes", "true", "on", "1"))) {
                        $value = true;
                    } elseif (in_array(strtolower($value), array("no", "false", "off", "0"))) {
                        $value = false;
                    }
                    
                    $conf[$stanza][$key] = $value;
                }
            }
        }
        
        fclose($fh);
        return $conf;
    } else {
        return array();
    }
}

/**
 * Connect to the database
 * 
 * Reads configuration from Docker secrets (production) or config file (all deployments).
 * Uses a 2-tier fallback approach for simplicity.
 * 
 * @return mysqli Database connection object
 */
function connectDatabase()
{
    $dbServer = null;
    $dbUsername = null;
    $dbPassword = null;
    $dbName = null;
    $dbPort = null;

    // Priority 1: Docker secrets (production)
    if (file_exists('/run/secrets/db_password')) {
        $fileSize = filesize('/run/secrets/db_password');
        if ($fileSize !== false && $fileSize > 0 && $fileSize <= 1024) {
            $secretContent = @file_get_contents('/run/secrets/db_password', false, null, 0, 1024);
            if ($secretContent !== false) {
                $dbPassword = trim($secretContent);
            } else {
                error_log("db_password secret file exists but cannot be read");
            }
        } else {
            error_log("db_password secret file is invalid (too large, empty, or unreadable)");
        }
    }

    // Priority 2: Config file (all deployments)
    $configFile = '/etc/rysen/fdmr-mon.cfg';
    if (file_exists($configFile)) {
        $config = conf_parser($configFile);
        
        // Use config values if not already set by secrets
        $dbServer = $dbServer ?? ($config['SELF SERVICE']['DB_SERVER'] ?? 'localhost');
        $dbUsername = $dbUsername ?? ($config['SELF SERVICE']['DB_USERNAME'] ?? 'selfcare');
        $dbPassword = $dbPassword ?? ($config['SELF SERVICE']['DB_PASSWORD'] ?? null);
        $dbName = $dbName ?? ($config['SELF SERVICE']['DB_NAME'] ?? 'fdmr');
        $dbPort = $dbPort ?? ($config['SELF SERVICE']['DB_PORT'] ?? 3306);
    } else {
        error_log("Config file not found: $configFile");
    }

    // Validate required values
    if (empty($dbPassword)) {
        error_log("Database password not configured in secrets or config file");
        http_response_code(500);
        die("Database configuration error. Please contact system administrator.");
    }

    if (empty($dbServer) || empty($dbUsername) || empty($dbName)) {
        error_log("Incomplete database configuration");
        http_response_code(500);
        die("Database configuration error. Please contact system administrator.");
    }

    // Translate Docker internal IP to host-accessible address
    // When PHP runs on host, it cannot reach internal Docker IPs (172.16.238.x)
    // MariaDB is exposed on host as localhost:8306 (see docker-compose.yml)
    if (preg_match('/^172\.16\.238\.\d+$/', $dbServer)) {
        $dbServer = '127.0.0.1';
        // Use the exposed port for host access
        if ($dbPort == 3306) {
            $dbPort = 8306;
        }
    }

    // Create connection
    $conn = new mysqli($dbServer, $dbUsername, $dbPassword, $dbName, $dbPort);

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        http_response_code(500);
        die("Database connection error. Please contact system administrator.");
    }

    return $conn;
}

/**
 * Get device details from database
 * 
 * Retrieves all information for a specific device by its int_id.
 * Uses prepared statements to prevent SQL injection.
 * 
 * @param int $intId Device int_id
 * @return array|null Device details array or null if not found
 */
function getDevDetails($intId)
{
    $conn = connectDatabase();
    
    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT * FROM Clients WHERE int_id = ?");
    if (!$stmt) {
        $conn->close();
        return null;
    }
    
    $stmt->bind_param("i", $intId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $devDetails = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $devDetails;
    }

    $stmt->close();
    $conn->close();
    return null;
}

/**
 * Update device options in database
 * 
 * Updates the device configuration options and sets the modified flag.
 * Uses prepared statements to prevent SQL injection.
 * 
 * @param int $intId Device int_id
 * @param string $options Options string in format "KEY=value;KEY=value"
 * @return bool True if update successful, false otherwise
 */
function updateDevOptions($intId, $options)
{
    $conn = connectDatabase();
    
    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("UPDATE Clients SET options = ?, modified = 1 WHERE int_id = ?");
    if (!$stmt) {
        $conn->close();
        return false;
    }
    
    $stmt->bind_param("si", $options, $intId);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();

    return $result;
}

/**
 * Verify user owns the specified device
 * 
 * Checks if the device int_id is in the user's authorized list.
 * 
 * @param int $intId Device int_id to verify
 * @return bool True if user owns device, false otherwise
 */
function verifyDeviceOwnership($intId)
{
    if (!isset($_SESSION['int_ids']) || !is_array($_SESSION['int_ids'])) {
        return false;
    }
    
    return in_array($intId, $_SESSION['int_ids'], true);
}

/**
 * Check if IP address is rate limited
 * Implements exponential backoff - lenient for hotspot users: 10 attempts in 10 min = 30 min lockout
 * 
 * @param string $ip Client IP address
 * @return array ['allowed' => bool, 'wait_time' => int seconds]
 */
function checkRateLimit($ip)
{
    $logFile = '/var/log/rysen/login-attempts.log';
    
    // Get recent attempts
    $attempts = [];
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - (RATE_LIMIT_WINDOW_MINUTES * 60);
        
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) === 2 && $parts[1] === $ip) {
                $timestamp = (int)$parts[0];
                if ($timestamp > $cutoff) {
                    $attempts[] = $timestamp;
                }
            }
        }
    }
    
    // Check if locked out
    if (count($attempts) >= RATE_LIMIT_MAX_ATTEMPTS) {
        $oldestAttempt = min($attempts);
        $lockoutEnd = $oldestAttempt + (RATE_LIMIT_LOCKOUT_MINUTES * 60);
        $waitTime = max(0, $lockoutEnd - time());
        
        if ($waitTime > 0) {
            error_log("Rate limit exceeded for IP: $ip (wait: {$waitTime}s)");
            return ['allowed' => false, 'wait_time' => $waitTime];
        }
    }
    
    return ['allowed' => true, 'wait_time' => 0];
}

/**
 * Record failed login attempt
 * 
 * @param string $ip Client IP address
 * @return void
 */
function recordFailedLogin($ip)
{
    $logFile = '/var/log/rysen/login-attempts.log';
    $logDir = dirname($logFile);
    
    // Ensure log directory exists
    if (!is_dir($logDir)) {
        $oldUmask = umask(0027); // rwxr-x---
        mkdir($logDir, 0750, true);
        umask($oldUmask);
    }
    
    // Ensure log file exists with proper permissions from creation
    if (!file_exists($logFile)) {
        $oldUmask = umask(0137); // rw-r-----
        touch($logFile);
        umask($oldUmask);
    }
    
    $entry = time() . '|' . $ip . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Clear login attempts for IP (on successful login)
 * 
 * @param string $ip Client IP address
 * @return void
 */
function clearLoginAttempts($ip)
{
    $logFile = '/var/log/rysen/login-attempts.log';
    if (!file_exists($logFile)) {
        return;
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filtered = array_filter($lines, function($line) use ($ip) {
        $parts = explode('|', $line);
        return count($parts) !== 2 || $parts[1] !== $ip;
    });
    
    file_put_contents($logFile, implode(PHP_EOL, $filtered) . PHP_EOL, LOCK_EX);
}
?>
