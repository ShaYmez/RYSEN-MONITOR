<?php
/**
 * Shared Helper Functions for Selfcare
 * 
 * Common utility functions used across the selfcare module
 */

/**
 * Parse device options string into associative array
 * 
 * @param string $optionsString Options in format "KEY1=val1;KEY2=val2,val3"
 * @return array Parsed options array
 */
function parseDeviceOptions($optionsString) {
    if (empty($optionsString)) {
        return [];
    }
    
    $options = [];
    $pairs = explode(';', trim($optionsString, ';'));
    
    foreach ($pairs as $pair) {
        if (empty($pair)) continue;
        
        if (strpos($pair, '=') === false) continue;
        
        list($key, $value) = explode('=', $pair, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Handle comma-separated values (TS1, TS2)
        if (strpos($value, ',') !== false) {
            $options[$key] = array_map('trim', explode(',', $value));
        } else {
            $options[$key] = $value;
        }
    }
    
    return $options;
}

/**
 * Get specific option value from options string
 * 
 * @param string $optionsString Full options string
 * @param string $key Option key to retrieve
 * @param mixed $default Default value if key not found
 * @return mixed Option value or default
 */
function getDeviceOption($optionsString, $key, $default = null) {
    $options = parseDeviceOptions($optionsString);
    return isset($options[$key]) ? $options[$key] : $default;
}

/**
 * Handle device selection from session and POST data
 * 
 * Validates and authorizes device selection to ensure users can only
 * access devices they own. CSRF token optional for device selection (lenient for hotspot users).
 * 
 * @return int|null Selected device int_id
 */
function handleDeviceSelection() {
    // Handle form submission
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['int_id'])) {
        // CSRF token check is lenient - warn but allow if missing (device selection is low risk)
        $csrfValid = isset($_POST['csrf_token']) && verifyCSRFToken($_POST['csrf_token']);
        if (!$csrfValid) {
            error_log("Device selection without valid CSRF token - allowing for compatibility");
        }
        
        $int_id = filter_var($_POST['int_id'], FILTER_VALIDATE_INT);
        
        // Verify the device belongs to the user
        if ($int_id !== false && !empty($int_id) && verifyDeviceOwnership($int_id)) {
            $_SESSION['selected_int_id'] = $int_id;
            return $int_id;
        } else {
            error_log("Invalid device selection attempt: int_id=$int_id");
        }
    }
    
    // Auto-select first device if none selected
    if (!isset($_SESSION['selected_int_id']) && !empty($_SESSION['int_ids'])) {
        $_SESSION['selected_int_id'] = $_SESSION['int_ids'][0];
    }
    
    // Verify selected device still belongs to user
    if (isset($_SESSION['selected_int_id']) && verifyDeviceOwnership($_SESSION['selected_int_id'])) {
        return $_SESSION['selected_int_id'];
    }
    
    return null;
}

/**
 * Redirect to current page (POST-redirect-GET pattern)
 */
function redirectToSelf() {
    header("Refresh: 0; url=" . $_SERVER['PHP_SELF']);
    exit();
}

/**
 * Sanitize and validate options string
 * 
 * Validates that the options string follows the expected format and
 * contains only allowed characters and keys.
 * 
 * @param string $options Raw options string
 * @return string|false Sanitized options string, empty string when no selfcare override, or false if invalid
 */
function sanitizeOptions($options) {
    // Remove any HTML tags
    $options = strip_tags($options);
    
    // Remove any null bytes
    $options = str_replace("\0", "", $options);

    // Empty form = no selfcare override (no TS keys and no function keys changed)
    if ($options === '') {
        return '';
    }

    if (!preg_match('/^([A-Z0-9]+=[A-Za-z0-9_,]*;?)+$/', $options)) {
        return false;
    }
    
    // Parse and validate individual keys
    $allowedKeys = ['TS1', 'TS2', 'DIAL', 'VOICE', 'LANG', 'SINGLE', 'TIMER', 'STICKY', 'PASS', 'DISC'];
    $pairs = explode(';', trim($options, ';'));
    
    foreach ($pairs as $pair) {
        if (empty($pair)) continue;
        
        if (strpos($pair, '=') === false) {
            return false; // Invalid format
        }
        
        list($key, $value) = explode('=', $pair, 2);
        
        // Verify key is in allowed list
        if (!in_array($key, $allowedKeys, true)) {
            return false; // Unauthorized key
        }

        // TS1=/TS2= clears statics on the server; other keys must have a value
        if ($value === '' && !in_array($key, ['TS1', 'TS2'], true)) {
            return false;
        }

        if ($key === 'DISC' && !in_array($value, ['0', '1'], true)) {
            return false;
        }

        if ($key === 'TS1' || $key === 'TS2') {
            if ($value !== '' && !preg_match('/^[0-9,]+$/', $value)) {
                return false;
            }
        }
    }
    
    return $options;
}

/**
 * Sanitize IPSC repeater options (same allowlist as hotspot selfcare).
 *
 * @param string $options Raw options string
 * @return string|false Sanitized options string, empty string when no selfcare override, or false if invalid
 */
