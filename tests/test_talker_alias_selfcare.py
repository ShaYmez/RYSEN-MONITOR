"""Talker alias selfcare switch stays on server default until chosen."""
import json
import shutil
import subprocess
from pathlib import Path

import pytest

NODE = shutil.which("node")

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")


def _read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


def test_alias_switch_sits_with_server_default_options():
    source = _read("html/ssmain.php")
    start = source.index('id="calchlpfun"')
    block = source[start:source.index("</table>", start)]
    assert 'id="dialTGInput"' in block
    assert 'id="aliasSelect"' in block
    alias_at = block.index('id="aliasSelect"')
    before = block[:alias_at]
    assert before.count("if (!$isRepeaterDevice)") == before.count("endif")
    assert "normalizeAliasOption(getDeviceOption($devDetails['options'], 'ALIAS', '-1'))" in source
    assert 'id="calc_aliassrv"' in block
    assert "retired" in _read("html/include/calc.php").lower()


def test_alias_option_is_omitted_until_the_switch_moves():
    script = _read("html/scripts/selfcare.js")
    assert "aliasOptionFragment" in script
    assert "Server default" in script or "aliasSelect.value !== '0'" in script
    assert "hasStaticTalkgroups" in script
    assert "syncStickyControl" in script
    assert "!hasStatics && stickyValue !== '-1'" in script


@pytest.mark.skipif(NODE is None, reason="Node is not installed")
def test_alias_only_save_does_not_clear_empty_slots(tmp_path):
    script = _read("html/scripts/selfcare.js")
    script = script.split("// Initialize when DOM is ready", 1)[0]
    harness = script + r"""
global.document = {
  getElementById(id) {
    if (id === "timeslotTable") return 1;
    if (id === "timeslotTable2") return 2;
    if (id === "aliasSelect") return { value: "1" };
    return null;
  }
};
const mgr = Object.create(SelfcareManager.prototype);
mgr.isIpsc = true;
let current = { 1: [], 2: [] };
mgr.getTimeslotValues = (table) => current[table] || [];
const saved = [];
mgr.setGeneratedOptions = (text) => { saved.push(text); };

mgr.initialSlots = { 1: [], 2: [] };
mgr.updateGeneratedText();

mgr.initialSlots = { 1: ["235"], 2: [] };
mgr.updateGeneratedText();

current = { 1: ["91"], 2: [] };
mgr.initialSlots = { 1: [], 2: [] };
mgr.updateGeneratedText();

const expected = ["ALIAS=1;", "TS1=;ALIAS=1;", "TS1=91;ALIAS=1;"];
if (JSON.stringify(saved) !== JSON.stringify(expected)) {
  console.error(JSON.stringify(saved));
  process.exit(1);
}
"""
    path = tmp_path / "alias-save.js"
    path.write_text(harness, encoding="utf-8")
    subprocess.check_call([NODE, str(path)])


@pytest.mark.skipif(NODE is None, reason="Node is not installed")
def test_statics_omit_sticky_from_generated_options(tmp_path):
    script = _read("html/scripts/selfcare.js")
    script = script.split("// Initialize when DOM is ready", 1)[0]
    harness = script + r"""
const sticky = { value: "1", disabled: false, title: "" };
global.document = {
  getElementById(id) {
    if (id === "timeslotTable") return 1;
    if (id === "timeslotTable2") return 2;
    if (id === "dialTGInput") return { value: "0" };
    if (id === "voiceSelect") return { value: "-1" };
    if (id === "languageselect") return { value: "en_GB" };
    if (id === "singleModeSelect") return { value: "-1" };
    if (id === "stickySelect") return sticky;
    if (id === "timeoutInput") return { value: "0" };
    return null;
  }
};
const mgr = Object.create(SelfcareManager.prototype);
mgr.isIpsc = false;
mgr.aliasOptionFragment = () => "";
let current = { 1: [], 2: [] };
mgr.getTimeslotValues = (table) => current[table] || [];
const saved = [];
mgr.setGeneratedOptions = (text) => { saved.push(text); };

mgr.updateGeneratedText();
if (sticky.disabled) { console.error("disabled without statics"); process.exit(1); }
current = { 1: [], 2: ["2350"] };
mgr.updateGeneratedText();
if (!sticky.disabled) { console.error("enabled with statics"); process.exit(1); }
current = { 1: [], 2: [] };
mgr.updateGeneratedText();
if (sticky.disabled) { console.error("stayed disabled"); process.exit(1); }

const expected = ["STICKY=1;", "TS2=2350;", "STICKY=1;"];
if (JSON.stringify(saved) !== JSON.stringify(expected)) {
  console.error(JSON.stringify(saved));
  process.exit(1);
}
"""
    path = tmp_path / "sticky-static.js"
    path.write_text(harness, encoding="utf-8")
    subprocess.check_call([NODE, str(path)])


@pytest.mark.skipif(PHP is None, reason="PHP CLI is not installed")
def test_alias_round_trip_keeps_server_default_empty():
    code = (
        "require $argv[1];"
        "echo json_encode(["
        "'omit'=>buildOptionsStringFromParsed(['TS2'=>'2350','ALIAS'=>'-1','STICKY'=>'-1'], false, false),"
        "'staticsticky'=>buildOptionsStringFromParsed(['TS2'=>'2350','STICKY'=>'1'], false, false),"
        "'emptysticky'=>buildOptionsStringFromParsed(['TS1'=>'','TS2'=>'','STICKY'=>'1'], false, false),"
        "'on'=>buildOptionsStringFromParsed(['ALIAS'=>'1'], false, false),"
        "'off'=>buildOptionsStringFromParsed(['ALIAS'=>'0','TS1'=>'235'], true, false),"
        "'yes'=>normalizeAliasOption('yes'),"
        "'blank'=>normalizeAliasOption(''),"
        "'san'=>sanitizeOptions('TS2=2350;ALIAS=0;'),"
        "'bad'=>sanitizeOptions('TS2=2350;ALIAS=2;'),"
        "'ipsc'=>sanitizeIpscOptions('TS1=235;TS2=2350;ALIAS=1;'),"
        "'ipscbad'=>sanitizeIpscOptions('TS2=2350;DIAL=9;'),"
        "'disc'=>sanitizeRepeaterDisconnectOptions('TS1=235;DISC=1;'),"
        "'discbad'=>sanitizeRepeaterDisconnectOptions('TS1=235;DIAL=9;DISC=1;'),"
        "'req'=>buildDisconnectRequest('TS1=235;DIAL=9;VOICE=1;', true, false),"
        "]);"
    )
    output = subprocess.check_output(
        [PHP, "-r", code, str(ROOT / "html/include/functions.php")],
        text=True,
    )
    assert json.loads(output) == {
        "omit": "TS2=2350;",
        "staticsticky": "TS2=2350;",
        "emptysticky": "TS1=;TS2=;STICKY=1;",
        "on": "ALIAS=1;",
        "off": "TS1=235;ALIAS=0;",
        "yes": "1",
        "blank": "-1",
        "san": "TS2=2350;ALIAS=0;",
        "bad": False,
        "ipsc": "TS1=235;TS2=2350;ALIAS=1;",
        "ipscbad": False,
        "disc": "TS1=235;DISC=1;",
        "discbad": False,
        "req": "TS1=235;DISC=1;",
    }
