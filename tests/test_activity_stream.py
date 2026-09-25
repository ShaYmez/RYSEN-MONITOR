#!/usr/bin/env python3
"""Activity indicators follow the current stream and ignore a late END."""
import shutil
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402


def _slot():
    return {
        "TS": "", "TYPE": "", "SUB": "", "SRC": "", "DEST": "",
        "CALL": "", "TG": "", "TRX": "", "SID": "",
    }


def _event(action, stream_id, peer="123", slot="2"):
    return [
        "GROUP VOICE", action, "RX", "MASTER", stream_id,
        peer, "456", slot, "9",
    ]


class TestActivityStream(unittest.TestCase):

    def setUp(self):
        monitor.CTABLE["MASTERS"].clear()
        monitor.CTABLE["OPENBRIDGES"].clear()
        peer = {1: _slot(), 2: _slot()}
        monitor.CTABLE["MASTERS"]["MASTER"] = {"PEERS": {123: peer, 124: _slot_pair()}}

    def tearDown(self):
        monitor.CTABLE["MASTERS"].clear()
        monitor.CTABLE["OPENBRIDGES"].clear()

    @patch("monitor.push_live_dashboard")
    def test_end_clears_the_stream_that_started(self, _push):
        monitor.rts_update(_event("START", "99"))
        talker = monitor.CTABLE["MASTERS"]["MASTER"]["PEERS"][123][2]
        listener = monitor.CTABLE["MASTERS"]["MASTER"]["PEERS"][124][2]
        self.assertTrue(talker["TS"])
        self.assertEqual(talker["TRX"], "RX")
        self.assertEqual(listener["TRX"], "TX")
        self.assertEqual(talker["SID"], "99")

        monitor.rts_update(_event("END", "99"))
        self.assertFalse(talker["TS"])
        self.assertEqual(talker["TRX"], "")
        self.assertEqual(listener["TRX"], "")
        self.assertEqual(talker["SID"], "")

    @patch("monitor.push_live_dashboard")
    def test_late_end_does_not_clear_a_newer_call(self, push):
        monitor.rts_update(_event("START", "99"))
        monitor.rts_update(_event("START", "100"))
        monitor.rts_update(_event("END", "99"))
        talker = monitor.CTABLE["MASTERS"]["MASTER"]["PEERS"][123][2]
        self.assertTrue(talker["TS"])
        self.assertEqual(talker["SID"], "100")
        self.assertEqual(talker["TRX"], "RX")
        self.assertEqual(push.call_count, 2)

    @patch("monitor.push_live_dashboard")
    def test_openbridge_end_drops_only_that_stream(self, _push):
        monitor.CTABLE["OPENBRIDGES"]["OBP"] = {"STREAMS": {}}
        monitor.rts_update(_event("START", "55"))
        # START above is a master event; paint the bridge stream directly.
        monitor.CTABLE["OPENBRIDGES"]["OBP"]["STREAMS"]["55"] = ("RX", "ZL2BEZ", "9", 1)
        monitor.CTABLE["OPENBRIDGES"]["OBP"]["STREAMS"]["56"] = ("RX", "M0VUB", "9", 1)
        monitor.rts_update([
            "GROUP VOICE", "END", "RX", "OBP", "55", "123", "456", "1", "9",
        ])
        streams = monitor.CTABLE["OPENBRIDGES"]["OBP"]["STREAMS"]
        self.assertNotIn("55", streams)
        self.assertIn("56", streams)


def _slot_pair():
    return {1: _slot(), 2: _slot()}
