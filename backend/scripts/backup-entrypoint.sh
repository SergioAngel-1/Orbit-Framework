#!/bin/sh
# ============================================================================
#  backup-entrypoint.sh — Entrypoint del contenedor `backup` de producción.
#
#  Instala mariadb-client (Alpine), configura el crontab y lanza crond en
#  primer plano para que el contenedor siga corriendo.
# ============================================================================
set -e

echo "[backup] Instalando cliente MariaDB..."
apk add --no-cache mariadb-client >/dev/null 2>&1

chmod +x /usr/local/bin/backup-cron.sh

# Programar el backup diario a las 03:00 UTC
(crontab -l 2>/dev/null || true; echo "0 3 * * * /usr/local/bin/backup-cron.sh >> /var/log/backup.log 2>&1") | crontab -

echo "[backup] Servicio iniciado. Backup programado diariamente a las 03:00 UTC."
echo "[backup] Retención: ${BACKUP_RETAIN_DAYS:-14} días."

# crond en primer plano (-f) con nivel de log info (-l 6)
exec crond -f -l 6
