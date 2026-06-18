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
# ============================================================================

set -e

WP="wp --path=/var/www/html --allow-root"
# SHA256 checksums para verificar integridad de plugins descargados de GitHub
JWT_AUTH_SHA256="37e7bcc573fdd74a6cf60475b01285e62e62fa9bd5684c1f6a365a6e2bce47d8"
WOOGRAPHQL_SHA256="15e9b320114349cc5213f7245d93985d45def4bbe27d9a1850a3a49c7f4f9e58"

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
echo "==> Instalando WPGraphQL (desde el repositorio oficial de WordPress.org)..."
$WP plugin install wp-graphql --activate

# --- WPGraphQL JWT Authentication — v0.7.2 (release fijado) ---
echo "==> Instalando WPGraphQL JWT Authentication v0.7.2 (desde GitHub)..."
JWT_URL="https://github.com/wp-graphql/wp-graphql-jwt-authentication/releases/download/v0.7.2/wp-graphql-jwt-authentication.zip"
JWT_TMP="/tmp/jwt-auth.zip"
if command -v curl >/dev/null 2>&1; then
	curl -sL -o "$JWT_TMP" "$JWT_URL"
	COMPUTED=$(sha256sum "$JWT_TMP" | cut -d' ' -f1)
	if [ "$COMPUTED" = "$JWT_AUTH_SHA256" ]; then
		$WP plugin install "$JWT_TMP" --activate
	else
		echo "    ⚠️  Checksum SHA256 de JWT Auth no coincide (esperado $JWT_AUTH_SHA256, obtenido $COMPUTED). Usando URL directa."
		$WP plugin install "$JWT_URL" --activate
	fi
	rm -f "$JWT_TMP"
else
	$WP plugin install "$JWT_URL" --activate
fi

# --- WPGraphQL CORS — v2.1.1 (release fijado, no tiene .zip en assets) ---
echo "==> Instalando WPGraphQL CORS v2.1.1 (desde GitHub)..."
$WP plugin install \
	"https://github.com/funkhaus/wp-graphql-cors/zipball/2.1.1" \
	--activate || echo "    (aviso) wp-graphql-cors opcional no instalado; el mu-plugin ya cubre CORS."

# --- WooCommerce — desde wp.org (siempre la última estable) ---
echo "==> Instalando WooCommerce (catálogo, carrito, pedidos)..."
$WP plugin install woocommerce --activate
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json 2>/dev/null || true
$WP option update woocommerce_default_country "ES" 2>/dev/null || true
$WP option update woocommerce_currency "EUR" 2>/dev/null || true

# --- WPGraphQL WooCommerce (WooGraphQL) — v1.0.2 (release fijado) ---
echo "==> Instalando WPGraphQL WooCommerce (WooGraphQL) v1.0.2..."
# Primero intentamos instalarlo desde wp.org (si la versión estable coincide)
$WP plugin install wp-graphql-woocommerce --activate 2>/dev/null && \
	echo "    (WooGraphQL instalado desde wp.org)" || {
	echo "    (WooGraphQL no disponible en wp.org; descargando release fijado v1.0.2)..."
	WOO_URL="https://github.com/wp-graphql/wp-graphql-woocommerce/releases/download/v1.0.2/wp-graphql-woocommerce.zip"
	WOO_TMP="/tmp/woo-graphql.zip"
	if command -v curl >/dev/null 2>&1; then
		curl -sL -o "$WOO_TMP" "$WOO_URL"
		COMPUTED=$(sha256sum "$WOO_TMP" | cut -d' ' -f1)
		if [ "$COMPUTED" = "$WOOGRAPHQL_SHA256" ]; then
			$WP plugin install "$WOO_TMP" --activate
		else
			echo "    ⚠️  Checksum SHA256 de WooGraphQL no coincide. Usando URL directa."
			$WP plugin install "$WOO_URL" --activate
		fi
		rm -f "$WOO_TMP"
	else
		$WP plugin install "$WOO_URL" --activate
	fi
} || echo "    (aviso) WooGraphQL no instalado; instálalo manualmente."

# ----------------------------------------------------------------------------
# 4) Redis Object Cache (acelera el acceso a la BD de WordPress)
# ----------------------------------------------------------------------------
echo "==> Instalando Redis Object Cache..."
$WP plugin install redis-cache --activate
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
$WP theme activate twentytwentyfour 2>/dev/null || true
$WP theme delete twentytwentythree twentytwentytwo 2>/dev/null || true

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

# Crear zona de envío: España peninsular con tarifa plana
$WP wc shipping_zone create \
	--name="España peninsular" \
	--porcelain 2>/dev/null || true

# ----------------------------------------------------------------------------
# 7) Contenido de ejemplo (garantiza que la home del frontend tenga posts)
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

# ----------------------------------------------------------------------------
# 7b) Categorías de producto de ejemplo
# ----------------------------------------------------------------------------
CAT_COUNT=$($WP wc product_cat list --per_page=1 --format=count 2>/dev/null || echo 0)
if [ "${CAT_COUNT}" -le 1 ]; then
	echo "==> Creando categorías de producto de ejemplo..."
	$WP wc product_cat create --name="Ropa" --description="Ropa y accesorios" --porcelain 2>/dev/null || true
	$WP wc product_cat create --name="Electrónica" --description="Dispositivos y gadgets" --porcelain 2>/dev/null || true
	$WP wc product_cat create --name="Hogar" --description="Artículos para el hogar" --porcelain 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
