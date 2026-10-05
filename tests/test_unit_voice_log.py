#!/usr/bin/env python3
"""A local unit call becomes a VOICE log line. An OpenBridge copy does not."""
import shutil
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402


NOW = "2026-10-05 18:48:49 BST"


def _parts(system, action="START", trx="RX", peer="235287"):
    return [
        "UNIT VOICE", action, trx, system, "935120762",
        peer, "2348831", "1", "2345875", "1.20",
    ]


class TestUnitVoiceLog(unittest.TestCase):
    def test_hytera_start_is_a_voice_line_for_the_radio(self):
        line = monitor.unit_voice_log_message(_parts("HYTERA-0"), NOW, {}, {}).lstrip()
        self.assertTrue(line.startswith("18:48:49 VOICE START"))
        self.assertIn("SYS: HYTERA-0", line)
        self.assertIn("TS: 1", line)
        self.assertIn("TGID: 2345875", line)
        self.assertIn("SUB: 2348831", line)
        self.assertNotIn("OBP", line)

    def test_end_carries_the_duration(self):
        line = monitor.unit_voice_log_message(_parts("HYTERA-0", action="END"), NOW, {}, {}).lstrip()
        self.assertIn("VOICE END", line)
        self.assertIn("Time: 1s", line)

    def test_openbridge_copy_and_tx_are_not_hears(self):
        self.assertIsNone(monitor.unit_voice_log_message(_parts("OBP-EU"), NOW, {}, {}))
        self.assertIsNone(monitor.unit_voice_log_message(_parts("HYTERA-0", trx="TX"), NOW, {}, {}))


if __name__ == "__main__":
    unittest.main()
