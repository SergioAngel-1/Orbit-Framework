#!/bin/sh
# ============================================================================
#  backup-cron.sh — Ejecutado por crond en el contenedor `backup`, ya sea
#  por el horario programado (`backup-cron.sh scheduled`) o por un backup
#  manual disparado desde wp-admin (`backup-cron.sh manual`, ver
#  backup-trigger-watch.sh).
#
#  Lee la configuración de backup desde hwe-backup-config.json (generado por
#  el plugin HWE Control Center). Si el archivo no existe, usa los valores
#  por defecto del entorno.
#
#  Opciones del panel que controlan este script:
#    enabled                → backup-entrypoint.sh decide si programa el cron
#                              (ver ahí); este script no vuelve a mirarla.
#    include_uploads        → si false, omite el tar de wp-content/uploads
#    exclude_thumbnails     → si true, el tar de uploads omite los tamaños que
#                              WordPress regenera (archivo-300x300.png…)
#    include_config_export  → si true, exporta hwe_config/hwe_config_secrets
#    retain_days             → días de retención de archivos locales
#    keep_only_latest        → si true, conserva solo el último de cada tipo
#                              (anula retain_days)
#
#  Nada se borra si la corrida falla: un backup roto no puede llevarse por
#  delante las copias buenas que quedan.
#    notify_email            → la notificación la envía WordPress vía
#                              WP-Cron/wp_mail() leyendo hwe-backup-status.json
#                              (ver BackupNotify.php) — este script NO envía
#                              correo, solo deja el resultado en ese archivo.
# ============================================================================
set -eu

TRIGGER="${1:-scheduled}"
DEST="${BACKUP_DIR:-/backups}"
CONFIG_FILE="${DEST}/hwe-backup-config.json"
STATUS_FILE="${DEST}/hwe-backup-status.json"
RUNNING_FILE="${DEST}/hwe-backup-running.json"
LOCK_DIR="${HWE_BACKUP_LOCK_DIR:-/tmp/hwe-backup.lock}"
# Un backup que muere (OOM, `docker kill`, un manual interrumpido) no ejecuta el `trap` y
# deja el lock puesto: sin salida, TODAS las corridas siguientes se omiten («ya hay un
# backup en curso») y el sitio se queda sin copias sin que nada lo avise. Por eso el lock
# guarda el PID de su dueño y se retira si el dueño ya no existe o si lleva más de
# LOCK_MAX_AGE_MIN minutos (un PID puede reutilizarse tras reiniciar el contenedor).
LOCK_MAX_AGE_MIN="${HWE_BACKUP_LOCK_MAX_AGE_MIN:-360}"

lock_is_stale() {
    owner=$(cat "$LOCK_DIR/pid" 2>/dev/null || true)
    case "$owner" in
        ''|*[!0-9]*) ;;                       # sin PID válido (lock antiguo): solo cuenta la edad
        *) kill -0 "$owner" 2>/dev/null || return 0 ;;   # el dueño ya no existe
    esac
    [ -n "$(find "$LOCK_DIR" -maxdepth 0 -mmin "+${LOCK_MAX_AGE_MIN}" 2>/dev/null)" ]
}

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    if lock_is_stale; then
        echo "[$(date -Iseconds)] Lock huérfano en ${LOCK_DIR} (su dueño ya no existe o supera ${LOCK_MAX_AGE_MIN} min): se retira." >&2
        rm -rf "$LOCK_DIR"
        mkdir "$LOCK_DIR" 2>/dev/null || {
            echo "[$(date -Iseconds)] Otra corrida tomó el lock a la vez, se omite esta."
            exit 0
        }
    else
        echo "[$(date -Iseconds)] Ya hay un backup en curso (lock activo en ${LOCK_DIR}), se omite esta corrida."
        exit 0
    fi
fi
echo "$$" > "$LOCK_DIR/pid"
# RUNNING_FILE vive en $DEST (compartido con el contenedor `wordpress` vía
# HWE_BACKUPS_DIR) a diferencia del lock, que es solo interno a este
# contenedor: es lo único que le permite a BackupConfig::readRunning() (panel
# de wp-admin) saber que hay una corrida en curso ahora mismo.
mkdir -p "$DEST"
trap 'rm -f "$RUNNING_FILE"; rm -rf "$LOCK_DIR"' EXIT
jq -n --arg started_at "$(date -Iseconds)" --arg trigger "$TRIGGER" \
    '{started_at: $started_at, trigger: $trigger}' \
    > "$RUNNING_FILE"

