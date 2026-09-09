<?php
/**
 * FreeSTAR-only Device API key bridge for authenticated hotspot selfcare.
 */
require_once 'ssconfunc.php';
require_once 'include/functions.php';
require_once 'include/api-config.php';
require_once 'audit_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function deviceKeyResponse($body, $code = 200)
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit();
}

initSecureSession();
session_start();

if (isset($_SESSION['last_activity'])
    && time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS) {
    session_unset();
    session_destroy();
    deviceKeyResponse(['success' => false, 'error' => 'Session expired'], 401);
}

if (!isSelfcareLoggedIn() || !isset($_SESSION['selected_int_id'])) {
    deviceKeyResponse(['success' => false, 'error' => 'Not authenticated'], 401);
}
$_SESSION['last_activity'] = time();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deviceKeyResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    deviceKeyResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
}

$intId = (int)$_SESSION['selected_int_id'];
if (!verifyDeviceOwnership($intId)) {
    deviceKeyResponse(['success' => false, 'error' => 'Access denied'], 403);
}
$device = getDevDetails($intId);
if (!$device) {
    deviceKeyResponse(['success' => false, 'error' => 'Device not found'], 404);
}
if (isIpscSession() || isIpscDeviceMode($device['mode'])) {
    deviceKeyResponse(['success' => false, 'error' => 'Device API keys are for hotspots only'], 403);
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
if (!$secure) {
    deviceKeyResponse(['success' => false, 'error' => 'HTTPS is required'], 403);
}

$issuer = dashboardDeviceKeyIssuerConfig();
if (empty($issuer['enabled'])) {
    deviceKeyResponse(['success' => false, 'error' => 'Device API key service is unavailable'], 403);
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['status', 'issue', 'rotate', 'revoke'], true)) {
    deviceKeyResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
if (in_array($action, ['rotate', 'revoke'], true)
    && (string)($_POST['confirm'] ?? '') !== '1') {
    deviceKeyResponse(['success' => false, 'error' => 'Confirmation required'], 400);
}

$coreId = dashboardDeviceCoreId($intId);
if ($coreId <= 0) {
    deviceKeyResponse(['success' => false, 'error' => 'Invalid device ID'], 400);
}

if ($action !== 'status') {
    $rateKey = 'device_key_mutations';
    $now = time();
    $attempts = $_SESSION[$rateKey][$coreId] ?? [];
    $attempts = array_values(array_filter($attempts, static function ($stamp) use ($now) {
        return is_int($stamp) && $stamp > $now - 3600;
    }));
    if (count($attempts) >= 5) {
        logSecurityEvent('device_api_key_rate_limit', [
            'radio_id' => $coreId,
            'action' => $action,
        ]);
        deviceKeyResponse(['success' => false, 'error' => 'Too many key changes. Try again later.'], 429);
    }
    $attempts[] = $now;
    $_SESSION[$rateKey][$coreId] = $attempts;
}

$result = dashboardDeviceKeyIssuerRequest($issuer, [
    'action' => $action,
    'radio_id' => $coreId,
    'key_id' => 'device-' . $coreId,
]);
if (isset($result['error'])) {
    $message = trim(strip_tags((string)$result['error']));
    if ($message === '') {
        $message = 'Device API key request failed';
    }
    $message = substr($message, 0, 200);
    logSecurityEvent('device_api_key_change', [
        'radio_id' => $coreId,
        'action' => $action,
        'result' => 'error',
        'status' => (int)($result['code'] ?? 502),
    ]);
    deviceKeyResponse(
        ['success' => false, 'error' => $message],
        max(400, min(599, (int)($result['code'] ?? 502)))
    );
}

logSecurityEvent('device_api_key_change', [
    'radio_id' => $coreId,
    'action' => $action,
    'result' => 'ok',
]);

$response = [
    'success' => true,
    'radio_id' => $coreId,
    'has_key' => !empty($result['has_key']),
];
foreach (['key_id', 'created_at', 'token'] as $field) {
    if (array_key_exists($field, $result)) {
        $response[$field] = $result[$field];
    }
}
deviceKeyResponse($response);
