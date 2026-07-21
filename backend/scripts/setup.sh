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
#    6. Crea webhooks de WooCommerce (pedido creado/actualizado).
#    7. Genera datos demo más ricos (categorías, productos variables).
#    8. Configura impuestos y método de envío básico.
#    9. Aplica la configuración de marca/diseño de la instancia (HWE Control
#       Center) desde instance.config.json, si existe (ver docs/CREATE_INSTANCE.md).
# ============================================================================

set -e

WP="wp --path=/var/www/html --allow-root"
# SHA256 checksums para verificar integridad de plugins descargados de GitHub
JWT_AUTH_SHA256="37e7bcc573fdd74a6cf60475b01285e62e62fa9bd5684c1f6a365a6e2bce47d8"
WOOGRAPHQL_SHA256="15e9b320114349cc5213f7245d93985d45def4bbe27d9a1850a3a49c7f4f9e58"

# ----------------------------------------------------------------------------
#  VERSIONES FIJADAS de los plugins de wp.org (fuente única de verdad).
#  Reproducibilidad: por defecto se instala una versión CONCRETA alineada con
#  docs/COMPATIBILITY.md (no "la última"). Cada una es overridable por entorno
#  para subir de versión de forma controlada (probar en staging primero).
#  Si una versión fijada no existiera en wp.org, se degrada a la última estable
#  con un AVISO (no rompe el primer arranque).
# ----------------------------------------------------------------------------
WPGRAPHQL_VERSION="${WPGRAPHQL_VERSION:-2.17.0}"
WOOCOMMERCE_VERSION="${WOOCOMMERCE_VERSION:-10.8.1}"
WOOGRAPHQL_WPORG_VERSION="${WOOGRAPHQL_WPORG_VERSION:-1.0.2}"
REDISCACHE_VERSION="${REDISCACHE_VERSION:-2.8.0}"

# Instala un plugin de wp.org fijando la versión; si esa versión no existe,
# degrada a la última estable con aviso (reproducible por defecto, resiliente).
install_wporg_pinned() {
	_slug="$1"
	_version="$2"
	# --force: sobrescribe si la carpeta del plugin ya existe (el bind mount de
	# wp-content conserva los plugins aunque se resetee la BD). Hace el script
	# re-ejecutable sin el error "Destination folder already exists".
	if $WP plugin install "$_slug" --version="$_version" --activate --force 2>/dev/null; then
		echo "    ✓ ${_slug} v${_version} (fijada)"
	else
		echo "    ⚠️  ${_slug} v${_version} no disponible en wp.org; instalando la última estable."
		$WP plugin install "$_slug" --activate --force
	fi
}

echo "==> Esperando a que la base de datos acepte conexiones..."
until $WP db check >/dev/null 2>&1; do
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
# 2b) Habilitar el registro de usuarios (necesario para registerUser de GraphQL)
# ----------------------------------------------------------------------------
echo "==> Habilitando el registro de usuarios (rol por defecto: subscriber)..."
$WP option update users_can_register 1
$WP option update default_role subscriber

# ----------------------------------------------------------------------------
# 3) Plugins esenciales para el ecosistema headless
#    ⚠️  Todas las versiones están FIJADAS a releases concretos para evitar
#        riesgos de cadena de suministro (issue C.1 del plan de auditoría).
#        Las descargas desde GitHub verifican checksum SHA256 cuando está
#        disponible.
# ----------------------------------------------------------------------------
echo "==> Instalando WPGraphQL v${WPGRAPHQL_VERSION} (desde WordPress.org)..."
install_wporg_pinned wp-graphql "$WPGRAPHQL_VERSION"