# ── Leer configuración (con fallback a env vars) ──────────────────────────────
INCLUDE_UPLOADS="true"
EXCLUDE_THUMBS="false"
INCLUDE_CONFIG="true"
RETAIN="${BACKUP_RETAIN_DAYS:-14}"
KEEP_ONLY_LATEST="false"

# OJO con `// true`: en jq `false // true` da `true` (el operador trata
# `false` como ausente), así que los booleanos que pueden valer false se leen
# comparando, no con valor por defecto.
if [ -f "$CONFIG_FILE" ] && command -v jq >/dev/null 2>&1; then
    INCLUDE_UPLOADS=$(jq -r 'if .include_uploads == false then "false" else "true" end'        "$CONFIG_FILE" 2>/dev/null || echo 'true')
    EXCLUDE_THUMBS=$(jq -r 'if .exclude_thumbnails == true then "true" else "false" end'       "$CONFIG_FILE" 2>/dev/null || echo 'false')
    INCLUDE_CONFIG=$(jq -r 'if .include_config_export == false then "false" else "true" end'   "$CONFIG_FILE" 2>/dev/null || echo 'true')
    RETAIN=$(jq -r '.retain_days // 14'                                                          "$CONFIG_FILE" 2>/dev/null || echo '14')
    KEEP_ONLY_LATEST=$(jq -r 'if .keep_only_latest == true then "true" else "false" end'       "$CONFIG_FILE" 2>/dev/null || echo 'false')
fi

# Entero >= 1 o el valor por defecto: `find -mmin +` con basura o con 0
# borraría de más (o fallaría en silencio, por el `|| true`).
case "$RETAIN" in
    ''|*[!0-9]*) RETAIN=14 ;;
esac
[ "$RETAIN" -ge 1 ] 2>/dev/null || RETAIN=14

STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

echo "[$(date -Iseconds)] ── Iniciando backup ${STAMP} (trigger: ${TRIGGER}) ──"

SUCCESS="true"
ERROR_MSG=""

# ── Base de datos ─────────────────────────────────────────────────────────────
DB_HOST="${MYSQL_HOST:-db}"
DB_USER="${MYSQL_USER:-wordpress}"
DB_NAME="${MYSQL_DATABASE:-wordpress}"
DB_FILE="${DEST}/db-${STAMP}.sql.gz"
DB_SIZE="0"

# MYSQL_PWD (no -p"$MYSQL_PASSWORD"): evita que la contraseña quede visible
# en ps aux/cmdline de este contenedor mientras el dump corre.
export MYSQL_PWD="${MYSQL_PASSWORD}"

# Sin pipefail (/bin/sh, no bash): si se canalizara directo a gzip, un
# mariadb-dump fallido no abortaría el script (el exit status del pipe es
# el de gzip). Se escribe a un archivo intermedio y se comprueba el
# resultado del dump explícitamente en el `if`.
DB_DUMP_FILE="${DEST}/db-${STAMP}.sql"
if mariadb-dump \
    -h "$DB_HOST" \
    -u "$DB_USER" \
    --single-transaction \
    --quick \
    --add-drop-database \
    --databases "$DB_NAME" \
    > "$DB_DUMP_FILE"
then
    gzip "$DB_DUMP_FILE"
    DB_SIZE="$(du -h "$DB_FILE" | cut -f1)"
    echo "[$(date -Iseconds)] ✓ BD: ${DB_FILE} (${DB_SIZE})"
else
    SUCCESS="false"
    ERROR_MSG="Falló mariadb-dump de la base de datos."
    echo "[$(date -Iseconds)] ✗ ${ERROR_MSG}"
    rm -f "$DB_DUMP_FILE"
fi

