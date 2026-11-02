#!/usr/bin/env bash
# ============================================================================
#  create-instance.test.sh — Prueba de tools/create-instance.sh sin Docker real.
#
#  Copia a un directorio temporal (mktemp -d) los ficheros que el alta necesita,
#  pone un `docker` falso en el PATH que solo registra sus llamadas, y comprueba:
#  valores de .env y frontend/.env.local, idempotencia de los secretos, slug
#  inválido, --dry-run sin escrituras, --plugin, y el aborto de --up sin docker.
#
#  Uso:  bash tools/tests/create-instance.test.sh     (sale con ≠0 si algo falla)
# ============================================================================
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

BASH_BIN="$(command -v bash)"
DOCKER_LOG="$TMP/docker.log"
export DOCKER_LOG
mkdir -p "$TMP/bin"
cat > "$TMP/bin/docker" <<'EOF'
#!/usr/bin/env bash
# Docker falso: registra la llamada y termina bien. No arranca nada.
echo "docker $*" >> "$DOCKER_LOG"
exit 0
EOF
chmod +x "$TMP/bin/docker"
export PATH="$TMP/bin:$PATH"

# Copia lo mínimo que el alta lee o ejecuta. El doctor se copia si ya existe en el repo.
make_framework() {
	local dir="$1"
	mkdir -p "$dir/backend/scripts" "$dir/frontend" "$dir/tools/doctor"
	cp "$REPO/.env.example" "$dir/"
	cp "$REPO/frontend/.env.example" "$dir/frontend/"
	cp "$REPO/backend/scripts/generate-secrets.sh" "$REPO/backend/scripts/instance.config.example.json" "$dir/backend/scripts/"
	cp "$REPO/docker-compose.override.example.yml" "$dir/"
	cp "$REPO/tools/create-instance.sh" "$dir/tools/"
	if [ -f "$REPO/tools/doctor.mjs" ]; then cp "$REPO/tools/doctor.mjs" "$dir/tools/"; fi
	if [ -d "$REPO/tools/doctor" ]; then cp "$REPO/tools/doctor/"*.mjs "$dir/tools/doctor/"; fi
}

PASS=0
FAILS=0
check() {
	local desc="$1"; shift
	if "$@" >/dev/null 2>&1; then
		PASS=$((PASS + 1)); echo "  ok    $desc"
	else
		FAILS=$((FAILS + 1)); echo "  FAIL  $desc"
	fi
}
not() { ! "$@"; }
# Valor de una clave activa (sin comentar) de un .env.
env_get() {
	awk -F= -v k="$2" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$1"
}
# Líneas de los secretos generados: sirven para comparar entre ejecuciones.
secret_lines() {
	grep -E '^(GRAPHQL_JWT_AUTH_SECRET_KEY|CSRF_SECRET|WC_WEBHOOK_SECRET|HWE_REVALIDATION_SECRET|NOOP_INTEGRITY_SECRET|HWE_SECRETS_KEY|HWE_2FA_KEY)=' "$1"
}
secret_is_set() { # fichero clave: no vacío y sin el placeholder de ejemplo
	local v
	v="$(env_get "$1" "$2")"
	[ -n "$v" ] && [ "${v#changeme}" = "$v" ]
}

echo "== Alta completa (pelaz) =="
FW="$TMP/alta"
make_framework "$FW"
cd "$FW"
bash tools/create-instance.sh pelaz --name "Ferretería Pelaz" \
	--frontend-port 3101 --wordpress-port 8101 --db-port 3401 --redis-port 6401 > "$TMP/run1.out"