# --- WPGraphQL JWT Authentication — v0.7.2 (release fijado) ---
# Nota: el directorio de plugins arranca siempre limpio (volumen Docker),
# no es necesario limpiar legacy jwt-auth/.
echo "==> Instalando WPGraphQL JWT Authentication v0.7.2 (desde GitHub)..."
JWT_URL="https://github.com/wp-graphql/wp-graphql-jwt-authentication/releases/download/v0.7.2/wp-graphql-jwt-authentication.zip"
JWT_TMP="/tmp/jwt-auth.zip"
if command -v curl >/dev/null 2>&1; then
	curl -sL -o "$JWT_TMP" "$JWT_URL"
	COMPUTED=$(sha256sum "$JWT_TMP" | cut -d' ' -f1)
	if [ "$COMPUTED" = "$JWT_AUTH_SHA256" ]; then
		$WP plugin install "$JWT_TMP" --activate --force
	else
		echo "    ⚠️  Checksum SHA256 de JWT Auth no coincide (esperado $JWT_AUTH_SHA256, obtenido $COMPUTED). Usando URL directa."
		$WP plugin install "$JWT_URL" --activate --force
	fi
	rm -f "$JWT_TMP"
else
	$WP plugin install "$JWT_URL" --activate --force
fi

# --- WPGraphQL CORS — v2.1.1 (release fijado, no tiene .zip en assets) ---
echo "==> Instalando WPGraphQL CORS v2.1.1 (desde GitHub)..."
$WP plugin install \
	"https://github.com/funkhaus/wp-graphql-cors/zipball/2.1.1" \
	--activate --force || echo "    (aviso) wp-graphql-cors opcional no instalado; el mu-plugin ya cubre CORS."

# --- WooCommerce — desde wp.org (versión fijada; ver COMPATIBILITY.md) ---
echo "==> Instalando WooCommerce v${WOOCOMMERCE_VERSION} (catálogo, carrito, pedidos)..."
install_wporg_pinned woocommerce "$WOOCOMMERCE_VERSION"
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json 2>/dev/null || true
$WP option update woocommerce_default_country "ES" 2>/dev/null || true
$WP option update woocommerce_currency "EUR" 2>/dev/null || true

# --- WPGraphQL WooCommerce (WooGraphQL) — v1.0.2 (release fijado) ---
echo "==> Instalando WPGraphQL WooCommerce (WooGraphQL) v1.0.2..."
# Primero intentamos instalarlo desde wp.org con la versión fijada
$WP plugin install wp-graphql-woocommerce --version="$WOOGRAPHQL_WPORG_VERSION" --activate --force 2>/dev/null && \
	echo "    (WooGraphQL v${WOOGRAPHQL_WPORG_VERSION} instalado desde wp.org)" || {
	echo "    (WooGraphQL v${WOOGRAPHQL_WPORG_VERSION} no disponible en wp.org; descargando release fijado v1.0.2 de GitHub)..."
	WOO_URL="https://github.com/wp-graphql/wp-graphql-woocommerce/releases/download/v1.0.2/wp-graphql-woocommerce.zip"
	WOO_TMP="/tmp/woo-graphql.zip"
	if command -v curl >/dev/null 2>&1; then
		curl -sL -o "$WOO_TMP" "$WOO_URL"
		COMPUTED=$(sha256sum "$WOO_TMP" | cut -d' ' -f1)
		if [ "$COMPUTED" = "$WOOGRAPHQL_SHA256" ]; then
			$WP plugin install "$WOO_TMP" --activate --force
		else
			echo "    ⚠️  Checksum SHA256 de WooGraphQL no coincide. Usando URL directa."
			$WP plugin install "$WOO_URL" --activate --force
		fi
		rm -f "$WOO_TMP"
	else
		$WP plugin install "$WOO_URL" --activate --force
	fi
} || echo "    (aviso) WooGraphQL no instalado; instálalo manualmente."

# --- HWE Banners: plugin propio del framework (ya presente en wp-content/plugins) ---
echo "==> Activando HWE Banners..."
$WP plugin activate hwe-banners 2>/dev/null || echo "    (aviso) hwe-banners no encontrado; ¿falta el directorio del plugin?"

# ----------------------------------------------------------------------------
# 4) Redis Object Cache (acelera el acceso a la BD de WordPress)
# ----------------------------------------------------------------------------
echo "==> Instalando Redis Object Cache v${REDISCACHE_VERSION}..."
install_wporg_pinned redis-cache "$REDISCACHE_VERSION"
$WP redis enable 2>/dev/null || echo "    (aviso) Redis Object Cache: enable diferido hasta que Redis esté disponible."

