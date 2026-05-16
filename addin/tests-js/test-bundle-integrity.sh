#!/usr/bin/env bash
# Phase-5 JS-Bundle-Integrity-Test.
#
# Stellt sicher, dass:
#   1. Die 16 Komponenten-Files existieren
#   2. addin/build-bundle.sh produziert ein bundle das byte-identisch
#      zum aktuellen src/taskpane.js ist (rebuild-stability)
#   3. Jedes Component-File ist syntactically gueltiges JS (node --check)
#   4. Die Symbol-Liste (function/class/const/let) gegen Snapshot stabil
#
# Snapshot persistiert in tests-js/symbol-snapshot.txt; loeschen +
# Test neu laufen lassen = neuer Baseline.

set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPTS="$ROOT/src/scripts"
BUNDLE="$ROOT/src/taskpane.js"
SNAPSHOT="$ROOT/tests-js/symbol-snapshot.txt"

fail=0
note() { echo "  [js-test] $*"; }
err()  { echo "  [js-test] FAIL: $*"; fail=1; }

expected=(
  "01-state.js"
  "02-office-init.js"
  "03-auth-handoff.js"
  "04-tabs.js"
  "05-today.js"
  "06-drafts-pending.js"
  "07-briefing.js"
  "08-sync.js"
  "09-autorefresh.js"
  "10-filter-list.js"
  "11-current-mail.js"
  "12-settings.js"
  "13-helpers.js"
  "14-toast.js"
  "15-long-op.js"
  "16-confirm-modal.js"
)

# (1) Components-Existenz
for f in "${expected[@]}"; do
  if [ ! -f "$SCRIPTS/$f" ]; then
    err "missing component: $SCRIPTS/$f"
  fi
done

# (2) Bundle-Rebuild-Stability
rebuilt=$(mktemp)
cat "$SCRIPTS"/*.js > "$rebuilt"
if ! diff -q "$rebuilt" "$BUNDLE" >/dev/null 2>&1; then
  err "Bundle drift: src/scripts/* concat != src/taskpane.js"
  err "    fix: bash addin/build-bundle.sh"
  diff "$BUNDLE" "$rebuilt" | head -10
fi
rm -f "$rebuilt"

# (3) Syntax-Check pro Component
if command -v node >/dev/null 2>&1; then
  for f in "$SCRIPTS"/*.js; do
    # Components sind Snippets, nicht standalone — viele referenzieren
    # Symbole aus vorhergehenden Files. node --check macht nur Syntax-
    # Pruefung (kein Resolve), das funktioniert auch fuer Snippets.
    if ! node --check "$f" 2>/dev/null; then
      err "syntax error: $f"
      node --check "$f" 2>&1 | head -5
    fi
  done
else
  note "node nicht installiert — Syntax-Check uebersprungen"
fi

# (4) Symbol-Snapshot
current=$(grep -hE '^(function|class|const|let|async function) [a-zA-Z_][a-zA-Z0-9_]*' "$SCRIPTS"/*.js \
  | sed -E 's/^(function|class|async function) ([a-zA-Z_][a-zA-Z0-9_]*).*/\1 \2/' \
  | sed -E 's/^(const|let) ([a-zA-Z_][a-zA-Z0-9_]*).*/\1 \2/' \
  | sort -u)

if [ -f "$SNAPSHOT" ]; then
  diff_out=$(diff <(echo "$current") "$SNAPSHOT" || true)
  if [ -n "$diff_out" ]; then
    err "Symbol-Drift gegenueber Snapshot:"
    echo "$diff_out" | head -30
  else
    note "Symbol-Snapshot stable ($(echo "$current" | wc -l) Top-Level-Symbole)"
  fi
else
  echo "$current" > "$SNAPSHOT"
  note "Snapshot initial erzeugt: $SNAPSHOT ($(echo "$current" | wc -l) Symbole)"
fi

if [ "$fail" -eq 0 ]; then
  note "Phase-5 JS-Bundle-Integrity OK"
  exit 0
fi
echo "  [js-test] $fail Issue(s) — bitte beheben."
exit 1
