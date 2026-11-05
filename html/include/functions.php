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
 * Map a stored ALIAS value onto the selfcare switch.
 *
 * Server default is -1 and is omitted from the options string.
 * 1 and 0 are explicit on and off for this hotspot or repeater.
 *
 * @param mixed $value
 * @return string '-1', '0', or '1'
 */
function normalizeAliasOption($value) {
    $norm = strtolower(trim((string) $value));
    if (in_array($norm, ['1', 'true', 'yes'], true)) {
        return '1';
    }
    if (in_array($norm, ['0', 'false', 'no'], true)) {
        return '0';
    }
    return '-1';
}

/**
 * ALIAS= fragment, empty when the switch is server default.
 *
 * @param mixed $value
 * @return string
 */
function aliasOptionFragment($value) {
    $norm = normalizeAliasOption($value);
    if ($norm === '0' || $norm === '1') {
        return 'ALIAS=' . $norm . ';';
    }
    return '';
}

/**
 * Resolve an optional device boolean onto its effective server value.
 *
 * @param mixed $value
 * @param bool $serverDefault
 * @return string '0' or '1'
 */
function effectiveBooleanOption($value, $serverDefault)
{
    $normalized = normalizeAliasOption($value);
    if ($normalized === '0' || $normalized === '1') {
        return $normalized;
    }
    return $serverDefault ? '1' : '0';
}

/**
 * Show a saved choice, otherwise the live server value.
 * An unreadable config has no live value, so the control stays blank.
 *
 * @param mixed $value
 * @param array $defaults
 * @param string $defaultKey
 * @return string
 */
function displayedBooleanOption($value, array $defaults, $defaultKey)
{
    $normalized = normalizeAliasOption($value);
    if ($normalized === '0' || $normalized === '1') {
        return $normalized;
    }
    if (empty($defaults['available'])) {
        return '';
    }
    return !empty($defaults[$defaultKey]) ? '1' : '0';
}

/**
 * @param mixed $value
 * @param array $defaults
 * @param string $defaultKey
 * @return string
 */
function displayedScalarOption($value, array $defaults, $defaultKey)
{
    if ($value !== null && $value !== '') {
        return (string) $value;
    }
    if (empty($defaults['available'])) {
        return '';
    }
    return (string) ($defaults[$defaultKey] ?? '');
}

/**
 * @param array $defaults
 * @param string $key
 * @param bool $boolean
 * @return string
 */
function serverDefaultAttribute(array $defaults, $key, $boolean = false)
{
    if (empty($defaults['available'])) {
        return '';
    }
    if ($boolean) {
        return !empty($defaults[$key]) ? '1' : '0';
    }
    return (string) ($defaults[$key] ?? '');
}

/**
 * Handle device selection from session and POST data
 * 
 * Validates and authorizes device selection to ensure users can only
 * access devices they own. A missing or invalid CSRF token leaves the current device selected.
 * 
 * @return int|null Selected device int_id
 */
