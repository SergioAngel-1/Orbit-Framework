#!/bin/sh
# ============================================================================
#  backup-cron.sh — Ejecutado por crond en el contenedor `backup`.
#
#  Lee la configuración de backup desde wp-content/hwe-backup-config.json
#  (generado por el plugin HWE Control Center). Si el archivo no existe,
#  usa los valores por defecto del entorno.
#
#  Opciones del panel que controlan este script:
#    enabled         → backup-entrypoint.sh ya no llegará a este script si false
#    include_uploads → si false, omite el tar de wp-content/uploads
#    retain_days     → días de retención de archivos locales
#    notify_email    → envía resumen por email (requiere sendmail/msmtp)
# ============================================================================
set -eu

CONFIG_FILE="/wp-content/hwe-backup-config.json"

# ── Leer configuración (con fallback a env vars) ──────────────────────────────
INCLUDE_UPLOADS="true"
RETAIN="${BACKUP_RETAIN_DAYS:-14}"
NOTIFY_EMAIL=""

if [ -f "$CONFIG_FILE" ] && command -v jq >/dev/null 2>&1; then
    INCLUDE_UPLOADS=$(jq -r '.include_uploads // true' "$CONFIG_FILE" 2>/dev/null || echo 'true')
    RETAIN=$(jq -r '.retain_days // 14'                "$CONFIG_FILE" 2>/dev/null || echo '14')
    NOTIFY_EMAIL=$(jq -r '.notify_email // ""'         "$CONFIG_FILE" 2>/dev/null || echo '')
fi

DEST="${BACKUP_DIR:-/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

echo "[$(date -Iseconds)] ── Iniciando backup ${STAMP} ──"

# ── Base de datos ─────────────────────────────────────────────────────────────
DB_HOST="${MYSQL_HOST:-db}"
DB_USER="${MYSQL_USER:-wordpress}"
DB_NAME="${MYSQL_DATABASE:-wordpress}"
DB_FILE="${DEST}/db-${STAMP}.sql.gz"

echo "[$(date -Iseconds)] → Volcando BD '${DB_NAME}' en ${DB_HOST}..."
mariadb-dump \
    -h "$DB_HOST" \
    -u "$DB_USER" \
    -p"${MYSQL_PASSWORD}" \
    --single-transaction \
    --quick \
    --add-drop-database \
    --databases "$DB_NAME" \
    | gzip > "$DB_FILE"

DB_SIZE="$(du -h "$DB_FILE" | cut -f1)"
echo "[$(date -Iseconds)] ✓ BD: ${DB_FILE} (${DB_SIZE})"

# ── Uploads / medios ──────────────────────────────────────────────────────────
UPLOADS_FILE=""
if [ "$INCLUDE_UPLOADS" = "true" ] && [ -d /wp-content/uploads ] && [ "$(ls -A /wp-content/uploads 2>/dev/null)" ]; then
    UPLOADS_FILE="${DEST}/uploads-${STAMP}.tar.gz"
    echo "[$(date -Iseconds)] → Empaquetando uploads..."
    tar -czf "$UPLOADS_FILE" -C /wp-content uploads
    UP_SIZE="$(du -h "$UPLOADS_FILE" | cut -f1)"
    echo "[$(date -Iseconds)] ✓ Uploads: ${UPLOADS_FILE} (${UP_SIZE})"
elif [ "$INCLUDE_UPLOADS" = "false" ]; then
    echo "[$(date -Iseconds)] ℹ Uploads omitidos (desactivado en el panel)."
else
    echo "[$(date -Iseconds)] ℹ Sin uploads que respaldar."
fi

# ── Retención ─────────────────────────────────────────────────────────────────
echo "[$(date -Iseconds)] → Limpiando backups anteriores a ${RETAIN} días..."
find "$DEST" -name "db-*.sql.gz"      -mtime "+${RETAIN}" -delete 2>/dev/null || true
find "$DEST" -name "uploads-*.tar.gz" -mtime "+${RETAIN}" -delete 2>/dev/null || true

# ── Resumen ───────────────────────────────────────────────────────────────────
TOTAL="$(du -sh "$DEST" 2>/dev/null | cut -f1 || echo '?')"
SUMMARY="Backup ${STAMP} completado. BD: ${DB_SIZE}. Espacio total: ${TOTAL}."
[ -n "$UPLOADS_FILE" ] && SUMMARY="${SUMMARY} Uploads: ${UP_SIZE}."

echo "[$(date -Iseconds)] ✓ ${SUMMARY}"
echo "[$(date -Iseconds)]   Recuerda sincronizar a almacenamiento externo (S3/rclone)."

# ── Notificación por email (opcional) ─────────────────────────────────────────
if [ -n "$NOTIFY_EMAIL" ] && command -v sendmail >/dev/null 2>&1; then
    {
        printf 'To: %s\n' "$NOTIFY_EMAIL"
        printf 'Subject: [HWE] %s\n' "$SUMMARY"
        printf 'Content-Type: text/plain; charset=UTF-8\n\n'
        printf '%s\n\nDirectorio: %s\n' "$SUMMARY" "$DEST"
    } | sendmail "$NOTIFY_EMAIL" 2>/dev/null || true
fi
