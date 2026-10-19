#!/usr/bin/env python3
"""NXDN reflector stanzas on Linked Systems."""
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
from monitor import build_hblink_table, rts_update, update_hblink_table  # noqa: E402


def _nxdn(connection="NO", pings=0, connected=None, talkgroup=65000):
    return {
        "MODE": "NXDNPEER",
        "ENABLED": True,
        "CALLSIGN": b"MX0SXC    ",
        "RADIO_ID": (2356427).to_bytes(4, "big"),
        "DESCRIPTION": "Example NXDN reflector",
        "TALKGROUP": talkgroup,
        "NXDN_ID": 12345,
        "MASTER_IP": "77.68.1.2",
        "MASTER_PORT": 41400,
        "STATS": {
            "CONNECTION": connection,
            "CONNECTED": connected,
            "PINGS_SENT": pings,
            "PINGS_ACKD": 0,
        },
    }


def _table():
    return {"MASTERS": {}, "PEERS": {}, "OPENBRIDGES": {}}


class TestNxdnMonitor(unittest.TestCase):
    def setUp(self):
        self.hb_inc = monitor.CONF["GLOBAL"].get("HB_INC")
        monitor.CONF["GLOBAL"]["HB_INC"] = False

    def tearDown(self):
        monitor.CONF["GLOBAL"]["HB_INC"] = self.hb_inc

    def test_nxdn_reflector_lands_in_services(self):
        ctable = _table()
        build_hblink_table({"NXDN-ROOM": _nxdn()}, ctable)
        row = ctable["PEERS"]["NXDN-ROOM"]
        self.assertEqual(row["MODE"], "NXDNPEER")
        self.assertEqual(row["CALLSIGN"], "MX0SXC")
        self.assertEqual(row["RADIO_ID"], 2356427)
        self.assertEqual(row["SLOTS"], "2")
        self.assertEqual(row["LOCATION"], "Example NXDN reflector · TG 65000")
        self.assertEqual(row["STATS"]["CONNECTED"], "--   --")

    def test_connected_nxdn_counts_polls(self):
        config = {"NXDN-ROOM": _nxdn(connection="YES", pings=4)}
        ctable = _table()
        build_hblink_table(config, ctable)
        row = ctable["PEERS"]["NXDN-ROOM"]
        self.assertIsNone(row["STATS"]["PINGS_ACKD"])
        self.assertEqual(row["STATS"]["PINGS_SENT"], 4)
        config["NXDN-ROOM"]["STATS"]["PINGS_SENT"] = 9
        update_hblink_table(config, ctable)
        self.assertEqual(row["STATS"]["PINGS_SENT"], 9)
        self.assertIsNone(row["STATS"]["PINGS_ACKD"])

    @patch("monitor.push_live_dashboard")
    def test_nxdn_voice_lights_slot_2(self, _push):
        ctable = _table()
        build_hblink_table({"NXDN-ROOM": _nxdn(connection="YES", pings=1)}, ctable)
        saved = monitor.CTABLE
        monitor.CTABLE = ctable
        try:
            rts_update([
                "GROUP VOICE", "START", "RX", "NXDN-ROOM", "stream",
                "2356427", "100", "2", "9", "0",
            ])
        finally:
            monitor.CTABLE = saved
        slot = ctable["PEERS"]["NXDN-ROOM"][2]
        self.assertTrue(slot["TS"])
        self.assertEqual(slot["TRX"], "RX")
        self.assertIn("TG 9", slot["DEST"])

    def test_services_row_names_nxdn_in_blue(self):
        ctable = _table()
        build_hblink_table({"NXDN-ROOM": _nxdn(connection="YES", pings=6)}, ctable)
        env = Environment(loader=FileSystemLoader(str(ROOT / "templates")))
        html = env.get_template("lnksys_table.html").render(_table=ctable)
        self.assertIn("NXDN-ROOM", html)
        self.assertIn("mode-nxdn", html)
        self.assertIn(">NXDN<", html)
        self.assertIn("6 polls", html)
        self.assertNotIn("NXDNPEER", html)
        css = (ROOT / "html" / "css" / "dashboard.css").read_text(encoding="utf-8")
        self.assertIn(".mode-nxdn", css)
        self.assertIn("#1a73e8", css)
