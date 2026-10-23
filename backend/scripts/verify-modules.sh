#!/bin/sh
# ============================================================================
#  verify-modules.sh — Verifica los módulos opcionales del framework: que cada uno esté
#  completo en el repo (entrada, README, MANUAL, montado en la pila) y, contra la pila
#  Docker LOCAL, que encenderlo y apagarlo hace lo que promete el contrato de módulo.
#
#  Secciones:
#    static       sin pila: el catálogo de PHP y el de TS coinciden, y cada plugin de módulo tiene
#                 su archivo de entrada, README.md y MANUAL.md y está montado en los tres servicios
#                 de WordPress de `docker-compose.yml` (sin el montaje no se puede activar).
#    toggle       con la pila: por cada módulo, apagado → `config.modules[<id>]` es false y sus
#                 rutas REST responden 404; encendido → es true y responden algo distinto de 404.
#                 Deja cada módulo como estaba (también si se interrumpe). `loyalty` añade:
#                 tablas propias, rutas internas con el secreto interno y la extensión
#                 `hwe-loyalty` de la Store API, con «Puntos»/«Referidos» encendidos de forma
#                 temporal y restaurados al salir.
#    i18n         con la pila: una traducción a un segundo idioma guardada en la meta de un
#                 producto llega por `Product.translation(locale)`, y un idioma no declarado no.
#    country      con la pila: el paquete del país de la tienda está en `/hwe/v1/geo`.
#    integration  con la pila: activa `external-integration`, ejecuta `verify-integration.sh all`
#                 y deja el módulo como estaba. Necesita `HWE_INTEGRATION_ALLOWED_IPS` con la IP
#                 de pruebas EN LA PILA y en el entorno de este script (cada `docker compose run`
#                 recrea `wordpress` si su entorno difiere del de la pila en marcha):
#                   export HWE_INTEGRATION_ALLOWED_IPS=198.51.100.7
#                   docker compose up -d --no-deps wordpress wp-cron
#                   sh backend/scripts/verify-modules.sh integration
#    all          (por defecto) todo lo anterior.
#
#  Uso (raíz del repo, con `docker compose up -d` y `hwe_wordpress` healthy):
#    sh backend/scripts/verify-modules.sh [static|toggle|i18n|country|integration|all] [--strict]
#    DEBUG=1 sh backend/scripts/verify-modules.sh toggle   # ver los errores de wp-cli
#
#  Lo que no se puede comprobar con la pila de WordPress sola (que el BFF devuelva 404 con el
#  módulo apagado, y que la UI no se pinte) lo cubren los tests del BFF y los e2e por módulo.
#
#  Lo que no se puede verificar aquí se OMITE y se dice; con --strict, omitir cuenta como fallo
#  (úsalo en la aceptación). Salida: 0 todo bien · N = comprobaciones fallidas · 2 sección
#  desconocida o la pila no responde.
# ============================================================================
set -u
cd "$(dirname "$0")/../.." || exit 1

SECTION="all"
STRICT=0
for arg in "$@"; do
  case "$arg" in
    --strict) STRICT=1 ;;
    -h | --help)
      sed -n '2,/^# =====/p' "$0" | sed 's/^# \{0,1\}//' | sed '$d'
      exit 0
      ;;
    *) SECTION="$arg" ;;
  esac
done

FAILS=0
SKIPS=0
SECRET=""
# Estado inicial de cada plugin tocado, «id=active|inactive», para restaurarlo al salir.
TOUCHED=""
FIXTURE_PRODUCT=""
# Marca de que se guardó la config del módulo `loyalty` para restaurarla al salir.
LOYALTY_CONFIG_SAVED=0

MODULES_PHP="backend/wp-content/mu-plugins/hwe-control-center/Modules.php"
MODULES_TS="frontend/src/lib/config/modules.ts"
COMPOSE="docker-compose.yml"
PLUGINS="backend/wp-content/plugins"

cli() {
  if [ "${DEBUG:-}" = "1" ]; then
    docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html "$@"
  else
    docker compose --profile cli run --rm -T --entrypoint wp wpcli --path=/var/www/html "$@" 2>/dev/null
  fi
}

