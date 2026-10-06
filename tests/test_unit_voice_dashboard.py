#!/usr/bin/env python3
"""Unit-call telemetry paints exact endpoints and renders private-call labels."""
import shutil
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from jinja2 import Environment, FileSystemLoader

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402


def _slot():
    return {
        "TS": "", "TYPE": "", "SUB": "", "SRC": "", "DEST": "",
        "CALL": "", "TG": "", "TRX": "", "SID": "", "RSSI": "",
        "UNIT_FROM": "", "UNIT_TO": "",
    }


def _peer():
    return {1: _slot(), 2: _slot()}


def _event(action, system, peer, slot="1", trx="RX", stream="900"):
    return [
        "UNIT VOICE", action, trx, system, stream,
        str(peer), "2348831", slot, "2345875", "4.20",
    ]


class TestUnitVoiceDashboard(unittest.TestCase):
    def setUp(self):
        monitor.CTABLE["MASTERS"].clear()
        monitor.CTABLE["PEERS"].clear()
        monitor.CTABLE["OPENBRIDGES"].clear()
        monitor.subscriber_ids.clear()
        monitor.subscriber_ids.update({
            2348831: {"CALLSIGN": "M0CALL", "NAME": "Caller"},
            2345875: {"CALLSIGN": "G0DEST", "NAME": "Callee"},
        })
        monitor.CTABLE["MASTERS"]["MASTER-A"] = {
            "PEERS": {111: _peer(), 112: _peer()}}
        monitor.CTABLE["MASTERS"]["MASTER-B"] = {
            "PEERS": {222: _peer(), 223: _peer()}}
        monitor.CTABLE["OPENBRIDGES"]["OBP-EU"] = {
            "NETWORK_ID": 2040, "STREAMS": {}}

    def tearDown(self):
        monitor.CTABLE["MASTERS"].clear()
        monitor.CTABLE["PEERS"].clear()
        monitor.CTABLE["OPENBRIDGES"].clear()
        monitor.subscriber_ids.clear()

    @patch("monitor.push_live_dashboard")
    def test_origin_and_delivery_paint_only_the_reported_peers(self, push):
        monitor.rts_update(_event("START", "MASTER-A", 111))
        origin = monitor.CTABLE["MASTERS"]["MASTER-A"]["PEERS"][111][1]
        untouched = monitor.CTABLE["MASTERS"]["MASTER-A"]["PEERS"][112][1]
        self.assertEqual(origin["TRX"], "UNIT_TX")
        self.assertEqual(origin["CALL"], "M0CALL")
        self.assertEqual(origin["TG"], "PC&nbsp;G0DEST")
        self.assertFalse(untouched["TS"])

        monitor.rts_update(_event("TO START", "MASTER-B", 222, slot="2", trx="TX"))
        delivery = monitor.CTABLE["MASTERS"]["MASTER-B"]["PEERS"][222][2]
        other = monitor.CTABLE["MASTERS"]["MASTER-B"]["PEERS"][223][2]
        self.assertEqual(delivery["TRX"], "UNIT_RX")
        self.assertEqual(delivery["CALL"], "G0DEST")
        self.assertEqual(delivery["TG"], "PC&nbsp;M0CALL")
        self.assertFalse(other["TS"])
        self.assertEqual(push.call_count, 2)

    @patch("monitor.push_live_dashboard")
    def test_late_delivery_end_does_not_clear_new_stream(self, _push):
        monitor.rts_update(_event("TO START", "MASTER-B", 222, stream="old"))
        monitor.rts_update(_event("TO START", "MASTER-B", 222, stream="new"))
        monitor.rts_update(_event("TO END", "MASTER-B", 222, stream="old"))
        slot = monitor.CTABLE["MASTERS"]["MASTER-B"]["PEERS"][222][1]
        self.assertTrue(slot["TS"])
        self.assertEqual(slot["SID"], "new")

    @patch("monitor.push_live_dashboard")
    def test_openbridge_via_stream_has_private_call_identity(self, _push):
        monitor.rts_update(
            _event("VIA START", "OBP-EU", 2040, trx="TX", stream="via"))
        stream = monitor.CTABLE["OPENBRIDGES"]["OBP-EU"]["STREAMS"]["via"]
        self.assertEqual(stream["ROLE"], "OUT")
        self.assertEqual(stream["CALL"], "M0CALL")
        self.assertEqual(stream["DEST"], "G0DEST")
        monitor.rts_update(
            _event("VIA END", "OBP-EU", 2040, trx="TX", stream="via"))
        self.assertNotIn(
            "via", monitor.CTABLE["OPENBRIDGES"]["OBP-EU"]["STREAMS"])

    def test_activity_and_openbridge_templates_use_unit_colours_and_labels(self):
        monitor.unit_rts_update(_event("START", "MASTER-A", 111))
        monitor.unit_rts_update(
            _event("TO START", "MASTER-B", 222, slot="2", trx="TX"))
        monitor.unit_rts_update(
            _event("VIA START", "OBP-EU", 2040, trx="TX", stream="via"))
        monitor.unit_rts_update(
            _event("VIA START", "OBP-EU", 2040, trx="RX", stream="via-in"))
        env = Environment(loader=FileSystemLoader(str(ROOT / "templates")))
        env.filters["flag_code"] = monitor.country_flag_code
        env.get_template("lnksys_table.html")
        env.get_template("main/stats.html")
        activity = env.get_template("main/activity.html").render(
            _table=monitor.CTABLE)
        stats = env.get_template("main/stats.html").render(
            _table=monitor.CTABLE)
        opb = env.get_template("opb_table.html").render(
            _table=monitor.CTABLE)
        css = (ROOT / "html" / "css" / "dashboard.css").read_text()
        self.assertIn("badge-unit-tx", activity)
        self.assertIn("badge-unit-rx", activity)
        self.assertIn("TX", activity)
        self.assertIn("RX", activity)
        self.assertIn(
            'class="text-xs text-dark">PC&nbsp;G0DEST', activity)
        self.assertNotIn(".badge-unit-tx .text-dark", css)
        self.assertEqual(activity.count("badge-unit-tx"), 1)
        self.assertEqual(activity.count("badge-unit-rx"), 1)
        self.assertNotIn("PC OUT", activity)
        self.assertIn("TX:", opb)
        self.assertIn("RX:", opb)
        self.assertNotIn("PC OUT", opb)
        self.assertNotIn("PC IN", opb)
        self.assertIn("M0CALL", opb)
        self.assertIn("PC&nbsp;G0DEST", opb)
        self.assertIn("<h3>2</h3>", stats)

    def test_lastheard_unit_row_uses_destination_subscriber(self):
        env = Environment(loader=FileSystemLoader(str(ROOT / "templates")))
        env.filters["flag_code"] = monitor.country_flag_code
        row = (
            "2026-10-06 20:00:00", 4.2, "UNIT VOICE", "MASTER-A",
            2345875, None, 2348831, ["M0CALL", "Caller"],
            ["G0DEST", "Callee"],
        )
        html = env.get_template("main/lastheard_rows.html").render(
            _table={"SETUP": {"LASTHEARD": True}}, lastheard=[row])
        full_html = env.get_template("lasthrd_log.html").render(_table=[row])
        self.assertIn("lh-unit-row", html)
        self.assertIn("badge-unit-tx", html)
        self.assertIn("PC 2345875", html)
        self.assertIn("G0DEST", html)
        self.assertIn("Callee", html)
        self.assertIn('<tr class="lh-unit-row">', full_html)


if __name__ == "__main__":
    unittest.main()
