#!/usr/bin/env bash
# ============================================================================
#  create-instance.sh — Alta de una instancia nueva a partir de un clon del
#  framework. Automatiza las secciones 2–7 de docs/CREATE_INSTANCE.md (ficheros
#  de entorno, puertos, secretos, marca, plugin propio y override de Docker) para
#  no hacerlas a mano. La guía sigue siendo la referencia de cada paso; este
#  script solo la ejecuta en orden.
#
#  Se ejecuta en la RAÍZ de un clon recién hecho del framework (§1):
#
#    bash tools/create-instance.sh <slug> [--name "Marca"] [--frontend-port N]
#        [--wordpress-port N] [--db-port N] [--redis-port N] [--plugin] [--up] [--dry-run]
#
#  Sin --up solo escribe ficheros y nunca pisa lo que ya existe:
#    · .env, frontend/.env.local y backend/scripts/instance.config.json se crean
#      desde su example; si ya existen se dejan como están y se dice.
#    · Los secretos los genera backend/scripts/generate-secrets.sh, que solo rellena
#      placeholders: reejecutar el alta no rota ninguno. HWE_SECRETS_KEY y
#      HWE_2FA_KEY se añaden solo si faltan.
#    · --plugin crea backend/wp-content/plugins/hwe-instance-<slug>/ y
#      docker-compose.override.yml (§7).
#
#  Con --up ejecuta además los pasos de §5–§6 (pila, WordPress y WooCommerce,
#  claves de WooCommerce escritas con tu usuario, plugin propio si --plugin y
#  frontend) y, al final, tools/doctor.mjs contra la pila. Necesita docker en el PATH.
#
#  Por qué el slug es tan estricto: va a los nombres de contenedor, de proyecto de
#  Compose y de plugin. Una mayúscula, un espacio o un símbolo romperían uno de
#  ellos a mitad del alta, cuando ya hay ficheros escritos.
#
#  Por qué los puertos se propagan a frontend/.env.local: en modo host (npm run dev)
#  el frontend habla con localhost:<puerto>. Si esas URL no coinciden con el puerto
#  real, los enlaces de recuperación y las llamadas del BFF fallan sin un mensaje claro.
# ============================================================================
set -euo pipefail

die() { echo "ERROR: $*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }

usage() {
	cat <<'EOF'
Uso: bash tools/create-instance.sh <slug> [opciones]

  <slug>              Identificador de la instancia: minúsculas, cifras y guiones,
                      empieza por letra o cifra (máx. 40 caracteres).
  --name "Marca"      Nombre de marca para instance.config.json (por defecto, el slug).
  --frontend-port N   Puerto del frontend en el host (FRONTEND_PORT).
  --wordpress-port N  Puerto de WordPress en el host (WORDPRESS_PORT).
  --db-port N         Puerto de MariaDB en el host (DB_PORT).
  --redis-port N      Puerto de Redis en el host (REDIS_PORT).
  --plugin            Crea backend/wp-content/plugins/hwe-instance-<slug>/ y
                      docker-compose.override.yml.
  --up                Además levanta la pila (docs/CREATE_INSTANCE.md §5–§6) y ejecuta
                      tools/doctor.mjs. Necesita docker.
  --dry-run           Imprime lo que haría sin escribir nada.
EOF
}

SLUG=""
NAME=""
ARG_FRONTEND=""
ARG_WORDPRESS=""
ARG_DB=""
ARG_REDIS=""
WITH_PLUGIN=0
UP=0
DRY_RUN=0

while [ "$#" -gt 0 ]; do
	case "$1" in
		--name)
			[ "$#" -ge 2 ] || die "--name necesita un valor"
			NAME="$2"; shift 2 ;;
		--frontend-port)
			[ "$#" -ge 2 ] || die "--frontend-port necesita un valor"
			ARG_FRONTEND="$2"; shift 2 ;;
		--wordpress-port)
			[ "$#" -ge 2 ] || die "--wordpress-port necesita un valor"
			ARG_WORDPRESS="$2"; shift 2 ;;
		--db-port)
			[ "$#" -ge 2 ] || die "--db-port necesita un valor"
			ARG_DB="$2"; shift 2 ;;
		--redis-port)
			[ "$#" -ge 2 ] || die "--redis-port necesita un valor"
			ARG_REDIS="$2"; shift 2 ;;
		--plugin) WITH_PLUGIN=1; shift ;;
		--up) UP=1; shift ;;
		--dry-run) DRY_RUN=1; shift ;;
		-h|--help) usage; exit 0 ;;
		-*) usage >&2; die "opción desconocida: $1" ;;
		*)
			[ -z "$SLUG" ] || die "solo se admite un slug (sobra «$1»)"
			SLUG="$1"; shift ;;
	esac
