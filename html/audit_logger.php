<?php
/**
 * Security Audit Logger
 * Centralized logging for security-relevant events
 * 
 * This file provides functions to log security events in a structured format
 * for monitoring, auditing, and incident response.
 */

/**
 * Log a security-relevant event
 * 
 * Logs events in JSON format with timestamp, event type, IP address, user, and details.
 * Events are written to /var/log/rysen/security-audit.log
 * Non-blocking - failures won't prevent authentication.
 * 
 * @param string $event Event type (e.g., 'login_success', 'login_failure', 'rate_limit_exceeded')
 * @param array $details Additional event details (optional)
 * @return void
 */
function logSecurityEvent($event, $details = [])
{
    try {
        $logFile = '/var/log/rysen/security-audit.log';
        $logDir = dirname($logFile);
        
        // Ensure log directory exists with proper permissions
        if (!is_dir($logDir)) {
            $oldUmask = umask(0027); // rwxr-x---
            if (!mkdir($logDir, 0750, true)) {
                throw new Exception("Failed to create log directory: $logDir");
            }
            umask($oldUmask);
        }
        
        // Ensure log file exists with proper permissions from creation
        if (!file_exists($logFile)) {
            $oldUmask = umask(0137); // rw-r-----
            if (!touch($logFile)) {
                throw new Exception("Failed to create log file: $logFile");
            }
            umask($oldUmask);
        }
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user' => $_SESSION['user_id'] ?? 'anonymous',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'details' => $details
        ];
        
        $logLine = json_encode($entry) . PHP_EOL;
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        // Non-blocking - log to PHP error log but don't fail
        error_log("Security audit logging failed: " . $e->getMessage());
    }
}

/**
 * Log successful login
 * 
 * @param string $username Username that successfully logged in
 * @return void
 */
function logLoginSuccess($username)
{
    logSecurityEvent('login_success', ['username' => $username]);
}

/**
 * Log failed login attempt
 * 
 * @param string $username Username that failed to log in
 * @param string $reason Reason for failure (e.g., 'invalid_password', 'invalid_username')
 * @return void
 */
function logLoginFailure($username, $reason = 'invalid_credentials')
{
    logSecurityEvent('login_failure', [
        'username' => $username,
        'reason' => $reason
    ]);
}

/**
 * Log rate limit exceeded event
 * 
 * @param int $attempts Number of failed attempts
 * @return void
 */
function logRateLimitExceeded($attempts)
{
    logSecurityEvent('rate_limit_exceeded', ['attempts' => $attempts]);
}

/**
 * Log password change
 * 
 * @param int $userId User ID that changed password
 * @return void
 */
function logPasswordChange($userId)
{
    logSecurityEvent('password_changed', ['user_id' => $userId]);
}

/**
 * Log device configuration change
 * 
 * @param int $deviceId Device ID that was modified
 * @param string $field Field that was changed
 * @return void
 */
function logConfigChange($deviceId, $field)
{
    logSecurityEvent('config_changed', [
        'device_id' => $deviceId,
        'field' => $field
    ]);
}

/**
 * Log user logout
 * 
 * @return void
 */
function logLogout()
{
    logSecurityEvent('logout');
}

/**
 * Log session timeout
 * 
 * @return void
 */
function logSessionTimeout()
{
    logSecurityEvent('session_timeout');
}
?>
