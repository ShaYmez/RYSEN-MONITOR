#!/usr/bin/env bash
#
# Install selfcare-admin menu into /usr/local/sbin (run as root).
#
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run as root: sudo $0" >&2
    exit 1
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE="${REPO_ROOT}/scripts/selfcare-admin.sh"
TARGET="/usr/local/sbin/selfcare-admin"

if [[ ! -f "$SOURCE" ]]; then
    echo "Not found: $SOURCE" >&2
    exit 1
fi

chmod +x "$SOURCE"
ln -sf "$SOURCE" "$TARGET"
echo "Installed: $TARGET -> $SOURCE"
echo "Run: sudo selfcare-admin"
