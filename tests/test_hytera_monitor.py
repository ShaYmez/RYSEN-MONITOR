#!/usr/bin/env python3
"""Monitor rendering coverage for native Hytera repeater peer records."""
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

from dmr_utils3.utils import int_id  # noqa: E402
from monitor import add_hb_peer, build_hblink_table, is_routing_master, refresh_hb_peer  # noqa: E402


PEER_ID = (235287).to_bytes(4, "big")


def _hytera_peer():
    return {
        "TX_FREQ": 439437500,
        "RX_FREQ": 430437500,
        "SLOTS": b"3",
        "PACKAGE_ID": "Hytera IP Multi-site Connect",
        "SOFTWARE_ID": "A9.02.03.009",
        "LOCATION": "",
        "DESCRIPTION": "RD985-00000000-000000-U1-0-F",
        "URL": "",
        "CALLSIGN": "GB7NR",
        "RADIO_ID": "235287",
        "PROTOCOL": "HYTERA",
        "HYTERA_HARDWARE": "RD985-00000000-000000-U1-0-F",
        "HYTERA_SERIAL": "14218D0441",
        "HYTERA_MODE": 0,
        "COLORCODE": b"1",
        "TX_POWER": "",
        "LATITUDE": "",
        "LONGITUDE": "",
        "HEIGHT": "",
        "CONNECTION": "YES",
        "CONNECTED": 0,
        "IP": "192.0.2.5",
        "PORT": 50000,
    }


def test_hytera_is_routing_master():
    assert is_routing_master("HYTERA")


def test_hytera_peer_formats_integer_frequency_metadata():
    peers = {}
    add_hb_peer(_hytera_peer(), peers, PEER_ID)
    peer = peers[int_id(PEER_ID)]

    assert peer["PROTOCOL"] == "HYTERA"
    assert peer["CALLSIGN"] == "GB7NR"
    assert peer["RADIO_ID"] == "235287"
    assert peer["TX_FREQ"] == "439.437500 MHz"
    assert peer["RX_FREQ"] == "430.437500 MHz"
    assert peer["HYTERA_HARDWARE"].startswith("RD985")
    assert peer["HYTERA_SERIAL"] == "14218D0441"
    assert peer["SOFTWARE_ID"] == "A9.02.03.009"


def test_refresh_hytera_peer_replaces_pending_firmware():
    peers = {}
    conf = _hytera_peer()
    conf["SOFTWARE_ID"] = "RDAC metadata pending"
    add_hb_peer(conf, peers, PEER_ID)
    peer = peers[int_id(PEER_ID)]
    peer[1]["TS"] = "keep"
    assert peer["SOFTWARE_ID"] == "RDAC metadata pending"

    conf["SOFTWARE_ID"] = "A9.02.03.009"
    refresh_hb_peer(conf, peer, PEER_ID)

    assert peer["SOFTWARE_ID"] == "A9.02.03.009"
    assert peer[1]["TS"] == "keep"


def test_hytera_system_appears_with_repeaters():
    config = {
        "HYTERA-0": {
            "ENABLED": True,
            "MODE": "HYTERA",
            "REPEAT": True,
            "PEERS": {PEER_ID: _hytera_peer()},
        }
    }
    ctable = {"MASTERS": {}, "PEERS": {}, "OPENBRIDGES": {}}
    build_hblink_table(config, ctable)

    assert "HYTERA-0" in ctable["MASTERS"]
    assert int_id(PEER_ID) in ctable["MASTERS"]["HYTERA-0"]["PEERS"]