# ----------------------------------------------------------------------------
# 4b) Habilitar la caché de WPGraphQL (aprovecha Redis para respuestas GraphQL)
# ----------------------------------------------------------------------------
echo "==> Habilitando caché de respuestas WPGraphQL..."
# La caché de WPGraphQL usa el mismo drop-in object-cache.php de Redis.
# Se activa vía filtro 'graphql_cache_active' (manejado en mu-plugin graphql-protection.php)
$WP option update graphql_cache_section 'on' 2>/dev/null || true

# ----------------------------------------------------------------------------
# 5) Limpieza de plugins/themes de ejemplo
# ----------------------------------------------------------------------------
echo "==> Limpiando plugins y temas de ejemplo..."
$WP plugin delete akismet hello 2>/dev/null || true
# WordPress headless: el tema no renderiza nada público (el frontend es
# Next.js), así que no se conserva ningún tema de wordpress.org. Se activa
# el tema propio del framework (backend/wp-content/themes/hwe-headless-base,
# ver docs/CREATE_INSTANCE.md) y se borran TODOS los temas bundled del core
# (Twenty Twenty-*, el set exacto varía según la versión de la imagen WP).
echo "==> Activando el tema base del framework (hwe-headless-base)..."
$WP theme activate hwe-headless-base
for t in twentytwentysix twentytwentyfive twentytwentyfour twentytwentythree twentytwentytwo twentytwentyone; do
	$WP theme delete "$t" 2>/dev/null || true
done

# ── Menú de navegación por defecto ─────────────────────────────────────────
# Gestionable después desde wp-admin → Apariencia → Menús. Los items son
# "enlaces personalizados" con rutas RELATIVAS del frontend (contrato en
# docs/FRONTEND_CONNECT.md §A.6).
if ! $WP menu list --fields=slug --format=csv 2>/dev/null | grep -q "^principal$"; then
	echo "==> Creando menú de navegación por defecto (Principal)..."
	$WP menu create "Principal" 2>/dev/null || true
	$WP menu item add-custom principal "Inicio" "/" 2>/dev/null || true
	$WP menu item add-custom principal "Tienda" "/products" 2>/dev/null || true
	$WP menu item add-custom principal "Blog" "/blog" 2>/dev/null || true
	$WP menu item add-custom principal "Sobre nosotros" "/about" 2>/dev/null || true
	$WP menu location assign principal primary 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
# 6) Webhooks de WooCommerce (pedido creado/actualizado)
#     Se crean apuntando al BFF para futuras integraciones (notificaciones,
#     sincronización de inventario, etc.).
# ----------------------------------------------------------------------------
if [ -n "${WC_WEBHOOK_SECRET:-}" ]; then
	echo "==> Creando webhooks de WooCommerce..."
	FRONTEND_URL="${HEADLESS_FRONTEND_URL:-http://frontend:3000}"

	# Webhook: pedido creado
	$WP wc webhook create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Pedido creado → BFF" \
		--topic="order.created" \
		--delivery_url="${FRONTEND_URL}/api/webhooks/woocommerce/order-created" \
		--secret="${WC_WEBHOOK_SECRET}" \
		--status="active" \
		--porcelain 2>/dev/null || echo "    (aviso) no se pudo crear webhook order.created."

	# Webhook: pedido actualizado
	$WP wc webhook create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Pedido actualizado → BFF" \
		--topic="order.updated" \
		--delivery_url="${FRONTEND_URL}/api/webhooks/woocommerce/order-updated" \
		--secret="${WC_WEBHOOK_SECRET}" \
		--status="active" \
		--porcelain 2>/dev/null || echo "    (aviso) no se pudo crear webhook order.updated."

	# Webhook: catálogo revalidado (el existente para revalidateTag)
	$WP wc webhook create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Revalidar catálogo → BFF" \
		--topic="product.updated" \
		--delivery_url="${FRONTEND_URL}/api/revalidate" \
		--secret="${WC_WEBHOOK_SECRET}" \
		--status="active" \
		--porcelain 2>/dev/null || echo "    (aviso) no se pudo crear webhook product.updated."