done

[ -n "$SLUG" ] || { usage >&2; die "falta el slug"; }
if ! [[ "$SLUG" =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]]; then
	die "slug inválido «$SLUG»: usa minúsculas, cifras y guiones, y empieza por letra o cifra"
fi
[ -n "$NAME" ] || NAME="$SLUG"
case "$NAME" in
	*$'\n'*) die "--name no admite saltos de línea" ;;
esac

check_port() {
	if [ -z "$2" ]; then return 0; fi
	case "$2" in
		''|*[!0-9]*) die "--$1 debe ser un número de puerto (recibido «$2»)" ;;
	esac
	if [ "$2" -lt 1 ] || [ "$2" -gt 65535 ]; then die "--$1 fuera de rango: $2"; fi
}
check_port frontend-port "$ARG_FRONTEND"
check_port wordpress-port "$ARG_WORDPRESS"
check_port db-port "$ARG_DB"
check_port redis-port "$ARG_REDIS"

# Antes de escribir nada: sin docker, --up no puede terminar y dejaría una mitad.
if [ "$UP" = 1 ] && [ "$DRY_RUN" = 0 ] && ! command -v docker >/dev/null 2>&1; then
	die "--up necesita docker en el PATH y no lo encuentro. Instálalo, o quita --up y levanta la pila a mano (docs/CREATE_INSTANCE.md §5–§6)"
fi
if [ "$DRY_RUN" = 0 ] && ! command -v node >/dev/null 2>&1; then
	die "node es necesario para escribir instance.config.json (Node ≥ 20)"
fi

for f in .env.example frontend/.env.example backend/scripts/generate-secrets.sh \
	backend/scripts/instance.config.example.json docker-compose.override.example.yml; do
	[ -f "$f" ] || die "no encuentro $f: ejecuta el script en la raíz de un clon del framework (docs/CREATE_INSTANCE.md §1)"
done
# Valor por defecto de un puerto: el del example, para que las URL del frontend
# y de WordPress queden coherentes aunque no se pase el flag.
example_value() {
	awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' .env.example
}
env_value() {
	[ -f "$1" ] || return 0
	awk -F= -v k="$2" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$1"
}
PORT_FRONTEND="${ARG_FRONTEND:-$(example_value FRONTEND_PORT)}"
PORT_WORDPRESS="${ARG_WORDPRESS:-$(example_value WORDPRESS_PORT)}"
PORT_DB="${ARG_DB:-$(example_value DB_PORT)}"
PORT_REDIS="${ARG_REDIS:-$(example_value REDIS_PORT)}"

# Crea DST desde SRC si no existe. Devuelve 0 cuando hay que aplicar cambios al
# fichero (recién creado, o lo crearía en --dry-run) y 1 si ya existía.
create_from() {
	local src="$1" dst="$2"
	if [ -e "$dst" ]; then
		say "    (ya existe) $dst: no lo toco"
		return 1
	fi
	if [ "$DRY_RUN" = 1 ]; then
		say "    [dry-run] crearía $dst desde $src"
	else
		cp "$src" "$dst"
		say "    ✓ $dst creado desde $src"
	fi
	return 0
}

