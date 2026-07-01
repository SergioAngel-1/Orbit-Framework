#!/bin/sh
# ============================================================================
#  generate-woo-keys.sh — Genera un par de claves REST de WooCommerce (ck/cs)
#  para que el BFF (Next.js) consuma la API wc/v3 con credenciales de servidor.
#
#  Uso:
#    docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
#
#  Por defecto escribe WC_CONSUMER_KEY/WC_CONSUMER_SECRET directamente en
#  /workspace/.env y /workspace/frontend/.env.local (bind mount del repo, ver
#  --workspace-dir más abajo) además de imprimirlas. Usa --print-only para
#  conservar el comportamiento anterior (solo imprime, no escribe archivos).
# ============================================================================

set -e

PRINT_ONLY=0
WORKSPACE_DIR="/workspace"
for arg in "$@"; do
	case "$arg" in
		--print-only) PRINT_ONLY=1 ;;
		--workspace-dir=*) WORKSPACE_DIR="${arg#--workspace-dir=}" ;;
	esac
done

WP="wp --path=/var/www/html --allow-root"
DESCRIPTION="Headless BFF"
USER_LOGIN="${WP_ADMIN_USER:-admin}"

if ! $WP plugin is-active woocommerce >/dev/null 2>&1; then
	echo "ERROR: WooCommerce no está activo. Ejecuta primero la instalación (setup.sh)." >&2
	exit 1
fi

# Genera las claves vía WP-CLI eval usando las funciones internas de WooCommerce.
# WooCommerce guarda el consumer_key hasheado y el consumer_secret en claro.
KEYS=$($WP eval '
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
')

CK=$(echo "$KEYS" | grep '^WC_CONSUMER_KEY=' | cut -d= -f2-)
CS=$(echo "$KEYS" | grep '^WC_CONSUMER_SECRET=' | cut -d= -f2-)

echo "$KEYS"
echo ""

# Escritura automática solo si el workspace (repo host) está montado en el
# contenedor (ver docker-compose.yml: servicio wpcli, mounts .env/.env.local).
# Si no está disponible (--print-only, o compose antiguo sin esos mounts),
# cae de vuelta al comportamiento original: solo imprimir.
if [ "$PRINT_ONLY" = "1" ]; then
	echo "↑ (--print-only) Copia estas dos líneas en tu .env y reinicia el frontend."
elif [ -f "$WORKSPACE_DIR/.env" ] && [ -f "$WORKSPACE_DIR/frontend/.env.local" ]; then
	for f in "$WORKSPACE_DIR/.env" "$WORKSPACE_DIR/frontend/.env.local"; do
		if grep -q '^WC_CONSUMER_KEY=' "$f"; then
			sed -i.bak "s|^WC_CONSUMER_KEY=.*|WC_CONSUMER_KEY=${CK}|" "$f" && rm -f "${f}.bak"
		else
			printf 'WC_CONSUMER_KEY=%s\n' "$CK" >> "$f"
		fi
		if grep -q '^WC_CONSUMER_SECRET=' "$f"; then
			sed -i.bak "s|^WC_CONSUMER_SECRET=.*|WC_CONSUMER_SECRET=${CS}|" "$f" && rm -f "${f}.bak"
		else
			printf 'WC_CONSUMER_SECRET=%s\n' "$CS" >> "$f"
		fi
	done
	echo "✔ Escritas en $WORKSPACE_DIR/.env y $WORKSPACE_DIR/frontend/.env.local."
	echo "  Reinicia el frontend: docker compose up -d frontend"
else
	echo "↑ Copia estas dos líneas en tu .env y reinicia el frontend."
	echo "  (escritura automática desactivada: $WORKSPACE_DIR/.env no está montado en este contenedor)"
fi
