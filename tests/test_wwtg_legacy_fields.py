"""Talkgroup list Country/MCC columns follow the API payload, not branding."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
CONFIG = ROOT / "html" / "include" / "api-config.php"
WWTG = ROOT / "html" / "include" / "wwtg.php"

pytestmark = pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")


def _field_present(rows, field):
    code = (
        "require $argv[1];"
        "$rows=json_decode($argv[2], true);"
        "echo dashboardTalkgroupFieldPresent($rows, $argv[3]) ? '1' : '0';"
    )
    return subprocess.check_output(
        [PHP, "-r", code, str(CONFIG), json.dumps(rows), field],
        text=True,
    ).strip()


def test_legacy_quadnet_shape_hides_country_and_mcc():
    rows = [
        {"tgid": 91, "callsign": "WorldWide", "id": 91},
        {"tgid": 320, "callsign": "QuadNet Array", "id": 320},
    ]
    assert _field_present(rows, "country") == "0"
    assert _field_present(rows, "mcc") == "0"


def test_freestar_shape_shows_country_and_mcc():
    rows = [
        {"tgid": 9, "callsign": "Dial-A-TG", "id": "9", "country": "Worldwide", "mcc": 901},
        {"tgid": 2350, "callsign": "UK", "id": "2350", "country": "United Kingdom", "mcc": 235},
    ]
    assert _field_present(rows, "country") == "1"
    assert _field_present(rows, "mcc") == "1"


def test_empty_or_null_meta_fields_stay_hidden():
    rows = [
        {"tgid": 91, "callsign": "WorldWide", "country": "", "mcc": None},
        {"tgid": 320, "callsign": "Array", "country": "   "},
    ]
    assert _field_present(rows, "country") == "0"
    assert _field_present(rows, "mcc") == "0"


def test_page_omits_legacy_column_headers():
    source = WWTG.read_text(encoding="utf-8")
    assert "dashboardTalkgroupFieldPresent($tgDataArray, 'country')" in source
    assert "dashboardTalkgroupFieldPresent($tgDataArray, 'mcc')" in source
    assert "if ($showCountry)" in source
    assert "if ($showMcc)" in source
    assert "API_NETWORK_NAME" in source
    assert "dashboardIsFreestarApiHost" not in source