function handleDeviceSelection() {
    // Handle form submission
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['int_id'])) {
        if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
            error_log("Device selection rejected: invalid CSRF token");
        } else {
            $int_id = filter_var($_POST['int_id'], FILTER_VALIDATE_INT);

            if ($int_id !== false && !empty($int_id) && verifyDeviceOwnership($int_id)) {
                $_SESSION['selected_int_id'] = $int_id;
                return $int_id;
            }

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
    header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
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
    $allowedKeys = ['TS1', 'TS2', 'DIAL', 'VOICE', 'LANG', 'SINGLE', 'TIMER', 'STICKY', 'ALIAS', 'PASS', 'DISC'];
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

        if ($key === 'ALIAS' && !in_array($value, ['0', '1'], true)) {
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
 * Sanitize repeater options for IPSC and Hytera (TS1/TS2 and ALIAS).
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

    $allowedKeys = ['TS1', 'TS2', 'ALIAS'];
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

        if ($key === 'ALIAS' && !in_array($value, ['0', '1'], true)) {
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
 * True when TS1 or TS2 lists a numbered static talkgroup.
 * Empty TS1=;TS2=; is not statics (same rule as RYSEN sticky).
 *
 * @param array $parsed
 * @return bool
 */
function parsedHasStaticTalkgroups(array $parsed) {
    foreach (['TS1', 'TS2'] as $key) {
        if (!isset($parsed[$key])) {
            continue;
        }
        $value = is_array($parsed[$key]) ? implode(',', $parsed[$key]) : (string) $parsed[$key];
        if (trim($value, ", \t\n\r\0\x0B") !== '') {
            return true;
        }
    }
    return false;
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

    if (!empty($parsed['LANG']) && (!isset($parsed['VOICE']) || (string) $parsed['VOICE'] === '1')) {
        $genText .= 'LANG=' . $parsed['LANG'] . ';';
    }

    if (isset($parsed['SINGLE']) && (string) $parsed['SINGLE'] !== '-1' && $parsed['SINGLE'] !== '') {
        $genText .= 'SINGLE=' . $parsed['SINGLE'] . ';';
    }

    if (!parsedHasStaticTalkgroups($parsed)
        && isset($parsed['STICKY']) && (string) $parsed['STICKY'] !== '-1' && $parsed['STICKY'] !== '') {
        $genText .= 'STICKY=' . $parsed['STICKY'] . ';';
    }

    $genText .= aliasOptionFragment($parsed['ALIAS'] ?? '-1');

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
    if ($isIpsc) {
        $parsed = array_intersect_key($parsed, array_flip(['TS1', 'TS2', 'ALIAS']));
    }
    $parsed['DISC'] = '1';

    return buildOptionsStringFromParsed($parsed, $isIpsc, $dialActive);
}

/**
 * Validate a repeater disconnect string (TS1/TS2, ALIAS, and DISC).
 *
 * @param string $options Built disconnect request
 * @return string|false
 */
function sanitizeRepeaterDisconnectOptions($options)
{
    $options = strip_tags((string) $options);
    $options = str_replace("\0", "", $options);

    if ($options === '') {
        return '';
    }

    if (!preg_match('/^([A-Z0-9]+=[A-Za-z0-9_,]*;?)+$/', $options)) {
        return false;
    }

    $allowedKeys = ['TS1', 'TS2', 'ALIAS', 'DISC'];
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
        if ($key === 'DISC' && !in_array($value, ['0', '1'], true)) {
            return false;
        }
        if ($key === 'ALIAS' && !in_array($value, ['0', '1'], true)) {
            return false;
        }
        if (($key === 'TS1' || $key === 'TS2') && $value !== '' && !preg_match('/^[0-9,]+$/', $value)) {
            return false;
        }
    }

    return $options;
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
 * Parse the colon-delimited RYSEN configuration without exposing unrelated
 * values (the file also contains credentials).
 *
 * @param string $path
 * @return array<string, array<string, string>>
 */
function parseRysenConfigSections($path = '/etc/rysen/rysen.cfg')
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $sections = [];
    $section = '';
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = strtoupper(trim(substr($line, 1, -1)));
            if ($section !== '' && !isset($sections[$section])) {
                $sections[$section] = [];
            }
            continue;
        }
        if ($section === '') {
            continue;
        }

        $colonAt = strpos($line, ':');
        $equalsAt = strpos($line, '=');
        if ($colonAt === false || ($equalsAt !== false && $equalsAt < $colonAt)) {
            $separatorAt = $equalsAt;
        } else {
            $separatorAt = $colonAt;
        }
        if ($separatorAt === false) {
            continue;
        }

        $key = strtoupper(trim(substr($line, 0, $separatorAt)));
        if ($key !== '') {
            $sections[$section][$key] = trim(substr($line, $separatorAt + 1));
        }
    }
    fclose($handle);

    return $sections;
}

/**
 * RYSEN-style boolean value.
 *
 * @param mixed $value
 * @param bool $fallback
 * @return bool
 */
function rysenConfigBool($value, $fallback = false)
{
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return (bool) $fallback;
}

/**
 * Effective function defaults for the selected client type.
 *
 * The dashboard and RYSEN run on the same master, so the local rysen.cfg is
 * the source of truth. The first enabled stanza of the matching mode is used.
 *
 * @param mixed $deviceMode Clients.mode
 * @param string $path
 * @return array<string, mixed>
 */
function loadRysenFunctionDefaults($deviceMode, $path = '/etc/rysen/rysen.cfg')
{
    $mode = (int) $deviceMode;
    $systemMode = $mode === 0 ? 'IPSC' : ($mode === -1 ? 'HYTERA' : 'MASTER');
    $defaults = [
        'voice' => false,
        'language' => 'en_GB',
        'single' => $systemMode === 'MASTER',
        'sticky' => false,
        'alias' => true,
        'timer' => $systemMode === 'MASTER' ? 60 : 10,
        'available' => false,
    ];

    $sections = parseRysenConfigSections($path);
    if (empty($sections)) {
        return $defaults;
    }

    $global = $sections['GLOBAL'] ?? [];
    $defaults['alias'] = rysenConfigBool($global['TALKER_ALIAS'] ?? null, true);

    $matching = null;
    foreach ($sections as $name => $values) {
        if ($name === 'GLOBAL' || strtoupper((string) ($values['MODE'] ?? '')) !== $systemMode) {
            continue;
        }
        if (!rysenConfigBool($values['ENABLED'] ?? null, false)) {
            continue;
        }
        $matching = $values;
        break;
    }
    if ($matching === null) {
        $defaults['available'] = false;
        return $defaults;
    }

    $defaults['voice'] = rysenConfigBool($matching['VOICE_IDENT'] ?? null, $defaults['voice']);
    $defaults['single'] = rysenConfigBool($matching['SINGLE_MODE'] ?? null, $defaults['single']);
    $defaults['sticky'] = rysenConfigBool($matching['STICKY_TG'] ?? null, $defaults['sticky']);
    if (array_key_exists('ALIAS', $matching)) {
        $defaults['alias'] = rysenConfigBool($matching['ALIAS'], $defaults['alias']);
    }
    $language = trim((string) ($matching['ANNOUNCEMENT_LANGUAGE'] ?? ''));
    if ($language !== '') {
        $defaults['language'] = $language;
    }
    $timer = filter_var($matching['DEFAULT_UA_TIMER'] ?? null, FILTER_VALIDATE_INT);
    if ($timer !== false && $timer >= 0) {
        $defaults['timer'] = $timer;
    }
    $defaults['available'] = true;

    return $defaults;
}