ok() { echo "  ok     $1"; }
fail() {
  echo "  FALLA  $1"
  [ -n "${2:-}" ] && echo "         $2"
  FAILS=$((FAILS + 1))
}
skip() {
  echo "  omite  $1"
  SKIPS=$((SKIPS + 1))
}

check() { # nombre, salida, patrón que DEBE aparecer (grep -E)
  if printf '%s' "$2" | grep -qE "$3"; then
    ok "$1"
  else
    fail "$1" "salida: $(printf '%s' "$2" | head -c 300)"
  fi
}

# Ids del catálogo de PHP (`Modules::CORE_IDS`), en orden.
php_ids() {
  sed -n "/CORE_IDS = \[/,/\];/p" "$MODULES_PHP" | sed -n "s/^ *'\([a-z0-9-]*\)',\{0,1\} *$/\1/p"
}

# Ids del catálogo de TS (`MODULE_IDS`), en orden.
ts_ids() {
  sed -n '/MODULE_IDS/,/\];/p' "$MODULES_TS" | sed -n 's/^ *"\([a-z0-9-]*\)",\{0,1\} *$/\1/p'
}

# La pila de WordPress responde.
require_stack() {
  if ! docker exec hwe_wordpress true >/dev/null 2>&1; then
    echo "La pila no responde: arranca con \`docker compose up -d\` (contenedor hwe_wordpress)."
    exit 2
  fi
}

setup_secret() {
  [ -n "$SECRET" ] && return 0
  # `WORDPRESS_CONFIG_EXTRA` del contenedor `wordpress` define el secreto; el contenedor de
  # WP-CLI no lo tiene. Si la pila lo define en wp-config.php, se lee con WP-CLI.
  SECRET="$(docker exec hwe_wordpress printenv WORDPRESS_CONFIG_EXTRA 2>/dev/null \
    | sed -n "s/.*'HWE_REVALIDATION_SECRET', '\([^']*\)'.*/\1/p" | head -n1)"
  [ -n "$SECRET" ] || SECRET="$(cli eval 'echo defined("HWE_REVALIDATION_SECRET") ? HWE_REVALIDATION_SECRET : "";')"
}

# wp_get <ruta> — código HTTP de un GET, dentro del contenedor (sin proxy) y con el secreto
# interno (como el BFF). Una ruta no registrada responde 404 SIEMPRE, también si solo admite POST;
# por eso el «encendido» solo se sondea en las rutas que admiten GET (no tienen efectos).
wp_get() {
  docker exec hwe_wordpress curl -s -o /dev/null -w '%{http_code}' \
    -H "X-HWE-Internal-Secret: $SECRET" "http://localhost$1"
}

bff() { # <json> — POST a /graphql como el BFF
  docker exec hwe_wordpress curl -s -X POST http://localhost/graphql \
    -H 'Content-Type: application/json' -H "X-HWE-Internal-Secret: $SECRET" -d "$1"
}

plugin_present() { docker exec hwe_wordpress test -f "/var/www/html/wp-content/plugins/hwe-$1/hwe-$1.php"; }

plugin_state() { # <id> → active | inactive
  if cli plugin is-active "hwe-$1" >/dev/null 2>&1; then echo active; else echo inactive; fi
}

# Anota el estado inicial (una sola vez) para restaurarlo al salir.
remember() {
  case "$TOUCHED" in
    *" $1="*) return 0 ;;
  esac
  TOUCHED="$TOUCHED $1=$(plugin_state "$1")"
}

set_module() { # <id> on|off
  if [ "$2" = on ]; then
    cli plugin activate "hwe-$1" >/dev/null 2>&1
  else
    cli plugin deactivate "hwe-$1" >/dev/null 2>&1
  fi
}

