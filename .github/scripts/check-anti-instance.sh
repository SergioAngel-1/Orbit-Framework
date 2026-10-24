#!/usr/bin/env bash
# Puerta anti-instancia: el framework no conoce a ninguna instancia concreta.
#
# Falla si en CUALQUIER archivo versionado aparece un resto de una instancia
# (nombres, slugs, dominios o supuestos de negocio): núcleo, starter (`components/`,
# vistas), tests, scripts, CI y documentación. El patrón cubre las instancias
# conocidas; al portar una nueva, añade aquí sus tokens antes de empezar (spec §2).
#
# NO se escanea:
#   · `examples/`: código de instancia a propósito (el ejemplo vivo del contrato).
#   · `docs/superpowers/`: especificaciones y planes de trabajo, que citan la
#     instancia de origen por necesidad.
#   · este mismo archivo (contiene el patrón) y los lockfiles.
#   · dependencias de terceros: no están versionadas (`vendor/`, `node_modules/`,
#     plugins y temas que no son `hwe-*`).
#
# Se escanea lo versionado más lo nuevo sin ignorar (`git ls-files --cached --others
# --exclude-standard`), así que también caza un archivo recién creado antes del commit.
#
# OJO al escribir: el patrón también caza palabras comunes del español
# («esenciales», «presencia» contienen «esencia»). Usa «imprescindible», «sedes», etc.
set -euo pipefail

PATTERN='siu|sheilak|mascota|essence|esencia|dormilab|atlas|tutuava|doohi'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

mapfile -t PATHS < <(
  git ls-files --cached --others --exclude-standard |
    grep -vE '^(examples/|docs/superpowers/|\.github/scripts/check-anti-instance\.sh$)' |
    grep -vE '(^|/)(package-lock\.json|composer\.lock)$' |
    while IFS= read -r file; do [ -f "$file" ] && printf '%s\n' "$file"; done
)

# Sin archivos que mirar, `grep -r` recorrería el directorio actual entero.
if [ "${#PATHS[@]}" -eq 0 ]; then
  echo "Puerta anti-instancia: 0 coincidencias en el repositorio."
  exit 0
fi

# -I: los binarios (imágenes, fuentes) no se leen como texto.
HITS="$(grep -rIliE "$PATTERN" "${PATHS[@]}" || true)"

if [ -n "$HITS" ]; then
  echo "Puerta anti-instancia: restos de una instancia en el repositorio:" >&2
  echo "$HITS" >&2
  echo "--" >&2
  echo "$HITS" | while IFS= read -r f; do grep -nIiE "$PATTERN" "$ROOT/$f" | head -n 5 | sed "s|^|$f:|"; done >&2
  exit 1
fi

echo "Puerta anti-instancia: 0 coincidencias en el repositorio."