# Pone KEY=valor en un .env: sustituye la primera línea KEY= (activa o comentada)
# o la añade al final. Escribe a un temporal y reemplaza, así un fallo a medias
# no deja el fichero truncado. Nunca imprime el valor (puede ser un secreto).
set_env() {
	local file="$1" key="$2" value="$3"
	if [ "$DRY_RUN" = 1 ]; then
		say "    [dry-run] $file: $key=<valor>"
		return 0
	fi
	local tmp
	tmp="$(mktemp "$file.XXXXXX")"
	awk -v k="$key" -v v="$value" '
		BEGIN { done = 0; re = "^#?[ \t]*" k "=" }
		$0 ~ re { if (!done) { print k "=" v; done = 1 } else print; next }
		{ print }
		END { if (!done) print k "=" v }
	' "$file" > "$tmp"
	mv "$tmp" "$file"
}

# Añade un secreto a .env solo si la clave no tiene valor todavía.
ensure_env_key() {
	local file="$1" key="$2"
	if [ -n "$(env_value "$file" "$key")" ]; then
		say "    (omitido) $key ya tiene valor en $file"
		return 0
	fi
	if [ "$DRY_RUN" = 1 ]; then
		say "    [dry-run] generaría $key en $file (openssl rand -base64 48)"
		return 0
	fi
	set_env "$file" "$key" "$(openssl rand -base64 48 | tr -d '\n')"
	say "    ✓ $key generado en $file"
}

# HWE_REVALIDATION_SECRET lo genera generate-secrets.sh solo en la raíz. En modo
# host, el BFF lee frontend/.env.local: si allí queda el placeholder, no habla con
# WordPress. Se copia el valor de la raíz cuando el del frontend es un placeholder.
sync_revalidation_secret() {
	local root_value front_value
	root_value="$(env_value .env HWE_REVALIDATION_SECRET)"
	front_value="$(env_value frontend/.env.local HWE_REVALIDATION_SECRET)"
	if [ -z "$root_value" ]; then
		say "    (aviso) .env no tiene HWE_REVALIDATION_SECRET: revísalo a mano"
		return 0
	fi
	case "$front_value" in
		""|changeme-*)
			set_env frontend/.env.local HWE_REVALIDATION_SECRET "$root_value"
			say "    ✓ HWE_REVALIDATION_SECRET copiado a frontend/.env.local" ;;
		*)
			if [ "$front_value" != "$root_value" ]; then
				say "    (aviso) frontend/.env.local tiene otro HWE_REVALIDATION_SECRET que .env: no coincidirán con WordPress"
			else
				say "    (omitido) HWE_REVALIDATION_SECRET ya coincide en .env y frontend/.env.local"
			fi ;;
	esac
}

