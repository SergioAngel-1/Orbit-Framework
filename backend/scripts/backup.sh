#!/bin/sh
# ============================================================================
#  backup.sh — Copia de seguridad de la base de datos y de wp-content.
#
#  Estrategia (ver docs/DEPLOYMENT.md §Backups):
#    - DB: mariadb-dump del contenedor `db` -> archivo .sql.gz con marca de tiempo.
#    - Medios/uploads: tar.gz de backend/wp-content/uploads.
#  Pensado para ejecutarse en el host (cron) o en CI/CD programado.
#
#  Uso:   sh backend/scripts/backup.sh [directorio_destino]
#  Restaurar:  ver restore.sh (usa la copia generada aquí).
#
#  Garantías:
#    · La contraseña de la base de datos NO viaja en la línea de comandos (`-p…` sería
#      visible en `ps aux` y en /proc/<pid>/cmdline durante el volcado): va por la
#      variable MYSQL_PWD, que `docker compose exec -e MYSQL_PWD` reenvía SIN poner el
#      valor en los argumentos.
#    · Cada paso se comprueba. Este script usa /bin/sh (no bash): sin `pipefail`, en
#      `mariadb-dump | gzip` el estado de la tubería es el de `gzip`, así que un volcado
#      que fallaba a medias «tenía éxito». Se vuelca a un archivo intermedio y solo
#      después se comprime.
#    · Si algo falla NO queda ninguna copia a medias que parezca válida, y el script
#      termina con error (código ≠ 0) explicando qué falló.
# ============================================================================
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
# shellcheck source=lib/load-env.sh
. "$HERE/lib/load-env.sh"

DEST="${1:-./backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

# MYSQL_USER, MYSQL_PASSWORD y MYSQL_DATABASE del .env, leído como datos (no `. ./.env`).
hwe_load_env .env

DB_USER="${MYSQL_USER:-wordpress}"
DB_PASS="${MYSQL_PASSWORD:-wordpress}"
DB_NAME="${MYSQL_DATABASE:-wordpress}"

DUMP_FILE="$DEST/db-$STAMP.sql"
DB_FILE="$DUMP_FILE.gz"
UPLOADS_FILE="$DEST/uploads-$STAMP.tar.gz"
DONE=0

fail() {
  echo "✗ $*" >&2
  exit 1
}

cleanup() {
  if [ "$DONE" != 1 ]; then
    rm -f "$DUMP_FILE" "$DB_FILE" "$UPLOADS_FILE"
    echo "✗ Backup ABORTADO: no se conserva ninguna copia parcial." >&2
  fi
}
trap cleanup EXIT

echo "→ Volcando base de datos '$DB_NAME'…"
# `-e MYSQL_PWD` (sin «=valor»): le pide a `docker compose exec` que reenvíe la variable ya
# presente en ESTE shell; el valor nunca aparece en los argumentos de ningún proceso.
export MYSQL_PWD="$DB_PASS"
docker compose exec -T -e MYSQL_PWD db \
  mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" > "$DUMP_FILE" \
  || fail "mariadb-dump falló (¿el contenedor 'db' está arriba y las credenciales son correctas?)."
[ -s "$DUMP_FILE" ] || fail "El volcado de '$DB_NAME' está vacío."
gzip "$DUMP_FILE" || fail "No se pudo comprimir el volcado."
gzip -t "$DB_FILE" || fail "El volcado comprimido no supera la comprobación de integridad."

UPLOADS_DONE=0
echo "→ Empaquetando uploads…"
if [ -d backend/wp-content/uploads ]; then
  tar -czf "$UPLOADS_FILE" -C backend/wp-content uploads || fail "No se pudieron empaquetar los uploads."
  tar -tzf "$UPLOADS_FILE" >/dev/null || fail "El paquete de uploads no supera la comprobación de integridad."
  UPLOADS_DONE=1
else
  echo "  (sin backend/wp-content/uploads que respaldar)"
fi

DONE=1
echo "✓ Backup completado en $DEST (db-$STAMP.sql.gz$([ "$UPLOADS_DONE" = 1 ] && echo ", uploads-$STAMP.tar.gz"))"
echo "  Retén estos archivos fuera del servidor (S3/almacenamiento externo)."