# ── loyalty: ajustes temporales ─────────────────────────────────────────────
# Las rutas internas y el canje solo existen con «Puntos»/«Referidos» encendidos, y los
# defaults del módulo nacen apagados. Se guarda la config en una opción aparte y se restaura
# al salir (también si el script se interrumpe): la verificación no puede dejar la tienda
# repartiendo puntos ni cambiar los ajustes de la instancia.
loyalty_config_save() {
  [ "$LOYALTY_CONFIG_SAVED" = 1 ] && return 0
  if cli eval 'update_option("hwe_verify_loyalty_config_backup", array("exists" => null !== get_option("hwe_config", null), "config" => get_option("hwe_config", array())), false);' >/dev/null 2>&1; then
    LOYALTY_CONFIG_SAVED=1
  fi
}

loyalty_config_enable() {
  # Sin copia de seguridad no se toca la config: un fallo a medias dejaría la tienda repartiendo
  # puntos con los ajustes cambiados.
  [ "$LOYALTY_CONFIG_SAVED" = 1 ] || return 1
  cli eval '$c = get_option("hwe_config", array()); if (!is_array($c)) { $c = array(); } $l = isset($c["loyalty"]) && is_array($c["loyalty"]) ? $c["loyalty"] : array(); $l["points_enabled"] = true; $l["referrals_enabled"] = true; $l["transfers_enabled"] = true; $c["loyalty"] = $l; update_option("hwe_config", $c, false);' >/dev/null 2>&1
}

loyalty_config_restore() {
  [ "$LOYALTY_CONFIG_SAVED" = 0 ] && return 0
  LOYALTY_CONFIG_SAVED=0
  cli eval '$b = get_option("hwe_verify_loyalty_config_backup", null); if (is_array($b)) { if (!empty($b["exists"])) { update_option("hwe_config", $b["config"], false); } else { delete_option("hwe_config"); } delete_option("hwe_verify_loyalty_config_backup"); }' >/dev/null 2>&1
}

# Comprobaciones propias de `loyalty`, con el plugin activo y sus interruptores encendidos:
# tablas del libro, rutas internas (con y sin el secreto) y la extensión de la Store API.
loyalty_checks() {
  tables="$(cli eval 'global $wpdb; $names = array("hwe_loyalty_ledger","hwe_loyalty_balances","hwe_loyalty_ledger_consumptions","hwe_loyalty_referrals"); $missing = array(); foreach ($names as $name) { $table = $wpdb->prefix . $name; if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) { $missing[] = $name; } } echo $missing ? "faltan:" . implode(",", $missing) : "todas";' 2>/dev/null | grep -aE '^(todas|faltan:)' | tail -n1)"
  check "loyalty activo: las tablas del libro y la red" "$tables" '^todas$'

  for route in summary transactions referrals; do
    code="$(wp_get "/wp-json/hwe/v1/loyalty/$route?user_id=1")"
    [ "$code" = 200 ] && ok "loyalty activo: /hwe/v1/loyalty/$route con el secreto → 200" \
      || fail "loyalty activo: /hwe/v1/loyalty/$route debía responder 200" "responde $code"
  done
  code="$(wp_get "/wp-json/hwe/v1/loyalty/validate-code?code=ABCDEFGH")"
  [ "$code" = 200 ] && ok "loyalty activo: /hwe/v1/loyalty/validate-code con el secreto → 200" \
    || fail "loyalty activo: /hwe/v1/loyalty/validate-code debía responder 200" "responde $code"

  # Sin el secreto, `hwe-bff-only` responde 404: no confirma que la ruta exista.
  code="$(docker exec hwe_wordpress curl -s -o /dev/null -w '%{http_code}' \
    "http://localhost/wp-json/hwe/v1/loyalty/summary?user_id=1")"
  [ "$code" = 404 ] && ok "loyalty: las rutas internas se cierran sin el secreto (404)" \
    || fail "loyalty: una ruta interna respondió $code sin el secreto" "debía ser 404"

  cart="$(docker exec hwe_wordpress curl -s -H "X-HWE-Internal-Secret: $SECRET" \
    http://localhost/wp-json/wc/store/v1/cart)"
  check "loyalty activo: la Store API publica extensions.hwe-loyalty" "$cart" '"hwe-loyalty"'
}

