<?php
/**
 * Selfcare Configuration Functions
 * 
 * Database connection and user authentication functions for the selfcare module
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

/**
 * Authenticate user with callsign and password
 * 
 * Validates user credentials against the database using PBKDF2 hashing.
 * Sets session variables for authenticated users.
 * Uses prepared statements to prevent SQL injection.
 * 
 * @param string $username User callsign
 * @param string $password User password
 * @return bool|string True if authentication successful, error message string otherwise
 */
function authenticateUser($username, $password)
{
    $conn = connectDatabase();
    
    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT int_id, callsign, psswd FROM Clients WHERE callsign = ?");
    if (!$stmt) {
        error_log("Database prepare error: " . $conn->error);
        $conn->close();
        return "Database error. Please contact administrator.";
    }
    
    $stmt->bind_param("s", $username);
    if (!$stmt->execute()) {
        error_log("Database execute error: " . $stmt->error);
        $stmt->close();
        $conn->close();
        return "Database error. Please contact administrator.";
    }
    
    $result = $stmt->get_result();
    $int_ids = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $storedPassword = $row['psswd'];
            $authenticated = false;
            
            // Use PBKDF2 SHA256 (original simple method for hotspot users)
            if (hash_pbkdf2("sha256", $password, "RYSEN", 2000) === $storedPassword) {
                $authenticated = true;
            }
            // Also try bcrypt for compatibility (but don't migrate)
            elseif (password_verify($password, $storedPassword)) {
                $authenticated = true;
            }
            
            if ($authenticated) {
                $_SESSION['user_id'] = $row['callsign'];
                $int_ids[] = $row['int_id'];
            }
        }
    }
    
    $stmt->close();

    if (!empty($int_ids)) {
        $_SESSION['int_ids'] = $int_ids;
        $conn->close();
        
        // Log successful login (non-blocking - use try-catch for visibility)
        try {
            logLoginSuccess($username);
        } catch (Exception $e) {
            error_log("Login audit logging failed: " . $e->getMessage());
        }
        
        return true;
    } else {
        $conn->close();
        
        // Log failed login (non-blocking - use try-catch for visibility)
        try {
            logLoginFailure($username);
        } catch (Exception $e) {
            error_log("Failed login audit logging failed: " . $e->getMessage());
        }
        
        return false;
    }
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
    // Prevent JavaScript access to session cookie
    ini_set('session.cookie_httponly', 1);
    
    // Only send cookie over HTTPS if available (but don't require it)
    $isSecure = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (! empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
    
    // Relaxed SameSite for device-based authentication
    ini_set('session.cookie_samesite', 'Lax');
    
    // Prevent session ID in URLs
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
