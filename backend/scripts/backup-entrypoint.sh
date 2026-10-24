#!/bin/sh
# ============================================================================
#  backup-entrypoint.sh — Entrypoint del contenedor `backup` de producción.
#
#  Lee la configuración desde el panel de WordPress (hwe-backup-config.json,
#  generado por el plugin HWE Control Center al guardar la sección Backups).
#  Si el archivo no existe, usa los valores por defecto del entorno.
#
#  IMPORTANTE: Reinicia el contenedor `backup` tras cambiar el horario en el
#  panel para que el cron se actualice.
#
#  crond corre SIEMPRE, incluso con "Activar backup automático" desactivado:
#  el watcher de "Backup ahora" (backup-trigger-watch.sh) es independiente de
#  ese interruptor, así que un backup manual sigue funcionando aunque el
#  automático esté apagado.
# ============================================================================
set -e

# Carpeta de scripts (montada :ro): de ahí salen el cron y el watcher del disparador.
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST="${BACKUP_DIR:-/backups}"
CONFIG_FILE="${DEST}/hwe-backup-config.json"

echo "[backup] Instalando dependencias (mariadb-client + jq)..."
apk add --no-cache mariadb-client jq >/dev/null 2>&1

# NO chmod aquí: backup-cron.sh y backup-trigger-watch.sh se montan `:ro`
# (docker-compose*.yml) por seguridad, así que chmod fallaría con "Read-only
# file system" y abortaría el entrypoint (set -e) en bucle de reinicio. Ya
# llegan +x porque están versionados como ejecutables en git (modo 100755).

# ── Leer configuración desde el panel ────────────────────────────────────────
ENABLED="true"
CRON_EXPR="0 ${BACKUP_HOUR_UTC:-3} * * *"
RETAIN="${BACKUP_RETAIN_DAYS:-14}"

if [ -f "$CONFIG_FILE" ]; then
    echo "[backup] Configuración encontrada en $CONFIG_FILE:"

    # No `.enabled // true`: en jq `false // true` da `true`, y apagar el
    # backup automático desde el panel no tenía ningún efecto.
    ENABLED=$(jq -r 'if .enabled == false then "false" else "true" end' "$CONFIG_FILE" 2>/dev/null || echo 'true')
    CRON_EXPR=$(jq -r '.cron // "0 3 * * *"'    "$CONFIG_FILE" 2>/dev/null || echo '0 3 * * *')
    RETAIN=$(jq -r '.retain_days // 14'          "$CONFIG_FILE" 2>/dev/null || echo '14')
    SCHEDULE=$(jq -r '.schedule // "daily"'      "$CONFIG_FILE" 2>/dev/null || echo 'daily')
    INCLUDE_UPLOADS=$(jq -r 'if .include_uploads == false then "false" else "true" end' "$CONFIG_FILE" 2>/dev/null || echo 'true')
    KEEP_ONLY_LATEST=$(jq -r 'if .keep_only_latest == true then "true" else "false" end' "$CONFIG_FILE" 2>/dev/null || echo 'false')
    EXCLUDE_THUMBS=$(jq -r 'if .exclude_thumbnails == true then "true" else "false" end' "$CONFIG_FILE" 2>/dev/null || echo 'false')
    UPDATED=$(jq -r '.updated_at // "?"'         "$CONFIG_FILE" 2>/dev/null || echo '?')

    echo "  enabled=$ENABLED | schedule=$SCHEDULE | cron='$CRON_EXPR'"
    echo "  retain_days=$RETAIN | keep_only_latest=$KEEP_ONLY_LATEST"
    echo "  include_uploads=$INCLUDE_UPLOADS | exclude_thumbnails=$EXCLUDE_THUMBS"
    echo "  última actualización desde panel: $UPDATED"
else
    echo "[backup] $CONFIG_FILE no encontrado. Usando valores por defecto."
    echo "  Configura los backups desde Ajustes → HWE Config → Backups automáticos."
fi

# ── Programar cron ────────────────────────────────────────────────────────────
# Escribe el cron en el archivo del sistema en lugar de crontab -,
# para asegurar compatibilidad con BusyBox crond de Alpine.
# El panel (www-data, gid 33 en el contenedor de WordPress) escribe en este
# mismo directorio su configuración y el disparador de «Backup ahora». Si no
# puede, ninguna de las dos cosas llega aquí y nada falla a la vista: se avisa.
DEST_GID=$(stat -c %g "$DEST" 2>/dev/null || echo "?")
DEST_MODE=$(stat -c %a "$DEST" 2>/dev/null || echo "?")
case "$DEST_MODE" in
    *7?|*6?) GROUP_WRITABLE=yes ;;
    *) GROUP_WRITABLE=no ;;
esac
if [ "$DEST_GID" != "33" ] || [ "$GROUP_WRITABLE" != "yes" ]; then
    echo "[backup] ⚠️  AVISO: ${DEST} es gid=${DEST_GID} modo=${DEST_MODE}: el panel de WordPress no puede escribir aquí."
    echo "           La configuración de copias y el botón «Backup ahora» NO funcionarán."
    echo "           En el host: sudo chgrp 33 backups && sudo chmod 775 backups"
fi

CRON_FILE="/etc/crontabs/root"
mkdir -p "$(dirname "$CRON_FILE")"

# La salida de cada corrida va al stdout del PROCESO 1 (crond), que es lo que lee
# `docker logs ${HWE_CONTAINER_PREFIX:-hwe}_backup`. Un archivo dentro del contenedor sería efímero e invisible
# para quien monitoriza; el resultado también queda en hwe-backup-status.json (panel).
CRON_LOG='>> /proc/1/fd/1 2>&1'

if [ "$ENABLED" = "true" ]; then
    printf '%s %s/backup-cron.sh scheduled %s\n' "$CRON_EXPR" "$SCRIPTS_DIR" "$CRON_LOG" > "$CRON_FILE"
    echo "[backup] Cron programado: '$CRON_EXPR'"
else
    : > "$CRON_FILE"
    echo "[backup] Backup automático DESACTIVADO desde el panel. Solo 'Backup ahora' (manual) seguirá disponible."
fi
printf '* * * * * %s/backup-trigger-watch.sh %s\n' "$SCRIPTS_DIR" "$CRON_LOG" >> "$CRON_FILE"

echo "[backup] Servicio listo."
echo "[backup] Backup inmediato: botón 'Backup ahora' en wp-admin, o 'docker exec ${HWE_CONTAINER_PREFIX:-hwe}_backup ${SCRIPTS_DIR}/backup-cron.sh manual'."

# ── Lanzar crond en primer plano ──────────────────────────────────────────────
exec crond -f -l 6