check ".env: HWE_CONTAINER_PREFIX=pelaz" grep -qxF "HWE_CONTAINER_PREFIX=pelaz" .env
check ".env: COMPOSE_PROJECT_NAME=pelaz (descomentado)" grep -qxF "COMPOSE_PROJECT_NAME=pelaz" .env
check ".env: FRONTEND_PORT=3101" grep -qxF "FRONTEND_PORT=3101" .env
check ".env: WORDPRESS_PORT=8101" grep -qxF "WORDPRESS_PORT=8101" .env
check ".env: DB_PORT=3401" grep -qxF "DB_PORT=3401" .env
check ".env: REDIS_PORT=6401" grep -qxF "REDIS_PORT=6401" .env
check ".env: WORDPRESS_PUBLIC_URL sigue al puerto de WordPress" grep -qxF "WORDPRESS_PUBLIC_URL=http://localhost:8101" .env
check "frontend/.env.local: NEXT_PUBLIC_SITE_URL coherente con el puerto" grep -qxF "NEXT_PUBLIC_SITE_URL=http://localhost:3101" frontend/.env.local
check "frontend/.env.local: ALLOWED_ORIGIN coherente con el puerto" grep -qxF "ALLOWED_ORIGIN=http://localhost:3101" frontend/.env.local
check "frontend/.env.local: API de WordPress en el puerto nuevo" grep -qxF "WORDPRESS_INTERNAL_API_URL=http://localhost:8101/graphql" frontend/.env.local
check "frontend/.env.local: Redis en el puerto nuevo" grep -qxF "REDIS_URL=redis://localhost:6401" frontend/.env.local
check "instance.config.json: brand.name = nombre dado" test "$(node -p 'require("./backend/scripts/instance.config.json").brand.name')" = "Ferretería Pelaz"
check "instance.config.json: brand.url = origen del frontend" test "$(node -p 'require("./backend/scripts/instance.config.json").brand.url')" = "http://localhost:3101"
check "secretos: JWT generado (no placeholder)" secret_is_set .env GRAPHQL_JWT_AUTH_SECRET_KEY
check "secretos: CSRF generado (no placeholder)" secret_is_set .env CSRF_SECRET
check "secretos: HWE_SECRETS_KEY y HWE_2FA_KEY generados" bash -c "[ -n \"\$(awk -F= '\$1==\"HWE_SECRETS_KEY\"{print \$2}' .env)\" ] && [ -n \"\$(awk -F= '\$1==\"HWE_2FA_KEY\"{print \$2}' .env)\" ]"
check "secretos: HWE_REVALIDATION_SECRET del frontend = el de la raíz" test "$(env_get .env HWE_REVALIDATION_SECRET)" = "$(env_get frontend/.env.local HWE_REVALIDATION_SECRET)"
check "salida: no imprime ningún valor secreto" not bash -c "for k in GRAPHQL_JWT_AUTH_SECRET_KEY HWE_SECRETS_KEY HWE_2FA_KEY; do v=\$(awk -F= -v k=\$k '\$1==k{print \$2}' .env); grep -qF -- \"\$v\" '$TMP/run1.out' && exit 0; done; exit 1"
check "sin --up no se llama a docker" not test -s "$DOCKER_LOG"

echo "== Idempotencia =="
cp .env "$TMP/env1"
cp frontend/.env.local "$TMP/frontend-env1"
secret_lines .env > "$TMP/secrets1"
bash tools/create-instance.sh pelaz --name "Ferretería Pelaz" \
	--frontend-port 3101 --wordpress-port 8101 --db-port 3401 --redis-port 6401 > "$TMP/run2.out"
check "segunda ejecución: .env byte a byte igual" cmp -s .env "$TMP/env1"
check "segunda ejecución: frontend/.env.local byte a byte igual" cmp -s frontend/.env.local "$TMP/frontend-env1"
check "segunda ejecución: los secretos no cambian" bash -c "grep -E '^(GRAPHQL_JWT_AUTH_SECRET_KEY|CSRF_SECRET|WC_WEBHOOK_SECRET|HWE_REVALIDATION_SECRET|NOOP_INTEGRITY_SECRET|HWE_SECRETS_KEY|HWE_2FA_KEY)=' .env | cmp -s - '$TMP/secrets1'"
check "segunda ejecución: lo dice (ya existe)" grep -q "ya existe" "$TMP/run2.out"

echo "== Slug inválido =="
for bad in "Mi Tienda" "UPPER" "-x" "con_guion_bajo" "" "a/b"; do
	if bash tools/create-instance.sh "$bad" >/dev/null 2>&1; then
		FAILS=$((FAILS + 1)); echo "  FAIL  slug «$bad» debería rechazarse"
	else
		PASS=$((PASS + 1)); echo "  ok    slug «$bad» rechazado"
	fi
