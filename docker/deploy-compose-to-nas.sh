#!/usr/bin/env bash
# Phase 9q-B (Marc 2026-05-22) — Deploy docker-compose.yml auf Synology-NAS.
#
# SCP/SFTP funktioniert auf Synology nicht (kein sftp-subsystem). Wir
# uebertragen das Compose-File via base64-Encoding ueber stdin der SSH-
# Verbindung. Vor dem Ueberschreiben wird ein Datums-Backup angelegt.
#
# Usage:
#   bash docker/deploy-compose-to-nas.sh                # default settings
#   NAS_HOST=ssh.example.com bash docker/deploy-compose-to-nas.sh
#
# Voraussetzungen:
#   - SSH-Key-Auth auf NAS eingerichtet (siehe reference_synology_nas)
#   - sudo-NOPASSWD fuer /usr/local/bin/docker

set -euo pipefail

NAS_HOST="${NAS_HOST:-ssh.domaincaster.com}"
NAS_USER="${NAS_USER:-ma.backes}"
NAS_PORT="${NAS_PORT:-2022}"
REMOTE_DIR="${REMOTE_DIR:-/volume1/docker/mailpilot-ai}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_COMPOSE="$SCRIPT_DIR/docker-compose.yml"
REMOTE_COMPOSE="$REMOTE_DIR/docker-compose.yml"

if [ ! -f "$LOCAL_COMPOSE" ]; then
	echo "FATAL: Lokales Compose-File nicht gefunden: $LOCAL_COMPOSE" >&2
	exit 1
fi

echo "[deploy] Source: $LOCAL_COMPOSE"
echo "[deploy] Target: $NAS_USER@$NAS_HOST:$REMOTE_COMPOSE"
echo ""

# Datum-Suffix fuers Backup
STAMP=$(date -u +%Y%m%d-%H%M%S)
B64=$(base64 -w0 "$LOCAL_COMPOSE")

ssh -p "$NAS_PORT" "$NAS_USER@$NAS_HOST" "
set -e
REMOTE='$REMOTE_COMPOSE'
BACKUP=\"\$REMOTE.bak.$STAMP\"
if [ -f \"\$REMOTE\" ]; then
	cp \"\$REMOTE\" \"\$BACKUP\"
	echo \"[remote] Backup: \$BACKUP\"
fi
echo '$B64' | base64 -d > \"\$REMOTE\"
echo \"[remote] geschrieben: \$REMOTE (\$(wc -c < \"\$REMOTE\") bytes)\"
echo ''
echo '[remote] docker compose config --quiet validation:'
cd '$REMOTE_DIR' && sudo -n /usr/local/bin/docker compose config --quiet \\
	&& echo '[remote] compose-Syntax OK' \\
	|| { echo '[remote] FEHLER bei docker compose config — rollback'; cp \"\$BACKUP\" \"\$REMOTE\"; exit 1; }
"

echo ""
echo "[deploy] fertig. Naechster Schritt:"
echo "  ssh -p $NAS_PORT $NAS_USER@$NAS_HOST 'cd $REMOTE_DIR && sudo -n /usr/local/bin/docker compose up -d --force-recreate'"
