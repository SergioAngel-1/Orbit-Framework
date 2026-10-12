#!/bin/sh
# ============================================================================
#  restore.sh — Restauración de la base de datos y de wp-content/uploads.
#
#  Contraparte de backup.sh. Restaura un backup creado por ese script:
#    - DB:      db-YYYYmmdd-HHMMSS.sql.gz   → importado al contenedor `db`.
#    - Uploads: uploads-YYYYmmdd-HHMMSS.tar.gz → extraído a backend/wp-content.
#
#  Uso:
#    sh backend/scripts/restore.sh <db-....sql.gz> [uploads-....tar.gz]
#
#  Ejemplos:
#    sh backend/scripts/restore.sh backups/db-20260619-030000.sql.gz \
#                                  backups/uploads-20260619-030000.tar.gz
#
#  SEGURIDAD: la restauración SOBREESCRIBE datos. Pide confirmación explícita.
#  Probar SIEMPRE en un entorno de staging antes de producción (ver RUNBOOK.md).
#
#  Garantías (ver backup.sh): la contraseña va por MYSQL_PWD, no por `-p…`; el volcado
#  se descomprime a un archivo temporal y se comprueba ANTES de tocar la base de datos
#  (en `gunzip | mariadb`, sin `pipefail`, un archivo corrupto o truncado «restauraba»
#  con éxito sin importar nada); y la integridad de los archivos se verifica antes de
#  pedir la confirmación destructiva.
# ============================================================================
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
# shellcheck source=lib/load-env.sh
. "$HERE/lib/load-env.sh"

DB_DUMP="${1:-}"
UPLOADS_TAR="${2:-}"

fail() {
  echo "✗ $*" >&2
  exit 1
}

if [ -z "$DB_DUMP" ]; then
  echo "Uso: sh backend/scripts/restore.sh <db-....sql.gz> [uploads-....tar.gz]" >&2
  exit 1
fi
[ -f "$DB_DUMP" ] || fail "No existe el volcado de DB: $DB_DUMP"
if [ -n "$UPLOADS_TAR" ]; then
  [ -f "$UPLOADS_TAR" ] || fail "No existe el archivo de uploads: $UPLOADS_TAR"
fi

# Integridad ANTES de pedir confirmación: un archivo corrupto o truncado se descubre aquí,
# no a mitad de una restauración que ya ha empezado a sobrescribir datos.
gzip -t "$DB_DUMP" || fail "El volcado está corrupto o truncado: $DB_DUMP"
if [ -n "$UPLOADS_TAR" ]; then
  gzip -t "$UPLOADS_TAR" || fail "El archivo de uploads está corrupto o truncado: $UPLOADS_TAR"
fi

# MYSQL_USER, MYSQL_PASSWORD y MYSQL_DATABASE del .env, leído como datos (no `. ./.env`).
hwe_load_env .env
DB_USER="${MYSQL_USER:-wordpress}"
DB_PASS="${MYSQL_PASSWORD:-wordpress}"
DB_NAME="${MYSQL_DATABASE:-wordpress}"

# Permite usar docker-compose.prod.yml: COMPOSE_FILE=docker-compose.prod.yml sh ...
COMPOSE="docker compose"
if [ -n "${COMPOSE_FILE:-}" ]; then
  COMPOSE="docker compose -f ${COMPOSE_FILE}"
fi

echo "⚠️  Vas a RESTAURAR y SOBREESCRIBIR:"
echo "    DB '$DB_NAME'  ←  $DB_DUMP"
[ -n "$UPLOADS_TAR" ] && echo "    uploads        ←  $UPLOADS_TAR"
printf "    ¿Continuar? Escribe 'restore' para confirmar: "
read -r CONFIRM
if [ "$CONFIRM" != "restore" ]; then
  echo "Cancelado."
  exit 1
fi

echo "→ Verificando que el contenedor 'db' está arriba…"
$COMPOSE ps db >/dev/null 2>&1 || fail "El servicio 'db' no está corriendo."

echo "→ Restaurando base de datos…"
TMP_SQL="$(mktemp)"
trap 'rm -f "$TMP_SQL"' EXIT
gunzip -c "$DB_DUMP" > "$TMP_SQL" || fail "No se pudo descomprimir el volcado: $DB_DUMP"
[ -s "$TMP_SQL" ] || fail "El volcado está vacío: $DB_DUMP"
# MYSQL_PWD (no -p…): ver backup.sh. El dump recrea las tablas.
export MYSQL_PWD="$DB_PASS"
$COMPOSE exec -T -e MYSQL_PWD db \
  mariadb -u"$DB_USER" "$DB_NAME" < "$TMP_SQL" \
  || fail "La importación en la base de datos falló; el estado de '$DB_NAME' es incierto."

if [ -n "$UPLOADS_TAR" ]; then
  echo "→ Restaurando uploads…"
  tar -xzf "$UPLOADS_TAR" -C backend/wp-content || fail "No se pudieron extraer los uploads."
fi

echo "→ Limpiando caché de objetos (Redis) para evitar datos obsoletos…"
$COMPOSE exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || \
  echo "  (no se pudo limpiar Redis; hazlo manualmente si procede)"

echo "✓ Restauración completada."
echo "  Verifica: login en /wp-admin, catálogo en el frontend y un pedido de prueba."
echo "  Si rotaste secretos, revisa que .env coincide con el entorno restaurado."