# ── Uploads / medios ──────────────────────────────────────────────────────────
UPLOADS_FILE=""
UP_SIZE=""
if [ "$SUCCESS" = "true" ] && [ "$INCLUDE_UPLOADS" = "true" ] && [ -d /wp-content/uploads ] && [ "$(ls -A /wp-content/uploads 2>/dev/null)" ]; then
    UPLOADS_FILE="${DEST}/uploads-${STAMP}.tar.gz"
    UPLOADS_LIST="$(mktemp)"
    TAR_STATUS="$(mktemp)"
    echo "[$(date -Iseconds)] → Empaquetando uploads..."

    # Lista explícita de archivos (`tar -T`): permite dejar fuera las
    # miniaturas sin depender de patrones de exclusión, que interpretarían los
    # corchetes o asteriscos de un nombre de archivo.
    (cd /wp-content && find uploads -type f) > "$UPLOADS_LIST"
    if [ "$EXCLUDE_THUMBS" = "true" ]; then
        # Miniatura = `<base>-<ancho>x<alto>.<ext>` cuyo `<base>.<cualquier
        # ext>` existe en la misma carpeta. Sin ese original al lado, el
        # archivo se guarda: podría ser una subida que se llame así.
        awk '
            { files[NR] = $0; stem = $0; sub(/\.[^.\/]*$/, "", stem); stems[stem] = 1 }
            END {
                for (i = 1; i <= NR; i++) {
                    f = files[i]
                    if (match(f, /-[0-9]+x[0-9]+\.[^.\/]+$/) && (substr(f, 1, RSTART - 1) in stems)) continue
                    print f
                }
            }' "$UPLOADS_LIST" > "${UPLOADS_LIST}.keep"
        echo "[$(date -Iseconds)]   Miniaturas omitidas: $(( $(wc -l < "$UPLOADS_LIST") - $(wc -l < "${UPLOADS_LIST}.keep") )) de $(wc -l < "$UPLOADS_LIST") archivos."
        mv "${UPLOADS_LIST}.keep" "$UPLOADS_LIST"
    fi

    # gzip -1, no el -6 de `tar -z`: el 90 % de uploads son PNG/JPG/WebP, que
    # ya van comprimidos, y el nivel alto solo gastaba CPU (en un VPS de 2
    # vCPU que comparte con Apache y MariaDB) para ganar menos de un 1 %.
    # Sin pipefail en /bin/sh: el estado de tar se recoge aparte, o un tar
    # fallido pasaría por bueno con el de gzip.
    ( cd /wp-content && tar -cf - -T "$UPLOADS_LIST"; echo $? > "$TAR_STATUS" ) | gzip -1 > "$UPLOADS_FILE"
    GZIP_RC=$?
    TAR_RC="$(cat "$TAR_STATUS" 2>/dev/null || echo 1)"
    rm -f "$UPLOADS_LIST" "$TAR_STATUS"

    if [ "$TAR_RC" = "0" ] && [ "$GZIP_RC" = "0" ]; then
        UP_SIZE="$(du -h "$UPLOADS_FILE" | cut -f1)"
        echo "[$(date -Iseconds)] ✓ Uploads: ${UPLOADS_FILE} (${UP_SIZE})"
    else
        SUCCESS="false"
        ERROR_MSG="Falló el empaquetado de uploads (tar=${TAR_RC}, gzip=${GZIP_RC})."
        echo "[$(date -Iseconds)] ✗ ${ERROR_MSG}"
        rm -f "$UPLOADS_FILE"
        UPLOADS_FILE=""
    fi
elif [ "$INCLUDE_UPLOADS" = "false" ]; then
    echo "[$(date -Iseconds)] ℹ Uploads omitidos (desactivado en el panel)."
fi

# ── Configuración del panel (opcional) ────────────────────────────────────────
CONFIG_FILE_OUT=""
if [ "$SUCCESS" = "true" ] && [ "$INCLUDE_CONFIG" = "true" ]; then
    CONFIG_FILE_OUT="${DEST}/config-${STAMP}.json"
    echo "[$(date -Iseconds)] → Exportando configuración del panel..."
    HWE_CONFIG_RAW=$(mariadb -N -B -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
        -e "SELECT option_value FROM wp_options WHERE option_name = 'hwe_config' LIMIT 1" 2>/dev/null || echo '')
    HWE_SECRETS_RAW=$(mariadb -N -B -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
        -e "SELECT option_value FROM wp_options WHERE option_name = 'hwe_config_secrets' LIMIT 1" 2>/dev/null || echo '')
    jq -n --arg cfg "$HWE_CONFIG_RAW" --arg secrets "$HWE_SECRETS_RAW" --arg stamp "$STAMP" \
        '{stamp: $stamp, hwe_config_serialized: $cfg, hwe_config_secrets_serialized: $secrets}' \
        > "$CONFIG_FILE_OUT"
    echo "[$(date -Iseconds)] ✓ Config: ${CONFIG_FILE_OUT}"
