#!/bin/sh
# ============================================================================
#  verify-integration.sh — Verifica, contra la pila Docker LOCAL, el módulo
#  `external-integration`: la puerta de red, el rol y la contraseña de aplicación, y la
#  política de GraphQL.
#
#  Crea un usuario y un pedido de prueba desechables, los comprueba y los borra al terminar
#  (también si se interrumpe). No toca nada más.
#
#  Uso (raíz del repo, con `docker compose up -d` y `hwe_wordpress` healthy):
#    sh backend/scripts/verify-integration.sh [tracking|gate|access|guard|all]
#    DEBUG=1 sh backend/scripts/verify-integration.sh guard   # ver los errores de wp-cli
#
#  Requisitos:
#    · el módulo `external-integration` activo;
#    · `HWE_INTEGRATION_ALLOWED_IPS` (en `.env`) con la IP de pruebas, por defecto
#      198.51.100.7 (RFC 5737: no enruta). Cambia otra con INTEGRATION_IP=….
#
#  Cómo simula al integrador: las llamadas «de la integración» salen DENTRO del contenedor
#  `hwe_wordpress` hacia localhost con `X-Forwarded-For: <IP de pruebas>`. Como localhost es un
#  proxy de confianza para mod_remoteip, PHP ve esa IP en `REMOTE_ADDR`, igual que con Caddy en
#  producción. La llamada «de una IP ajena» usa otra IP en la misma cabecera. El BFF se simula
#  con la cabecera `X-HWE-Internal-Secret`.
#
#  Lo que NO se puede verificar en local: que Caddy vea la IP real del integrador ni que la
#  cabecera `Authorization` llegue a PHP a través de Caddy → Apache. Se comprueba en el despliegue.
#
#  Salida: 0 todo bien · N = número de comprobaciones fallidas · 2 sección desconocida.
# ============================================================================
set -u
cd "$(dirname "$0")/../.." || exit 1

SECTION="${1:-all}"
FAILS=0
INTEGRATION_IP="${INTEGRATION_IP:-198.51.100.7}"
USER_LOGIN="integration-test"
CUSTOMER_LOGIN="integration-test-cliente"
ORDER_ID=""
FIXTURES=0
APP_PW=""
TEST_PASS=""
SECRET=""

cli() {
  if [ "${DEBUG:-}" = "1" ]; then
    docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html "$@"
  else
    docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html "$@" 2>/dev/null
  fi
}

# gql <json> [usuario:contraseña] — POST a /graphql como si llegara de la IP de la integración.
gql() {
  if [ -n "${2:-}" ]; then
    docker exec hwe_wordpress curl -s -X POST http://localhost/graphql \
      -H 'Content-Type: application/json' -H "X-Forwarded-For: $INTEGRATION_IP" -u "$2" -d "$1"
  else
    docker exec hwe_wordpress curl -s -X POST http://localhost/graphql \
      -H 'Content-Type: application/json' -H "X-Forwarded-For: $INTEGRATION_IP" -d "$1"
  fi
}

# bff <json> — POST a /graphql como el BFF (secreto interno, sin X-Forwarded-For).
bff() {
  docker exec hwe_wordpress curl -s -X POST http://localhost/graphql \
    -H 'Content-Type: application/json' -H "X-HWE-Internal-Secret: $SECRET" -d "$1"
}

check() { # nombre, salida, patrón que DEBE aparecer (grep -E)
  if printf '%s' "$2" | grep -qE "$3"; then
    echo "  ok     $1"
  else
    echo "  FALLA  $1"
    echo "         salida: $(printf '%s' "$2" | head -c 400)"
    FAILS=$((FAILS + 1))
  fi
}

check_not() { # nombre, salida, patrón que NO debe aparecer
  if printf '%s' "$2" | grep -qE "$3"; then
    echo "  FALLA  $1"
    echo "         salida: $(printf '%s' "$2" | head -c 400)"
    FAILS=$((FAILS + 1))
  else
    echo "  ok     $1"
  fi
}

setup_secret() {
  [ -n "$SECRET" ] && return 0
  SECRET="$(cli eval 'echo defined("HWE_REVALIDATION_SECRET") ? HWE_REVALIDATION_SECRET : "";')"
}