restore() {
  loyalty_config_restore
  for entry in $TOUCHED; do
    id="${entry%%=*}"
    state="${entry#*=}"
    if [ "$state" = active ]; then set_module "$id" on; else set_module "$id" off; fi
  done
  TOUCHED=""
  if [ -n "$FIXTURE_PRODUCT" ]; then
    cli eval "wp_delete_post($FIXTURE_PRODUCT, true);" >/dev/null 2>&1
    FIXTURE_PRODUCT=""
  fi
}
trap restore EXIT

# Rutas REST propias registradas (contienen `hwe`), una por línea: `ruta<TAB>MÉTODOS`, ordenadas.
routes() {
  cli eval 'foreach (rest_get_server()->get_routes() as $r => $handlers) {
      if (strpos($r, "hwe") === false) { continue; }
      $m = [];
      foreach ($handlers as $h) { $m = array_merge($m, array_keys((array) ($h["methods"] ?? []))); }
      echo $r . "\t" . implode(",", array_unique($m)) . "\n";
    }' | grep -a '^/' | sort
}

module_flag() { # <id> → true | false | ?
  out="$(docker exec hwe_wordpress curl -s http://localhost/wp-json/hwe/v1/config \
    | grep -ao "\"$1\":\(true\|false\)" | head -n1 | sed 's/.*://')"
  echo "${out:-?}"
}

# ── static ──────────────────────────────────────────────────────────────────

section_static() {
  echo "· catálogo y archivos de cada módulo"
  PHP_IDS="$(php_ids)"
  TS_IDS="$(ts_ids)"
  if [ -n "$PHP_IDS" ] && [ "$PHP_IDS" = "$TS_IDS" ]; then
    ok "el catálogo de PHP y el de TS coinciden ($(printf '%s\n' "$PHP_IDS" | grep -c .) módulos)"
  else
    fail "el catálogo de PHP y el de TS difieren" "PHP: $(echo $PHP_IDS)  ·  TS: $(echo $TS_IDS)"
  fi

  for id in $PHP_IDS; do
    dir="$PLUGINS/hwe-$id"
    missing=""
    [ -f "$dir/hwe-$id.php" ] || missing="$missing hwe-$id.php"
    [ -f "$dir/README.md" ] || missing="$missing README.md"
    [ -f "$dir/MANUAL.md" ] || missing="$missing MANUAL.md"
    if [ -n "$missing" ]; then
      fail "$id: faltan archivos" "$missing"
    elif ! grep -q 'Plugin Name:' "$dir/hwe-$id.php"; then
      fail "$id: la entrada no tiene cabecera «Plugin Name:»"
    else
      ok "$id: entrada, README y MANUAL"
    fi
    mounts="$(grep -c "plugins/hwe-$id:/var/www/html/wp-content/plugins/hwe-$id" "$COMPOSE")"
    if [ "$mounts" -ge 3 ]; then
      ok "$id: montado en los 3 servicios de WordPress"
    else
      fail "$id: montado en $mounts de 3 servicios de $COMPOSE" "sin el montaje el módulo no se puede activar en la pila"
    fi
  done
}

# ── toggle ──────────────────────────────────────────────────────────────────

