"""Security and compatibility guards for FreeSTAR Selfcare runtime controls."""
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HTML = ROOT / "html"


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


def test_runtime_ui_uses_same_freestar_gate_and_disconnect_is_ungated():
    page = _read("html/ssmain.php")
    assert "$showRuntimeControls = $showDeviceApiKey;" in page
    gate = page.index("<?php if ($showRuntimeControls): ?>")
    disconnect = page.index('id="calchlpdisconnect"')
    drop_call = page.index('id="device-runtime-drop-call"')
    assert disconnect < gate < drop_call
    assert 'id="device-runtime-drop-dynamic"' in page
    assert 'id="device-runtime-table"' in page


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
