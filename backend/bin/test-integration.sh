#!/usr/bin/env bash
# Komfort-Wrapper: bringt die Test-MariaDB hoch, sourct die generierten
# DB-Credentials und startet PHPUnit. Reine Convenience um test-db-up.sh —
# kein eigenes DB-Setup, damit es genau eine sichere Quelle dafuer gibt.
#
# Hintergrund: Integration-Tests brauchen eine echte MariaDB (Schema ist
# MariaDB-spezifisch: ENUM, utf8mb4, JSON, FK-CASCADE). Statt eines fragilen
# SQLite-Fallbacks kapseln wir den vorhandenen, abgesicherten Container.
#
# Usage:
#   bash backend/bin/test-integration.sh                  # Integration-Suite
#   bash backend/bin/test-integration.sh --testsuite Unit # andere Suite
#   bash backend/bin/test-integration.sh --filter SenderResolverTest
#   (Extra-Argumente werden 1:1 an phpunit durchgereicht.)
#
# Teardown danach: bash backend/bin/test-db-up.sh --down

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE=/tmp/mailpilot-test-db.env

# 1. Test-DB hochfahren (idempotent: laeuft sie schon + healthy, No-op).
bash "$SCRIPT_DIR/test-db-up.sh"

# 2. Generierte Credentials (DB_HOST/PORT/NAME/USER/PASS) sourcen.
if [ ! -f "$ENV_FILE" ]; then
	echo "[test-integration] FEHLER: $ENV_FILE fehlt — test-db-up.sh ist nicht durchgelaufen." >&2
	exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

# 3. PHPUnit ausfuehren. Default-Suite: Integration; Extra-Args werden
#    durchgereicht. exec, damit der phpunit-Exit-Code unser Exit-Code wird.
cd "$BACKEND_DIR"
if [ "$#" -eq 0 ]; then
	set -- --testsuite Integration
fi
exec ./vendor/bin/phpunit --no-coverage "$@"
