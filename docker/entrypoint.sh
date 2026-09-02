#!/bin/sh
# =====================================================================
#  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
#  BCSP-064 -- Bachelor of Computer Applications, IGNOU
# ---------------------------------------------------------------------
#  Container entrypoint.
#
#  Imports the schema on first start (idempotent -- it does nothing if
#  the database already has tables), then hands control to Apache.
#
#  The import deliberately cannot block Apache: init-db.php always exits
#  0, so a database that is slow or unreachable delays seeding but never
#  prevents the web server from coming up.
# =====================================================================
set -e

echo "[entrypoint] running database initialisation check"
php /var/www/html/docker/init-db.php || echo "[entrypoint] init check returned non-zero; continuing"

echo "[entrypoint] starting Apache"
exec docker-php-entrypoint apache2-foreground
