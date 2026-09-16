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


def _flag_code(mcc, callsign=""):
    code = (
        "require $argv[1];"
        "$mcc=json_decode($argv[2]);"
        "echo dashboardTalkgroupFlagCode($mcc, $argv[3]);"
    )
    return subprocess.check_output(
        [PHP, "-r", code, str(CONFIG), json.dumps(mcc), callsign],
        text=True,
    ).strip()


def test_page_omits_legacy_column_headers():
    source = WWTG.read_text(encoding="utf-8")
    assert "dashboardTalkgroupFieldPresent($tgDataArray, 'country')" in source
    assert "dashboardTalkgroupFieldPresent($tgDataArray, 'mcc')" in source
    assert "if ($showCountry)" in source
    assert "if ($showMcc)" in source
    assert "API_NETWORK_NAME" in source
    assert "dashboardIsFreestarApiHost" not in source
    assert "renderDashboardTalkgroupName($tgData['callsign'] ?? '', $tgData['mcc'] ?? '')" in source


def test_search_sits_outside_table_scroll():
    source = WWTG.read_text(encoding="utf-8")
    assert "wwtg-search-group" in source
    assert "table-responsive wwtg-table-scroll" in source
    toolbar = source.find("data-table-toolbar")
    scroll = source.find("wwtg-table-scroll")
    table = source.find('id=\\"wwtg-table\\"', scroll)
    assert 0 < toolbar < scroll < table


def test_mcc_selects_country_flag_and_legacy_stays_world():
    assert _flag_code(None, "WorldWide") == "world"
    assert _flag_code("", "QuadNet Array") == "world"
    assert _flag_code(901, "Dial-A-TG") == "world"
    assert _flag_code(235, "UK") == "234"
    assert _flag_code(310, "USA") == "310"
    assert _flag_code(313, "USA") == "313"
    assert _flag_code(320, "TAC") == "310"
    assert _flag_code("", '<img src="flags/208.png"> France') == "208"
