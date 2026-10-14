#!/bin/sh
# ============================================================================
#  check-versions.sh — Compara los plugins externos fijados en `setup.sh` con la
#  última release publicada.
#
#  Dependabot no vigila estos plugins (se instalan por WP-CLI, no por un
#  gestor de paquetes), así que esta es la revisión periódica: wp.org para
#  WooCommerce, WPGraphQL y Redis Object Cache; GitHub para JWT Authentication,
#  WooGraphQL y WPGraphQL CORS.
#
#  Uso:   sh backend/scripts/check-versions.sh [--strict]
#    --strict   sale con código 1 si algún plugin está por detrás (para CI/cron)
#
#  Solo informa: NO instala ni cambia nada. Subir de versión es una decisión
#  (probar en staging y cambiar a la vez versión y SHA-256 en `setup.sh`; ver
#  docs/RUNBOOK.md «Revisión de versiones»). Un salto de *major* o una versión
#  más nueva de lo que el plugin declara probado merece revisar su changelog.
#  Requiere `curl`.
# ============================================================================
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SETUP="$HERE/setup.sh"
STRICT=0
[ "${1:-}" = "--strict" ] && STRICT=1

command -v curl >/dev/null 2>&1 || {
  echo "✗ Hace falta curl." >&2
  exit 2
}

# Valor por defecto de una variable `NOMBRE="${NOMBRE:-x.y.z}"` de setup.sh.
pinned() {
  sed -n "s/^$1=\"\${$1:-\([^}]*\)}\".*/\1/p" "$SETUP" | head -1
}

# Primer `"clave":"valor"` de un JSON en una línea (sin jq).
json_field() {
  grep -o "\"$1\": *\"[^\"]*\"" | head -1 | cut -d'"' -f4
}

wporg_latest() {
  curl -fsS --max-time 20 "https://api.wordpress.org/plugins/info/1.0/$1.json" | json_field version
}

github_latest() {
  curl -fsS --max-time 20 "https://api.github.com/repos/$1/releases/latest" | json_field tag_name | sed 's/^v//'
}

OUTDATED=0
FAILED=0

row() { # nombre fijada última origen
  _status="al día"
  if [ -z "$3" ]; then
    _status="sin dato"
    FAILED=1
  elif [ "$2" != "$3" ]; then
    _status="REVISAR"
    OUTDATED=1
  fi
  printf '%-30s %-10s %-10s %-8s %s\n' "$1" "$2" "${3:-?}" "$_status" "$4"
}

printf '%-30s %-10s %-10s %-8s %s\n' "Plugin" "Fijada" "Última" "Estado" "Origen"

row "WooCommerce"   "$(pinned WOOCOMMERCE_VERSION)"      "$(wporg_latest woocommerce 2>/dev/null || true)"  "wp.org"
row "WPGraphQL"     "$(pinned WPGRAPHQL_VERSION)"        "$(wporg_latest wp-graphql 2>/dev/null || true)"   "wp.org"
row "Redis Object Cache" "$(pinned REDISCACHE_VERSION)"  "$(wporg_latest redis-cache 2>/dev/null || true)"  "wp.org"
row "WooGraphQL"    "$(pinned WOOGRAPHQL_WPORG_VERSION)" "$(github_latest wp-graphql/wp-graphql-woocommerce 2>/dev/null || true)" "GitHub"
row "WPGraphQL JWT Auth" "$(sed -n 's#.*wp-graphql-jwt-authentication/releases/download/v\([0-9.]*\)/.*#\1#p' "$SETUP" | head -1)" "$(github_latest wp-graphql/wp-graphql-jwt-authentication 2>/dev/null || true)" "GitHub"
row "WPGraphQL CORS" "$(sed -n 's#.*wp-graphql-cors/zipball/\([0-9.]*\).*#\1#p' "$SETUP" | head -1)" "$(github_latest funkhaus/wp-graphql-cors 2>/dev/null || true)" "GitHub"

echo
if [ "$FAILED" = 1 ]; then
  echo "⚠ Algún origen no respondió: vuelve a intentarlo (¿sin red o límite de la API de GitHub?)."
fi
if [ "$OUTDATED" = 1 ]; then
  echo "Hay plugins por detrás de su última release. Revisa el changelog y, si procede,"
  echo "súbelos en staging cambiando versión y SHA-256 a la vez (docs/RUNBOOK.md)."
  [ "$STRICT" = 1 ] && exit 1
fi
exit 0
