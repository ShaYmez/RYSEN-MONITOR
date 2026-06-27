#!/usr/bin/env bash
#
# System X selfcare admin menu (IPSC + overview).
# No Python required on the host — uses docker exec mariadb + php-cli for password hashes.
#
# Usage:
#   sudo ./scripts/selfcare-admin.sh
#   CONFIG_FILE=/etc/rysen/fdmr-mon.cfg DB_CONTAINER=mariadb ./scripts/selfcare-admin.sh
#
set -euo pipefail

CONFIG_FILE="${CONFIG_FILE:-/etc/rysen/fdmr-mon.cfg}"
DB_CONTAINER="${DB_CONTAINER:-mariadb}"
IPSC_MODE=0

# ---------------------------------------------------------------------------
# UI helpers
# ---------------------------------------------------------------------------
bold() { printf '\033[1m%s\033[0m\n' "$*"; }
pause() { read -r -p "Press Enter to continue..." _ </dev/tty; }

clear_screen() {
  if [[ -t 1 ]]; then
    clear || true
  fi
}

# ---------------------------------------------------------------------------
# Config + DB
# ---------------------------------------------------------------------------
cfg_get() {
  local section="$1" key="$2" file="$3"
  awk -F'=' -v section="[${section}]" -v key="$key" '
    /^[[:space:]]*#/ { next }
    /^[[:space:]]*$/ { next }
    /^\[/ {
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", $0)
      in_section = ($0 == section)
      next
    }
    in_section {
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", $1)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
      if ($1 == key) { print $2; exit }
    }
  ' "$file"
}

load_db_settings() {
  if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Config not found: $CONFIG_FILE" >&2
    exit 1
  fi

  DB_USER="$(cfg_get 'SELF SERVICE' 'DB_USERNAME' "$CONFIG_FILE")"
  DB_PASS="$(cfg_get 'SELF SERVICE' 'DB_PASSWORD' "$CONFIG_FILE")"
  DB_NAME="$(cfg_get 'SELF SERVICE' 'DB_NAME' "$CONFIG_FILE")"
  DB_HOST="$(cfg_get 'SELF SERVICE' 'DB_SERVER' "$CONFIG_FILE")"
  DB_PORT="$(cfg_get 'SELF SERVICE' 'DB_PORT' "$CONFIG_FILE")"

  DB_USER="${DB_USER:-selfcare}"
  DB_NAME="${DB_NAME:-selfcare}"
  DB_PORT="${DB_PORT:-3306}"

  if [[ -z "$DB_PASS" ]]; then
    echo "DB_PASSWORD is empty in [SELF SERVICE] of $CONFIG_FILE" >&2
    exit 1
  fi

  USE_DOCKER=0
  if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$DB_CONTAINER"; then
    USE_DOCKER=1
  fi

  if [[ "$USE_DOCKER" -eq 0 ]]; then
    if [[ "$DB_HOST" =~ ^172\.16\.238\.[0-9]+$ ]]; then
      DB_HOST="127.0.0.1"
      if [[ "$DB_PORT" == "3306" ]]; then
        DB_PORT="8306"
      fi
    fi
    if ! command -v mariadb >/dev/null 2>&1 && ! command -v mysql >/dev/null 2>&1; then
      echo "No docker container '$DB_CONTAINER' and no mysql/mariadb client on host." >&2
      exit 1
    fi
  fi
}

sql_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\'/\'\'}"
  printf '%s' "$s"
}

run_sql() {
  local query="$1"
  if [[ "$USE_DOCKER" -eq 1 ]]; then
    docker exec -i "$DB_CONTAINER" mariadb \
      -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      --batch --skip-column-names -e "$query"
  else
    local client="mariadb"
    command -v mariadb >/dev/null 2>&1 || client="mysql"
    "$client" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      --batch --skip-column-names -e "$query"
  fi
}

run_sql_table() {
  local query="$1"
  if [[ "$USE_DOCKER" -eq 1 ]]; then
    docker exec -i "$DB_CONTAINER" mariadb \
      -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      -e "$query"
  else
    local client="mariadb"
    command -v mariadb >/dev/null 2>&1 || client="mysql"
    "$client" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      -e "$query"
  fi
}

# ---------------------------------------------------------------------------
# Password hashing (same as html/ssconfunc.php)
# ---------------------------------------------------------------------------
hash_selfcare_password() {
  local pass="$1"
  if command -v php >/dev/null 2>&1; then
    php -r 'echo hash_pbkdf2("sha256", $argv[1], "RYSEN", 2000);' "$pass"
    return 0
  fi
  if command -v openssl >/dev/null 2>&1 && openssl kdf -help 2>&1 | grep -q PBKDF2; then
    printf '%s' "$pass" | openssl kdf -keylen 32 \
      -kdfopt digest:SHA256 -kdfopt salt:RYSEN -kdfopt iter:2000 PBKDF2 \
      | od -An -tx1 | tr -d ' \n'
    return 0
  fi
  echo "Need php-cli or openssl 3+ for password hashing." >&2
  return 1
}

