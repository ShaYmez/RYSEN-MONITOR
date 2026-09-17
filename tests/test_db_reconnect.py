"""Monitor MariaDB pool must reconnect after wait_timeout / server-gone-away."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_mon_db_reconnects_after_drop():
    text = (ROOT / "mon_db.py").read_text(encoding="utf-8")
    assert "cp_reconnect=True" in text


def test_proxy_db_reconnects_after_drop():
    text = (ROOT / "proxy" / "proxy_db.py").read_text(encoding="utf-8")
    assert "cp_reconnect=True" in text