setup_order() {
  [ -n "$ORDER_ID" ] && return 0
  FIXTURES=1
  ORDER_ID="$(cli eval '$o = wc_create_order(); $o->set_billing_email("integration-test@example.invalid"); $o->set_status("on-hold"); $o->save(); echo $o->get_id();')"
  echo "  (pedido de prueba #$ORDER_ID)"
}

setup_user() {
  [ -n "$APP_PW" ] && return 0
  FIXTURES=1
  TEST_PASS="$(openssl rand -hex 12)"
  cli user delete "$USER_LOGIN" --yes >/dev/null 2>&1
  cli user create "$USER_LOGIN" integration-test@example.invalid --role=hwe_integration --user_pass="$TEST_PASS" >/dev/null
  APP_PW="$(cli user application-password create "$USER_LOGIN" verify --porcelain)"
}

teardown_fixtures() {
  # Solo si se creó algo: una sección desconocida no debe tocar la pila.
  [ "$FIXTURES" = "1" ] || return 0
  cli user delete "$USER_LOGIN" --yes >/dev/null 2>&1
  cli user delete "$CUSTOMER_LOGIN" --yes >/dev/null 2>&1
  if [ -n "$ORDER_ID" ]; then
    cli eval "\$o = wc_get_order($ORDER_ID); if (\$o) { \$o->delete(true); }" >/dev/null 2>&1
  fi
}
trap teardown_fixtures EXIT

section_tracking() {
  echo "· campos de envío"
  ADMIN_ID="$(cli user list --role=administrator --field=ID | head -n1)"
  setup_order
  out="$(cli eval "
    wp_set_current_user($ADMIN_ID);
    \$_POST = [
      'hwe_order_tracking_nonce' => wp_create_nonce('hwe_order_tracking'),
      'hwe_carrier'         => 'Transportadora X',
      'hwe_tracking_number' => '12345',
      'hwe_tracking_url'    => 'javascript:alert(1)',
    ];
    do_action('woocommerce_process_shop_order_meta', $ORDER_ID, wc_get_order($ORDER_ID));
    \$o = wc_get_order($ORDER_ID);
    echo json_encode([\$o->get_meta('_carrier'), \$o->get_meta('_tracking_number'), \$o->get_meta('_tracking_url')]);
  ")"
  check "guarda transportadora y guía y descarta un enlace javascript:" "$out" '^\["Transportadora X","12345",""\]$'
}

section_gate() {
  echo "· puerta de GraphQL"
  WP_HOST_PORT="$(docker port hwe_wordpress 80/tcp 2>/dev/null | head -n1 | sed 's/.*://')"
  setup_secret
  q='{"query":"{ __typename }"}'

  out="$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://localhost:$WP_HOST_PORT/graphql" \
    -H 'Content-Type: application/json' -H 'X-Forwarded-For: 203.0.113.9' -d "$q")"
  check "IP no autorizada (desde el host): 404" "$out" '^404$'

  out="$(bff "$q")"
  check "el BFF (con su secreto) sigue pasando" "$out" 'RootQuery'

  out="$(gql "$q")"
  check "IP autorizada: GraphQL responde" "$out" 'RootQuery'

  out="$(docker exec hwe_wordpress curl -s -o /dev/null -w '%{http_code}' -H "X-Forwarded-For: $INTEGRATION_IP" http://localhost/wp-json/wc/store/v1/cart)"
  check "la Store API sigue cerrada para la IP autorizada" "$out" '^404$'

  out="$(docker exec hwe_wordpress curl -s -o /dev/null -w '%{http_code}' -H "X-Forwarded-For: $INTEGRATION_IP" http://localhost/wp-json/hwe/v1/auth/me)"
  check "hwe/v1/auth sigue cerrada para la IP autorizada" "$out" '^404$'
}

