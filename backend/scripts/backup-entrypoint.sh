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
# ============================================================================
set -e

CONFIG_FILE="/wp-content/hwe-backup-config.json"

echo "[backup] Instalando dependencias (mariadb-client + jq)..."
# Solo se silencia la salida normal: si falla (red, repositorio), el error debe verse en
# `docker logs`, no esconderse tras un contenedor que "arranca" sin poder hacer copias.
apk add --no-cache mariadb-client jq >/dev/null

# NO chmod: backup-cron.sh se monta `:ro` (docker-compose.prod.yml) por seguridad, y un
# chmod sobre un archivo de un montaje de solo lectura falla SIEMPRE (EROFS), aunque ya
# sea ejecutable; con `set -e` el entrypoint moría al arrancar y el contenedor entraba en
# bucle de reinicios sin hacer nunca una copia. Por eso el job se lanza con `/bin/sh`,
# que no necesita el bit de ejecución (644 en el repo; se pierde en checkouts de Windows).

# ── Leer configuración desde el panel ────────────────────────────────────────
ENABLED="true"
CRON_EXPR="0 ${BACKUP_HOUR_UTC:-3} * * *"
RETAIN="${BACKUP_RETAIN_DAYS:-14}"

if [ -f "$CONFIG_FILE" ]; then
    echo "[backup] Configuración encontrada en $CONFIG_FILE:"

    ENABLED=$(jq -r '.enabled // true'          "$CONFIG_FILE" 2>/dev/null || echo 'true')
    CRON_EXPR=$(jq -r '.cron // "0 3 * * *"'    "$CONFIG_FILE" 2>/dev/null || echo '0 3 * * *')
    RETAIN=$(jq -r '.retain_days // 14'          "$CONFIG_FILE" 2>/dev/null || echo '14')
    SCHEDULE=$(jq -r '.schedule // "daily"'      "$CONFIG_FILE" 2>/dev/null || echo 'daily')
    INCLUDE_UPLOADS=$(jq -r '.include_uploads // true' "$CONFIG_FILE" 2>/dev/null || echo 'true')
    UPDATED=$(jq -r '.updated_at // "?"'         "$CONFIG_FILE" 2>/dev/null || echo '?')

    echo "  enabled=$ENABLED | schedule=$SCHEDULE | cron='$CRON_EXPR'"
    echo "  retain_days=$RETAIN | include_uploads=$INCLUDE_UPLOADS"
    echo "  última actualización desde panel: $UPDATED"
else
    echo "[backup] $CONFIG_FILE no encontrado. Usando valores por defecto."
    echo "  Configura los backups desde Ajustes → HWE Config → Backups automáticos."
fi

# ── Si está desactivado desde el panel, mantener el contenedor vivo sin cron ─
if [ "$ENABLED" = "false" ]; then
    echo "[backup] Backup automático DESACTIVADO desde el panel de WordPress."
    echo "[backup] Contenedor en espera. Activa el backup desde wp-admin para iniciar."
    exec tail -f /dev/null
fi

# ── Programar cron ────────────────────────────────────────────────────────────
# Escribe el cron en el archivo del sistema en lugar de crontab -,
# para asegurar compatibilidad con BusyBox crond de Alpine.
CRON_FILE="/etc/crontabs/root"
mkdir -p "$(dirname "$CRON_FILE")"
# La salida del job va a la salida estándar del proceso 1 (PID 1 = este entrypoint, luego
# crond): así los resultados Y LOS FALLOS de cada copia aparecen en `docker logs hwe_backup`.
# Antes iba a /var/log/backup.log, dentro del contenedor, donde nadie mira.
printf '%s /bin/sh /usr/local/bin/backup-cron.sh >> /proc/1/fd/1 2>&1\n' "$CRON_EXPR" > "$CRON_FILE"

echo "[backup] Cron programado: '$CRON_EXPR'"
echo "[backup] Servicio listo. El primer backup se ejecutará según el horario configurado."
echo "[backup] Para un backup inmediato: docker exec hwe_backup /bin/sh /usr/local/bin/backup-cron.sh"
echo "[backup] Resultados y fallos de cada copia: docker logs hwe_backup"

# ── Lanzar crond en primer plano ──────────────────────────────────────────────
exec crond -f -l 6