done

echo "== --dry-run no escribe =="
DRY="$TMP/dry"
make_framework "$DRY"
cd "$DRY"
bash tools/create-instance.sh dryco --plugin --up --dry-run > "$TMP/dry.out"
check "dry-run: no crea .env ni frontend/.env.local" bash -c "! test -e .env && ! test -e frontend/.env.local"
check "dry-run: no crea instance.config.json, plugin ni override" bash -c "! test -e backend/scripts/instance.config.json && ! test -e backend/wp-content/plugins/hwe-instance-dryco && ! test -e docker-compose.override.yml"
check "dry-run: no llama a docker aunque pidas --up" not test -s "$DOCKER_LOG"
check "dry-run: lo anuncia" grep -q "dry-run" "$TMP/dry.out"

echo "== --plugin =="
PLG="$TMP/plugin"
make_framework "$PLG"
cd "$PLG"
bash tools/create-instance.sh plug-shop --plugin > "$TMP/plugin.out"
check "plugin: carpeta hwe-instance-plug-shop creada" test -d backend/wp-content/plugins/hwe-instance-plug-shop
check "plugin: cabecera Plugin Name en el fichero principal" grep -q "Plugin Name:" backend/wp-content/plugins/hwe-instance-plug-shop/hwe-instance-plug-shop.php
check "plugin: README remite a PLUGIN_DEVELOPMENT.md" grep -q "docs/PLUGIN_DEVELOPMENT.md" backend/wp-content/plugins/hwe-instance-plug-shop/README.md
check "override: monta solo el plugin de la instancia" grep -qF "./backend/wp-content/plugins/hwe-instance-plug-shop:/var/www/html/wp-content/plugins/hwe-instance-plug-shop" docker-compose.override.yml
check "override: no queda hwe-instance-example" not grep -q "hwe-instance-example" docker-compose.override.yml

PLG2="$TMP/sinplugin"
make_framework "$PLG2"
cd "$PLG2"
bash tools/create-instance.sh sin-plugin >/dev/null
check "sin --plugin: no crea plugin ni override" bash -c "! test -e backend/wp-content/plugins/hwe-instance-sin-plugin && ! test -e docker-compose.override.yml"

echo "== --up =="
NOD="$TMP/nodocker"
make_framework "$NOD"
cd "$NOD"
if env -i PATH=/nonexistent "$BASH_BIN" tools/create-instance.sh sinhub --up > "$TMP/nodocker.out" 2>&1; then
	FAILS=$((FAILS + 1)); echo "  FAIL  --up sin docker debería abortar"
else
	PASS=$((PASS + 1)); echo "  ok    --up sin docker aborta"
fi
check "--up sin docker: avisa de docker y no escribe nada" bash -c "grep -q 'docker' '$TMP/nodocker.out' && ! test -e .env"

UPFW="$TMP/up"
make_framework "$UPFW"
cd "$UPFW"
# El doctor puede fallar aquí (no hay pila): la prueba mira las llamadas a docker, no su salida.
bash tools/create-instance.sh upco --plugin --up --frontend-port 3102 --wordpress-port 8102 > "$TMP/up.out" 2>&1 || true
first_db="$(grep -n 'compose up -d db redis' "$DOCKER_LOG" | head -1 | cut -d: -f1)"
first_wp="$(grep -n 'compose run --rm wpcli$' "$DOCKER_LOG" | head -1 | cut -d: -f1)"
check "--up: db y redis antes que wpcli" test "$first_db" -lt "$first_wp"
check "--up: claves de WooCommerce con --user del host" grep -q "run --rm --user" "$DOCKER_LOG"
check "--up: activa el plugin propio" grep -q "plugin activate hwe-instance-upco" "$DOCKER_LOG"
check "--up: levanta la pila al final" test "$(tail -n 1 "$DOCKER_LOG")" = "docker compose up -d"
check "--up: termina con la comprobación del doctor (o avisa si no está)" grep -q "tools/doctor.mjs" "$TMP/up.out"

echo
echo "Resultado: $PASS ok, $FAILS fallos."
[ "$FAILS" -eq 0 ]