section_access() {
  echo "· rol y Application Password"
  setup_order
  setup_user

  out="$(cli eval "echo wp_authenticate('$USER_LOGIN', '$TEST_PASS')->get_error_code();")"
  check "la cuenta no puede iniciar sesión interactiva" "$out" 'hwe_integration_no_login'

  out="$(gql "{\"query\":\"{ orders(first: 5) { nodes { databaseId status } } }\"}" "$USER_LOGIN:$APP_PW")"
  check "con la contraseña de aplicación lee pedidos" "$out" "\"databaseId\":$ORDER_ID"

  out="$(gql "{\"query\":\"{ orders(first: 5) { nodes { databaseId } } }\"}" "$USER_LOGIN:contrasena-mala")"
  check_not "con una contraseña mala no ve el pedido" "$out" "\"databaseId\":$ORDER_ID"

  out="$(docker exec hwe_wordpress curl -s -o /dev/null -w '%{http_code}' -u "$USER_LOGIN:$APP_PW" \
    -H "X-Forwarded-For: $INTEGRATION_IP" http://localhost/wp-json/wc/v3/orders)"
  check "la contraseña de aplicación no sirve en REST (401)" "$out" '^401$'

  out="$(cli eval "
    \$u = get_user_by('login', '$USER_LOGIN');
    echo json_encode([
      user_can(\$u, 'manage_woocommerce'), user_can(\$u, 'list_users'),
      user_can(\$u, 'edit_products'), user_can(\$u, 'edit_shop_orders'),
    ]);
  ")"
  check "rol mínimo: solo pedidos" "$out" '^\[false,false,false,true\]$'

  out="$(cli eval "
    \$u = get_user_by('login', '$USER_LOGIN');
    \$p = get_posts(['numberposts' => 1, 'post_status' => 'any'])[0]->ID;
    echo json_encode([user_can(\$u, 'edit_post', \$p), user_can(\$u, 'edit_post', $ORDER_ID)]);
  ")"
  check "puede editar el pedido, no las entradas del blog" "$out" '^\[false,true\]$'

  out="$(cli eval "
    \$a = get_users(['role' => 'administrator', 'number' => 1])[0];
    echo json_encode(wp_is_application_passwords_available_for_user(\$a));
  ")"
  check "los demás usuarios no pueden tener Application Passwords" "$out" '^false$'
}