write_plugin_files() {
	local dir="$1"
	cat > "$dir/hwe-instance-$SLUG.php" <<EOF
<?php
/**
 * Plugin Name:  HWE Instancia ($SLUG)
 * Description:  Código propio de la instancia «$SLUG». El framework no lo versiona ni lo toca.
 *               Enganches al núcleo y reglas: docs/PLUGIN_DEVELOPMENT.md.
 * Version:      0.1.0
 * Requires PHP: 8.4
 * Text Domain:  hwe-instance-$SLUG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Engánchate al núcleo solo por hooks estables (docs/PLUGIN_DEVELOPMENT.md §0).
EOF
	cat > "$dir/README.md" <<EOF
# hwe-instance-$SLUG

Plugin propio de la instancia «$SLUG». Vive en el repositorio de la instancia, fuera del
núcleo del framework, y se monta en desarrollo con \`docker-compose.override.yml\`
(docs/CREATE_INSTANCE.md §7).

- Enganches permitidos y reglas del contrato: \`docs/PLUGIN_DEVELOPMENT.md\`.
- Para que \`wp hwe setup\` lo active junto con los módulos, regístralo en el filtro
  \`hwe_modules_catalog\` y añade su id a \`modules\` de \`backend/scripts/instance.config.json\`.
EOF
}

# ---------------------------------------------------------------------------
step "Alta de la instancia «$SLUG» (nombre de marca: «$NAME»)"
say "    Puertos: frontend $PORT_FRONTEND, WordPress $PORT_WORDPRESS, DB $PORT_DB, Redis $PORT_REDIS."
[ "$DRY_RUN" = 1 ] && say "    (dry-run: no se escribe nada)"

step "Variables de entorno (.env y frontend/.env.local)"
if create_from .env.example .env; then
	set_env .env HWE_CONTAINER_PREFIX "$SLUG"
	set_env .env COMPOSE_PROJECT_NAME "$SLUG"
	[ -z "$ARG_FRONTEND" ] || set_env .env FRONTEND_PORT "$ARG_FRONTEND"
	[ -z "$ARG_WORDPRESS" ] || set_env .env WORDPRESS_PORT "$ARG_WORDPRESS"
	[ -z "$ARG_DB" ] || set_env .env DB_PORT "$ARG_DB"
	[ -z "$ARG_REDIS" ] || set_env .env REDIS_PORT "$ARG_REDIS"
	set_env .env WORDPRESS_PUBLIC_URL "http://localhost:$PORT_WORDPRESS"
	set_env .env NEXT_PUBLIC_PAYMENT_RETURN_URL "http://localhost:$PORT_FRONTEND/checkout/return"
fi
if create_from frontend/.env.example frontend/.env.local; then
	set_env frontend/.env.local NEXT_PUBLIC_SITE_URL "http://localhost:$PORT_FRONTEND"
	set_env frontend/.env.local ALLOWED_ORIGIN "http://localhost:$PORT_FRONTEND"
	set_env frontend/.env.local NEXT_PUBLIC_PAYMENT_RETURN_URL "http://localhost:$PORT_FRONTEND/checkout/return"
	set_env frontend/.env.local WORDPRESS_INTERNAL_API_URL "http://localhost:$PORT_WORDPRESS/graphql"
	set_env frontend/.env.local WORDPRESS_PUBLIC_URL "http://localhost:$PORT_WORDPRESS"
	set_env frontend/.env.local WC_API_URL "http://localhost:$PORT_WORDPRESS/wp-json/wc/v3"
	set_env frontend/.env.local WC_STORE_API_URL "http://localhost:$PORT_WORDPRESS/wp-json/wc/store/v1"
	set_env frontend/.env.local REDIS_URL "redis://localhost:$PORT_REDIS"
fi

step "Secretos (generate-secrets.sh, HWE_SECRETS_KEY, HWE_2FA_KEY)"
if [ "$DRY_RUN" = 1 ]; then
	say "    [dry-run] ejecutaría: sh backend/scripts/generate-secrets.sh"
	ensure_env_key .env HWE_SECRETS_KEY
	ensure_env_key .env HWE_2FA_KEY
	say "    [dry-run] copiaría HWE_REVALIDATION_SECRET de .env a frontend/.env.local si allí es un placeholder"
else
	sh backend/scripts/generate-secrets.sh
	ensure_env_key .env HWE_SECRETS_KEY
	ensure_env_key .env HWE_2FA_KEY
	sync_revalidation_secret
fi

step "Configuración de marca (backend/scripts/instance.config.json)"
CONFIG=backend/scripts/instance.config.json
BRAND_URL="http://localhost:$PORT_FRONTEND"
if [ -e "$CONFIG" ]; then
	say "    (ya existe) $CONFIG: no lo toco"
elif [ "$DRY_RUN" = 1 ]; then
	say "    [dry-run] crearía $CONFIG con brand.name = «$NAME» y brand.url = $BRAND_URL"
else
	# flag «wx»: falla si el fichero aparece entre medias, en vez de pisarlo.
	node -e '
const fs = require("node:fs");
const [src, dst, name, url] = process.argv.slice(1);
const cfg = JSON.parse(fs.readFileSync(src, "utf8"));
cfg.brand = { ...cfg.brand, name, url };
fs.writeFileSync(dst, JSON.stringify(cfg, null, 2) + "\n", { flag: "wx" });
' backend/scripts/instance.config.example.json "$CONFIG" "$NAME" "$BRAND_URL"
	say "    ✓ $CONFIG creado (brand.name = «$NAME», brand.url = $BRAND_URL)"
fi

if [ "$WITH_PLUGIN" = 1 ]; then
	step "Plugin propio y override de Docker (docs/CREATE_INSTANCE.md §7)"
	PLUGIN_DIR="backend/wp-content/plugins/hwe-instance-$SLUG"
	if [ -e "$PLUGIN_DIR" ]; then
		say "    (ya existe) $PLUGIN_DIR: no lo toco"
	elif [ "$DRY_RUN" = 1 ]; then
		say "    [dry-run] crearía $PLUGIN_DIR/ con hwe-instance-$SLUG.php y README.md"
	else
		mkdir -p "$PLUGIN_DIR"
		write_plugin_files "$PLUGIN_DIR"
		say "    ✓ $PLUGIN_DIR creado"
	fi
	if [ -e docker-compose.override.yml ]; then
		say "    (ya existe) docker-compose.override.yml: no lo toco"
	elif [ "$DRY_RUN" = 1 ]; then
		say "    [dry-run] crearía docker-compose.override.yml con el slug $SLUG"
	else
		sed "s/hwe-instance-example/hwe-instance-$SLUG/g" docker-compose.override.example.yml > docker-compose.override.yml
		say "    ✓ docker-compose.override.yml creado"
	fi
fi

DOCTOR_RC=0
if [ "$UP" = 1 ]; then
	if [ "$DRY_RUN" = 1 ]; then
		step "Con --up (dry-run) se ejecutaría:"
		say "    docker compose up -d db redis"
		say "    docker compose run --rm wpcli"
		say "    docker compose run --rm --user \"\$(id -u):\$(id -g)\" --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh   (si falta WC_CONSUMER_KEY)"
		[ "$WITH_PLUGIN" = 0 ] || say "    docker compose run --rm --entrypoint wp wpcli --path=/var/www/html plugin activate hwe-instance-$SLUG"
		say "    docker compose up -d"
		say "    node tools/doctor.mjs --frontend http://localhost:$PORT_FRONTEND --wordpress http://localhost:$PORT_WORDPRESS"
	else
		step "Pila: db, redis y WordPress con WooCommerce (§5)"
		docker compose up -d db redis
		docker compose run --rm wpcli

		step "Claves de WooCommerce para el BFF (§6)"
		if [ -n "$(env_value .env WC_CONSUMER_KEY)" ]; then
			say "    (omitido) WC_CONSUMER_KEY ya está en .env: no se generan claves nuevas"
		else
			# --user: el script escribe .env como tu usuario, no como www-data.
			docker compose run --rm --user "$(id -u):$(id -g)" --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
		fi

		if [ "$WITH_PLUGIN" = 1 ]; then
			step "Activando hwe-instance-$SLUG"
			docker compose run --rm --entrypoint wp wpcli --path=/var/www/html plugin activate "hwe-instance-$SLUG"
		fi

		step "Frontend y resto de servicios"
		docker compose up -d

		step "Comprobación de la pila con tools/doctor.mjs"
		if [ -f tools/doctor.mjs ]; then
			DOCTOR_RC=0
			node tools/doctor.mjs --frontend "http://localhost:$PORT_FRONTEND" --wordpress "http://localhost:$PORT_WORDPRESS" || DOCTOR_RC=$?
		else
			say "    (aviso) no encuentro tools/doctor.mjs: comprueba la pila a mano (docs/UPGRADE.md §3, paso 6)"
		fi
	fi
fi

cat <<EOF

Pasos que quedan a mano (este script no los automatiza):
  1. Marca: revisa brand.* y design.* en backend/scripts/instance.config.json. brand.url
     queda en http://localhost:$PORT_FRONTEND; en producción pon el dominio público.
  2. Repositorio: si clonaste con --origin framework, origin debe pasar a ser el de la
     instancia (docs/CREATE_INSTANCE.md §1).
  3. .gitignore: quita la línea de mu-plugins/instance/* y descomenta las de
     plugins/hwe-instance-* si versionas aquí el código de la instancia (§7).
  4. CI: variable de repositorio HWE_INSTANCE = true (docs/CREATE_INSTANCE.md, «CI de la instancia»).
  5. Código propio: frontend/src/instance/ y backend/wp-content/mu-plugins/instance/ (§7).
EOF

if [ "$UP" = 1 ] && [ "$DOCTOR_RC" -ne 0 ]; then
	say "tools/doctor.mjs reporta fallos (código $DOCTOR_RC). Si el frontend aún arranca, espera y vuelve a ejecutarlo."
fi
exit "$DOCTOR_RC"
