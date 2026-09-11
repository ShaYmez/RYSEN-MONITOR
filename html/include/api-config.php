<?php
/**
 * Dashboard API endpoint configuration loader.
 *
 * Primary source: /etc/rysen/systemx-network.ini
 * Legacy fallback: dashboard/config/api-endpoints.php (deprecated)
 * Built-in FreeSTAR defaults apply when neither file is present.
 *
 * This file lives under include/ so it is updated from the repo on upgrade.
 */

function dashboardLoadNetworkIni($path = '/etc/rysen/systemx-network.ini')
{
    if (!is_file($path)) {
        return null;
    }
    $ini = @parse_ini_file($path, true, INI_SCANNER_TYPED);
    return is_array($ini) ? $ini : null;
}

/**
 * Match System-X-Installer freestar_lastheard_wanted exactly.
 */
function dashboardIsFreestarApiHost($ini)
{
    if (!is_array($ini)) {
        return false;
    }
    $network = isset($ini['network']) && is_array($ini['network']) ? $ini['network'] : [];
    $api = isset($ini['api']) && is_array($ini['api']) ? $ini['api'] : [];
    $lastheard = trim((string)($api['lastheard_api_url'] ?? ''));
    if ($lastheard !== '') {
        return preg_match('#://api\.freestar\.network/#', $lastheard) === 1;
    }
    return strpos((string)($network['org_url'] ?? ''), 'freestar.network') !== false
        && strpos((string)($api['status_api_url'] ?? ''), 'api.freestar.network') !== false;
}

function dashboardDeviceKeyIssuerConfig($path = '/etc/rysen/systemx-network.ini')
{
    $ini = dashboardLoadNetworkIni($path);
    $api = is_array($ini) && isset($ini['api']) && is_array($ini['api']) ? $ini['api'] : [];
    $url = trim((string)($api['device_key_issuer_url'] ?? ''));
    $token = trim((string)($api['device_key_issuer_token'] ?? ''));
    $parts = $url !== '' ? @parse_url($url) : false;
    $safeUrl = is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'api.freestar.network'
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && (string)($parts['path'] ?? '') === '/v2/internal/device-keys';

    return [
        'enabled' => dashboardIsFreestarApiHost($ini) && $safeUrl && $token !== '',
        'url' => $safeUrl ? $url : '',
        'token' => $token,
    ];
}

function dashboardDeviceControlConfig($path = '/etc/rysen/systemx-network.ini')
{
    $issuer = dashboardDeviceKeyIssuerConfig($path);
    $ini = dashboardLoadNetworkIni($path);
    $api = is_array($ini) && isset($ini['api']) && is_array($ini['api']) ? $ini['api'] : [];
    $url = trim((string)($api['device_control_url'] ?? ''));
    $parts = $url !== '' ? @parse_url($url) : false;
    $safeUrl = is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'api.freestar.network'
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && (string)($parts['path'] ?? '') === '/v2/internal/device-control';

    return [
        'enabled' => !empty($issuer['enabled']) && $safeUrl,
        'url' => $safeUrl ? $url : '',
        'token' => (string)($issuer['token'] ?? ''),
    ];
}

function dashboardDeviceCoreId($intId)
{
    $digits = preg_replace('/\D+/', '', (string)$intId);
    if (!is_string($digits) || $digits === '') {
        return 0;
    }
    if (strlen($digits) === 9) {
        $digits = substr($digits, 0, 7);
    }
    return (int)$digits;
}

function dashboardDeviceKeyIssuerRequest($config, $payload)
{
    if (empty($config['enabled']) || empty($config['url']) || empty($config['token'])) {
        return ['error' => 'Device API key service is unavailable', 'code' => 503];
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return ['error' => 'Invalid Device API key request', 'code' => 400];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => false,
            'max_redirects' => 0,
            'header' => [
                'Authorization: Bearer ' . $config['token'],
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json),
            ],
            'content' => $json,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($config['url'], false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $message = is_array($body) && isset($body['error'])
            ? (string)$body['error']
            : 'Device API key service is unavailable';
        return ['error' => $message, 'code' => $status >= 400 ? $status : 502];
    }
    return $body;
}

function dashboardDeviceControlRequest($config, $payload)
{
    if (empty($config['enabled']) || empty($config['url']) || empty($config['token'])) {
        return ['error' => 'Runtime controls are unavailable', 'code' => 503];
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return ['error' => 'Invalid runtime control request', 'code' => 400];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => false,
            'max_redirects' => 0,
            'header' => [
                'Authorization: Bearer ' . $config['token'],
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json),
            ],
            'content' => $json,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($config['url'], false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        return [
            'error' => 'Runtime controls are unavailable',
            'code' => $status >= 400 ? $status : 502,
        ];
    }
    return $body;
}

if (!defined('API_NETWORK_NAME')) {
    $networkIniFile = '/etc/rysen/systemx-network.ini';
    $legacyConfigFile = __DIR__ . '/../config/api-endpoints.php';
    $loaded = false;

    if (file_exists($networkIniFile)) {
        $ini = dashboardLoadNetworkIni($networkIniFile);
        if (is_array($ini)) {
            $network = $ini['network'] ?? [];
            $api = $ini['api'] ?? [];

            define('API_NETWORK_NAME', $network['name'] ?? 'System X');
            define('API_NETWORK_LABEL', $network['label'] ?? 'FreeSTAR System X DMR Network');
            define('API_TG_JSON_URL', $api['tg_json_url'] ?? 'https://api.freestar.network/v1/talkgroup_ids.json');
            define('API_TG_CSV_URL', $api['tg_csv_url'] ?? 'https://api.freestar.network/v1/talkgroup_ids.csv');
            define('API_BRIDGE_JSON_URL', $api['bridge_json_url'] ?? 'https://api.freestar.network/v1/bridge_ids.json');
            define('API_SERVERS_CSV_URL', $api['servers_csv_url'] ?? 'https://api.freestar.network/v1/SystemX_Hosts.csv');
            define('API_SERVERS_CSV_SKIP_LINES', (int)($api['servers_csv_skip_lines'] ?? 2));
            define('API_SERVER_STALE_MINUTES', (int)($api['server_stale_minutes'] ?? 10));
            $loaded = true;
        }
    }

    if (!$loaded && file_exists($legacyConfigFile)) {
        require_once $legacyConfigFile;
        $loaded = true;
    }

    if (!$loaded) {
        define('API_NETWORK_NAME', 'System X');
        define('API_NETWORK_LABEL', 'FreeSTAR System X DMR Network');
        define('API_TG_JSON_URL', 'https://api.freestar.network/v1/talkgroup_ids.json');
        define('API_TG_CSV_URL', 'https://api.freestar.network/v1/talkgroup_ids.csv');
        define('API_BRIDGE_JSON_URL', 'https://api.freestar.network/v1/bridge_ids.json');
        define('API_SERVERS_CSV_URL', 'https://api.freestar.network/v1/SystemX_Hosts.csv');
        define('API_SERVERS_CSV_SKIP_LINES', 2);
        define('API_SERVER_STALE_MINUTES', 10);
    }
}

/**
 * Fetch remote API content with basic error handling.
 *
 * @param string $url
 * @return string|false
 */
function dashboardFetchApiContent($url)
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    return @file_get_contents($url, false, $context);
}