elif [ "$INCLUDE_CONFIG" = "false" ]; then
    echo "[$(date -Iseconds)] ℹ Export de configuración omitido (desactivado en el panel)."
fi

# ── Retención ─────────────────────────────────────────────────────────────────
# Borra todos los artefactos de un tipo salvo el más reciente. Los nombres
# llevan la marca YYYYmmdd-HHMMSS, así que el orden alfabético es el
# cronológico. El patrón es el mismo que BackupConfig::ARTIFACT_PATTERN: nada
# que no sea un artefacto de backup (volcados a mano, `go-live/`…) se toca.
prune_all_but_latest() {
    ls -1 "$DEST" 2>/dev/null | grep -E "$1" | sort | sed '$d' | while read -r OLD; do
        rm -f "${DEST}/${OLD}" && echo "[$(date -Iseconds)]   borrado ${OLD}"
    done
}

if [ "$SUCCESS" != "true" ]; then
    echo "[$(date -Iseconds)] ℹ Retención omitida: el backup falló y no se borra ninguna copia anterior."
elif [ "$KEEP_ONLY_LATEST" = "true" ]; then
    echo "[$(date -Iseconds)] → Conservando solo el último backup de cada tipo..."
    prune_all_but_latest '^db-[0-9]{8}-[0-9]{6}\.sql\.gz$'
    prune_all_but_latest '^uploads-[0-9]{8}-[0-9]{6}\.tar\.gz$'
    prune_all_but_latest '^config-[0-9]{8}-[0-9]{6}\.json$'
else
    # En minutos y con una hora de holgura, no `-mtime +N`: `find` redondea la
    # edad a días enteros hacia abajo, así que `-mtime +7` solo borraba a
    # partir de los OCHO días y «7 días» dejaba 8 copias diarias. Con la
    # holgura, la copia de hace exactamente N días cae aunque la de hoy haya
    # terminado unos minutos antes que aquélla: con copia diaria quedan N.
    MAX_AGE_MIN=$(( RETAIN * 1440 - 60 ))
    echo "[$(date -Iseconds)] → Limpiando backups de más de ${RETAIN} días..."
    for PATTERN in 'db-*.sql.gz' 'uploads-*.tar.gz' 'config-*.json'; do
        find "$DEST" -maxdepth 1 -type f -name "$PATTERN" -mmin "+${MAX_AGE_MIN}" \
            -exec rm -f {} \; -exec echo "[$(date -Iseconds)]   borrado" {} \; 2>/dev/null || true
    done
fi

# ── Resumen + status.json ──────────────────────────────────────────────────────
if [ "$SUCCESS" = "true" ]; then
    SUMMARY="Backup ${STAMP} (${TRIGGER}) completado. BD: ${DB_SIZE}."
    if [ -n "$UPLOADS_FILE" ]; then
        if [ "$EXCLUDE_THUMBS" = "true" ]; then
            SUMMARY="${SUMMARY} Uploads (sin miniaturas): ${UP_SIZE}."
        else
            SUMMARY="${SUMMARY} Uploads: ${UP_SIZE}."
        fi
    fi
else
    SUMMARY="Backup ${STAMP} (${TRIGGER}) FALLÓ: ${ERROR_MSG}"
fi
echo "[$(date -Iseconds)] $([ "$SUCCESS" = "true" ] && echo '✓' || echo '✗') ${SUMMARY}"
echo "[$(date -Iseconds)]   Recuerda sincronizar a almacenamiento externo (S3/rclone)."

FILES_JSON="[]"
for f in "$DB_FILE" "$UPLOADS_FILE" "$CONFIG_FILE_OUT"; do
    [ -n "$f" ] && [ -f "$f" ] || continue
    FILES_JSON=$(echo "$FILES_JSON" | jq --arg name "$(basename "$f")" '. + [$name]')
done

jq -n \
    --arg last_run_at "$(date -Iseconds)" \
    --arg trigger "$TRIGGER" \
    --argjson success "$SUCCESS" \
    --arg summary "$SUMMARY" \
    --argjson files "$FILES_JSON" \
    '{last_run_at: $last_run_at, trigger: $trigger, success: $success, summary: $summary, files: $files}' \
    > "$STATUS_FILE"

[ "$SUCCESS" = "true" ]