read_password() {
  local prompt="$1" var
  read -r -s -p "$prompt" var </dev/tty
  echo >&2
  printf '%s' "$var"
}

validate_radio_id() {
  local id="$1"
  [[ "$id" =~ ^[1-9][0-9]{0,8}$ ]]
}

password_status() {
  local psswd="$1"
  if [[ -z "$psswd" ]]; then
    echo "NOT SET (first-time selfcare claim available when online)"
  else
    echo "SET"
  fi
}

logged_in_label() {
  if [[ "$1" == "1" ]]; then
    echo "ONLINE"
  else
    echo "offline"
  fi
}

dmr_id_hex() {
  printf '%08X' "$1"
}

# ---------------------------------------------------------------------------
# IPSC queries
# ---------------------------------------------------------------------------
list_ipsc_repeaters() {
  local online_only="${1:-0}"
  local where="mode = $IPSC_MODE"
  if [[ "$online_only" -eq 1 ]]; then
    where="$where AND logged_in = 1"
  fi
  bold "IPSC repeaters (mode=0):"
  run_sql_table "SELECT int_id AS ID, TRIM(callsign) AS Callsign,
    IF(logged_in=1,'yes','no') AS Online,
    IF(psswd IS NULL OR psswd='','no','yes') AS PasswordSet,
    options AS Options
    FROM Clients WHERE $where ORDER BY int_id;"
}

fetch_ipsc_row() {
  local radio_id="$1"
  run_sql "SELECT int_id, TRIM(callsign), mode, logged_in,
    IFNULL(psswd,''), IFNULL(options,'')
    FROM Clients WHERE int_id = ${radio_id} LIMIT 1;"
}

show_ipsc_device() {
  local radio_id="$1"
  local row
  row="$(fetch_ipsc_row "$radio_id" || true)"
  if [[ -z "$row" ]]; then
    echo "No Clients row for ID $radio_id."
    return 1
  fi
  IFS=$'\t' read -r int_id callsign mode logged_in psswd options <<<"$row"
  bold "Device $int_id — $callsign"
  echo "  Mode:        $mode $( [[ "$mode" == "$IPSC_MODE" ]] && echo '(IPSC)' || echo '(not IPSC — row may be MMDVM repeater/hotspot)' )"
  echo "  Status:      $(logged_in_label "$logged_in")"
  echo "  Password:    $(password_status "$psswd")"
  echo "  Options:     ${options:-<empty>}"
}

prompt_radio_id() {
  local id
  while true; do
    read -r -p "DMR radio ID: " id </dev/tty
    id="${id//[[:space:]]/}"
    if validate_radio_id "$id"; then
      echo "$id"
      return 0
    fi
    echo "Invalid radio ID (1–9 digits, max 9 digits total)."
  done
}

