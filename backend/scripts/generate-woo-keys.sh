#!/bin/sh
# ============================================================================
#  generate-woo-keys.sh — Genera un par de claves REST de WooCommerce (ck/cs)
#  para que el BFF (Next.js) consuma la API wc/v3 con credenciales de servidor.
#
#  Uso:
#    docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
#
#  Copia los valores impresos en tu archivo .env (raíz):
#    WC_CONSUMER_KEY=ck_...
#    WC_CONSUMER_SECRET=cs_...
#  y reinicia el frontend:  docker compose up -d frontend
# ============================================================================

set -e

WP="wp --path=/var/www/html --allow-root"
DESCRIPTION="Headless BFF"
USER_LOGIN="${WP_ADMIN_USER:-admin}"

if ! $WP plugin is-active woocommerce >/dev/null 2>&1; then
	echo "ERROR: WooCommerce no está activo. Ejecuta primero la instalación (setup.sh)." >&2
	exit 1
fi

# Genera las claves vía WP-CLI eval usando las funciones internas de WooCommerce.
# WooCommerce guarda el consumer_key hasheado y el consumer_secret en claro.
$WP eval '
$user = get_user_by( "login", "'"$USER_LOGIN"'" );
if ( ! $user ) { fwrite( STDERR, "Usuario admin no encontrado\n" ); exit( 1 ); }

global $wpdb;
$consumer_key    = "ck_" . wc_rand_hash();
$consumer_secret = "cs_" . wc_rand_hash();

$wpdb->insert(
	$wpdb->prefix . "woocommerce_api_keys",
	array(
		"user_id"         => $user->ID,
		"description"     => "'"$DESCRIPTION"'",
		"permissions"     => "read_write",
		"consumer_key"    => wc_api_hash( $consumer_key ),
		"consumer_secret" => $consumer_secret,
		"truncated_key"   => substr( $consumer_key, -7 ),
	),
	array( "%d", "%s", "%s", "%s", "%s", "%s" )
);

echo "WC_CONSUMER_KEY=" . $consumer_key . "\n";
echo "WC_CONSUMER_SECRET=" . $consumer_secret . "\n";
'

echo ""
echo "↑ Copia estas dos líneas en tu .env y reinicia el frontend."
