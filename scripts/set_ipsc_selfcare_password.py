#!/usr/bin/env python3
"""
Set selfcare password for an IPSC repeater (Clients.mode = 0).

Uses the same PBKDF2-SHA256 hash as html/ssconfunc.php:
  hash_pbkdf2('sha256', password, 'RYSEN', 2000)  # hex output

Examples:
  python3 set_ipsc_selfcare_password.py 235287 'secret'
  python3 set_ipsc_selfcare_password.py --config /etc/rysen/fdmr-mon.cfg 235287
  python3 set_ipsc_selfcare_password.py --register --callsign GB7NR 235287 'secret'
"""
from __future__ import annotations

import argparse
import configparser
import getpass
import hashlib
import re
import struct
import sys
from pathlib import Path

try:
    import MySQLdb
except ImportError as err:
    sys.exit(
        "mysqlclient is required (pip install mysqlclient).\n"
        f"Import error: {err}"
    )

IPSC_MODE = 0
DEFAULT_CONFIG = Path("/etc/rysen/fdmr-mon.cfg")
PBKDF2_SALT = b"RYSEN"
PBKDF2_ROUNDS = 2000


def hash_password(password: str) -> str:
    digest = hashlib.pbkdf2_hmac(
        "sha256",
        password.encode("utf-8"),
        PBKDF2_SALT,
        PBKDF2_ROUNDS,
    )
    return digest.hex()


def load_db_config(config_path: Path) -> dict:
    if not config_path.is_file():
        sys.exit(f"Config not found: {config_path}")

    parser = configparser.ConfigParser()
    parser.read(config_path)
    if "SELF SERVICE" not in parser:
        sys.exit(f"No [SELF SERVICE] section in {config_path}")

    section = parser["SELF SERVICE"]
    host = section.get("DB_SERVER", "localhost")
    port = int(section.get("DB_PORT", "3306"))
    user = section.get("DB_USERNAME", "selfcare")
    password = section.get("DB_PASSWORD", "")
    database = section.get("DB_NAME", "selfcare")

    if not password:
        sys.exit("DB_PASSWORD is empty in [SELF SERVICE]")

    # Host PHP remaps Docker bridge IPs to localhost (see ssconfunc.php)
    if re.match(r"^172\.16\.238\.\d+$", host):
        host = "127.0.0.1"
        if port == 3306:
            port = 8306

    return {
        "host": host,
        "port": port,
        "user": user,
        "password": password,
        "database": database,
    }


def connect_db(cfg: dict):
    return MySQLdb.connect(
        host=cfg["host"],
        port=cfg["port"],
        user=cfg["user"],
        passwd=cfg["password"],
        db=cfg["database"],
        charset="utf8mb4",
    )


def dmr_id_blob(radio_id: int) -> bytes:
    return struct.pack(">I", radio_id)


def fetch_row(conn, radio_id: int):
    cur = conn.cursor()
    cur.execute(
        "SELECT int_id, callsign, mode, psswd FROM Clients WHERE int_id = %s",
        (radio_id,),
    )
    row = cur.fetchone()
    cur.close()
    return row


def update_password(conn, radio_id: int, psswd_hash: str) -> None:
    cur = conn.cursor()
    cur.execute(
        "UPDATE Clients SET psswd = %s WHERE int_id = %s",
        (psswd_hash.encode("ascii"), radio_id),
    )
    if cur.rowcount == 0:
        cur.close()
        raise SystemExit(
            f"No Clients row for int_id {radio_id}. "
            "Use --register to pre-provision, or wait for repeater to connect."
        )
    conn.commit()
    cur.close()


def register_row(conn, radio_id: int, callsign: str, psswd_hash: str) -> None:
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO Clients (
            int_id, dmr_id, callsign, host, mode, logged_in, modified, last_seen, psswd
        ) VALUES (%s, %s, %s, '', %s, 0, 0, UNIX_TIMESTAMP(), %s)
        ON DUPLICATE KEY UPDATE
            callsign = VALUES(callsign),
            mode = %s,
            psswd = VALUES(psswd)
        """,
        (
            radio_id,
            dmr_id_blob(radio_id),
            callsign[:10],
            IPSC_MODE,
            psswd_hash.encode("ascii"),
            IPSC_MODE,
        ),
    )
    conn.commit()
    cur.close()


def main() -> None:
    ap = argparse.ArgumentParser(
        description="Set IPSC repeater selfcare password (Clients.mode=0)."
    )
    ap.add_argument(
        "--config",
        type=Path,
        default=DEFAULT_CONFIG,
        help=f"fdmr-mon.cfg path (default: {DEFAULT_CONFIG})",
    )
    ap.add_argument(
        "--register",
        action="store_true",
        help="Insert or update row before repeater has connected",
    )
    ap.add_argument(
        "--callsign",
        metavar="CALL",
        help="Required with --register (max 10 chars)",
    )
    ap.add_argument(
        "radio_id",
        type=int,
        metavar="RADIO_ID",
        help="DMR repeater ID (e.g. 235287)",
    )
    ap.add_argument(
        "password",
        nargs="?",
        help="Selfcare password (prompted if omitted)",
    )
    args = ap.parse_args()

    if args.radio_id < 1:
        sys.exit("RADIO_ID must be a positive integer")

    if args.register and not args.callsign:
        sys.exit("--callsign is required with --register")

    password = args.password
    if not password:
        password = getpass.getpass("Selfcare password: ")
        confirm = getpass.getpass("Confirm password: ")
        if password != confirm:
            sys.exit("Passwords do not match")

    if not password or len(password) > 100:
        sys.exit("Password must be 1–100 characters")

    psswd_hash = hash_password(password)
    db_cfg = load_db_config(args.config)
    conn = connect_db(db_cfg)

    try:
        existing = fetch_row(conn, args.radio_id)
        if existing:
            _int_id, callsign, mode, _old = existing
            if mode not in (IPSC_MODE, 4) and mode is not None:
                print(f"Note: existing row has mode={mode} (IPSC uses mode=0)")
            if mode == 4:
                print(
                    "Warning: this int_id is an MMDVM hotspot row (mode=4). "
                    "Refusing to change password. Use hotspot selfcare instead.",
                    file=sys.stderr,
                )
                sys.exit(1)
            update_password(conn, args.radio_id, psswd_hash)
            print(f"Password set for {callsign.strip()} ({args.radio_id})")
        elif args.register:
            register_row(conn, args.radio_id, args.callsign.upper(), psswd_hash)
            print(
                f"Registered IPSC row for {args.callsign.upper()} ({args.radio_id}), password set"
            )
        else:
            sys.exit(
                f"No Clients row for {args.radio_id}. "
                "Wait for repeater to connect, or use --register --callsign CALL."
            )
    finally:
        conn.close()


if __name__ == "__main__":
    main()
