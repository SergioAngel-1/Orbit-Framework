#!/bin/sh
# ============================================================================
#  backup.sh — Copia de seguridad de la base de datos y de wp-content.
#
#  Estrategia (ver docs/DEPLOYMENT.md §Backups):
#    - DB: mysqldump del contenedor `db` -> archivo .sql.gz con marca de tiempo.
#    - Medios/uploads: tar.gz de backend/wp-content/uploads.
#  Pensado para ejecutarse en el host (cron) o en CI/CD programado.
#
#  Uso:   sh backend/scripts/backup.sh [directorio_destino]
#  Restaurar DB:  gunzip < backup.sql.gz | docker compose exec -T db \
#                   mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"
# ============================================================================
set -eu

DEST="${1:-./backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

# Carga variables del .env si existe (MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE).
if [ -f .env ]; then
  # shellcheck disable=SC1091
  . ./.env
fi

DB_USER="${MYSQL_USER:-wordpress}"
DB_PASS="${MYSQL_PASSWORD:-wordpress}"
DB_NAME="${MYSQL_DATABASE:-wordpress}"

echo "→ Volcando base de datos '$DB_NAME'…"
docker compose exec -T db \
  mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction --quick "$DB_NAME" \
  | gzip > "$DEST/db-$STAMP.sql.gz"

echo "→ Empaquetando uploads…"
if [ -d backend/wp-content/uploads ]; then
  tar -czf "$DEST/uploads-$STAMP.tar.gz" -C backend/wp-content uploads
fi

echo "✓ Backup completado en $DEST (db-$STAMP.sql.gz, uploads-$STAMP.tar.gz)"
echo "  Retén estos archivos fuera del servidor (S3/almacenamiento externo)."