section_guard() {
  echo "· lista de operaciones, updateOrder y metaData"
  setup_secret
  setup_order
  setup_user
  auth="$USER_LOGIN:$APP_PW"

  out="$(gql '{"query":"{ posts { nodes { id } } }"}' "$auth")"
  check "una query fuera de la lista se rechaza" "$out" 'Operaci.*n no permitida'

  out="$(gql '{"query":"mutation { login(input: {username: \"x\", password: \"y\"}) { authToken } }"}' "$auth")"
  check "la mutación login se rechaza" "$out" 'Operaci.*n no permitida'

  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID, billing: {firstName: \\\"X\\\"}}) { order { databaseId } } }\"}" "$auth")"
  check "updateOrder rechaza campos distintos de status" "$out" 'solo admite cambiar el estado'

  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID}) { order { databaseId } } }\"}" "$auth")"
  check "updateOrder exige status" "$out" 'exige'

  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID, status: PROCESSING}) { order { databaseId status } } }\"}" "$auth")"
  check "updateOrder cambia el estado" "$out" '"status":"PROCESSING"'
  out="$(cli eval "echo wc_get_order($ORDER_ID)->get_status();")"
  check "el estado cambió de verdad en WooCommerce" "$out" '^processing$'

  # Un pedido sin líneas no tiene un total que salga de ellas: cambiarle el estado no puede
  # recalcularlo ni pisar su origen.
  cli eval "\$o = wc_get_order($ORDER_ID); \$o->set_total(40500); \$o->set_created_via('checkout'); \$o->save();" >/dev/null
  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID, status: COMPLETED}) { order { status total(format: RAW) } } }\"}" "$auth")"
  check "updateOrder devuelve el pedido con su total intacto" "$out" '"status":"COMPLETED","total":"40500"'
  out="$(cli eval "\$o = wc_get_order($ORDER_ID); echo json_encode([\$o->get_status(), (float) \$o->get_total(), \$o->get_created_via()]);")"
  check "updateOrder no recalcula el total ni pisa created_via" "$out" '^\["completed",40500,"checkout"\]$'

  cli eval "\$o = wc_get_order($ORDER_ID); \$o->set_status('pending'); \$o->save();" >/dev/null
  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID, status: PROCESSING}) { order { status } } }\"}" "$auth")"
  check "un pedido sin pagar no se puede pasar a procesando" "$out" 'sin pagar'
  out="$(cli eval "echo wc_get_order($ORDER_ID)->get_status();")"
  check "el pedido sin pagar sigue pendiente" "$out" '^pending$'
  out="$(gql "{\"query\":\"mutation { updateOrder(input: {orderId: $ORDER_ID, status: CANCELLED}) { order { status } } }\"}" "$auth")"
  check "un pedido sin pagar sí se puede cancelar" "$out" '"status":"CANCELLED"'

  cli eval "
    \$o = wc_get_order($ORDER_ID);
    \$o->update_meta_data('_hwe_secret_test', 'dato-privado');
    \$o->update_meta_data('_carrier', 'Transportadora X');
    \$o->update_meta_data('respuesta_pasarela', 'respuesta-pasarela');
    \$p = wc_get_products(['limit' => 1, 'status' => 'publish', 'type' => 'simple'])[0];
    \$i = \$o->add_product(\$p, 1);
    wc_add_order_item_meta(\$i, 'modalidad', 'Virtual');
    wc_add_order_item_meta(\$i, '_reduced_stock', '1');
    \$o->save();
  " >/dev/null
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { metaData { key value } } }\"}" "$auth")"
  check "metaData deja pasar la clave de envío" "$out" '_carrier'
  check_not "metaData del pedido no deja pasar meta visible heredado" "$out" 'respuesta_pasarela|respuesta-pasarela'
  check_not "metaData oculta las claves privadas" "$out" '_hwe_secret_test|dato-privado'
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { lineItems { nodes { metaData { key value } } } } }\"}" "$auth")"
  check "el meta visible de la línea sí sale" "$out" 'Virtual'
  check_not "el meta privado de la línea no sale" "$out" '_reduced_stock'

  cust="$(cli user create "$CUSTOMER_LOGIN" integration-test-cliente@example.invalid --role=customer --porcelain)"
  cli eval "\$o = wc_get_order($ORDER_ID); \$o->set_customer_id($cust); \$o->set_billing_first_name('Ana'); \$o->save();" >/dev/null
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { customer { databaseId } } }\"}" "$auth")"
  check "order.customer devuelve el id del cliente" "$out" "\"databaseId\":$cust"
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { orderKey } }\"}" "$auth")"
  check "orderKey no se puede leer" "$out" 'Campo no permitido'
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { customerIpAddress customerUserAgent } }\"}" "$auth")"
  check "IP y user agent del comprador no se pueden leer" "$out" 'Campo no permitido'
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { customer { sessionToken } } }\"}" "$auth")"
  check "los campos de sesión de Customer no se pueden leer" "$out" 'Campo no permitido'
  cli eval "update_user_meta($cust, 'graphql_jwt_auth_secret', 'secreto-jwt-prueba');" >/dev/null
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { customer { metaData { key value } } } }\"}" "$auth")"
  check "el user meta de Customer no se puede leer" "$out" 'Campo no permitido'
  check_not "el secreto JWT del cliente no sale por metaData" "$out" 'secreto-jwt-prueba'
  out="$(gql "{\"query\":\"{ order(id: $ORDER_ID, idType: DATABASE_ID) { orderNotes { nodes { note } } } }\"}" "$auth")"
  check "las notas internas no se exponen" "$out" 'Campo no permitido'
  out="$(gql '{"query":"{ customers(first: 1) { nodes { databaseId } } }"}' "$auth")"
  check "la query raíz customers sigue rechazada" "$out" 'Operaci.*n no permitida'
  out="$(gql '{"query":"{ users(first: 1) { nodes { databaseId } } }"}' "$auth")"
  check "la query raíz users sigue rechazada" "$out" 'Operaci.*n no permitida'

  out="$(bff '{"query":"{ posts(first: 1) { nodes { id } } }"}')"
  check "el BFF (llamada interna) no queda restringido" "$out" '"posts"'
}

case "$SECTION" in
  tracking) section_tracking ;;
  gate)     section_gate ;;
  access)   section_access ;;
  guard)    section_guard ;;
  all)      section_tracking; section_gate; section_access; section_guard ;;
  *)        echo "sección desconocida: $SECTION (tracking|gate|access|guard|all)"; exit 2 ;;
esac

echo
if [ "$FAILS" -eq 0 ]; then echo "TODO OK"; else echo "$FAILS comprobación(es) fallida(s)"; fi
exit "$FAILS"
