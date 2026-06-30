#!/bin/sh
# ============================================================================
#  seed-demo.sh — Carga datos demo (productos, categorías, imágenes)
#  Ejecutar dentro del contenedor `wpcli`:
#      docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
#
#  Requisito: WordPress + WooCommerce instalados (vía setup.sh).
# ============================================================================

set -e

WP="wp --path=/var/www/html --allow-root"

echo "==> Verificando WooCommerce..."
if ! $WP plugin is-active woocommerce >/dev/null 2>&1; then
	echo "    WooCommerce no está activo. Ejecuta setup.sh primero."
	exit 1
fi

# ----------------------------------------------------------------------------
# 1) Categorías de producto
# ----------------------------------------------------------------------------
echo "==> Creando categorías de producto..."

_CATS="Electrónica"
_ENS="Electronics"
_ROPA="Ropa y Accesorios"
_ROPA_EN="Clothing & Accessories"
_HOGAR="Hogar"
_HOGAR_EN="Home"

$WP wc product_cat create --name="$_CATS" --description="$_ENS" --porcelain 2>/dev/null || echo "    (skip) $_CATS"
$WP wc product_cat create --name="$_ROPA" --description="$_ROPA_EN" --porcelain 2>/dev/null || echo "    (skip) $_ROPA"
$WP wc product_cat create --name="$_HOGAR" --description="$_HOGAR_EN" --porcelain 2>/dev/null || echo "    (skip) $_HOGAR"

# ----------------------------------------------------------------------------
# 2) Atributos globales (para productos variables)
# ----------------------------------------------------------------------------
echo "==> Creando atributos..."
# Los comandos wc requieren --user para autenticarse en la REST API de WooCommerce
$WP wc product_attribute create --user="${WP_ADMIN_USER:-admin}" --name="Talla" --slug="talla" --type="select" --order_by="menu_order" --porcelain 2>/dev/null || true
$WP wc product_attribute create --user="${WP_ADMIN_USER:-admin}" --name="Size" --slug="size" --type="select" --order_by="menu_order" --porcelain 2>/dev/null || true
$WP wc product_attribute create --user="${WP_ADMIN_USER:-admin}" --name="Color" --slug="color" --type="select" --order_by="menu_order" --porcelain 2>/dev/null || true

# ----------------------------------------------------------------------------
# 3) Productos simples
# ----------------------------------------------------------------------------
echo "==> Creando productos de ejemplo..."

USER="${WP_ADMIN_USER:-admin}"

# Producto 1 — Simple
$WP wc product create \
	--user="$USER" \
	--name="Auriculares Bluetooth Pro" \
	--slug="auriculares-bluetooth-pro" \
	--type=simple \
	--regular_price="89.99" \
	--sale_price="69.99" \
	--manage_stock=true \
	--stock_quantity=25 \
	--weight="0.25" \
	--categories='[{"name":"'"$_CATS"'"}]' \
	--description="Auriculares inalámbricos con cancelación de ruido activa, 30h de batería y códec aptX HD. Ideales para música y llamadas." \
	--short_description="Auriculares Bluetooth con cancelación de ruido" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Auriculares Bluetooth Pro"

# Producto 2 — Simple
$WP wc product create \
	--user="$USER" \
	--name="Camiseta Algodón Premium" \
	--slug="camiseta-algodon-premium" \
	--type=simple \
	--regular_price="29.99" \
	--manage_stock=true \
	--stock_quantity=50 \
	--weight="0.20" \
	--categories='[{"name":"'"$_ROPA"'"}]' \
	--description="Camiseta de algodón orgánico peinado 180g/m². Corte regular, costuras reforzadas y etiqueta removible. Disponible en varios colores." \
	--short_description="Camiseta de algodón orgánico premium" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Camiseta Algodón Premium"

# Producto 3 — Simple (sin rebaja)
$WP wc product create \
	--user="$USER" \
	--name="Lámpara LED Inteligente" \
	--slug="lampara-led-inteligente" \
	--type=simple \
	--regular_price="45.00" \
	--manage_stock=true \
	--stock_quantity=15 \
	--weight="0.40" \
	--categories='[{"name":"'"$_HOGAR"'"}]' \
	--description="Lámpara LED WiFi compatible con Alexa y Google Home. 16 millones de colores, temperatura regulable y programación horaria. Eficiencia energética A++." \
	--short_description="Lámpara LED WiFi con 16M colores" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Lámpara LED Inteligente"

