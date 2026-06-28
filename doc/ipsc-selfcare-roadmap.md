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
| MMDVM login | **Callsign + password** — `mode > 0` only |
| IPSC login | **Callsign or radio ID + password** — routes by logged-in `Clients` row and `mode` (`0` = IPSC) |
| First password | Admin via `selfcare-admin`, **or** sysop first-time claim on login when `psswd` empty and repeater online |
| Claim / email verify | **Self-service claim in v1**; email magic-link verification **deferred** — see [Future](#future-email-verification) |
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

## Repo ownership (source of truth)

Core Python for the stack lives in **RYSEN**. Deployment images are built from segmented repos.

```
RYSEN                          RYSEN-SP-SELFCARE              Docker Hub
(master copy of scripts)  →    (proxy stack only)        →    shaymez/rysen-sp-selfcare:latest
                               hotspot_proxy_v2.py              proxy container :62031

RYSEN                          (in same image / compose)
ipsc_proxy.py             →    systemx / ipsc-proxy       →    :56002 UDP forward only (no selfcare)

RYSEN-MONITOR                  Host Apache
PHP selfcare UI           →    /var/www/html/dashboard
```

| File / concern | Source of truth | Deployed via |
|----------------|-----------------|--------------|
| `proxy_db.py`, `hotspot_proxy_v2.py` | **RYSEN** | Merge → **RYSEN-SP-SELFCARE** → proxy image |
| IPSC register + options poll | **RYSEN** `ipsc` | `systemx` container rebuild |
| `ipsc_proxy.py` | **RYSEN** | `ipsc-proxy` container (no selfcare changes) |
| Selfcare PHP/JS, admin script | **RYSEN-MONITOR** | Host web root (git pull) |
| `RYSEN-MONITOR/proxy/` | **Reference only** (FDMR-Monitor fork) | **Not** used for Docker builds |

**Do not** treat `RYSEN-MONITOR/proxy/proxy_db.py` as the deployment source. Edit **RYSEN**, merge to **RYSEN-SP-SELFCARE**, rebuild the image.

### Shared `Clients` table — two consumers

Both MMDVM and IPSC selfcare set `modified = 1` on the same MariaDB table. Two processes react:

| Consumer | Poll filter | Action |
|----------|-------------|--------|
| **RYSEN master** (`systemx`) | `modified = 1 AND mode = 0` | `options_config()` for IPSC static TGs |
| **Hotspot proxy** (`rysen-sp-selfcare`) | `modified = 1 AND mode > 0` | Homebrew RPTO to hotspots on :62031 |

`ipsc_proxy` does not read `Clients` and needs **no** selfcare changes.


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

**Status:** implemented on `ipsc` branch.

**Goal:** Sysop edits TS1/TS2 for their repeater. MMDVM pages unchanged.

| Step | Change |
|------|--------|
| 3.1 | Login: callsign **or** all-digit radio ID; `findClientsByLogin()` + `authenticateUser()` route by **logged-in** row `mode` (`0` = IPSC, `> 0` = MMDVM). Same DMR ID can swap hardware — one `Clients` row per `int_id`. |
| 3.1b | First-time claim: empty `psswd` + online IPSC row → set password on login (`claimIpscPassword()`). |
| 3.1c | Account page: `ssaccount.php` — IPSC password change; link from `ssmain.php` when `is_ipsc` session. |
| 3.2 | Device picker: `235287 — GB7NR (IPSC)` for `mode = 0` |
| 3.3 | Form: TS1 / TS2 only for IPSC (Functions table hidden) |
| 3.4 | `sanitizeIpscOptions()` server-side; `updateDevOptions()` unchanged |

**Acceptance:** Login with callsign or `235287` + password → change TS2 → RYSEN logs `(SELF SERVICE) Applied options for IPSC 235287` within ~5s. MMDVM callsign login unchanged. First-time claim works when admin reset password to empty.

---

## Phase 3b — Hotspot proxy: exclude IPSC rows from RPTO poll

**Status:** pending — edit **RYSEN**, merge to **RYSEN-SP-SELFCARE**, rebuild image.

**Goal:** Hotspot proxy ignores `mode = 0` rows so it does not attempt RPTO for IPSC repeaters. IPSC selfcare is **not** implemented in `ipsc_proxy` or the hotspot proxy — only this exclusion is needed.

| Step | Repo | Change |
|------|------|--------|
| 3b.1 | **RYSEN** `ipsc` (then `master`) | `proxy_db.py` → `slct_db` adds `AND mode > 0` |
| 3b.2 | **RYSEN-SP-SELFCARE** | Merge/copy `proxy_db.py` from RYSEN |
| 3b.3 | **RYSEN-SP-SELFCARE** | CI or manual build → `shaymez/rysen-sp-selfcare:latest` |
| 3b.4 | VM | `docker compose pull proxy && docker compose up -d proxy` |

```python
# proxy_db.py — slct_db (RYSEN source of truth)
def slct_db(self):
    return self.dbpool.runQuery(
        "SELECT dmr_id, options FROM Clients "
        "WHERE modified = True AND logged_in = True AND mode > 0")
```

**Acceptance:** IPSC save sets `modified=1` on `mode=0` → RYSEN master applies options; proxy logs show **no** RPTO attempt for that `dmr_id`. MMDVM hotspot save still sends RPTO as before.

MMDVM-only sites: safe to defer until IPSC is enabled on that system.

---

## Phase 4 — Rollout on live systems

Minimal checklist when IPSC is merged and a site adds a repeater:

1. Deploy RYSEN build with IPSC + selfcare hooks (`systemx` container rebuild).
2. Pull **RYSEN-MONITOR** on VM — PHP/CSS/JS (see [VM deploy](#vm-deploy-notes) below).
3. Add `[SELF SERVICE]` to `rysen.cfg` if not already present.
4. **No** DB migration.
5. **RYSEN** `proxy_db.py` → merge **RYSEN-SP-SELFCARE** → rebuild `shaymez/rysen-sp-selfcare:latest` → redeploy `proxy` (IPSC sites only).
6. Commission repeater: `sudo selfcare-admin` → set password or reset for first-time claim.
7. Repeater connects → row appears → sysop logs in with callsign or radio ID.

MMDVM-only sites: skip step 5–7 until IPSC is added.

### VM deploy notes

After `git pull` on the VM:

| Change type | Action |
|-------------|--------|
| PHP / CSS / JS under `html/` | Reload Apache (`sudo systemctl reload apache2` or your site equivalent). Symlinked docroot (`/var/www/html/dashboard` → repo `html/`) picks up files on pull. |
| Dashboard templates (`templates/`) | Rebuild monitor image: `docker compose build monitor && docker compose up -d monitor` |
| Admin menu | Once per host: `sudo ./scripts/install-selfcare-admin.sh` → `sudo selfcare-admin` |

Run the [manual regression checklist](#manual-regression-checklist) before merging `ipsc` to `master`.

---

## Admin selfcare tools

**Preferred (VM / production):** interactive bash menu — no Python on host; reads `[SELF SERVICE]` from `fdmr-mon.cfg`; SQL via `docker exec mariadb` when the container is running.

Install once (symlink into `/usr/local/sbin`):

```bash
cd /opt/RYSEN-MONITOR
sudo ./scripts/install-selfcare-admin.sh
sudo selfcare-admin
```

Or run directly:

```bash
sudo /opt/RYSEN-MONITOR/scripts/selfcare-admin.sh
# CONFIG_FILE=/etc/rysen/fdmr-mon.cfg DB_CONTAINER=mariadb
```

Menu: list IPSC repeaters, set/change password, reset password (enables first-time claim), pre-register.

Uses PBKDF2-SHA256 (`salt=RYSEN`, 2000 rounds) via `php-cli` when available — same hash as PHP selfcare (`ssconfunc.php`).

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

Existing `[SELF SERVICE]` section continues to serve PHP selfcare and `selfcare-admin`.

---

## Future: email verification

Self-service first-time claim (empty password + online IPSC) is **in v1**. Still deferred:

- Email magic link; optional cross-check against operator registry or RadioID-style database
- Separate `owner_email` column would be added then — not required now

---

## Testing

| Test | Where |
|------|--------|
| IPSC register → `Clients` row `mode=0` | RYSEN + DB |
| Admin password / claim hash matches PHP login | `selfcare-admin` + `ssconfunc.php` |
| `modified=1` → static bridges | RYSEN logs |
| MMDVM hotspot selfcare regression | RYSEN-SP-SELFCARE proxy image |
| IPSC login (callsign + radio ID) | RYSEN-MONITOR Phase 3 |
| Proxy ignores IPSC `modified` rows | RYSEN → RYSEN-SP-SELFCARE Phase 3b |

### Manual regression checklist

Run on a test VM before merging `ipsc` → `master`.

**MMDVM (unchanged)**

- [ ] Callsign + password login
- [ ] Device picker and options save → proxy RPTO / `modified` clears
- [ ] Talkgroup fields: can type values that share a prefix with an existing TG (e.g. `2350` when `235` in TS1) — dupe check on blur only
- [ ] Logout and session timeout

**IPSC**

- [ ] Repeater online → `Clients` row `mode=0`, `logged_in=1`
- [ ] Admin: `selfcare-admin` set password
- [ ] Login with **radio ID** + password → TS1/TS2 form only (no Functions table)
- [ ] Login with **callsign** + password (same repeater)
- [ ] Save TS1/TS2 → `modified=1` → RYSEN applies static bridges within poll interval
- [ ] Account page (`ssaccount.php`): change password; footer spacing and dark-mode link colours
- [ ] Admin reset password → first-time claim on login (empty password flow)
- [ ] Logout; re-login with new password

**Dashboard (if templates changed on branch)**

- [ ] Activity QSO badges: desktop flex layout; mobile 3-column grid
- [ ] Linked Systems shows IPSC repeater when connected

**Deploy smoke**

- [ ] `git pull` + Apache reload picks up PHP/CSS
- [ ] `docker compose build monitor` if templates changed

---

## References

- **RYSEN** `ipsc`: `ipsc_master.py`, `bridge_master.py`, `proxy_db.py`, `ipsc_proxy.py`
- **RYSEN-SP-SELFCARE**: https://github.com/ShaYmez/RYSEN-SP-SELFCARE — builds `shaymez/rysen-sp-selfcare:latest`
- **RYSEN-MONITOR**: `html/ssconfunc.php`, `html/sslogin.php`, `html/ssaccount.php`, `html/ssmain.php`, `scripts/selfcare-admin.sh`, `scripts/install-selfcare-admin.sh`
- IPSC cfg sample: RYSEN `IPSC-SAMPLE.cfg` (`TS1_STATIC`, `TS2_STATIC`, `MAX_PEERS: 1`)
