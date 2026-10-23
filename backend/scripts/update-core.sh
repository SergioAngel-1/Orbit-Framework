#!/bin/sh
# ============================================================================
#  update-core.sh — Mantiene al día el core de WordPress del volumen.
#
#  ── El problema que resuelve ───────────────────────────────────────────────
#  El core vive en el volumen `wordpress_core:/var/www/html` (compartido con
#  `wpcli`, `wp-cron` y —en solo lectura— `caddy`). El entrypoint de la imagen
#  oficial SOLO copia el core si falta `/var/www/html/index.php`, y ese archivo
#  existe desde el primer arranque: por eso subir la etiqueta de la imagen
#  (`wordpress:7.1` → `7.2`) NO actualiza nada. El sitio sigue con el core viejo
#  y los parches de seguridad de WordPress no llegan por el camino que uno cree
#  (`docker compose pull`).
#
#  ── Uso ────────────────────────────────────────────────────────────────────
#  # Estado: versión instalada y checksums del core (¿alguien lo modificó?)
#  docker compose -f docker-compose.prod.yml --env-file .env.prod \
#      run --rm wpcli /scripts/update-core.sh
#
#  # Actualizar a la versión que trae la imagen nueva (tras el `pull`)
#  docker compose -f docker-compose.prod.yml --env-file .env.prod \
#      run --rm wpcli /scripts/update-core.sh --apply 7.2
#  docker compose -f docker-compose.prod.yml --env-file .env.prod \
#      restart wordpress   # OPcache usa validate_timestamps=0: sin reinicio, sirve el código viejo
#
#  En desarrollo, sin `-f`/`--env-file`:
#  docker compose run --rm wpcli /scripts/update-core.sh
#
#  Necesita salida a Internet (api.wordpress.org): `wpcli` está en la red `app`
#  del compose de producción. Haz una copia de seguridad antes (`RUNBOOK.md` §1).
# ============================================================================

set -eu

APPLY=0
TO=""

while [ $# -gt 0 ]; do
    case "$1" in
        --apply)
            APPLY=1
            shift
            ;;
        --apply=*)
            APPLY=1
            TO="${1#--apply=}"
            shift
            ;;
        -h|--help)
            sed -n '2,30p' "$0"
            exit 0
            ;;
        -*)
            echo "Opción desconocida: $1" >&2
            exit 2
            ;;
        *)
            TO="$1"
            shift
            ;;
    esac
done

actual=$(wp core version --allow-root 2>/dev/null || true)
if [ -z "$actual" ]; then
    echo "ERROR: no se pudo leer la versión del core." >&2
    echo "       ¿Está el volumen montado en /var/www/html y wp-config.php presente?" >&2
    exit 1
fi

echo "Core instalado: ${actual}"

if [ "$APPLY" = "0" ]; then
    echo
    echo "Comprobando checksums del core (detecta archivos modificados a mano)..."
    # No aborta: un core modificado es un aviso, no un fallo del script.
    wp core verify-checksums --allow-root || {
        echo "AVISO: los checksums no cuadran. Reinstala el core con --apply <versión>." >&2
    }
    echo
    echo "La versión que toca es la de la imagen en uso (p. ej. wordpress:7.2 → 7.2)."
    echo "Para actualizar:  $0 --apply <versión>   y después reinicia el contenedor."
    exit 0
fi

if [ -z "$TO" ]; then
    echo "ERROR: falta la versión destino. Uso: $0 --apply <versión>" >&2
    exit 2
fi

echo
echo "Actualizando el core ${actual} → ${TO} (esto descarga de wordpress.org)…"
# `--force`: sin él, wp-cli se niega si la versión ya está «instalada» y el core
# quedó a medias. Es una actualización de core versionada, no un downgrade
# silencioso: la versión la elige quien ejecuta el script.
wp core update --version="$TO" --force --allow-root
wp core verify-checksums --allow-root

echo
echo "Hecho: core $(wp core version --allow-root)."
echo "Reinicia WordPress para recargar OPcache (validate_timestamps=0):"
echo "  docker compose -f docker-compose.prod.yml --env-file .env.prod restart wordpress"