# Producto 4 — Simple
$WP wc product create \
	--user="$USER" \
	--name="Mochila Impermeable 40L" \
	--slug="mochila-impermeable-40l" \
	--type=simple \
	--regular_price="59.99" \
	--manage_stock=true \
	--stock_quantity=30 \
	--weight="0.65" \
	--categories='[{"name":"'"$_ROPA"'"}]' \
	--description="Mochila urbana impermeable con compartimento acolchado para portátil de hasta 15.6\", bolsillo organizador, puerto USB externo y cierre reforzado." \
	--short_description="Mochila urbana 40L con puerto USB" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Mochila Impermeable 40L"

# Producto 5 — Simple (precio alto)
$WP wc product create \
	--user="$USER" \
	--name="Monitor 4K UHD 27\"" \
	--slug="monitor-4k-uhd-27" \
	--type=simple \
	--regular_price="399.99" \
	--manage_stock=true \
	--stock_quantity=10 \
	--weight="4.50" \
	--categories='[{"name":"'"$_CATS"'"}]' \
	--description="Monitor IPS 27\" 4K UHD (3840x2160), 60Hz, HDR10, 99% sRGB. Puertos HDMI 2.0, DisplayPort 1.4 y USB-C con carga 65W. Altavoces integrados." \
	--short_description="Monitor IPS 27\" 4K UHD HDR10" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Monitor 4K UHD 27\""

# Producto 6 — Simple (económico)
$WP wc product create \
	--user="$USER" \
	--name="Taza Cerámica Personalizada" \
	--slug="taza-ceramica-personalizada" \
	--type=simple \
	--regular_price="14.99" \
	--manage_stock=true \
	--stock_quantity=100 \
	--weight="0.35" \
	--categories='[{"name":"'"$_HOGAR"'"}]' \
	--description="Taza de cerámica de alta calidad, 330ml. Apta para microondas y lavavajillas. Acabado brillante y asa ergonómica." \
	--short_description="Taza cerámica 330ml apta microondas" \
	--status=publish \
	--porcelain 2>/dev/null || \
	echo "    (aviso) no se pudo crear Taza Cerámica Personalizada"

# ----------------------------------------------------------------------------
# 4) Producto variable (Tallas S/M/L)
# ----------------------------------------------------------------------------
echo "==> Creando producto variable..."
VARIABLE_ID=$($WP wc product create \
	--user="$USER" \
	--name="Chaqueta Técnica Cortavientos" \
	--slug="chaqueta-tecnica-cortavientos" \
	--type=variable \
	--manage_stock=false \
	--weight="0.50" \
	--categories='[{"name":"'"$_ROPA"'"}]' \
	--description="Chaqueta cortavientos ultraligera con capucha plegable, costuras termoselladas y bolsillo con cremallera. Ideal para exterior y viaje." \
	--short_description="Chaqueta cortavientos ultraligera" \
	--attributes='[{"name":"Talla","slug":"talla","visible":true,"variation":true,"options":["S","M","L"]}]' \
	--status=publish \
	--porcelain 2>/dev/null)

if [ -n "$VARIABLE_ID" ]; then
	echo "    Producto variable creado (ID: $VARIABLE_ID), creando variaciones..."
	for _TALLA in "S" "M" "L"; do
		case "$_TALLA" in
			S) _PRECIO="69.99"; _STOCK=20 ;;
			M) _PRECIO="74.99"; _STOCK=35 ;;
			L) _PRECIO="79.99"; _STOCK=15 ;;
		esac
		$WP wc product variation create \
			--user="$USER" \
			--parent_id="$VARIABLE_ID" \
			--attributes='[{"name":"Talla","option":"'"$_TALLA"'"}]' \
			--regular_price="$_PRECIO" \
			--manage_stock=true \
			--stock_quantity=$_STOCK \
			--porcelain 2>/dev/null || true
	done
else
	echo "    (aviso) no se pudo crear la chaqueta variable"
fi

# ----------------------------------------------------------------------------
# 5) Páginas estáticas de WooCommerce
# ----------------------------------------------------------------------------
echo "==> Verificando páginas WooCommerce..."
$WP wc tool run install_pages 2>/dev/null || true

echo ""
echo "============================================================"
echo "  ✔ Datos demo cargados."
echo "  Productos: 6 simples + 1 variable (3 variaciones)"
echo "  Categorías: $_CATS / $_ROPA / $_HOGAR"
echo "============================================================"
