#!/usr/bin/env bash
# Build a deployable Office Add-in manifest from manifest.template.xml.
# Substitutes domain placeholders so the same template can be used across
# environments (dev sideload, staging, production).
#
# Usage:
#   ADDIN_BASE_URL=https://mp.example.com ./build-manifest.sh
#
# Optional:
#   ADDIN_PROVIDER (default: "MailPilot")
#   ADDIN_DISPLAY  (default: "MailPilot AI")
#   OUTPUT         (default: ./manifest.xml next to the template)

set -euo pipefail

BASE_URL="${ADDIN_BASE_URL:?ADDIN_BASE_URL must be set, e.g. https://mp.example.com}"
PROVIDER="${ADDIN_PROVIDER:-MailPilot}"
DISPLAY="${ADDIN_DISPLAY:-MailPilot AI}"
OUTPUT="${OUTPUT:-$(dirname "$0")/manifest.xml}"
TEMPLATE="$(dirname "$0")/manifest.template.xml"

if [[ ! -f "$TEMPLATE" ]]; then
	echo "ERROR: template not found: $TEMPLATE" >&2
	exit 1
fi

# Strip trailing slash from BASE_URL — manifest expects bare origin.
BASE_URL="${BASE_URL%/}"

if [[ ! "$BASE_URL" =~ ^https://[^[:space:]]+$ ]]; then
	echo "ERROR: ADDIN_BASE_URL must be an absolute https:// URL, got: $BASE_URL" >&2
	exit 2
fi

# Use sed with an unusual delimiter (|) since the value contains slashes.
sed \
	-e "s|__ADDIN_BASE_URL__|${BASE_URL}|g" \
	-e "s|__ADDIN_PROVIDER__|${PROVIDER}|g" \
	-e "s|__ADDIN_DISPLAY__|${DISPLAY}|g" \
	"$TEMPLATE" > "$OUTPUT"

echo "Wrote $OUTPUT (base=$BASE_URL provider=$PROVIDER display=$DISPLAY)"
