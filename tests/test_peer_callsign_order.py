#!/usr/bin/env python3
"""Repeaters, hotspots, and bridges list by callsign, not master name."""
import shutil
import sys
import unittest
from pathlib import Path

from jinja2 import Environment, FileSystemLoader

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402


def _slot():
    return {"TRX": "", "SUB": "", "DEST": "", "TS": "", "RSSI": ""}


def _peer(callsign, client, protocol=None, rx="430.000", tx="430.000"):
    peer = {
        "CALLSIGN": callsign,
        "RADIO_ID": str(client),
        "RX_FREQ": rx,
        "TX_FREQ": tx,
        "SLOTS": "Duplex",
        "DESCRIPTION": "",
        "SOFTWARE_ID": "",
        "PACKAGE_ID": "",
        "LOCATION": "",
        "TX_POWER": "",
        "LATITUDE": "",
        "LONGITUDE": "",
        "HEIGHT": "",
        "CONNECTED": "1s",
        "TS1_STATIC": [],
        "TS2_STATIC": [],
        1: _slot(),
        2: _slot(),
    }
    if protocol:
        peer["PROTOCOL"] = protocol
    return peer


def _masters():
    """Insertion order is SYSTEM, then IPSC, then HYTERA — not callsign order."""
    return {
        "SYSTEM-9": {"PEERS": {2340002: _peer("M0HOT", 2340002)}},
        "IPSC-1": {"PEERS": {2350001: _peer("GB7AAA", 2350001, protocol="IPSC", rx="N/A", tx="N/A")}},
        "HYTERA-3": {"PEERS": {2350002: _peer("GB3ZZZ", 2350002, protocol="HYTERA")}},
        "SYSTEM-1": {"PEERS": {2340001: _peer("2E0HOT", 2340001)}},
        "SYSTEM-4": {"PEERS": {9000004: _peer("ZBRIDGE", 9000004, rx="N/A", tx="N/A")}},
        "SYSTEM-2": {"PEERS": {9000002: _peer("ABRIDGE", 9000002, rx="N/A", tx="N/A")}},
    }


def _section(html, start, end):
    return html.split(start, 1)[1].split(end, 1)[0]


class TestPeerCallsignOrder(unittest.TestCase):
    def setUp(self):
        self.env = Environment(loader=FileSystemLoader(str(ROOT / "templates")))
        self.env.filters["flag_code"] = monitor.country_flag_code
        self.env.filters["peers_by_callsign"] = monitor.peers_by_callsign
        self.table = {
            "MASTERS": _masters(),
            "PEERS": {},
            "OPENBRIDGES": {},
            "SETUP": {"BRIDGES": False},
        }

    def test_filter_orders_every_master_by_callsign(self):
        ordered = [peer["CALLSIGN"] for _master, _client, peer in monitor.peers_by_callsign(self.table["MASTERS"])]
        self.assertEqual(ordered, ["2E0HOT", "ABRIDGE", "GB3ZZZ", "GB7AAA", "M0HOT", "ZBRIDGE"])

    def test_home_sections_follow_callsign(self):
        html = self.env.get_template("main/connected.html").render(_table=self.table)
        repeaters = _section(html, 'id="rptrs-statbtn"', 'id="htspts-statbtn"')
        hotspots = _section(html, 'id="htspts-statbtn"', 'id="brdg-statbtn"')
        bridges = _section(html, 'id="brdg-statbtn"', "</div>\n</div>")
        self.assertLess(repeaters.index("GB3ZZZ"), repeaters.index("GB7AAA"))
        self.assertLess(hotspots.index("2E0HOT"), hotspots.index("M0HOT"))
        self.assertLess(bridges.index("ABRIDGE"), bridges.index("ZBRIDGE"))

    def test_linked_systems_tables_follow_callsign(self):
        html = self.env.get_template("lnksys_table.html").render(_table=self.table, emaster=False)
        repeaters = _section(html, 'id="tbl_rptrs"', 'id="tbl_htspts"')
        hotspots = _section(html, 'id="tbl_htspts"', 'id="tbl_brdgs"')
        bridges = _section(html, 'id="tbl_brdgs"', 'id="tbl_srv"')
        self.assertLess(repeaters.index("GB3ZZZ"), repeaters.index("GB7AAA"))
        self.assertLess(hotspots.index("2E0HOT"), hotspots.index("M0HOT"))
        self.assertLess(bridges.index("ABRIDGE"), bridges.index("ZBRIDGE"))

    def test_static_talkgroup_tables_follow_callsign(self):
        html = self.env.get_template("statictg_table.html").render(_table=self.table, emaster=False)
        repeaters = _section(html, 'id="tbl_rptrs"', 'id="tbl_htspts"')
        hotspots = _section(html, 'id="tbl_htspts"', 'id="tbl_brdgs"')
        bridges = html.split('id="tbl_brdgs"', 1)[1]
        self.assertLess(repeaters.index("GB3ZZZ"), repeaters.index("GB7AAA"))
        self.assertLess(hotspots.index("2E0HOT"), hotspots.index("M0HOT"))
        self.assertLess(bridges.index("ABRIDGE"), bridges.index("ZBRIDGE"))


if __name__ == "__main__":
    unittest.main()
