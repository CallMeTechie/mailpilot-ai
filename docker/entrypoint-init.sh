#!/bin/sh
# MailPilot AI — backend container init.
#
# When RUN_INIT_TASKS=1, runs DB user setup + schema migrations before
# starting the main process. Idempotent: ensure_db_user.php uses
# CREATE…IF NOT EXISTS, migrate.php skips already-applied versions.
#
# When RUN_INIT_TASKS=0 (worker, admin), only runs exec "$@".
set -e

# Monolog points at config/../../var/log/app.log which resolves to
# /var/log/app.log — root-owned in the base image, so the first
# php-fpm worker (www-data) cannot create the file and every logger
# call throws "Permission denied" while masking the real error.
# Pre-create the file with the right owner so every fresh container
# has a writable log destination. Idempotent.
mkdir -p /var/log
: > /var/log/app.log 2>/dev/null || true
chown www-data:www-data /var/log/app.log 2>/dev/null || true
chmod 0664 /var/log/app.log 2>/dev/null || true

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