set_ipsc_password() {
  local radio_id="$1"
  local pass confirm hash

  pass="$(read_password "New selfcare password: ")"
  confirm="$(read_password "Confirm password: ")"
  if [[ "$pass" != "$confirm" ]]; then
    echo "Passwords do not match."
    return 1
  fi
  if [[ ${#pass} -lt 6 || ${#pass} -gt 100 ]]; then
    echo "Password must be 6–100 characters."
    return 1
  fi

  hash="$(hash_selfcare_password "$pass")"
  local esc
  esc="$(sql_escape "$hash")"

  local row
  row="$(fetch_ipsc_row "$radio_id" 2>/dev/null || true)"
  if [[ -z "$row" ]]; then
    echo "No Clients row for $radio_id. Pre-register first or wait for repeater to connect."
    return 1
  fi

  IFS=$'\t' read -r _ _ mode _ _ _ <<<"$row"
  if [[ "$mode" == "4" ]]; then
    echo "Refusing: ID $radio_id is an MMDVM hotspot row (mode=4). Use hotspot selfcare."
    return 1
  fi

  run_sql "UPDATE Clients SET psswd = '${esc}' WHERE int_id = ${radio_id};"
  echo "Password updated for ID $radio_id."
}

reset_ipsc_password() {
  local radio_id="$1"
  local row
  row="$(fetch_ipsc_row "$radio_id" 2>/dev/null || true)"
  if [[ -z "$row" ]]; then
    echo "No Clients row for $radio_id."
    return 1
  fi

  IFS=$'\t' read -r _ callsign mode logged_in _ _ <<<"$row"
  if [[ "$mode" != "$IPSC_MODE" ]]; then
    echo "Warning: mode=$mode (not IPSC). Reset will still clear psswd on this row."
  fi

  read -r -p "Clear password for $callsign ($radio_id)? Sysop can claim again at login [y/N]: " ans </dev/tty
  if [[ ! "$ans" =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    return 0
  fi

  run_sql "UPDATE Clients SET psswd = NULL WHERE int_id = ${radio_id};"
  echo "Password cleared. Sysop can set a new password via selfcare (empty password) when repeater is online."
}

pre_register_ipsc() {
  local radio_id="$1"
  local callsign pass confirm hash hex

  read -r -p "Callsign (max 10 chars): " callsign </dev/tty
  callsign="$(echo "$callsign" | tr '[:lower:]' '[:upper:]' | tr -d '[:space:]')"
  if [[ -z "$callsign" || ${#callsign} -gt 10 ]]; then
    echo "Invalid callsign."
    return 1
  fi

  pass="$(read_password "Selfcare password: ")"
  confirm="$(read_password "Confirm password: ")"
  if [[ "$pass" != "$confirm" ]]; then
    echo "Passwords do not match."
    return 1
  fi
  if [[ ${#pass} -lt 6 || ${#pass} -gt 100 ]]; then
    echo "Password must be 6–100 characters."
    return 1
  fi

  hash="$(hash_selfcare_password "$pass")"
  hex="$(dmr_id_hex "$radio_id")"
  local esc_hash esc_call
  esc_hash="$(sql_escape "$hash")"
  esc_call="$(sql_escape "$callsign")"

  run_sql "INSERT INTO Clients (
      int_id, dmr_id, callsign, host, mode, logged_in, modified, last_seen, psswd
    ) VALUES (
      ${radio_id}, UNHEX('${hex}'), '${esc_call}', '', ${IPSC_MODE}, 0, 0, UNIX_TIMESTAMP(), '${esc_hash}'
    ) ON DUPLICATE KEY UPDATE
      callsign = VALUES(callsign),
      mode = ${IPSC_MODE},
      psswd = VALUES(psswd);"

  echo "Registered/updated IPSC row for $callsign ($radio_id)."
}

ipsc_device_menu() {
  local radio_id="$1"
  while true; do
    clear_screen
    bold "IPSC selfcare — ID $radio_id"
    echo
    show_ipsc_device "$radio_id" || true
    echo
    echo "  1) Set / change selfcare password"
    echo "  2) Reset password (clear — first-time login at selfcare)"
    echo "  3) Pre-register / force IPSC row (if missing)"
    echo "  0) Back"
    echo
    local choice
    read -r -p "Choice: " choice </dev/tty
    case "$choice" in
      1) set_ipsc_password "$radio_id"; pause ;;
      2) reset_ipsc_password "$radio_id"; pause ;;
      3) pre_register_ipsc "$radio_id"; pause ;;
      0) return 0 ;;
      *) echo "Invalid choice."; pause ;;
    esac
  done
}

ipsc_menu() {
  while true; do
    clear_screen
    bold "IPSC selfcare management"
    echo
    echo "  1) Manage repeater by radio ID"
    echo "  2) Show logged-in IPSC repeaters"
    echo "  3) Show all IPSC rows"
    echo "  0) Back"
    echo
    local choice
    read -r -p "Choice: " choice </dev/tty
    case "$choice" in
      1)
        local id
        id="$(prompt_radio_id)"
        ipsc_device_menu "$id"
        ;;
      2)
        clear_screen
        list_ipsc_repeaters 1
        pause
        ;;
      3)
        clear_screen
        list_ipsc_repeaters 0
        pause
        ;;
      0) return 0 ;;
      *) echo "Invalid choice."; pause ;;
    esac
  done
}

main_menu() {
  while true; do
    clear_screen
    bold "System X — Selfcare admin"
    echo "Config: $CONFIG_FILE"
    if [[ "$USE_DOCKER" -eq 1 ]]; then
      echo "Database: docker://${DB_CONTAINER} (${DB_NAME}@${DB_USER})"
    else
      echo "Database: ${DB_HOST}:${DB_PORT}/${DB_NAME} (${DB_USER})"
    fi
    echo
    echo "  1) IPSC selfcare"
    echo "  2) Show logged-in IPSC repeaters"
    echo "  3) Show all IPSC repeaters"
    echo "  0) Exit"
    echo
    local choice
    read -r -p "Choice: " choice </dev/tty
    case "$choice" in
      1) ipsc_menu ;;
      2)
        clear_screen
        list_ipsc_repeaters 1
        pause
        ;;
      3)
        clear_screen
        list_ipsc_repeaters 0
        pause
        ;;
      0) echo "Bye."; exit 0 ;;
      *) echo "Invalid choice."; pause ;;
    esac
  done
}

# ---------------------------------------------------------------------------
# Entry
# ---------------------------------------------------------------------------
if [[ ! -t 0 ]]; then
  echo "This script is interactive; run it from a terminal." >&2
  exit 1
fi

load_db_settings
main_menu
