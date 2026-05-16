#!/usr/bin/env bash
# Phase-4 CSS-Bundle-Integrity-Test.
#
# Stellt sicher, dass:
#   1. Die 12 Komponenten-Files in der erwarteten Reihenfolge existieren
#   2. Die Loading-Order in taskpane.html mit dem Dateinamen-Prefix matched
#   3. Selektoren-Snapshot stabil bleibt (regression-freeze)
#   4. :root nur in genau einer Component definiert ist
#
# Snapshot wird beim ersten Lauf erzeugt; danach pinnt er die exakte
# Selector-Liste. Bei legitimer Aenderung: snapshot loeschen und Test
# neu laufen lassen.
#
# Usage: bash addin/tests-css/test-bundle-integrity.sh
# Exit:  0 = OK, 1 = Drift erkannt

set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STYLES="$ROOT/src/styles"
HTML="$ROOT/src/taskpane.html"
SNAPSHOT="$ROOT/tests-css/selector-snapshot.txt"

fail=0
note() { echo "  [css-test] $*"; }
err()  { echo "  [css-test] FAIL: $*"; fail=1; }

expected=(
  "01-tokens.css"
  "02-reset.css"
  "03-layout-tabs.css"
  "04-sections-counters.css"
  "05-bulk-actions.css"
  "06-sync-progress.css"
  "07-modal.css"
  "08-toast.css"
  "09-mail-list.css"
  "10-buttons-fields.css"
  "11-autosort-correction.css"
  "12-overlay-today-pending.css"
)

# (1) Components-Existenz
for f in "${expected[@]}"; do
  if [ ! -f "$STYLES/$f" ]; then
    err "missing component: $STYLES/$f"
  fi
done

# (2) Reihenfolge in HTML
got=()
while IFS= read -r line; do
  got+=("$line")
done < <(grep -oE 'styles/[0-9]+-[a-z\-]+\.css' "$HTML" | sed 's|styles/||')

if [ "${#got[@]}" -ne "${#expected[@]}" ]; then
  err "HTML hat ${#got[@]} CSS-Links, erwartet ${#expected[@]}"
fi
for i in "${!expected[@]}"; do
  if [ "${got[$i]:-}" != "${expected[$i]}" ]; then
    err "HTML-Position $i: erwartet ${expected[$i]}, gefunden ${got[$i]:-MISSING}"
  fi
done

# (3) Selector-Snapshot
# Python statt sed/grep weil multi-line Selektoren + Klammer-Tracking
# robuster gehen. Skipt @keyframes/@container/@media-Bloecke (Inner-Selektoren
# zaehlen nicht als top-level), nimmt nur top-level Rules.
current=$(python3 - "$STYLES" <<'PY'
import sys, os, re, glob
sels = set()
for path in sorted(glob.glob(os.path.join(sys.argv[1], "*.css"))):
    with open(path) as f:
        src = f.read()
    # Strip Kommentare
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    depth = 0
    buf = ''
    for ch in src:
        if ch == '{':
            if depth == 0:
                sel = buf.strip()
                if sel and not sel.startswith('@'):
                    # Multiple comma-separated Selektoren splitten
                    for s in sel.split(','):
                        s = s.strip()
                        if s:
                            sels.add(s)
                buf = ''
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                buf = ''
        elif depth == 0:
            buf += ch
print('\n'.join(sorted(sels)))
PY
)

if [ -f "$SNAPSHOT" ]; then
  diff_out=$(diff <(echo "$current") "$SNAPSHOT" || true)
  if [ -n "$diff_out" ]; then
    err "Selector-Drift gegenueber Snapshot:"
    echo "$diff_out" | head -30
  else
    note "Selector-Snapshot stable ($(echo "$current" | wc -l) Selektoren)"
  fi
else
  echo "$current" > "$SNAPSHOT"
  note "Snapshot initial erzeugt: $SNAPSHOT ($(echo "$current" | wc -l) Selektoren)"
fi

# (4) :root genau 1x
root_count=$(grep -l '^:root' "$STYLES"/*.css | wc -l)
if [ "$root_count" -ne 1 ]; then
  err ":root muss in genau 1 Component definiert sein, gefunden in $root_count"
fi

if [ "$fail" -eq 0 ]; then
  note "Phase-4 CSS-Bundle-Integrity OK"
  exit 0
fi
echo "  [css-test] $fail Issue(s) — bitte beheben."
exit 1
