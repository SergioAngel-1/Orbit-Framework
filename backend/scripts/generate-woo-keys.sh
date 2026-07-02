#!/bin/sh
# ============================================================================
#  generate-woo-keys.sh — Genera un par de claves REST de WooCommerce (ck/cs)
#  para que el BFF (Next.js) consuma la API wc/v3 con credenciales de servidor.
#
#  Uso (nota el --user: escribe como el usuario del HOST, no como www-data:33
#  del servicio wpcli — así puede escribir en .env/.env.local sin necesitar
#  permisos de escritura para "otros" en esos archivos, que contienen
#  secretos y deben quedarse en modo 600):
#    docker compose run --rm --user "$(id -u):$(id -g)" \
#      --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
#
#  Por defecto escribe WC_CONSUMER_KEY/WC_CONSUMER_SECRET directamente en
#  /workspace/.env y /workspace/frontend/.env.local (bind mount del repo, ver
#  --workspace-dir más abajo) además de imprimirlas. Usa --print-only para
#  conservar el comportamiento anterior (solo imprime, no escribe archivos) —
#  necesario si corres el comando SIN el --user de arriba.
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

# Escribe una variable en un archivo SIN `sed -i` (que crea un temp file en el
# MISMO directorio para el rename — el bind mount de un solo archivo expone
# /workspace como directorio propiedad de root dentro del contenedor, no
# escribible salvo que el proceso sea root). En vez de eso: genera el
# contenido en /tmp (siempre escribible) y vuelca ese contenido DENTRO del
# archivo existente con `cat >`, que solo necesita permiso de escritura sobre
# el archivo — por eso el comando de arriba corre con `--user
# "$(id -u):$(id -g)"`: así el proceso ES el dueño del archivo (modo 600
# normal, sin aflojar permisos a "otros").
set_env_var() {
	_file="$1"; _key="$2"; _value="$3"
	_tmp="/tmp/env.$$.tmp"
	if grep -q "^${_key}=" "$_file"; then
		sed "s|^${_key}=.*|${_key}=${_value}|" "$_file" > "$_tmp" || return 1
		cat "$_tmp" > "$_file" || return 1
		rm -f "$_tmp"
	else
		printf '%s=%s\n' "$_key" "$_value" >> "$_file" || return 1
	fi
}

# Escritura automática solo si el workspace (repo host) está montado en el
# contenedor (ver docker-compose.yml: servicio wpcli, mounts .env/.env.local).
# Si no está disponible (--print-only, o compose antiguo sin esos mounts),
# cae de vuelta al comportamiento original: solo imprimir.
if [ "$PRINT_ONLY" = "1" ]; then
	echo "↑ (--print-only) Copia estas dos líneas en tu .env y reinicia el frontend."
elif [ -f "$WORKSPACE_DIR/.env" ] && [ -f "$WORKSPACE_DIR/frontend/.env.local" ]; then
	WRITE_FAILED=0
	for f in "$WORKSPACE_DIR/.env" "$WORKSPACE_DIR/frontend/.env.local"; do
		set_env_var "$f" "WC_CONSUMER_KEY" "$CK" || WRITE_FAILED=1
		set_env_var "$f" "WC_CONSUMER_SECRET" "$CS" || WRITE_FAILED=1
	done
	if [ "$WRITE_FAILED" = "1" ]; then
		echo "⚠️  No se pudo escribir en $WORKSPACE_DIR/.env o frontend/.env.local." >&2
		echo "    ¿Corriste el comando con --user \"\$(id -u):\$(id -g)\"? (ver cabecera del script)" >&2
		echo "↑ Copia las dos líneas de arriba a mano en tu .env y reinicia el frontend."
	else
		echo "✔ Escritas en $WORKSPACE_DIR/.env y $WORKSPACE_DIR/frontend/.env.local."
		echo "  Reinicia el frontend: docker compose up -d frontend"
	fi
else
	echo "↑ Copia estas dos líneas en tu .env y reinicia el frontend."
	echo "  (escritura automática desactivada: $WORKSPACE_DIR/.env no está montado en este contenedor)"
fi
