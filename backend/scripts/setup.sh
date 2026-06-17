#!/bin/sh
# ============================================================================
#  setup.sh — Instalación y configuración automática de WordPress Headless
#  Se ejecuta dentro del contenedor `wpcli`:
#      docker compose run --rm wpcli
#
#  Realiza:
#    1. Espera a que la base de datos y los archivos de WP estén listos.
#    2. Instala WordPress (core) si aún no está instalado.
#    3. Instala y activa los plugins headless: WPGraphQL, JWT Auth y CORS.
#    4. Configura permalinks "pretty" (requisito de WPGraphQL).
#    5. Limpia plugins/themes de ejemplo y deja el sitio listo.
# ============================================================================

set -e

WP="wp --path=/var/www/html --allow-root"

echo "==> Esperando a que la base de datos acepte conexiones..."
until $WP db check >/dev/null 2>&1; do
	# Si el core aún no está, db check fallará; comprobamos conectividad cruda.
	if $WP db query "SELECT 1;" >/dev/null 2>&1; then
		break
	fi
	echo "    ...db no disponible todavía, reintentando en 3s"
	sleep 3
done

# ----------------------------------------------------------------------------
# 1) Instalar el core de WordPress (idempotente)
# ----------------------------------------------------------------------------
if $WP core is-installed >/dev/null 2>&1; then
	echo "==> WordPress ya está instalado. Omitiendo instalación del core."
else
	echo "==> Instalando WordPress core..."
	$WP core install \
		--url="${WP_URL:-http://localhost:8080}" \
		--title="${WP_TITLE:-Headless Web Ecosystem}" \
		--admin_user="${WP_ADMIN_USER:-admin}" \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" \
		--admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
		--skip-email
fi

# ----------------------------------------------------------------------------
# 2) Permalinks "pretty" — WPGraphQL requiere estructura distinta de la simple
# ----------------------------------------------------------------------------
echo "==> Configurando permalinks..."
$WP rewrite structure '/%postname%/' --hard
$WP rewrite flush --hard

# ----------------------------------------------------------------------------
# 3) Plugins esenciales para el ecosistema headless
# ----------------------------------------------------------------------------
echo "==> Instalando WPGraphQL (desde el repositorio oficial de WordPress.org)..."
$WP plugin install wp-graphql --activate

echo "==> Instalando WPGraphQL JWT Authentication (desde GitHub)..."
$WP plugin install \
	"https://github.com/wp-graphql/wp-graphql-jwt-authentication/archive/refs/heads/master.zip" \
	--activate

echo "==> Instalando WPGraphQL CORS (desde GitHub)..."
# Capa de CORS adicional a nivel de plugin. El mu-plugin headless-config.php
# ya aplica CORS; este plugin ofrece además ajustes en el panel de WPGraphQL.
$WP plugin install \
	"https://github.com/funkhaus/wp-graphql-cors/archive/refs/heads/master.zip" \
	--activate || echo "    (aviso) wp-graphql-cors opcional no instalado; el mu-plugin ya cubre CORS."

# ----------------------------------------------------------------------------
# 4) Limpieza de plugins/themes de ejemplo
# ----------------------------------------------------------------------------
echo "==> Limpiando plugins y temas de ejemplo..."
$WP plugin delete akismet hello 2>/dev/null || true
# Mantiene un único tema activo (necesario para que WP arranque) y borra el resto.
$WP theme activate twentytwentyfour 2>/dev/null || true
$WP theme delete twentytwentythree twentytwentytwo 2>/dev/null || true

# ----------------------------------------------------------------------------
# 5) Contenido de ejemplo (garantiza que la home del frontend tenga posts)
# ----------------------------------------------------------------------------
POST_COUNT=$($WP post list --post_type=post --post_status=publish --format=count 2>/dev/null || echo 0)
if [ "${POST_COUNT}" -lt 5 ]; then
	echo "==> Generando posts de ejemplo..."
	i=1
	while [ "$i" -le 5 ]; do
		$WP post create \
			--post_type=post \
			--post_status=publish \
			--post_title="Post de ejemplo #${i}" \
			--post_content="Contenido de demostración generado automáticamente para el post número ${i}. Edítalo o elimínalo desde el panel de WordPress."
		i=$((i + 1))
	done
fi

echo ""
echo "============================================================"
echo "  ✔ WordPress Headless listo."
echo "  Panel:    ${WP_URL:-http://localhost:8080}/wp-admin"
echo "  GraphQL:  ${WP_URL:-http://localhost:8080}/graphql"
echo "  Usuario:  ${WP_ADMIN_USER:-admin}"
echo "============================================================"