section_toggle() {
  echo "· encendido y apagado de cada módulo"
  require_stack
  setup_secret
  OUT_ON="$(mktemp)"
  OUT_OFF="$(mktemp)"
  for id in $(php_ids); do
    echo "  [$id]"
    if ! plugin_present "$id"; then
      skip "$id: el plugin no está montado en la pila (recrea con docker compose up -d --no-deps wordpress wp-cron)"
      continue
    fi
    remember "$id"

    set_module "$id" off
    flag_off="$(module_flag "$id")"
    routes_off="$(routes)"

    set_module "$id" on
    if [ "$id" = loyalty ]; then
      # Las rutas y el canje existen siempre, pero solo responden con los interruptores
      # encendidos: se encienden de forma temporal y `restore` devuelve la config original.
      loyalty_config_save
      loyalty_config_enable
    fi
    flag_on="$(module_flag "$id")"
    routes_on="$(routes)"

    [ "$flag_off" = false ] && ok "$id apagado: config.modules = false" \
      || fail "$id apagado: config.modules debía ser false" "vale $flag_off"
    [ "$flag_on" = true ] && ok "$id encendido: config.modules = true" \
      || fail "$id encendido: config.modules debía ser true" "vale $flag_on"

    if [ "$id" = loyalty ]; then
      loyalty_checks
    fi

    # Rutas que solo existen con el módulo encendido.
    f_off="$(mktemp)"
    f_on="$(mktemp)"
    printf '%s\n' "$routes_off" >"$f_off"
    printf '%s\n' "$routes_on" >"$f_on"
    own="$(comm -13 "$f_off" "$f_on")"
    rm -f "$f_off" "$f_on"
    # Solo las rutas literales (las de expresión regular no se pueden sondear tal cual).
    own="$(printf '%s\n' "$own" | grep -v '(' | grep -v '^$')"
    if [ -z "$own" ]; then
      ok "$id: no declara rutas REST propias (solo GraphQL o la config)"
      continue
    fi

    # Encendido (ya lo está): las que admiten GET responden algo distinto de 404.
    printf '%s\n' "$own" | while IFS="$(printf '\t')" read -r route methods; do
      case ",$methods," in
        *,GET,*)
          code="$(wp_get "/wp-json$route")"
          if [ "$code" = 404 ]; then
            echo "  FALLA  $id encendido: $route sigue en 404"
          else
            echo "  ok     $id encendido: $route → $code"
          fi
          ;;
        *) echo "  ok     $id encendido: $route registrada ($methods)" ;;
      esac
    done > "$OUT_ON"
    cat "$OUT_ON"
    FAILS=$((FAILS + $(grep -c '^  FALLA' "$OUT_ON")))

    # Apagado: ya no están registradas y responden 404.
    set_module "$id" off
    routes_after="$(routes | cut -f1)"
    printf '%s\n' "$own" | while IFS="$(printf '\t')" read -r route methods; do
      if printf '%s\n' "$routes_after" | grep -qxF "$route"; then
        echo "  FALLA  $id apagado: $route sigue registrada"
      elif [ "$(wp_get "/wp-json$route")" != 404 ]; then
        echo "  FALLA  $id apagado: $route no responde 404"
      else
        echo "  ok     $id apagado: $route → 404"
      fi
    done > "$OUT_OFF"
    cat "$OUT_OFF"
    FAILS=$((FAILS + $(grep -c '^  FALLA' "$OUT_OFF")))
  done
  rm -f "$OUT_ON" "$OUT_OFF"
  restore
}

# ── i18n ────────────────────────────────────────────────────────────────────

