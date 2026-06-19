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
# ============================================================================
set -eu

DB_DUMP="${1:-}"
UPLOADS_TAR="${2:-}"

if [ -z "$DB_DUMP" ]; then
  echo "Uso: sh backend/scripts/restore.sh <db-....sql.gz> [uploads-....tar.gz]" >&2
  exit 1
fi
if [ ! -f "$DB_DUMP" ]; then
  echo "✗ No existe el volcado de DB: $DB_DUMP" >&2
  exit 1
fi

# Carga variables del .env si existe.
if [ -f .env ]; then
  # shellcheck disable=SC1091
  . ./.env
fi
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
$COMPOSE ps db >/dev/null 2>&1 || { echo "✗ El servicio 'db' no está corriendo."; exit 1; }

echo "→ Restaurando base de datos…"
# --force para continuar ante objetos preexistentes; el dump recrea las tablas.
gunzip -c "$DB_DUMP" | $COMPOSE exec -T db \
  mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"

if [ -n "$UPLOADS_TAR" ]; then
  if [ ! -f "$UPLOADS_TAR" ]; then
    echo "✗ No existe el archivo de uploads: $UPLOADS_TAR" >&2
    exit 1
  fi
  echo "→ Restaurando uploads…"
  tar -xzf "$UPLOADS_TAR" -C backend/wp-content
fi

echo "→ Limpiando caché de objetos (Redis) para evitar datos obsoletos…"
$COMPOSE exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || \
  echo "  (no se pudo limpiar Redis; hazlo manualmente si procede)"

echo "✓ Restauración completada."
echo "  Verifica: login en /wp-admin, catálogo en el frontend y un pedido de prueba."
echo "  Si rotaste secretos, revisa que .env coincide con el entorno restaurado."
