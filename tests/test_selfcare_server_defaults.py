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
    assert "displayedScalarOption(" in page
    assert "'TIMER'" in page
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
    assert 'name="reset_functions"' in page
    assert "normalizeSelfcareOptions(" in page
    assert "resetSelfcareFunctionOptions(" in page
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


@pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")
def test_disabled_or_missing_config_is_not_a_live_default(tmp_path):
    config = tmp_path / "rysen.cfg"
    config.write_text(
        """
[GLOBAL]
TALKER_ALIAS: True

[OLD]
MODE: MASTER
ENABLED: False
DEFAULT_UA_TIMER: 15
SINGLE_MODE: True
""".strip(),
        encoding="utf-8",
    )
    code = (
        "require $argv[1];"
        "$disabled = loadRysenFunctionDefaults(4, $argv[2]);"
        "$missing = loadRysenFunctionDefaults(4, $argv[2] . '.missing');"
        "$live = ["
        "'available'=>true,'voice'=>false,'language'=>'en_GB',"
        "'single'=>true,'sticky'=>false,'alias'=>false,'timer'=>10"
        "];"
        "echo json_encode(["
        "'disabled'=>$disabled,"
        "'missing'=>$missing['available'],"
        "'inherit'=>normalizeSelfcareOptions("
        "'TS2=2350;VOICE=0;SINGLE=1;ALIAS=0;TIMER=10;LANG=en_GB;', '', $live, false),"
        "'override'=>normalizeSelfcareOptions('TS2=2350;VOICE=1;TIMER=5;', '', $live, false),"
        "'reset'=>resetSelfcareFunctionOptions("
        "'TS1=91;TS2=2350;VOICE=1;DIAL=9;ALIAS=1;TIMER=5;PASS=secret;', false),"
        "'repeater'=>resetSelfcareFunctionOptions('TS1=91;ALIAS=1;', true),"
        "'kept'=>normalizeSelfcareOptions('TS2=2350;', 'VOICE=1;TIMER=5;', "
        "['available'=>false], false),"
        "]);"
    )
    output = subprocess.check_output(
        [PHP, "-r", code, str(ROOT / "html/include/functions.php"), str(config)],
        text=True,
    )
    values = json.loads(output)
    assert values["disabled"]["available"] is False
    assert values["disabled"]["timer"] != 15
    assert values["missing"] is False
    assert values["inherit"] == "TS2=2350;"
    assert values["override"] == "TS2=2350;VOICE=1;TIMER=5;"
    assert values["reset"] == "TS1=91;TS2=2350;PASS=secret;"
    assert values["repeater"] == "TS1=91;"
    assert values["kept"] == "TS2=2350;VOICE=1;TIMER=5;"


@pytest.mark.skipif(NODE is None, reason="Node is not installed")
def test_public_calculator_builds_the_selfcare_string(tmp_path):
    script = _read("html/scripts/selfcare.js")
    script = script.split("// Initialize when DOM is ready", 1)[0]
    page = _read("html/include/calc.php")
    index = _read("html/index.php")
    assert 'id="options-calculator"' in page
    assert 'class="col-12 col-md-5 mb-3 mb-md-0"' in page
    assert 'id="modeSelector"' in page
    assert 'value="repeater"' in page
    assert "selfcare.js?v=" in index
    harness = script + r"""
const quoted = { value: "" };
const plain = { value: "" };
const elements = {
  timeslotTable: 1,
  timeslotTable2: 2,
  dialTGInput: { value: "0" },
  voiceSelect: { value: "0" },
  languageselect: { value: "en_GB" },
  singleModeSelect: { value: "1" },
  stickySelect: { value: "0", disabled: false, title: "" },
  aliasSelect: { value: "0" },
  timeoutInput: { value: "10" },
  "server-defaults-available": { value: "1" },
  "server-default-voice": { value: "0" },
  "server-default-language": { value: "en_GB" },
  "server-default-single": { value: "1" },
  "server-default-sticky": { value: "0" },
  "server-default-alias": { value: "0" },
  "server-default-timer": { value: "10" },
  generatedTextWithQuotes: quoted,
  generatedTextWithoutQuotes: plain
};
global.document = {
  getElementById(id) { return elements[id] || null; }
};
const mgr = Object.create(SelfcareManager.prototype);
mgr.calculator = true;
mgr.deviceMode = 4;
mgr.isIpsc = false;
mgr.initialSlots = { 1: ["91"], 2: ["2350"] };
mgr.getTimeslotValues = table => table === 1 ? ["91"] : ["2350"];
mgr.syncStickyControl = () => {};
mgr.updateGeneratedText();
if (plain.value !== "TS2=2350;" || quoted.value !== 'Options="TS2=2350;"') {
  console.error(JSON.stringify({ plain: plain.value, quoted: quoted.value }));
  process.exit(1);
}
mgr.deviceMode = 1;
elements.voiceSelect.value = "1";
elements.languageselect.value = "de_DE";
mgr.updateGeneratedText();
if (plain.value !== "TS1=91;TS2=2350;VOICE=1;LANG=de_DE;") {
  console.error(plain.value);
  process.exit(1);
}
mgr.isIpsc = true;
elements.aliasSelect.value = "1";
mgr.updateGeneratedText();
if (plain.value !== "TS1=91;TS2=2350;ALIAS=1;") {
  console.error(plain.value);
  process.exit(1);
}
mgr.resetFunctionsToServerDefaults();
if (elements.voiceSelect.value !== "0" || elements.aliasSelect.value !== "0" || elements.timeoutInput.value !== "10" || plain.value.indexOf("ALIAS=") !== -1) {
  console.error(JSON.stringify({ voice: elements.voiceSelect.value, alias: elements.aliasSelect.value, plain: plain.value }));
  process.exit(1);
}
"""
    path = tmp_path / "calculator.js"
    path.write_text(harness, encoding="utf-8")
    subprocess.check_call([NODE, str(path)], cwd=tmp_path)


@pytest.mark.skipif(NODE is None, reason="Node is not installed")
def test_reset_submits_server_functions_and_keeps_static_talkgroups(tmp_path):
    script = _read("html/scripts/selfcare.js")
    script = script.split("// Initialize when DOM is ready", 1)[0]
    harness = script + r"""
const flag = { value: "0" };
let submitted = false;
const elements = {
  resetFunctions: flag,
  saveChangesForm: { submit() { submitted = true; } },
  timeoutInput: { value: "5" },
  dialTGInput: { value: "9990" }
};
global.document = {
  getElementById(id) { return elements[id] || null; }
};
const mgr = Object.create(SelfcareManager.prototype);
mgr.resetFunctionsToServerDefaults();
if (!submitted || flag.value !== "1" || elements.timeoutInput.value !== "5" || elements.dialTGInput.value !== "9990") {
  console.error(JSON.stringify({ submitted, flag: flag.value, timer: elements.timeoutInput.value }));
  process.exit(1);
}
delete elements.resetFunctions;
submitted = false;
mgr.resetFunctionsToServerDefaults();
if (submitted) {
  console.error("reset saved without the server flag");
  process.exit(1);
}
"""
    path = tmp_path / "reset-defaults.js"
    path.write_text(harness, encoding="utf-8")
    subprocess.check_call([NODE, str(path)])
