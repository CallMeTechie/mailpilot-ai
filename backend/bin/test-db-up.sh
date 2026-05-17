#!/usr/bin/env bash
# Phase-H8 — Startet die PHPUnit-Test-DB SICHER:
#   - 127.0.0.1-binding (KEIN globaler 0.0.0.0)
#   - Random Root-Passwort (kein leeres Passwort)
#   - Migrations werden automatisch eingespielt
#
# Hintergrund: 2026-05-16 wurde ein lokaler Test-Container ohne Bindung +
# ohne Passwort innerhalb von Stunden Opfer eines Ransomware-Scans
# (RECOVER_YOUR_DATA-Tabelle). Dieses Skript verhindert das wiederholen.
#
# Usage:
#   bash backend/bin/test-db-up.sh        # startet Container + migriert
#   bash backend/bin/test-db-up.sh --down # stoppt + entfernt Container
#
# Nach erfolgreichem Start: DB_PASS aus /tmp/mailpilot-test-db.env lesen.
#   source /tmp/mailpilot-test-db.env
#   ./vendor/bin/phpunit --no-coverage

set -eu

CONTAINER=mailpilot-test-db
IMAGE=mariadb:11.4
DB_NAME=mailpilot_test
PASS_FILE=/tmp/mailpilot-test-db.env

if [ "${1:-}" = "--down" ]; then
  if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER}\$"; then
    docker rm -f "$CONTAINER"
    echo "[test-db] container removed"
  else
    echo "[test-db] no container to remove"
  fi
  rm -f "$PASS_FILE"
  exit 0
fi

# Wenn Container schon läuft + healthy + Pass-File existiert: nichts tun.
if [ -f "$PASS_FILE" ] && docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}\$"; then
  if docker exec "$CONTAINER" mariadb-admin ping --silent 2>/dev/null; then
    echo "[test-db] already running — $PASS_FILE has DB_PASS"
    exit 0
  fi
fi

# Container neu — alten entfernen falls vorhanden (Stopped/Failed)
docker rm -f "$CONTAINER" 2>/dev/null || true

DB_ROOT_PASS=$(openssl rand -hex 24)
docker run -d \
  --name "$CONTAINER" \
  -p 127.0.0.1:3306:3306 \
  -e MARIADB_ROOT_PASSWORD="$DB_ROOT_PASS" \
  -e MARIADB_DATABASE="$DB_NAME" \
  --health-cmd='mariadb-admin ping --silent' \
  --health-interval=2s \
  --health-retries=15 \
  "$IMAGE" >/dev/null

# Pass-File mit restriktiven Permissions
umask 077
cat > "$PASS_FILE" <<EOF
# Phase-H8 — generiert von test-db-up.sh
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=$DB_NAME
export DB_USER=root
export DB_PASS=$DB_ROOT_PASS
EOF

echo "[test-db] container starting…"
for i in $(seq 1 30); do
  if docker exec "$CONTAINER" mariadb -uroot -p"$DB_ROOT_PASS" -e "SELECT 1" >/dev/null 2>&1; then
    echo "[test-db] ready after ${i}s"
    break
  fi
  sleep 1
done

# Migrations einspielen
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
echo "[test-db] running migrations…"
( cd "$BACKEND_DIR" && APP_ENV=test \
    DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME="$DB_NAME" \
    DB_USER=root DB_PASS="$DB_ROOT_PASS" \
    php bin/migrate.php ) | tail -3

echo "[test-db] OK — source $PASS_FILE before running phpunit"
