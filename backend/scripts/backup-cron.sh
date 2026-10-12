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
#
#  Garantías (ver backup.sh): la contraseña va por MYSQL_PWD, no por `-p…` (visible en
#  `ps` del contenedor); el volcado va a un archivo intermedio y cada paso se comprueba
#  (en /bin/sh no hay `pipefail`: `mariadb-dump | gzip` daba por bueno un volcado
#  fallido); y si algo falla no queda ninguna copia a medias y el script sale con error,
#  que `docker logs hwe_backup` muestra (ver backup-entrypoint.sh).
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
DB_DUMP_FILE="${DEST}/db-${STAMP}.sql"
DB_FILE="${DB_DUMP_FILE}.gz"
UPLOADS_FILE=""
DONE=0

fail() {
    echo "[$(date -Iseconds)] ✗ $*" >&2
    exit 1
}

# Si algo falla no queda ninguna copia a medias que parezca válida.
cleanup() {
    if [ "$DONE" != 1 ]; then
        rm -f "$DB_DUMP_FILE" "$DB_FILE"
        [ -z "$UPLOADS_FILE" ] || rm -f "$UPLOADS_FILE"
        echo "[$(date -Iseconds)] ✗ Backup ${STAMP} ABORTADO: no se conserva ninguna copia parcial." >&2
    fi
}
trap cleanup EXIT

echo "[$(date -Iseconds)] → Volcando BD '${DB_NAME}' en ${DB_HOST}..."
# MYSQL_PWD (no -p"…"): la contraseña no queda visible en `ps` ni en /proc/<pid>/cmdline.
export MYSQL_PWD="${MYSQL_PASSWORD:-}"
mariadb-dump \
    -h "$DB_HOST" \
    -u "$DB_USER" \
    --single-transaction \
    --quick \
    --add-drop-database \
    --databases "$DB_NAME" \
    > "$DB_DUMP_FILE" \
    || fail "mariadb-dump falló (¿${DB_HOST} está accesible y las credenciales son correctas?)."
[ -s "$DB_DUMP_FILE" ] || fail "El volcado de '${DB_NAME}' está vacío."
gzip "$DB_DUMP_FILE" || fail "No se pudo comprimir el volcado."
gzip -t "$DB_FILE" || fail "El volcado comprimido no supera la comprobación de integridad."

DB_SIZE="$(du -h "$DB_FILE" | cut -f1)"
echo "[$(date -Iseconds)] ✓ BD: ${DB_FILE} (${DB_SIZE})"

# ── Uploads / medios ──────────────────────────────────────────────────────────
if [ "$INCLUDE_UPLOADS" = "true" ] && [ -d /wp-content/uploads ] && [ "$(ls -A /wp-content/uploads 2>/dev/null)" ]; then
    UPLOADS_FILE="${DEST}/uploads-${STAMP}.tar.gz"
    echo "[$(date -Iseconds)] → Empaquetando uploads..."
    tar -czf "$UPLOADS_FILE" -C /wp-content uploads || fail "No se pudieron empaquetar los uploads."
    tar -tzf "$UPLOADS_FILE" >/dev/null || fail "El paquete de uploads no supera la comprobación de integridad."
    UP_SIZE="$(du -h "$UPLOADS_FILE" | cut -f1)"
    echo "[$(date -Iseconds)] ✓ Uploads: ${UPLOADS_FILE} (${UP_SIZE})"
elif [ "$INCLUDE_UPLOADS" = "false" ]; then
    echo "[$(date -Iseconds)] ℹ Uploads omitidos (desactivado en el panel)."
else
    echo "[$(date -Iseconds)] ℹ Sin uploads que respaldar."
fi

# La copia de arriba ya está completa y verificada.
DONE=1

# ── Retención ─────────────────────────────────────────────────────────────────
# Un fallo aquí no tumba la copia que acaba de hacerse, pero tampoco se esconde: si no se
# pueden borrar las antiguas, se acumulan hasta llenar el disco sin que nada lo diga.
case "$RETAIN" in
    ''|*[!0-9]*)
        echo "[$(date -Iseconds)] AVISO: retain_days '${RETAIN}' no es un número; se usan 14 días." >&2
        RETAIN=14 ;;
esac
echo "[$(date -Iseconds)] → Limpiando backups anteriores a ${RETAIN} días..."
find "$DEST" -name "db-*.sql.gz"      -mtime "+${RETAIN}" -delete \
    || echo "[$(date -Iseconds)] AVISO: no se pudo aplicar la retención a los volcados en ${DEST} (permisos o disco)." >&2
find "$DEST" -name "uploads-*.tar.gz" -mtime "+${RETAIN}" -delete \
    || echo "[$(date -Iseconds)] AVISO: no se pudo aplicar la retención a los uploads en ${DEST} (permisos o disco)." >&2

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
