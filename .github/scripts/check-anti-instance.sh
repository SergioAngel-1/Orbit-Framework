#!/usr/bin/env bash
# Puerta anti-instancia: el núcleo no conoce a ninguna instancia concreta.
#
# Falla si en las RUTAS DE NÚCLEO aparece cualquier resto de una instancia
# (nombres, slugs, dominios o supuestos de negocio). El patrón cubre las instancias
# conocidas; al portar una nueva, añade aquí sus tokens antes de empezar (spec §2).
#
# Rutas de núcleo (spec §2): `backend/` salvo las rutas de instancia, `frontend/src/lib/**`,
# `frontend/src/app/api/**`, `frontend/src/proxy.ts` y `frontend/src/components/ui/**`.
# NO se escanea: `docs/` (documenta el cruce con nombres reales), `examples/` (código de
# instancia a propósito), ni dependencias de terceros (`vendor/`, `node_modules/`, plugins
# y temas que no son `hwe-*`).
#
# OJO al escribir en el núcleo: el patrón también caza palabras comunes del español
# ("esenciales" contiene "esencia"). Usa "necesario", "imprescindible", etc.
set -euo pipefail

PATTERN='siu|sheilak|mascota|essence|esencia|dormilab|atlas'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

mapfile -t PATHS < <(
  cd "$ROOT" &&
    find backend/wp-content/mu-plugins -type f -not -path '*/instance/*' &&
    find backend/wp-content/plugins/hwe-banners backend/wp-content/themes/hwe-headless-base -type f 2>/dev/null &&
    find backend/scripts backend/tests -type f -not -path '*/vendor/*' &&
    find frontend/src/lib frontend/src/app/api frontend/src/components/ui -type f -not -path '*/node_modules/*' &&
    printf '%s\n' frontend/src/proxy.ts
)

HITS="$(grep -rliE "$PATTERN" "${PATHS[@]}" || true)"

if [ -n "$HITS" ]; then
  echo "Puerta anti-instancia: restos de instancia en rutas de núcleo:" >&2
  echo "$HITS" >&2
  echo "--" >&2
  echo "$HITS" | while IFS= read -r f; do grep -niE "$PATTERN" "$ROOT/$f" | head -n 5; done >&2
  exit 1
fi

echo "Puerta anti-instancia: 0 coincidencias en rutas de núcleo."
