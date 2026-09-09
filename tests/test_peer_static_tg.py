#!/usr/bin/env python3
"""Per-peer Static TG regression tests for MASTER and IPSC OPTIONS."""
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

if not (ROOT / "fdmr-mon.cfg").exists():
    shutil.copy(ROOT / "fdmr-mon_SAMPLE.cfg", ROOT / "fdmr-mon.cfg")

import monitor  # noqa: E402
from dmr_utils3.utils import bytes_4  # noqa: E402


def _ctable(*peer_ids):
    return {
        "MASTERS": {
            "MASTER-1": {
                "PEERS": {peer_id: {} for peer_id in peer_ids},
            }
        },
        "PEERS": {},
        "OPENBRIDGES": {},
    }


def test_two_master_peers_never_share_static_talkgroups(monkeypatch):
    peer_a = 234587501
    peer_b = 235653502
    config = {
        "MASTER-1": {
            "MODE": "MASTER",
            "TS1_STATIC": "999",
            "TS2_STATIC": "998",
            "PEERS": {
                bytes_4(peer_a): {"OPTIONS": "TS2=2350,121;"},
                bytes_4(peer_b): {"OPTIONS": "TS1=91;TS2=116;"},
            },
        }
    }
    monkeypatch.setattr(monitor, "CONFIG", config)
    monkeypatch.setattr(monitor, "CTABLE", _ctable(peer_a, peer_b))
    monkeypatch.setattr(monitor, "BRIDGES", {})

    monitor.build_tgstats()

    peers = monitor.CTABLE["MASTERS"]["MASTER-1"]["PEERS"]
    assert peers[peer_a]["TS1_STATIC"] == []
    assert peers[peer_a]["TS2_STATIC"] == ["2350", "121"]
    assert peers[peer_b]["TS1_STATIC"] == ["91"]
    assert peers[peer_b]["TS2_STATIC"] == ["116"]


def test_empty_master_peer_does_not_fall_back_to_stanza(monkeypatch):
    peer = 234587501
    monkeypatch.setattr(monitor, "CONFIG", {
        "MASTER-1": {
            "MODE": "MASTER",
            "OPTIONS": "TS1=9;TS2=999;",
            "TS1_STATIC": "91",
            "TS2_STATIC": "2350",
            "PEERS": {bytes_4(peer): {"OPTIONS": ""}},
        }
    })
    monkeypatch.setattr(monitor, "CTABLE", _ctable(peer))
    monkeypatch.setattr(monitor, "BRIDGES", {})

    monitor.build_tgstats()

    shown = monitor.CTABLE["MASTERS"]["MASTER-1"]["PEERS"][peer]
    assert shown["TS1_STATIC"] == []
    assert shown["TS2_STATIC"] == []


def test_options_parser_supports_legacy_numbered_nul_and_deduplication():
    ts1, ts2 = monitor.ts_lists_from_options(
        b"TS1_STATIC=91,bad,91;TS2=999;"
        b"TS2_2=2350;TS2_1=116,116;TS2_3=2350;\x00\x00"
    )
    assert ts1 == ["91"]
    assert ts2 == ["116", "2350"]


def test_simplex_and_duplex_options():
    assert monitor.ts_lists_from_options("TS2=2350;") == ([], ["2350"])
    assert monitor.ts_lists_from_options("TS1=91;TS2=2350;") == (
        ["91"],
        ["2350"],
    )
    assert monitor.ts_lists_from_options("") == ([], [])


def test_ipsc_uses_system_options_fallback(monkeypatch):
    peer = 235287
    monkeypatch.setattr(monitor, "CONFIG", {
        "MASTER-1": {
            "MODE": "IPSC",
            "OPTIONS": "TS1=91;TS2=2350;",
            "PEERS": {bytes_4(peer): {"OPTIONS": ""}},
        }
    })
    monkeypatch.setattr(monitor, "CTABLE", _ctable(peer))
    monkeypatch.setattr(monitor, "BRIDGES", {})

    monitor.build_tgstats()

    shown = monitor.CTABLE["MASTERS"]["MASTER-1"]["PEERS"][peer]
    assert shown["TS1_STATIC"] == ["91"]
    assert shown["TS2_STATIC"] == ["2350"]


def test_config_refresh_rebuilds_static_talkgroups(monkeypatch):
    peer = 234587501
    config = {
        "MASTER-1": {
            "MODE": "MASTER",
            "PEERS": {bytes_4(peer): {"OPTIONS": "TS2=2350;"}},
        }
    }
    monkeypatch.setattr(monitor, "CONFIG", config)
    monkeypatch.setattr(monitor, "CTABLE", _ctable(peer))
    monkeypatch.setattr(monitor, "BRIDGES", {})
    monkeypatch.setattr(monitor, "build_time", 0)
    monkeypatch.setattr(monitor, "GROUPS", {key: [] for key in monitor.GROUPS})

    monitor.build_stats()
    assert monitor.CTABLE["MASTERS"]["MASTER-1"]["PEERS"][peer]["TS2_STATIC"] == ["2350"]

    config["MASTER-1"]["PEERS"][bytes_4(peer)]["OPTIONS"] = "TS2=121,116;"
    monkeypatch.setattr(monitor, "build_time", 0)
    monitor.build_stats()
    assert monitor.CTABLE["MASTERS"]["MASTER-1"]["PEERS"][peer]["TS2_STATIC"] == ["121", "116"]
