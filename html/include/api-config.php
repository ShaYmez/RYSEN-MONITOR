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

if (!defined('API_NETWORK_NAME')) {
    $networkIniFile = '/etc/rysen/systemx-network.ini';
    $legacyConfigFile = __DIR__ . '/../config/api-endpoints.php';
    $loaded = false;

    if (file_exists($networkIniFile)) {
        $ini = @parse_ini_file($networkIniFile, true, INI_SCANNER_TYPED);
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