section_i18n() {
  echo "· traducción a un segundo idioma"
  require_stack
  setup_secret
  LOCALE="$(docker exec hwe_wordpress curl -s http://localhost/wp-json/hwe/v1/config \
    | grep -ao '"locales":"[^"]*"' | head -n1 | sed 's/.*:"//; s/"$//' | cut -d, -f1 | tr -d ' ')"
  if [ -z "$LOCALE" ]; then
    skip "el sitio no declara idiomas secundarios (config.i18n.locales vacío)"
    return 0
  fi
  SUFFIX="$(printf '%s' "$LOCALE" | tr 'A-Z-' 'a-z_')"
  FIXTURE_PRODUCT="$(cli eval "
    \$p = new WC_Product_Simple();
    \$p->set_name('verify-modules');
    \$p->set_status('publish');
    \$p->set_regular_price('1');
    \$p->save();
    update_post_meta(\$p->get_id(), '_hwe_name_$SUFFIX', 'verify-modules-translated');
    echo \$p->get_id();
  " | grep -a '^[0-9][0-9]*$' | tail -n1)"
  if [ -z "$FIXTURE_PRODUCT" ]; then
    fail "no se pudo crear el producto de prueba (¿WooCommerce activo?)"
    return 0
  fi
  echo "  (producto de prueba #$FIXTURE_PRODUCT, idioma $LOCALE)"

  q="{\"query\":\"{ product(id: \\\"$FIXTURE_PRODUCT\\\", idType: DATABASE_ID) { name translation(locale: \\\"$LOCALE\\\") { name } other: translation(locale: \\\"zz\\\") { name } } }\"}"
  out="$(bff "$q")"
  check "la traducción guardada llega por Product.translation($LOCALE)" "$out" '"translation":\{"name":"verify-modules-translated"\}'
  check "un idioma no declarado no devuelve traducción" "$out" '"other":null'
}

# ── country ─────────────────────────────────────────────────────────────────

section_country() {
  echo "· paquete de país"
  require_stack
  COUNTRY="$(docker exec hwe_wordpress curl -s http://localhost/wp-json/hwe/v1/config \
    | grep -ao '"country":"[A-Z][A-Z]"' | head -n1 | sed 's/.*:"//; s/"$//')"
  if [ -z "$COUNTRY" ]; then
    skip "la config no expone el país por defecto de la tienda"
    return 0
  fi
  setup_secret
  packs="$(cli eval 'echo implode(",", array_keys(hwe_geo_packs()));' | grep -a '^[a-z]' | tail -n1)"
  packs="$(printf '%s' "$packs" | tr 'A-Z' 'a-z')"
  check "el núcleo trae el paquete genérico" "$packs" '(^|,)generic(,|$)'
  check "el núcleo trae el paquete de Colombia (co)" "$packs" '(^|,)co(,|$)'

  out="$(docker exec hwe_wordpress curl -s -H "X-HWE-Internal-Secret: $SECRET" http://localhost/wp-json/hwe/v1/geo)"
  lower="$(printf '%s' "$COUNTRY" | tr 'A-Z' 'a-z')"
  if printf ',%s,' "$packs" | grep -q ",$lower,"; then
    check "/hwe/v1/geo sirve el paquete de $COUNTRY, sin fallback" "$out" "\"country\":\"$COUNTRY\".*\"fallback\":false"
  else
    # Sin paquete para el país: el contrato es caer al genérico DECLARÁNDOLO (`fallback`), nunca
    # inventar divisiones ni servir en silencio un paquete de otro país.
    check "/hwe/v1/geo cae al genérico declarando el fallback para $COUNTRY" "$out" "\"country\":\"generic\".*\"fallback\":true.*\"requested\":\"$COUNTRY\""
  fi
}

# ── integration ─────────────────────────────────────────────────────────────

section_integration() {
  echo "· módulo external-integration"
  require_stack
  if ! plugin_present external-integration; then
    skip "external-integration no está montado en la pila"
    return 0
  fi
  # Sin una IP en la lista, la puerta no deja pasar a nadie y cada comprobación fallaría por la
  # misma causa: se dice una vez y se omite.
  if ! docker exec hwe_wordpress printenv WORDPRESS_CONFIG_EXTRA 2>/dev/null \
    | grep -q "'HWE_INTEGRATION_ALLOWED_IPS', '[^']"; then
    skip "HWE_INTEGRATION_ALLOWED_IPS está vacío en la pila (ver la cabecera del script: exportarlo y recrear wordpress)"
    return 0
  fi
  remember external-integration
  set_module external-integration on
  if sh backend/scripts/verify-integration.sh all; then
    ok "verify-integration.sh all"
  else
    fail "verify-integration.sh all"
  fi
  restore
}

case "$SECTION" in
  static) section_static ;;
  toggle) section_toggle ;;
  i18n) section_i18n ;;
  country) section_country ;;
  integration) section_integration ;;
  all) section_static; section_toggle; section_i18n; section_country; section_integration ;;
  *)
    echo "sección desconocida: $SECTION (static|toggle|i18n|country|integration|all)"
    exit 2
    ;;
esac

echo
if [ "$STRICT" = 1 ] && [ "$SKIPS" -gt 0 ]; then
  echo "$SKIPS comprobación(es) omitida(s): con --strict cuentan como fallo"
  FAILS=$((FAILS + SKIPS))
fi
if [ "$FAILS" -eq 0 ]; then
  if [ "$SKIPS" -gt 0 ]; then echo "TODO OK ($SKIPS omitida(s))"; else echo "TODO OK"; fi
else
  echo "$FAILS comprobación(es) fallida(s)"
fi
[ "$FAILS" -gt 255 ] && FAILS=255
exit "$FAILS"
