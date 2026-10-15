#!/usr/bin/env python3
"""YSF reflector stanzas on Linked Systems."""
import shutil
import sys
import time
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


def _ysf(connection="NO", pings=0, connected=None, ackd_missing=True, dgid=0):
    stats = {
        "CONNECTION": connection,
        "CONNECTED": connected,
        "PINGS_SENT": pings,
    }
    if not ackd_missing:
        stats["PINGS_ACKD"] = pings
    return {
        "MODE": "YSFPEER",
        "ENABLED": True,
        "CALLSIGN": b"MX0SXC    ",
        "RADIO_ID": (2356427).to_bytes(4, "big"),
        "DESCRIPTION": "GB YSF 2350",
        "DGID": dgid,
        "MASTER_IP": "77.68.1.2",
        "MASTER_PORT": 42000,
        "STATS": stats,
    }


def _xlx():
    return {
        "MODE": "XLXPEER",
        "ENABLED": True,
        "CALLSIGN": b"XLX999  ",
        "RADIO_ID": (9999).to_bytes(4, "big"),
        "LOCATION": b"Module A",
        "DESCRIPTION": b"XLX room",
        "URL": b"",
        "SLOTS": b"2",
        "MASTER_IP": "127.0.0.1",
        "MASTER_PORT": 62030,
        "XLXSTATS": {
            "CONNECTION": "YES",
            "CONNECTED": time.time() - 125,
            "PINGS_SENT": 8,
            "PINGS_ACKD": 7,
        },
    }


def _table():
    return {"MASTERS": {}, "PEERS": {}, "OPENBRIDGES": {}}