function sanitizeIpscOptions($options)
{
    $options = strip_tags($options);
    $options = str_replace("\0", "", $options);

    if ($options === '') {
        return '';
    }

    if (!preg_match('/^([A-Z0-9]+=[A-Za-z0-9_,]*;?)+$/', $options)) {
        return false;
    }

    $allowedKeys = ['TS1', 'TS2'];
    $pairs = explode(';', trim($options, ';'));

    foreach ($pairs as $pair) {
        if (empty($pair)) {
            continue;
        }

        if (strpos($pair, '=') === false) {
            return false;
        }

        list($key, $value) = explode('=', $pair, 2);

        if (!in_array($key, $allowedKeys, true)) {
            return false;
        }

        if ($key === 'TS1' || $key === 'TS2') {
            if ($value !== '' && !preg_match('/^[0-9,]+$/', $value)) {
                return false;
            }
        }
    }

    return $options;
}

/**
 * Build an options string from a parsed options array (hotspot function keys).
 *
 * @param array $parsed
 * @param bool $isIpsc
 * @param bool $dialActive
 * @return string
 */
function buildOptionsStringFromParsed(array $parsed, $isIpsc, $dialActive = false) {
    $genText = '';
    $staticApplicable = $isIpsc || !$dialActive;

    if ($staticApplicable) {
        foreach (['TS1', 'TS2'] as $tsKey) {
            if (!isset($parsed[$tsKey])) {
                continue;
            }
            $value = is_array($parsed[$tsKey]) ? implode(',', $parsed[$tsKey]) : (string) $parsed[$tsKey];
            $genText .= $tsKey . '=' . $value . ';';
        }
    } elseif (isset($parsed['TS2'])) {
        $value = is_array($parsed['TS2']) ? implode(',', $parsed['TS2']) : (string) $parsed['TS2'];
        $genText .= 'TS2=' . $value . ';';
    }

    if (!empty($parsed['DIAL']) && (int) $parsed['DIAL'] > 0) {
        $genText .= 'DIAL=' . $parsed['DIAL'] . ';';
    }

    if (isset($parsed['VOICE']) && (string) $parsed['VOICE'] !== '-1' && $parsed['VOICE'] !== '') {
        $genText .= 'VOICE=' . $parsed['VOICE'] . ';';
    }

    if (isset($parsed['VOICE']) && (string) $parsed['VOICE'] === '1' && !empty($parsed['LANG'])) {
        $genText .= 'LANG=' . $parsed['LANG'] . ';';
    }

    if (isset($parsed['SINGLE']) && (string) $parsed['SINGLE'] !== '-1' && $parsed['SINGLE'] !== '') {
        $genText .= 'SINGLE=' . $parsed['SINGLE'] . ';';
    }

    if (isset($parsed['STICKY']) && (string) $parsed['STICKY'] !== '-1' && $parsed['STICKY'] !== '') {
        $genText .= 'STICKY=' . $parsed['STICKY'] . ';';
    }

    if (!empty($parsed['TIMER']) && (int) $parsed['TIMER'] > 0) {
        $genText .= 'TIMER=' . $parsed['TIMER'] . ';';
    }

    if (!empty($parsed['PASS'])) {
        $genText .= 'PASS=' . $parsed['PASS'] . ';';
    }

    if (isset($parsed['DISC']) && (string) $parsed['DISC'] === '1') {
        $genText .= 'DISC=1;';
    }

    return $genText;
}

/**
 * Build selfcare disconnect request — append DISC=1 without changing static talkgroups.
 *
 * @param string|null $optionsString Current Clients.options value
 * @param bool $isIpsc
 * @param bool $dialActive Hotspot dial-a-tg active (TS UI hidden)
 * @return string
 */
function buildDisconnectRequest($optionsString, $isIpsc, $dialActive = false) {
    $parsed = parseDeviceOptions($optionsString ?? '');
    $parsed['DISC'] = '1';

    return buildOptionsStringFromParsed($parsed, $isIpsc, $dialActive);
}

/**
 * Whether stored options are empty / unset (no selfcare override).
 *
 * @param string|null $optionsString
 * @return bool
 */
function isEmptyDeviceOptions($optionsString) {
    return $optionsString === null || $optionsString === '';
}

/**
 * Label for selfcare device picker.
 *
 * @param int $intId Device int_id
 * @param array $devDetails Row from Clients
 * @return string
 */
function formatDevicePickerLabel($intId, $devDetails)
{
    if (isIpscDeviceMode($devDetails['mode'])) {
        return $intId . ' — ' . trim($devDetails['callsign']) . ' (IPSC)';
    }

    return (string) $intId;
}

/**
 * Generate CSRF token for form protection
 * 
 * Creates a unique token stored in the session to prevent CSRF attacks.
 * 
 * @return string CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * 
 * Validates that the submitted token matches the session token.
 * 
 * @param string $token Token to verify
 * @return bool True if token is valid, false otherwise
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize output for HTML display
 * 
 * Escapes special characters to prevent XSS attacks.
 * 
 * @param string $string String to sanitize
 * @return string Sanitized string safe for HTML output
 */
function escapeHtml($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
