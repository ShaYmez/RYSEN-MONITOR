"""Security and compatibility guards for FreeSTAR Selfcare runtime controls."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[1]
HTML = ROOT / "html"
PHP = shutil.which("php")


def _read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


def test_gateway_enforces_session_ownership_https_csrf_and_hotspot():
    source = _read("html/ssdevicecontrol.php")
    for required in (
        "isSelfcareLoggedIn()",
        "verifyDeviceOwnership($intId)",
        "verifyCSRFToken($_POST['csrf_token'])",
        "isIpscSession()",
        "isIpscDeviceMode($device['mode'])",
        "HTTPS is required",
        "dashboardDeviceControlConfig()",
    ):
        assert required in source
    assert source.index("selfcareRequestIsHttps()") < source.index("session_start()")

    key = _read("html/ssdevicekey.php")
    assert key.index("selfcareRequestIsHttps()") < key.index("session_start()")

    status = _read("html/sscheck.php")
    assert "HTTPS is required" in status
    assert status.index("selfcareRequestIsHttps()") < status.index("session_start()")


def test_gateway_whitelists_actions_and_forwards_exact_selected_id():
    source = _read("html/ssdevicecontrol.php")
    assert "['status', 'drop-call', 'drop-dynamic']" in source
    assert "'radio_id' => $intId" in source
    assert "dashboardDeviceCoreId($intId)" not in source
    assert "device_control_mutations" in source
    assert "selfcare_device_control" in source


def test_browser_never_receives_issuer_configuration_or_token():
    browser = _read("html/ssmain.php") + _read("html/scripts/selfcare.js")
    assert "device_key_issuer_token" not in browser
    assert "device_control_url" not in browser
    assert "Authorization: Bearer" not in browser
    assert "ssdevicecontrol.php" in browser


def test_runtime_ui_uses_control_config_and_disconnect_is_ungated():
    page = _read("html/ssmain.php")
    assert "$showRuntimeControls = $showDeviceApiKey;" not in page
    assert "dashboardDeviceControlConfig()" in page
    assert "!empty($deviceControl['enabled'])" in page
    gate = page.index("<?php if ($showRuntimeControls): ?>")
    disconnect = page.index('id="calchlpdisconnect"')
    drop_call = page.index('id="device-runtime-drop-call"')
    assert disconnect < gate < drop_call
    assert 'id="device-runtime-drop-dynamic"' in page
    assert 'id="device-runtime-table"' in page


def test_issuer_http_does_not_follow_redirects():
    source = _read("html/include/api-config.php")
    assert source.count("'follow_location' => false") >= 2
    assert source.count("'max_redirects' => 0") >= 2


def test_runtime_polling_is_visible_serialized_and_refreshes_after_actions():
    script = _read("html/scripts/selfcare.js")
    for required in (
        "DEVICE_RUNTIME_POLL_MS",
        "this.runtimeStatusInFlight",
        "this.runtimeActionBusy",
        "document.hidden",
        "visibilitychange",
        "refreshDeviceRuntime()",
        "runDeviceControl(action)",
    ):
        assert required in script
    assert "setInterval(" in script
    disconnect = script[script.index("disconnectDynamicLink() {"):]
    assert disconnect.index("stopDeviceRuntimePolling()") < disconnect.index(
        "postDisconnectPhase('request')"
    )

    monitor = _read("html/scripts/monitor.js")
    tbody = monitor[monitor.index("function updateTbody"):monitor.index("function handleMainSection")]
    assert tbody.index("prepareDomSwapCleanup()") < tbody.index("el.innerHTML = html")


def test_bridge_rows_escape_api_fields():
    source = _read("html/include/wwbridges.php")
    row = source[source.index("foreach ( $brDataArray as $brData )"):source.index('echo "</table>')]
    for field in (
        "tgid", "name", "dmrplus", "tgif", "qrm", "qnet", "ysf", "dstar",
        "p25", "nxdn", "m17", "peanut", "asl", "echolink",
    ):
        assert f"$brData['{field}']" in row
    assert row.count("htmlspecialchars(") >= 14
    assert "$brName = htmlspecialchars" in row


@pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")
def test_https_accepts_proxy_client_signals_and_keeps_the_first_hop():
    code = r"""
        require $argv[1];
        $cases = json_decode($argv[2], true);
        $out = [];
        foreach ($cases as $name => $server) {
            $_SERVER = $server;
            $out[$name] = selfcareRequestIsHttps();
        }
        echo json_encode($out);
    """
    cases = {
        "plain": {},
        "request_scheme": {"REQUEST_SCHEME": "https"},
        "forwarded_ssl": {"HTTP_X_FORWARDED_SSL": "on"},
        "forwarded_ssl_chain": {"HTTP_X_FORWARDED_SSL": "off, on"},
        "forwarded_proto": {"HTTP_FORWARDED": 'for=192.0.2.1;proto=https'},
        "forwarded_first_hop_http": {
            "HTTP_FORWARDED": 'for=192.0.2.1;proto=http, for=192.0.2.2;proto=https',
        },
        "xfp_first_hop": {"HTTP_X_FORWARDED_PROTO": "https, http"},
        "xfp_blocks_later_https": {
            "HTTP_X_FORWARDED_PROTO": "http",
            "HTTP_X_FORWARDED_SSL": "on",
            "HTTP_FORWARDED": "proto=https",
        },
    }
    output = subprocess.check_output(
        [PHP, "-r", code, str(ROOT / "html/ssconfunc.php"), json.dumps(cases)],
        text=True,
    )
    assert json.loads(output) == {
        "plain": False,
        "request_scheme": True,
        "forwarded_ssl": True,
        "forwarded_ssl_chain": False,
        "forwarded_proto": True,
        "forwarded_first_hop_http": False,
        "xfp_first_hop": True,
        "xfp_blocks_later_https": False,
    }


def test_all_runtime_translation_keys_cover_configured_languages():
    translations = __import__("json").loads(_read("html/translations.json"))
    languages = set(translations["runtime_drop_call"])
    keys = (
        "runtime_drop_dynamic",
        "runtime_dynamic_title",
        "runtime_slot",
        "runtime_talkgroup",
        "runtime_status_loading",
        "runtime_status_online",
        "runtime_status_offline",
        "runtime_status_ambiguous",
        "runtime_status_unavailable",
        "runtime_status_empty",
        "runtime_action_error",
    )
    for key in keys:
        assert set(translations[key]) == languages
