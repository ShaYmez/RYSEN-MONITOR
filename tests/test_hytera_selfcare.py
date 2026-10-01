"""Hytera selfcare save gate and RDAC display helpers."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")


def _read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


def test_hytera_save_uses_repeater_sanitizer():
    source = _read("html/ssmain.php")
    assert "isRepeaterDeviceMode($postDev['mode'])" in source
    assert "sanitizeIpscOptions($_POST['genText'])" in source
    assert "isIpscDeviceMode($postDev['mode'])" not in source


def test_hytera_disconnect_treats_repeater_as_ipsc_options():
    source = _read("html/ssdisconnect.php")
    assert "isRepeaterDeviceMode($devDetails['mode'])" in source


@pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")
def test_hytera_metadata_helpers_handle_pending_and_real_values():
    code = (
        "require $argv[1];"
        "echo json_encode(["
        "'null_model'=>hyteraMetadataModel(null),"
        "'empty_model'=>hyteraMetadataModel(''),"
        "'model'=>hyteraMetadataModel('RD985-00000000-000000-U1-0-F'),"
        "'zero_freq'=>hyteraMetadataFrequency(null, 0),"
        "'half_freq'=>hyteraMetadataFrequency(439437500, 0),"
        "'freq'=>hyteraMetadataFrequency(439437500, 430437500),"
        "]);"
    )
    output = subprocess.check_output(
        [PHP, "-r", code, str(ROOT / "html/include/functions.php")],
        text=True,
    )
    assert json.loads(output) == {
        "null_model": "Pending RDAC",
        "empty_model": "Pending RDAC",
        "model": "RD985",
        "zero_freq": "Pending RDAC",
        "half_freq": "Pending RDAC",
        "freq": "439.437500 / 430.437500 MHz",
    }
