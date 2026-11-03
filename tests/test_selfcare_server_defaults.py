"""Selfcare displays and reapplies effective RYSEN function defaults."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
NODE = shutil.which("node")


def _read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


def test_function_controls_show_effective_values_only():
    page = _read("html/ssmain.php")
    assert "loadRysenFunctionDefaults($devDetails['mode'])" in page
    assert "getDeviceOption($devDetails['options'], 'TIMER', $serverDefaults['timer'])" in page
    assert 'id="calc_voicesrv"' not in page
    assert 'id="calc_smodesrv"' not in page
    assert 'id="calc_stickysrv"' not in page
    assert 'id="calc_aliassrv"' not in page
    timer_at = page.index('id="timeoutInput"')
    table_end = page.index("</tbody>", timer_at)
    reset_at = page.index('id="calc_reset_defaults"', timer_at)
    assert timer_at < reset_at < table_end
    assert 'id="calc_reset_defaults"' not in page[table_end:]
    assert "Cache-Control: no-store, no-cache, must-revalidate" in page
    assert "scripts/monitor.js?v=" in page
    assert "scripts/selfcare.js?v=" in page
    script = _read("html/scripts/monitor.js")
    fetch_at = script.index("fetch('translations.json'")
    assert script.index("applyPageTranslations(getPageLanguage())", fetch_at) < script.index(
        "return data;", fetch_at
    )


@pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")
def test_rysen_defaults_come_from_enabled_matching_stanza(tmp_path):
    config = tmp_path / "rysen.cfg"
    config.write_text(
        """
[GLOBAL]
TALKER_ALIAS: False

[OLD]
MODE: MASTER
ENABLED: False
DEFAULT_UA_TIMER: 15

[SYSTEM]
MODE: MASTER
ENABLED: True
DEFAULT_UA_TIMER: 45
SINGLE_MODE: False
VOICE_IDENT: True
STICKY_TG: True
ANNOUNCEMENT_LANGUAGE: de_DE
ALIAS: True

[IPSC]
MODE: IPSC
ENABLED: True
DEFAULT_UA_TIMER: 12
SINGLE_MODE: False
VOICE_IDENT: False
STICKY_TG: False
ANNOUNCEMENT_LANGUAGE: en_GB
""".strip(),
        encoding="utf-8",
    )
    code = (
        "require $argv[1];"
        "echo json_encode(["
        "'hotspot'=>loadRysenFunctionDefaults(4, $argv[2]),"
        "'ipsc'=>loadRysenFunctionDefaults(0, $argv[2])"
        "]);"
    )
    output = subprocess.check_output(
        [
            PHP,
            "-r",
            code,
            str(ROOT / "html/include/functions.php"),
            str(config),
        ],
        text=True,
    )
    values = json.loads(output)
    assert values["hotspot"] == {
        "voice": True,
        "language": "de_DE",
        "single": False,
        "sticky": True,
        "alias": True,
        "timer": 45,
        "available": True,
    }
    assert values["ipsc"]["timer"] == 12
    assert values["ipsc"]["alias"] is False


@pytest.mark.skipif(NODE is None, reason="Node is not installed")
def test_reset_submits_server_functions_and_keeps_static_talkgroups(tmp_path):
    script = _read("html/scripts/selfcare.js")
    script = script.split("// Initialize when DOM is ready", 1)[0]
    harness = script + r"""
const elements = {
  timeslotTable: 1,
  timeslotTable2: 2,
  dialTGInput: { value: "9990" },
  voiceSelect: { value: "0" },
  languageselect: { value: "en_GB" },
  singleModeSelect: { value: "1" },
  stickySelect: { value: "0", disabled: false, title: "" },
  aliasSelect: { value: "1" },
  timeoutInput: { value: "5" },
  "server-default-voice": { value: "1" },
  "server-default-language": { value: "de_DE" },
  "server-default-single": { value: "0" },
  "server-default-sticky": { value: "1" },
  "server-default-alias": { value: "0" },
  "server-default-timer": { value: "45" }
};
global.document = {
  getElementById(id) { return elements[id] || null; }
};
const mgr = Object.create(SelfcareManager.prototype);
mgr.isIpsc = false;
mgr.initialSlots = { 1: ["91"], 2: ["2350"] };
mgr.getTimeslotValues = table => table === 1 ? ["91"] : ["2350"];
mgr.toggleTimeslot2 = () => {};
mgr.toggleLanguageDropdown = () => {};
let submitted = "";
mgr.setGeneratedOptions = text => { submitted = text; };
mgr.saveChanges = function () { this.updateGeneratedText(); };
mgr.resetFunctionsToServerDefaults();

const expected = "TS1=91;TS2=2350;VOICE=1;LANG=de_DE;SINGLE=0;ALIAS=0;TIMER=45;";
if (submitted !== expected || elements.dialTGInput.value !== "0") {
  console.error(JSON.stringify({ submitted, dial: elements.dialTGInput.value }));
  process.exit(1);
}
"""
    path = tmp_path / "reset-defaults.js"
    path.write_text(harness, encoding="utf-8")
    subprocess.check_call([NODE, str(path)])