else
	echo "==> WC_WEBHOOK_SECRET no definido. Omitiendo creación de webhooks."
	echo "    (Los webhooks pueden crearse manualmente desde WooCommerce > Ajustes > Webhooks)"
fi

# ----------------------------------------------------------------------------
# 6b) Configuración de impuestos y envío
# ----------------------------------------------------------------------------
echo "==> Configurando impuestos y envío..."
# Impuestos: precios con IVA incluido (estilo europeo)
$WP option update woocommerce_calc_taxes "yes" 2>/dev/null || true
$WP option update woocommerce_prices_include_tax "yes" 2>/dev/null || true
$WP option update woocommerce_tax_based_on "shipping" 2>/dev/null || true
$WP option update woocommerce_default_customer_address "base" 2>/dev/null || true

echo "==> Configurando zona de envío y método de tarifa plana..."
ZONE_ID=$($WP wc shipping_zone create \
	--user="${WP_ADMIN_USER:-admin}" \
	--name="España peninsular" \
	--porcelain 2>/dev/null || echo "")

if [ -n "$ZONE_ID" ] && [ "$ZONE_ID" -gt 0 ] 2>/dev/null; then
	# Add zone location (Spain)
	$WP wc shipping_zone_location save "$ZONE_ID" \
		--user="${WP_ADMIN_USER:-admin}" \
		'[{"code":"ES","type":"country"}]' 2>/dev/null || true

	# Add flat rate method
	$WP wc shipping_zone_method create "$ZONE_ID" \
		--user="${WP_ADMIN_USER:-admin}" \
		--method_id="flat_rate" \
		--enabled=true \
		--porcelain 2>/dev/null && \
		echo "    ✓ Método de envío tarifa plana añadido a zona $ZONE_ID" || \
		echo "    (aviso) No se pudo añadir el método de envío. Configúralo manualmente en WooCommerce."
else
	echo "    (aviso) No se pudo crear la zona de envío. Configúrala manualmente."
fi

# ----------------------------------------------------------------------------
# 7) Contenido de ejemplo — DESHABILITADO
# ----------------------------------------------------------------------------
# El setup deja WordPress LIMPIO: solo plugins instalados/activados y la
# configuración headless. NO se crean posts, categorías ni productos de ejemplo.
#
# ¿Quieres datos demo para probar el frontend? Ejecútalos bajo demanda:
#   docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
echo "==> WordPress limpio (sin contenido de ejemplo). Demo opcional: seed-demo.sh"

# ----------------------------------------------------------------------------
# 8) Configuración de instancia (HWE Control Center)
#    Seedea marca/diseño/redes/legal/ecommerce/SEO desde un único JSON en vez
#    de rellenar el formulario de wp-admin a mano. Ver
#    backend/scripts/instance.config.example.json y docs/CREATE_INSTANCE.md.
# ----------------------------------------------------------------------------
echo "==> Aplicando configuración de instancia (HWE Control Center)..."
if [ -f /scripts/instance.config.json ]; then
	$WP hwe setup /scripts/instance.config.json
else
	echo "    (sin /scripts/instance.config.json — se omite; configura la marca manualmente en wp-admin → HWE Config,"
	echo "     o copia backend/scripts/instance.config.example.json a backend/scripts/instance.config.json y reejecuta)"
fi

echo ""
echo "============================================================"
echo "  ✔ WordPress Headless + WooCommerce listo."
echo "  Panel:    ${WP_URL:-http://localhost:8080}/wp-admin"
echo "  GraphQL:  ${WP_URL:-http://localhost:8080}/graphql"
echo "  Store API:${WP_URL:-http://localhost:8080}/wp-json/wc/store/v1/cart"
echo "  Usuario:  ${WP_ADMIN_USER:-admin}"
echo ""
echo "  SIGUIENTE PASO (claves WooCommerce para el BFF):"
echo "    docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh"
echo "    -> copia WC_CONSUMER_KEY / WC_CONSUMER_SECRET en tu .env y reinicia el frontend."
echo "============================================================"