class TestYsfMonitor(unittest.TestCase):
    def setUp(self):
        self.hb_inc = monitor.CONF["GLOBAL"].get("HB_INC")
        monitor.CONF["GLOBAL"]["HB_INC"] = False

    def tearDown(self):
        monitor.CONF["GLOBAL"]["HB_INC"] = self.hb_inc

    def test_ysf_reflector_lands_in_services(self):
        config = {
            "YSF-2350": _ysf(),
            "PEER-1": {
                "MODE": "PEER",
                "ENABLED": True,
                "CALLSIGN": b"PEER1",
                "RADIO_ID": (1).to_bytes(4, "big"),
                "LOCATION": "",
                "DESCRIPTION": "",
                "SLOTS": b"2",
                "MASTER_IP": "127.0.0.1",
                "MASTER_PORT": 62030,
                "STATS": {"CONNECTION": "NO", "CONNECTED": None, "PINGS_SENT": 0, "PINGS_ACKD": 0},
            },
        }
        ctable = _table()
        build_hblink_table(config, ctable)
        self.assertIn("YSF-2350", ctable["PEERS"])
        self.assertNotIn("PEER-1", ctable["PEERS"])
        row = ctable["PEERS"]["YSF-2350"]
        self.assertEqual(row["MODE"], "YSFPEER")
        self.assertEqual(row["CALLSIGN"], "MX0SXC")
        self.assertEqual(row["RADIO_ID"], 2356427)
        self.assertEqual(row["SLOTS"], "2")
        self.assertEqual(row["LOCATION"], "GB YSF 2350")
        self.assertEqual(row["STATS"]["CONNECTION"], "NO")
        self.assertEqual(row["STATS"]["CONNECTED"], "--   --")
        self.assertEqual(row["STATS"]["PINGS_SENT"], 0)
        self.assertIsInstance(row[1], dict)
        self.assertIsInstance(row[2], dict)

    def test_dgid_is_shown_when_the_stanza_is_not_the_whole_room(self):
        ctable = _table()
        build_hblink_table({"YSF-9": _ysf(dgid=9)}, ctable)
        self.assertEqual(ctable["PEERS"]["YSF-9"]["LOCATION"], "GB YSF 2350 · DGID 9")

    def test_connected_ysf_keeps_uptime_without_rysen_stamp(self):
        config = {"YSF-2350": _ysf(connection="YES", pings=4)}
        ctable = _table()
        build_hblink_table(config, ctable)
        row = ctable["PEERS"]["YSF-2350"]
        row[2]["TS"] = True
        row[2]["TRX"] = "RX"
        started = row["_CONNECTED_AT"]
        self.assertIsInstance(started, float)
        self.assertNotEqual(row["STATS"]["CONNECTED"], "--   --")
        self.assertEqual(row["STATS"]["PINGS_SENT"], 4)
        self.assertIsNone(row["STATS"]["PINGS_ACKD"])

        config["YSF-2350"]["STATS"]["PINGS_SENT"] = 9
        update_hblink_table(config, ctable)
        self.assertEqual(row["_CONNECTED_AT"], started)
        self.assertEqual(row["STATS"]["PINGS_SENT"], 9)
        self.assertTrue(row[2]["TS"])
        self.assertEqual(row[2]["TRX"], "RX")

        config["YSF-2350"]["STATS"]["CONNECTION"] = "NO"
        update_hblink_table(config, ctable)
        self.assertNotIn("_CONNECTED_AT", row)
        self.assertEqual(row["STATS"]["CONNECTED"], "--   --")
        self.assertEqual(row["STATS"]["PINGS_SENT"], 0)

    def test_reflector_added_and_removed_on_config_refresh(self):
        ctable = _table()
        ctable["MASTERS"]["SYSTEM-1"] = {"REPEAT": "repeat", "PEERS": {}}
        update_hblink_table({"SYSTEM-1": {"ENABLED": True, "MODE": "MASTER", "REPEAT": True, "PEERS": {}}}, ctable)
        self.assertEqual(ctable["PEERS"], {})

        update_hblink_table({
            "SYSTEM-1": {"ENABLED": True, "MODE": "MASTER", "REPEAT": True, "PEERS": {}},
            "YSF-2350": _ysf(connection="YES", pings=2),
        }, ctable)
        self.assertIn("YSF-2350", ctable["PEERS"])

        update_hblink_table({
            "SYSTEM-1": {"ENABLED": True, "MODE": "MASTER", "REPEAT": True, "PEERS": {}},
        }, ctable)
        self.assertNotIn("YSF-2350", ctable["PEERS"])

    def test_xlx_stats_still_use_the_ack_counter(self):
        ctable = _table()
        build_hblink_table({"XLX-999": _xlx()}, ctable)
        row = ctable["PEERS"]["XLX-999"]
        self.assertEqual(row["MODE"], "XLXPEER")
        self.assertEqual(row["CALLSIGN"], "XLX999")
        self.assertEqual(row["LOCATION"], "Module A")
        self.assertEqual(row["SLOTS"], "2")
        self.assertEqual(row["STATS"]["CONNECTION"], "YES")
        self.assertEqual(row["STATS"]["PINGS_SENT"], 8)
        self.assertEqual(row["STATS"]["PINGS_ACKD"], 7)
        self.assertIn("m", row["STATS"]["CONNECTED"])

    @patch("monitor.push_live_dashboard")
    def test_ysf_voice_lights_slot_2(self, _push):
        ctable = _table()
        build_hblink_table({"YSF-2350": _ysf(connection="YES", pings=1)}, ctable)
        saved = monitor.CTABLE
        monitor.CTABLE = ctable
        try:
            rts_update([
                "GROUP VOICE", "START", "RX", "YSF-2350", "stream",
                "2356427", "2340001", "2", "9", "0",
            ])
        finally:
            monitor.CTABLE = saved
        slot = ctable["PEERS"]["YSF-2350"][2]
        self.assertTrue(slot["TS"])
        self.assertEqual(slot["TRX"], "RX")
        self.assertIn("2340001", slot["SUB"])
        self.assertIn("TG 9", slot["DEST"])

    def test_services_row_names_ysf_and_counts_polls(self):
        ctable = _table()
        build_hblink_table({"YSF-2350": _ysf(connection="YES", pings=6, dgid=0)}, ctable)
        env = Environment(loader=FileSystemLoader(str(ROOT / "templates")))
        html = env.get_template("lnksys_table.html").render(_table=ctable)
        self.assertIn("YSF-2350", html)
        self.assertIn("yaesu.png", html)
        self.assertIn(">YSF<", html)
        self.assertIn("MX0SXC", html)
        self.assertIn("6 polls", html)
        self.assertNotIn("YSFPEER", html)