/**
 * Function keys a device can override. Repeaters only have talker alias here.
 *
 * @param bool $isRepeater
 * @return string[]
 */
function selfcareFunctionKeys($isRepeater)
{
    return $isRepeater
        ? ['ALIAS']
        : ['DIAL', 'VOICE', 'LANG', 'SINGLE', 'STICKY', 'ALIAS', 'TIMER'];
}

/**
 * Remove saved function overrides so the device follows the master again.
 * Static talkgroups and the selfcare password stay in place.
 *
 * @param string|null $optionsString
 * @param bool $isRepeater
 * @return string
 */
function resetSelfcareFunctionOptions($optionsString, $isRepeater)
{
    $parsed = parseDeviceOptions($optionsString ?? '');
    foreach (selfcareFunctionKeys($isRepeater) as $key) {
        unset($parsed[$key]);
    }
    unset($parsed['DISC']);

    return buildOptionsStringFromParsed($parsed, $isRepeater, false);
}

/**
 * Drop posted function values that already match the freshly read config.
 * When the config cannot be read, keep saved function values instead of
 * replacing them with built-in guesses.
 *
 * @param string|null $postedOptions
 * @param string|null $existingOptions
 * @param array $defaults
 * @param bool $isRepeater
 * @return string
 */
function normalizeSelfcareOptions($postedOptions, $existingOptions, array $defaults, $isRepeater)
{
    $posted = parseDeviceOptions($postedOptions ?? '');
    if (empty($defaults['available'])) {
        $existing = parseDeviceOptions($existingOptions ?? '');
        foreach (selfcareFunctionKeys($isRepeater) as $key) {
            if (!array_key_exists($key, $posted) && array_key_exists($key, $existing)) {
                $posted[$key] = $existing[$key];
            }
        }
    } else {
        $serverBit = function ($key) use ($defaults) {
            return !empty($defaults[$key]) ? '1' : '0';
        };
        foreach (['VOICE' => 'voice', 'SINGLE' => 'single', 'STICKY' => 'sticky', 'ALIAS' => 'alias'] as $option => $key) {
            if (isset($posted[$option]) && (string) $posted[$option] === $serverBit($key)) {
                unset($posted[$option]);
            }
        }
        if (isset($posted['TIMER']) && (int) $posted['TIMER'] === (int) ($defaults['timer'] ?? 0)) {
            unset($posted['TIMER']);
        }
        $voiceOn = array_key_exists('VOICE', $posted)
            ? (string) $posted['VOICE'] === '1'
            : !empty($defaults['voice']);
        if (!$voiceOn || (isset($posted['LANG']) && (string) $posted['LANG'] === (string) ($defaults['language'] ?? ''))) {
            unset($posted['LANG']);
        }
        if (isset($posted['DIAL']) && (int) $posted['DIAL'] <= 0) {
            unset($posted['DIAL']);
        }
    }

    $dialActive = !empty($posted['DIAL']) && (int) $posted['DIAL'] > 0;
    return buildOptionsStringFromParsed($posted, $isRepeater, $dialActive);
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
    if (isHyteraDeviceMode($devDetails['mode'])) {
        return $intId . ' — ' . trim($devDetails['callsign']) . ' (Hytera)';
    }

    return (string) $intId;
}

/**
 * Short Hytera model from an RDAC hardware string.
 *
 * @param mixed $hardware RDAC hardware field
 * @return string Model abbreviation or pending placeholder
 */
function hyteraMetadataModel($hardware)
{
    $hardware = trim((string) ($hardware ?? ''));
    if ($hardware === '') {
        return 'Pending RDAC';
    }
    $parts = preg_split('/[-\s]/', $hardware);
    $model = trim((string) ($parts[0] ?? ''));
    return $model !== '' ? $model : 'Pending RDAC';
}

/**
 * Hytera TX/RX display from RDAC Hertz values.
 *
 * @param mixed $txFrequency
 * @param mixed $rxFrequency
 * @return string Formatted pair or pending placeholder
 */
function hyteraMetadataFrequency($txFrequency, $rxFrequency)
{
    $tx = (int) ($txFrequency ?? 0);
    $rx = (int) ($rxFrequency ?? 0);
    if ($tx <= 0 || $rx <= 0) {
        return 'Pending RDAC';
    }
    return number_format($tx / 1000000, 6) . ' / ' . number_format($rx / 1000000, 6) . ' MHz';
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
