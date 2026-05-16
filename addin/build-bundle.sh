#!/usr/bin/env bash
# Phase-5 Bundle-Builder fuer taskpane.js.
#
# Konkateniert addin/src/scripts/*.js (alphabetisch durch 01-/02-/…
# Prefix erzwungene Reihenfolge) zu addin/src/taskpane.js. Resultat
# wird vom Office Add-in geladen (taskpane.html referenziert nur eine
# Datei).
#
# Marc-Workflow:
#   1. Edit in addin/src/scripts/NN-*.js
#   2. bash addin/build-bundle.sh
#   3. Add-in reloaden (im Office-Client F5)
#
# Test:
#   bash addin/tests-css/test-bundle-integrity.sh  (CSS)
#   bash addin/tests-js/test-bundle-integrity.sh   (JS, dieser)

set -eu

ROOT="$(cd "$(dirname "$0")" && pwd)"
SCRIPTS="$ROOT/src/scripts"
OUT="$ROOT/src/taskpane.js"

if [ ! -d "$SCRIPTS" ]; then
  echo "ERROR: $SCRIPTS existiert nicht" >&2
  exit 1
fi

cat "$SCRIPTS"/*.js > "$OUT"
LINES=$(wc -l < "$OUT")
echo "[bundle] $OUT geschrieben — $LINES Zeilen aus $(ls "$SCRIPTS" | wc -l) Komponenten"
