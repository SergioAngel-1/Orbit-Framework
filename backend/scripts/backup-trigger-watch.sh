#!/bin/sh
# ============================================================================
#  backup-trigger-watch.sh — Ejecutado por crond cada minuto en el
#  contenedor `backup`. Si hay un backup manual pendiente (botón "Backup
#  ahora" en wp-admin, ver BackupConfig::writeTrigger()), lo ejecuta.
#
#  El lock contra solapes con una corrida programada vive dentro de
#  backup-cron.sh (protege ambos disparadores por igual), no aquí.
# ============================================================================
set -eu

TRIGGER_FILE="${BACKUP_DIR:-/backups}/hwe-backup-trigger.json"

[ -f "$TRIGGER_FILE" ] || exit 0

echo "[$(date -Iseconds)] [trigger-watch] Trigger manual detectado, ejecutando backup..."
rm -f "$TRIGGER_FILE"
"$(dirname "$0")/backup-cron.sh" manual
