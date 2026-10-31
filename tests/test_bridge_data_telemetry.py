#!/usr/bin/env python3
"""RYSEN bridge DATA telemetry is recognized without spurious warnings."""
import shutil
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402

BRDG = monitor.OPCODE["BRDG_EVENT"]


def _quadnet_payload():
    return [
        "UNIT CSBK,DATA,RX,OBP-SystemX,2452713010,3180,2352050,1,2352050",
        "UNIT DATA,DATA,TX,OBP-Qbridge,2452713010,3180,2352050,1,2352050",
        "UNIT DATA,DATA,TX,OBP-Atlanta-GA,2452713010,3180,2352050,1,2352050",
    ]


class TestBridgeDataTelemetry(unittest.TestCase):
    def setUp(self):
        monitor._unit_sms_lh_recent.clear()
        monitor.CONF["OPB_FLTR"]["OPB_FILTER"] = []
        monitor.db_conn = MagicMock()

    def tearDown(self):
        monitor._unit_sms_lh_recent.clear()

    def test_short_bridge_event_is_ignored(self):
        monitor.process_message((BRDG + "GROUP VOICE,END").encode())
        monitor.db_conn.ins_lstheard_log.assert_not_called()

    def test_alias_keeps_later_fields_when_an_earlier_one_is_empty(self):
        table = {1: {"CALLSIGN": "M0VUB", "CITY": None, "STATE": "UK", "NAME": "Shane"}}
        self.assertEqual(monitor.alias_string(1, table), "M0VUB, UK")
        missing_call = {2: {"CALLSIGN": None, "NAME": "Shane"}}
        self.assertEqual(monitor.alias_short(2, missing_call), "Shane")

    @patch("monitor.ensureDeferred")
    def test_unit_csbk_records_lastheard(self, deferred):
        line = _quadnet_payload()[0]
        monitor.process_message((BRDG + line).encode())

        deferred.assert_called_once()
        monitor.db_conn.ins_lstheard_log.assert_called_once_with(
            None, "UNIT CSBK", "OBP-SystemX", "2352050", "2352050")

    @patch("monitor.ensureDeferred")
    def test_unit_data_tx_is_silent(self, deferred):
        for line in _quadnet_payload()[1:]:
            with patch.object(monitor.logger, "debug") as debug_log:
                monitor.process_message((BRDG + line).encode())
                self.assertTrue(
                    any(
                        "BRIDGE DATA telemetry" in str(c)
                        for c in debug_log.call_args_list
                    ))
        monitor.db_conn.ins_lstheard_log.assert_not_called()
        deferred.assert_not_called()

    @patch("monitor.ensureDeferred")
    def test_csbk_then_header_dedupes_on_stream(self, deferred):
        csbk = _quadnet_payload()[0]
        header = (
            "UNIT DATA HEADER,DATA,RX,SYSTEM-1,2452713010,111,2352050,1,2352050"
        )
        monitor.process_message((BRDG + csbk).encode())
        monitor.process_message((BRDG + header).encode())
        self.assertEqual(monitor.db_conn.ins_lstheard_log.call_count, 1)
        self.assertEqual(deferred.call_count, 1)

    @patch("monitor.ensureDeferred")
    def test_group_data_blocks_do_not_warn(self, deferred):
        lines = [
            "DATA HEADER,DATA,RX,SYS,1,2,3,1,9",
            "OTHER DATA,DATA,RX,SYS,1,2,3,1,9",
            "VCSBK 1/2 DATA BLOCK,DATA,RX,SYS,1,2,3,1,9",
            "UNIT VCSBK 3/4 DATA BLOCK,DATA,RX,SYS,1,2,3,1,9",
        ]
        with patch.object(monitor.logger, "warning") as warn:
            for line in lines:
                monitor.process_message((BRDG + line).encode())
            warn.assert_not_called()
        monitor.db_conn.ins_lstheard_log.assert_not_called()

    @patch("monitor.ensureDeferred")
    def test_quadnet_batch_no_unknown_warning(self, deferred):
        with patch.object(monitor.logger, "warning") as warn:
            for line in _quadnet_payload():
                monitor.process_message((BRDG + line).encode())
            unknown = [
                c for c in warn.call_args_list
                if c.args and "Unknown log message" in str(c.args[0])
            ]
            self.assertEqual(unknown, [])


if __name__ == "__main__":
    unittest.main()
