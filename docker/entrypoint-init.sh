#!/bin/sh
# MailPilot AI — backend container init.
#
# When RUN_INIT_TASKS=1, runs DB user setup + schema migrations before
# starting the main process. Idempotent: ensure_db_user.php uses
# CREATE…IF NOT EXISTS, migrate.php skips already-applied versions.
#
# When RUN_INIT_TASKS=0 (worker, admin), only runs exec "$@".
set -e

if [ "${RUN_INIT_TASKS:-0}" = "1" ]; then
	if [ -z "${DB_ROOT_PASS:-}" ]; then
		echo "[entrypoint-init] ERROR: RUN_INIT_TASKS=1 but DB_ROOT_PASS is empty" >&2
		exit 1
	fi

	echo "[entrypoint-init] Waiting for MariaDB (root login) ..."
	until php -r 'new PDO("mysql:host=" . (getenv("DB_HOST") ?: "mariadb") . ";dbname=mysql", "root", getenv("DB_ROOT_PASS"));' 2>/dev/null; do
		sleep 2
	done

	echo "[entrypoint-init] Ensuring DB user has wildcard host grant ..."
	php /app/bin/ensure_db_user.php

	echo "[entrypoint-init] Applying migrations ..."
	cd /app && php bin/migrate.php
fi

exec "$@"
