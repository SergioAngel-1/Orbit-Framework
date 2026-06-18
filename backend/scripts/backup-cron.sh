#!/bin/sh
# ============================================================================
#  backup-cron.sh — Ejecutado por el contenedor `backup` en producción.
#
#  A diferencia de backup.sh (que corre en el host con docker compose exec),
#  este script accede directamente a la BD por la red interna Docker y monta
#  wp-content/uploads como volumen de solo lectura.
#
#  Variables de entorno requeridas (proveídas por docker-compose.prod.yml):
#    MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE
#    BACKUP_DIR (default: /backups)
#    BACKUP_RETAIN_DAYS (default: 14)
# ============================================================================
set -eu

DEST="${BACKUP_DIR:-/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RETAIN="${BACKUP_RETAIN_DAYS:-14}"

mkdir -p "$DEST"

echo "[$(date -Iseconds)] backup-cron: iniciando backup ${STAMP}..."

# ── Base de datos ─────────────────────────────────────────────────────────────
echo "[$(date -Iseconds)] → Volcando BD '${MYSQL_DATABASE:-wordpress}'..."
mariadb-dump \
    -h "${MYSQL_HOST:-db}" \
    -u "${MYSQL_USER:-wordpress}" \
    -p"${MYSQL_PASSWORD}" \
    --single-transaction \
    --quick \
    --add-drop-database \
    --databases "${MYSQL_DATABASE:-wordpress}" \
    | gzip > "${DEST}/db-${STAMP}.sql.gz"

DB_SIZE="$(du -h "${DEST}/db-${STAMP}.sql.gz" | cut -f1)"
echo "[$(date -Iseconds)] ✓ DB comprimida: ${DB_SIZE}"

# ── Uploads / medios ──────────────────────────────────────────────────────────
if [ -d /wp-content/uploads ] && [ "$(ls -A /wp-content/uploads)" ]; then
    echo "[$(date -Iseconds)] → Empaquetando uploads..."
    tar -czf "${DEST}/uploads-${STAMP}.tar.gz" -C /wp-content uploads
    UP_SIZE="$(du -h "${DEST}/uploads-${STAMP}.tar.gz" | cut -f1)"
    echo "[$(date -Iseconds)] ✓ Uploads comprimidos: ${UP_SIZE}"
else
    echo "[$(date -Iseconds)] ⚠ No hay uploads que respaldar."
fi

# ── Retención: elimina backups más antiguos que RETAIN días ──────────────────
find "$DEST" -name "db-*.sql.gz"      -mtime "+${RETAIN}" -delete 2>/dev/null || true
find "$DEST" -name "uploads-*.tar.gz" -mtime "+${RETAIN}" -delete 2>/dev/null || true

TOTAL="$(du -sh "$DEST" 2>/dev/null | cut -f1 || echo "?")"
echo "[$(date -Iseconds)] ✓ Backup ${STAMP} completado. Espacio total en ${DEST}: ${TOTAL}"
echo "[$(date -Iseconds)]   Recuerda sincronizar a almacenamiento externo (S3/rclone)."
