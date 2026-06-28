#!/usr/bin/env python3
"""Smoke tests for Motorola IPSC repeaters on RYSEN-Monitor."""
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402
from dmr_utils3.utils import int_id  # noqa: E402
from monitor import (  # noqa: E402
    _alias_location,
    _ipsc_callsign,
    _ipsc_location,
    _ipsc_radio_id,
    add_hb_peer,
    build_hblink_table,
    is_routing_master,
    refresh_hb_peer,
    update_hblink_table,
)


def _ipsc_peer(callsign=b"GB7NR   ", radio_id="235287", location=b""):
    return {
        "TX_FREQ": b"0000000",
        "RX_FREQ": b"0000000",
        "SLOTS": b"3",
        "PACKAGE_ID": b"Motorola IPSC Repeater" + b"\x00" * 19,
        "SOFTWARE_ID": b"Motorola IPSC 04.02.04.01 (voice, data)" + b"\x00" * 2,
        "LOCATION": location,
        "DESCRIPTION": b"Digital TS1:IPSC TS2:IPSC",
        "URL": b"",
        "CALLSIGN": callsign,
        "RADIO_ID": radio_id,
        "PROTOCOL": "IPSC",
        "COLORCODE": b"1",
        "CONNECTION": "YES",
        "CONNECTED": 0,
        "IP": "192.168.1.10",
        "PORT": 62030,
    }


def test_is_routing_master():
    assert is_routing_master("MASTER")
    assert is_routing_master("IPSC")
    assert not is_routing_master("PEER")


def test_ipsc_system_in_masters():
    peer_key = b"\x00\x00\x03\x97"
    config = {
        "IPSC-198": {
            "ENABLED": True,
            "MODE": "IPSC",
            "REPEAT": True,
            "PEERS": {peer_key: _ipsc_peer()},
        }
    }
    ctable = {"MASTERS": {}, "PEERS": {}, "OPENBRIDGES": {}}
    build_hblink_table(config, ctable)
    assert "IPSC-198" in ctable["MASTERS"]
    peer = ctable["MASTERS"]["IPSC-198"]["PEERS"][int_id(peer_key)]
    assert peer["CALLSIGN"] == "GB7NR"
    assert peer["RADIO_ID"] == "235287"
    assert peer["PROTOCOL"] == "IPSC"
    assert peer["SOFTWARE_ID"].startswith("Motorola IPSC 04.02.04.01")
    assert peer["PACKAGE_ID"] == "Motorola IPSC Repeater"
    assert peer["DESCRIPTION"] == "Digital TS1:IPSC TS2:IPSC"
    assert peer["LOCATION"] == ""
    assert peer["TX_FREQ"] == "N/A"
    assert peer["RX_FREQ"] == "N/A"


def test_ipsc_location_from_config():
    peer_conf = _ipsc_peer(location=b"Nottingham")
    assert _ipsc_location(peer_conf, "235287") == "Nottingham"


def test_ipsc_location_fallback_to_peer_ids():
    monitor.peer_ids.clear()
    monitor.peer_ids[235287] = {
        "CALLSIGN": "GB7NR",
        "CITY": "Nottingham",
        "STATE": "",
    }
    peer_conf = _ipsc_peer()
    assert _ipsc_location(peer_conf, "235287") == "Nottingham"
    assert _alias_location("235287") == "Nottingham"


def test_ipsc_peer_metadata_refresh():
    monitor.peer_ids.clear()
    peer_key = b"\x00\x00\x03\x97"
    config = {
        "IPSC-198": {
            "ENABLED": True,
            "MODE": "IPSC",
            "REPEAT": True,
            "PEERS": {peer_key: _ipsc_peer()},
        }
    }
    ctable = {"MASTERS": {}, "PEERS": {}, "OPENBRIDGES": {}}
    build_hblink_table(config, ctable)
    peer_id = int_id(peer_key)
    assert ctable["MASTERS"]["IPSC-198"]["PEERS"][peer_id]["LOCATION"] == ""

    config["IPSC-198"]["PEERS"][peer_key]["LOCATION"] = b"Nottingham"
    update_hblink_table(config, ctable)
    assert ctable["MASTERS"]["IPSC-198"]["PEERS"][peer_id]["LOCATION"] == "Nottingham"

    config["IPSC-198"]["PEERS"][peer_key]["LOCATION"] = b""
    monitor.peer_ids.clear()
    monitor.peer_ids[235287] = {"CALLSIGN": "GB7NR", "CITY": "Nottingham", "STATE": ""}
    update_hblink_table(config, ctable)
    assert ctable["MASTERS"]["IPSC-198"]["PEERS"][peer_id]["LOCATION"] == "Nottingham"


def test_refresh_hb_peer_preserves_timeslots():
    peer_key = b"\x00\x00\x03\x97"
    peers = {}
    add_hb_peer(_ipsc_peer(), peers, peer_key)
    peer = peers[int_id(peer_key)]
    peer[1]["TS"] = True
    peer[1]["TRX"] = "RX"
    peer[1]["CALL"] = "TEST"

    refresh_hb_peer(_ipsc_peer(location=b"Nottingham"), peer, peer_key)
    assert peer["LOCATION"] == "Nottingham"
    assert peer[1]["TS"] is True
    assert peer[1]["TRX"] == "RX"
    assert peer[1]["CALL"] == "TEST"


def test_ipsc_callsign_fallback_to_peer_ids():
    monitor.peer_ids.clear()
    monitor.peer_ids[235287] = {"CALLSIGN": "GB7NR"}
    peer_conf = _ipsc_peer(callsign=b"235287", radio_id="235287")
    peer_key = b"\x00\x00\x03\x97"
    assert _ipsc_callsign(peer_conf, peer_key, "235287") == "GB7NR"


def test_ipsc_radio_id_from_peer_conf():
    peer_conf = _ipsc_peer()
    peer_key = b"\x00\x00\x03\x97"
    assert _ipsc_radio_id(peer_conf, peer_key) == "235287"


def test_non_ipsc_peer_unchanged():
    peer_conf = {
        "TX_FREQ": b"4345000",
        "RX_FREQ": b"4345000",
        "SLOTS": b"2",
        "PACKAGE_ID": b"MMDVM_HS_Hat",
        "SOFTWARE_ID": b"2024.02.05",
        "LOCATION": b"Test City",
        "DESCRIPTION": b"",
        "URL": b"",
        "CALLSIGN": b"TEST1   ",
        "COLORCODE": b"1",
        "TX_POWER": b"10W",
        "LATITUDE": b"51.0",
        "LONGITUDE": b"-1.0",
        "HEIGHT": b"100m",
        "CONNECTION": "YES",
        "CONNECTED": 0,
        "IP": "10.0.0.1",
        "PORT": 62030,
    }
    peers = {}
    add_hb_peer(peer_conf, peers, b"\x00\x12\xd6\x87")
    peer = peers[1234567]
    assert peer["CALLSIGN"] == "TEST1"
    assert "PROTOCOL" not in peer
    assert "RADIO_ID" not in peer
