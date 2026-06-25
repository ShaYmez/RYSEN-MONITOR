<?php
/**
 * Secure Error Handler
 * Prevents information disclosure via stack traces and detailed error messages
 * 
 * This file should be included at the top of all PHP entry points before any other code.
 * It configures PHP to log errors securely without exposing sensitive information to users.
 */

// Production error handling - disable display, enable logging
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/rysen/php-errors.log');
error_reporting(E_ALL);

/**
 * Custom error handler
 * Logs full error details securely while showing generic messages to users
 */
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log full error details securely
    error_log(sprintf(
        "[%s] Error %d: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $errno,
        $errstr,
        $errfile,
        $errline
    ));
    
    // Return generic error to user for fatal errors
    if ($errno === E_ERROR || $errno === E_USER_ERROR) {
        http_response_code(500);
        echo "An error occurred. Please contact support.";
        exit(1);
    }
    
    // Let PHP handle non-fatal errors (warnings, notices)
    return false;
});

/**
 * Custom exception handler
 * Logs exception details securely while showing generic messages to users
 */
set_exception_handler(function($exception) {
    // Log exception details with full stack trace
    error_log(sprintf(
        "[%s] Uncaught exception: %s in %s on line %d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));
    
    // Generic message to user
    http_response_code(500);
    echo "An unexpected error occurred. Please try again later.";
    exit(1);
});

/**
 * Shutdown handler to catch fatal errors
 * Catches errors that cannot be handled by the error handler
 */
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log(sprintf(
            "[%s] Fatal error: %s in %s on line %d",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        ));
        
        // Clear any partial output
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        http_response_code(500);
        echo "A fatal error occurred. Please contact support.";
    }
});
?>