# 7c) Productos de ejemplo (simples + variables, con categorías)
# ----------------------------------------------------------------------------
PRODUCT_COUNT=$($WP post list --post_type=product --post_status=publish --format=count 2>/dev/null || echo 0)
if [ "${PRODUCT_COUNT}" -lt 5 ]; then
	echo "==> Generando productos de ejemplo..."

	# Obtener IDs de categorías
	CAT_ROPA=$($WP wc product_cat list --search="Ropa" --field=id --porcelain 2>/dev/null | head -1)
	CAT_ELECTRONICA=$($WP wc product_cat list --search="Electrónica" --field=id --porcelain 2>/dev/null | head -1)
	CAT_HOGAR=$($WP wc product_cat list --search="Hogar" --field=id --porcelain 2>/dev/null | head -1)

	# Producto simple 1: Camiseta
	$WP wc product create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Camiseta básica" \
		--type=simple \
		--regular_price="29.95" \
		--manage_stock=true \
		--stock_quantity=50 \
		--stock_status=instock \
		--status=publish \
		--description="Camiseta de algodón orgánico, cómoda y duradera. Ideal para el día a día." \
		--short_description="Camiseta de algodón orgánico" \
		--categories="[{\"id\":${CAT_ROPA:-1}}]" \
		--porcelain >/dev/null 2>&1 || \
		echo "    (aviso) no se pudo crear producto Camiseta."

	# Producto simple 2: Auriculares
	$WP wc product create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Auriculares inalámbricos" \
		--type=simple \
		--regular_price="79.99" \
		--sale_price="59.99" \
		--manage_stock=true \
		--stock_quantity=25 \
		--stock_status=instock \
		--status=publish \
		--description="Auriculares Bluetooth con cancelación de ruido activa. 20h de autonomía." \
		--short_description="Bluetooth, cancelación de ruido" \
		--categories="[{\"id\":${CAT_ELECTRONICA:-1}}]" \
		--porcelain >/dev/null 2>&1 || \
		echo "    (aviso) no se pudo crear producto Auriculares."

	# Producto simple 3: Lámpara
	$WP wc product create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Lámpara de escritorio LED" \
		--type=simple \
		--regular_price="45.00" \
		--manage_stock=true \
		--stock_quantity=30 \
		--stock_status=instock \
		--status=publish \
		--description="Lámpara LED regulable con temperatura de color ajustable y puerto USB de carga." \
		--short_description="LED regulable con puerto USB" \
		--categories="[{\"id\":${CAT_HOGAR:-1}}]" \
		--porcelain >/dev/null 2>&1 || \
		echo "    (aviso) no se pudo crear producto Lámpara."

	# --- Producto variable: Sudaderas (tallas S/M/L/XL) ---
	echo "    Creando producto variable: Sudadera..."
	PARENT_ID=$($WP wc product create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Sudadera con capucha" \
		--type=variable \
		--manage_stock=false \
		--status=publish \
		--description="Sudadera unisex con capucha y bolsillo canguro. Disponible en varias tallas." \
		--short_description="Unisex con capucha, varios colores" \
		--categories="[{\"id\":${CAT_ROPA:-1}}]" \
		--porcelain 2>/dev/null || echo "")

		if [ -n "$PARENT_ID" ] && [ "$PARENT_ID" -gt 0 ] 2>/dev/null; then
			# Crear atributo "Talla"
			ATTR_ID=$($WP wc product_attribute create \
				--name="Talla" \
				--slug="talla" \
				--type="select" \
				--order_by="menu_order" \
				--porcelain 2>/dev/null || echo "")

			if [ -n "$ATTR_ID" ] && [ "$ATTR_ID" -gt 0 ] 2>/dev/null; then
				# Añadir términos del atributo
				for talla in S M L XL; do
					TERM_ID=$($WP wc product_attribute term create \
						--attribute_id="$ATTR_ID" \
						--name="$talla" \
						--porcelain 2>/dev/null || echo "")
				done

				# Asociar atributo al producto
				$WP wc product update "$PARENT_ID" \
					--attributes="[{\"id\":${ATTR_ID},\"name\":\"Talla\",\"position\":0,\"visible\":true,\"variation\":true,\"options\":[\"S\",\"M\",\"L\",\"XL\"]}]" \
					--porcelain 2>/dev/null || true

				# Crear variaciones
				for talla in S M L XL; do
					PRECIO="49.95"
					[ "$talla" = "XL" ] && PRECIO="54.95"
					$WP wc product variation create \
						"$PARENT_ID" \
						--sku="HOOD-${talla}" \
						--regular_price="$PRECIO" \
						--manage_stock=true \
						--stock_quantity=20 \
						--attributes="[{\"id\":${ATTR_ID},\"option\":\"${talla}\"}]" \
						--porcelain 2>/dev/null || true
				done
			fi
		else
			echo "    (aviso) no se pudo crear producto variable Sudadera."
		fi

	# Producto simple adicional: Mochila
	$WP wc product create \
		--user="${WP_ADMIN_USER:-admin}" \
		--name="Mochila impermeable 30L" \
		--type=simple \
		--regular_price="65.00" \
		--manage_stock=true \
		--stock_quantity=15 \
		--stock_status=instock \
		--status=publish \
		--description="Mochila impermeable con compartimento para portátil de hasta 15.6\", bolsillo acolchado y correas ajustables." \
		--short_description="Impermeable, 30L, compartimento laptop" \
		--categories="[{\"id\":${CAT_ROPA:-1}}]" \
		--porcelain >/dev/null 2>&1 || \
		echo "    (aviso) no se pudo crear producto Mochila."
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
