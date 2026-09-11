"""PHP FreeSTAR-only Device API key gate tests."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")

pytestmark = pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")


def _config(tmp_path, ini_text):
    ini = tmp_path / "systemx-network.ini"
    ini.write_text(ini_text, encoding="utf-8")
    code = (
        "require $argv[1];"
        "$config=dashboardDeviceKeyIssuerConfig($argv[2]);"
        "$control=dashboardDeviceControlConfig($argv[2]);"
        "echo json_encode(['enabled'=>$config['enabled'],"
        "'url'=>$config['url'],'control_enabled'=>$control['enabled'],"
        "'control_url'=>$control['url'],"
        "'core'=>dashboardDeviceCoreId(234018901)]);"
    )
    output = subprocess.check_output(
        [PHP, "-r", code, str(ROOT / "html/include/api-config.php"), str(ini)],
        text=True,
    )
    return json.loads(output)


def _ini(lastheard, org="https://freestar.network/systemx-dmr",
         status="https://api.freestar.network/v1/update-server-status.php",
         issuer="https://api.freestar.network/v2/internal/device-keys",
         control="https://api.freestar.network/v2/internal/device-control"):
    return f"""[network]
org_url = {org}
[api]
lastheard_api_url = {lastheard}
status_api_url = {status}
device_key_issuer_url = {issuer}
device_key_issuer_token = test-issuer-token
device_control_url = {control}
"""


def test_explicit_freestar_lastheard_enables_issuer(tmp_path):
    result = _config(
        tmp_path,
        _ini("https://api.freestar.network/v2/ingest/activity"),
    )
    assert result == {
        "enabled": True,
        "url": "https://api.freestar.network/v2/internal/device-keys",
        "control_enabled": True,
        "control_url": "https://api.freestar.network/v2/internal/device-control",
        "core": 2340189,
    }


def test_explicit_third_party_lastheard_never_falls_back(tmp_path):
    result = _config(tmp_path, _ini("https://third-party.example/ingest"))
    assert result["enabled"] is False
    assert result["control_enabled"] is False


def test_empty_lastheard_requires_both_freestar_fallback_values(tmp_path):
    assert _config(tmp_path, _ini(""))["enabled"] is True
    assert _config(
        tmp_path,
        _ini("", org="https://third-party.example"),
    )["enabled"] is False
    assert _config(
        tmp_path,
        _ini("", status="https://third-party.example/status"),
    )["enabled"] is False


def test_issuer_url_cannot_exfiltrate_credential(tmp_path):
    result = _config(
        tmp_path,
        _ini(
            "https://api.freestar.network/v2/ingest/activity",
            issuer="https://attacker.example/v2/internal/device-keys",
        ),
    )
    assert result["enabled"] is False
    assert result["url"] == ""


def test_control_url_cannot_exfiltrate_issuer_credential(tmp_path):
    result = _config(
        tmp_path,
        _ini(
            "https://api.freestar.network/v2/ingest/activity",
            control="https://attacker.example/v2/internal/device-control",
        ),
    )
    assert result["enabled"] is True
    assert result["control_enabled"] is False
    assert result["control_url"] == ""


def test_blank_control_url_disables_runtime_controls(tmp_path):
    result = _config(
        tmp_path,
        _ini(
            "https://api.freestar.network/v2/ingest/activity",
            control="",
        ),
    )
    assert result["enabled"] is True
    assert result["control_enabled"] is False
    assert result["control_url"] == ""
