#!/bin/sh
# Quick script to create demo products (used after setup.sh)
set -e
WP="wp --path=/var/www/html --allow-root"
USER="${WP_ADMIN_USER:-admin}"

CAT_ELECTRONICA=$($WP wc product_cat list --user="$USER" --search="Electrónica" --field=id 2>/dev/null | head -1)
CAT_ROPA=$($WP wc product_cat list --user="$USER" --search="Ropa" --field=id 2>/dev/null | head -1)
CAT_HOGAR=$($WP wc product_cat list --user="$USER" --search="Hogar" --field=id 2>/dev/null | head -1)

echo "Creating 6 demo products..."

$WP wc product create --user="$USER" --name="Auriculares Bluetooth Pro" --type=simple --regular_price=89.99 --sale_price=69.99 --manage_stock=true --stock_quantity=25 --categories="[{\"id\":$CAT_ELECTRONICA}]" --status=publish --porcelain
$WP wc product create --user="$USER" --name="Camiseta Algodón Premium" --type=simple --regular_price=29.99 --manage_stock=true --stock_quantity=50 --categories="[{\"id\":$CAT_ROPA}]" --status=publish --porcelain
$WP wc product create --user="$USER" --name="Lámpara LED Inteligente" --type=simple --regular_price=45.00 --manage_stock=true --stock_quantity=15 --categories="[{\"id\":$CAT_HOGAR}]" --status=publish --porcelain
$WP wc product create --user="$USER" --name="Mochila Impermeable 40L" --type=simple --regular_price=59.99 --manage_stock=true --stock_quantity=30 --categories="[{\"id\":$CAT_ROPA}]" --status=publish --porcelain
$WP wc product create --user="$USER" --name="Monitor 4K UHD 27" --type=simple --regular_price=399.99 --manage_stock=true --stock_quantity=10 --categories="[{\"id\":$CAT_ELECTRONICA}]" --status=publish --porcelain
$WP wc product create --user="$USER" --name="Taza Cerámica Personalizada" --type=simple --regular_price=14.99 --manage_stock=true --stock_quantity=100 --categories="[{\"id\":$CAT_HOGAR}]" --status=publish --porcelain

$WP wc tool run install_pages 2>/dev/null || true
echo "Done! 6 products created."
