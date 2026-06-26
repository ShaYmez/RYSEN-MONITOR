# IPSC selfcare roadmap

Surgical plan for IPSC repeater static talkgroup (TS1/TS2) selfcare. MMDVM hotspot selfcare stays as-is. IPSC is not required on live systems until RYSEN `ipsc` is merged to `master` and deployed.

## How it fits together

```
MMDVM (unchanged)                    IPSC (new)
─────────────────                    ──────────
Hotspot → proxy :62031               Repeater → ipsc-proxy :56002
proxy → Clients row (mode 4)         RYSEN → Clients row (mode 0)
PHP selfcare → modified=1            PHP selfcare → modified=1
proxy → RPTO → hotspot               RYSEN master poll → options_config()
hotspot → OPTIONS → master           (no packet to repeater)
master → static bridges              master → static bridges
```

Static talkgroups for IPSC are applied **on the server** (`bridge_master.options_config()` → `TS1_STATIC` / `TS2_STATIC` / `make_static_tg()`). The repeater does not receive a Homebrew options string.

## Locked decisions

| Topic | Decision |
|-------|----------|
| MMDVM login | **Callsign + password** — no change |
| IPSC login | **Radio ID + password** (DMR repeater ID, e.g. `235287`) |
| First password | Admin runs `scripts/set_ipsc_selfcare_password.py` when commissioning |
| Claim / email verify | **Deferred** — see [Future](#future-claim--email-verification) |
| New IPSC proxy | **No** — reuse existing `ipsc-proxy`; master reads `Clients` directly |
| DB schema on live systems | **No ALTER TABLE** — use reserved `mode` value (see below) |

## Database: no schema migration

Existing `Clients` table is sufficient. No new columns on production MariaDB.

| `mode` | Meaning | Who writes it |
|--------|---------|---------------|
| `4` | MMDVM simplex hotspot | `rysen-sp-selfcare` proxy (unchanged) |
| other `> 0` | MMDVM duplex hotspot | proxy (unchanged) |
| **`0`** | **IPSC repeater** | RYSEN `ipsc_master` on register |

**Why this works**

- Live MMDVM rows keep `mode = 4` (or duplex values). Behaviour is unchanged.
- IPSC rows use `mode = 0`, which the proxy never creates today.
- Master polls only `modified = 1 AND mode = 0`.
- When IPSC is enabled, the proxy needs **one** filter so it does not try RPTO for IPSC rows:

```sql
-- proxy slct_db (at IPSC rollout only)
SELECT dmr_id, options FROM Clients WHERE modified = 1 AND logged_in = 1 AND mode > 0
```

Until IPSC is deployed, that line is not required on a MMDVM-only system.

**Slot mapping** (`IPSC-79`, etc.) is resolved at runtime by scanning `CONFIG['SYSTEMS']` for `MODE == IPSC` and matching peer `RADIO_ID` to `int_id`. No `system_slot` column.

**Password on re-register:** IPSC upsert must **not** clear `psswd` (today’s proxy `ins_conf` sets `psswd = NULL` on reconnect — that path must not run for IPSC rows).

## Work split

| Repo | Scope |
|------|--------|
| **RYSEN** (`ipsc` → `master`) | Register IPSC peers in `Clients`; poll `modified` IPSC rows; apply `options_config()` |
| **RYSEN-MONITOR** (`ipsc`) | Docs, password script, then IPSC login + TS1/TS2-only UI |

---

## Phase 1 — RYSEN: register IPSC peers in `Clients`

**Goal:** Connected IPSC repeater appears in `Clients` like a hotspot, without touching MMDVM code paths.

| Step | Change |
|------|--------|
| 1.1 | Small `selfcare_db.py` (Twisted `adbapi`, same pattern as `proxy/proxy_db.py`) |
| 1.2 | Config block in `rysen.cfg` (see [Configuration](#configuration)) |
| 1.3 | Hook `ipsc_master._register_hbp_peer()` → upsert `Clients` with `mode = 0`, `protocol` N/A |
| 1.4 | Upsert fields: `int_id`, `dmr_id`, `callsign`, `host`, `logged_in = 1`, `last_seen` |
| 1.5 | On duplicate: update callsign/host/last_seen; **preserve** `psswd` and `options` |
| 1.6 | On de-register / timeout: `logged_in = 0` only |
| 1.7 | Gate all of the above on `[SELF SERVICE] ENABLED = True` |

**Acceptance:** Repeater registers → `SELECT * FROM Clients WHERE int_id = <radio_id>` shows `mode = 0`, `logged_in = 1`. Reconnect does not wipe password set by admin script.

---

## Phase 2 — RYSEN: apply selfcare options (master poll)

**Goal:** `TS1=` / `TS2=` from selfcare apply to the correct `IPSC-N` slot without RPTO.

| Step | Change |
|------|--------|
| 2.1 | Periodic task (~5 s): `SELECT int_id, options FROM Clients WHERE modified = 1 AND mode = 0` |
| 2.2 | Find slot: `CONFIG['SYSTEMS'][*]` where `MODE == 'IPSC'` and connected peer `RADIO_ID == int_id` |
| 2.3 | Set `CONFIG['SYSTEMS'][slot]['OPTIONS']` to DB string, e.g. `TS1=9,10;TS2=2350;` |
| 2.4 | Call existing `options_config()` for that slot |
| 2.5 | On success: `modified = 0`; on failure: log, leave `modified = 1` |
| 2.6 | If `options` empty on first register, seed from cfg `TS1_STATIC` / `TS2_STATIC` |

**Acceptance:** Manual test:

```sql
UPDATE Clients SET options = 'TS2=2350;', modified = 1 WHERE int_id = 235287 AND mode = 0;
```

Within one poll interval, RYSEN logs show static bridge updates for the correct `IPSC-N`.

---

## Phase 3 — RYSEN-MONITOR: IPSC selfcare UI

**Goal:** Sysop edits TS1/TS2 for their repeater. MMDVM pages unchanged.

| Step | Change |
|------|--------|
| 3.1 | Login: if username is all digits, authenticate by `int_id` + password for `mode = 0` rows only |
| 3.2 | MMDVM: existing callsign login path unchanged |
| 3.3 | Device picker: show radio ID + IPSC label for `mode = 0` |
| 3.4 | Form: TS1 / TS2 only for IPSC (hide PASS, DIAL, STICKY, etc.) |
| 3.5 | `updateDevOptions()` unchanged — same `TS1=…;TS2=…;` string |

**Acceptance:** Login with `235287` + password → change TS2 → traffic uses new static TG after Phase 2 poll.

---

## Phase 4 — Rollout on live systems

Minimal checklist when IPSC is merged and a site adds a repeater:

1. Deploy RYSEN build with IPSC + selfcare hooks.
2. Add `[SELF SERVICE]` to `rysen.cfg` if not already present (monitor may already use it).
3. **No** DB migration.
4. Patch proxy `slct_db` to `AND mode > 0` (one line, IPSC sites only).
5. Commission repeater: `set_ipsc_selfcare_password.py <radio_id> '<password>'`.
6. Repeater connects → row appears → sysop logs in with radio ID.

MMDVM-only sites: skip steps 4–6 until IPSC is added.

---

## Admin password script

Set or change selfcare password for an IPSC repeater **before or after** first connect.

```bash
# From repo (uses /etc/rysen/fdmr-mon.cfg by default)
python3 scripts/set_ipsc_selfcare_password.py 235287 'your-secret'

# Explicit config
python3 scripts/set_ipsc_selfcare_password.py --config /etc/rysen/fdmr-mon.cfg 235287

# Pre-provision before first connect (creates row with mode=0)
python3 scripts/set_ipsc_selfcare_password.py --register --callsign GB7NR 235287 'your-secret'
```

Uses the same PBKDF2-SHA256 hash as PHP selfcare (`salt=RYSEN`, 2000 rounds). Requires `mysqlclient` (see `requirements.txt`).

---

## Configuration

### RYSEN `rysen.cfg` (new / extended)

```ini
[SELF SERVICE]
ENABLED: True
DB_HOST: mariadb
DB_PORT: 3306
DB_USER: selfcare
DB_PASS: ...
DB_NAME: selfcare
POLL_INTERVAL: 5
```

Docker services use `DB_HOST=mariadb`. Host PHP and admin scripts use the **IP** in `fdmr-mon.cfg` `[SELF SERVICE]` `DB_SERVER` (not the Docker DNS name) — see deployment notes in your compose setup.

### `fdmr-mon.cfg` (monitor / PHP — unchanged for MMDVM)

Existing `[SELF SERVICE]` section continues to serve PHP selfcare and the password script.

---

## Future: claim / email verification

Not in scope for v1. When needed:

- Self-service “claim repeater” while `logged_in = 1` and row has empty `psswd`
- Email magic link; optional cross-check against operator registry or RadioID-style database
- Separate `owner_email` column would be added then — not required now

---

## Testing

| Test | Where |
|------|--------|
| IPSC register → `Clients` row `mode=0` | RYSEN + DB |
| Password script → login hash matches PHP | RYSEN-MONITOR script + `ssconfunc.php` |
| `modified=1` → static bridges | RYSEN logs |
| MMDVM hotspot selfcare regression | Existing site, no proxy query change until IPSC |
| Radio ID login | RYSEN-MONITOR Phase 3 |

---

## References

- RYSEN `ipsc` branch: `ipsc_master.py`, `bridge_master.py` (`options_config`, `make_static_tg`)
- RYSEN-MONITOR: `proxy/proxy_db.py`, `html/ssconfunc.php`, `html/ssmain.php`
- IPSC cfg sample: RYSEN `IPSC-SAMPLE.cfg` (`TS1_STATIC`, `TS2_STATIC`, `MAX_PEERS: 1`)
