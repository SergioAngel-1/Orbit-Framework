#!/bin/sh
# ============================================================================
#  verify-security.sh — Comprueba, con tráfico HTTP REAL, que la pila en marcha (dev o
#  prod) está cerrada como debe. Idempotente: no cambia nada (lo único «con efecto» es
#  revalidar una caché, inocuo).
#
#  Por qué existe: los tests unitarios simulan `fetch` y `$_SERVER`; no pueden decir si la
#  pila REAL responde 404 a GraphQL o si un webhook firmado atraviesa el proxy. Esas
#  propiedades solo se ven con las capas puestas (WordPress + mu-plugins + Caddy + Next).
#
#  Qué comprueba:
#    1. WordPress solo atiende al BFF (`hwe-bff-only`): GraphQL, la Store API y
#       `hwe/v1/auth` responden 404 SIN el secreto interno, por ruta y por parámetro
#       (`/?graphql`, `?rest_route=`), y con un secreto incorrecto. Con el secreto
#       correcto GraphQL SÍ responde: sin ese control positivo, los 404 de una pila rota
#       parecerían «cerrada».
#    2. Webhooks: uno firmado (HWE y WooCommerce) atraviesa el proxy sin `Origin`; uno sin
#       firma o con firma falsa se rechaza; una escritura normal sin Origin se rechaza.
#    3. El login de un usuario con 2FA no entrega tokens (ni cookies de sesión) antes del
#       segundo factor.
#    4. Cabeceras de seguridad del frontend (CSP sin generadores de QR externos, nosniff,
#       X-Frame-Options, Referrer-Policy, Permissions-Policy, sin X-Powered-By) y, con
#       PUBLIC_URL, las de las rutas que Caddy sirve directo desde WordPress.
#
#  Uso:
#    WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 \
#    HWE_REVALIDATION_SECRET=… WC_WEBHOOK_SECRET=… \
#    VERIFY_2FA_USERNAME=… VERIFY_2FA_PASSWORD=… \
#      sh backend/scripts/verify-security.sh [--strict]
#
#  Variables:
#    WP_URL          WordPress directo (por defecto http://localhost:8080)
#    BFF_URL         Frontend / BFF      (por defecto http://localhost:3000)
#    PUBLIC_URL      (opcional) dominio público tras Caddy, en producción: activa las
#                    cabeceras de las rutas de WordPress y HSTS
#    BFF_ORIGIN      Origin que acepta el BFF (ALLOWED_ORIGIN); por defecto BFF_URL
#    HWE_REVALIDATION_SECRET   secreto interno (control positivo y firma de webhooks HWE)
#    WC_WEBHOOK_SECRET         firma de los webhooks de WooCommerce
#    VERIFY_2FA_USERNAME / VERIFY_2FA_PASSWORD   usuario CON 2FA activo (se crea con
#                    /account/security: no se puede automatizar sin TOTP)
#
#  Lo que falta un secreto/usuario se OMITE y se dice; con --strict, omitir cuenta como
#  fallo (úsalo en la aceptación). Salida: 0 todo bien · 1 algún fallo · 2 la pila no
#  responde · 64 uso incorrecto.
#
#  Los secretos nunca se imprimen y viajan por archivo o stdin, no en los argumentos de
#  curl. (`openssl dgst -hmac` sí recibe la clave como argumento, un instante: ejecútalo
#  en tu máquina o en un host de confianza, no en uno compartido.)
# ============================================================================
set -u

STRICT=0
for arg in "$@"; do
	case "$arg" in
		--strict) STRICT=1 ;;
		-h | --help)
			sed -n '2,/^# =====/p' "$0" | sed 's/^# \{0,1\}//' | sed '$d'
			exit 0
			;;
		*)
			echo "Opción desconocida: $arg (usa --strict o --help)" >&2
			exit 64
			;;
	esac
done

strip_slash() { printf '%s' "${1%/}"; }

WP_URL="$(strip_slash "${WP_URL:-http://localhost:8080}")"
BFF_URL="$(strip_slash "${BFF_URL:-http://localhost:3000}")"
PUBLIC_URL="$(strip_slash "${PUBLIC_URL:-}")"
BFF_ORIGIN="$(strip_slash "${BFF_ORIGIN:-$BFF_URL}")"
SECRET="${HWE_REVALIDATION_SECRET:-}"
WC_SECRET="${WC_WEBHOOK_SECRET:-}"
USER_2FA="${VERIFY_2FA_USERNAME:-}"
PASS_2FA="${VERIFY_2FA_PASSWORD:-}"

umask 077
TMP="$(mktemp -d)"
trap 'rm -f "$TMP/body" "$TMP/headers" "$TMP/err" "$TMP/hsecret" "$TMP/jar" "$TMP/payload"; rmdir "$TMP" 2>/dev/null' EXIT

PASS=0
FAIL=0
SKIP=0

ok() {
	PASS=$((PASS + 1))
	echo "  ✓ $*"
}
bad() {
	FAIL=$((FAIL + 1))
	echo "  ✗ $*"
}
skip() {
	SKIP=$((SKIP + 1))
	echo "  ○ $* (omitida)"
}
section() { printf '\n%s\n' "$*"; }

# http MÉTODO URL [argumentos de curl…]
#   Deja el código en $HTTP_CODE, el cuerpo en $TMP/body y las cabeceras en $TMP/headers.
http() {
	_m="$1"
	_u="$2"
	shift 2
	: >"$TMP/body"
	: >"$TMP/headers"
	HTTP_CODE="$(curl -sS -m 15 -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$_m" "$@" "$_u" 2>"$TMP/err")" || HTTP_CODE="000"
}

# header_of NOMBRE → valor de esa cabecera de la última respuesta (vacío si no está).
header_of() {
	awk -v want="$(printf '%s' "$1" | tr 'A-Z' 'a-z')" '
		{ line = $0; sub(/\r$/, "", line); name = line; sub(/:.*/, "", name) }
		tolower(name) == want { sub(/^[^:]*:[ \t]*/, "", line); print line; exit }
	' "$TMP/headers"
}

body_has() { grep -q -- "$1" "$TMP/body"; }

# expect_404 DESCRIPCIÓN MÉTODO URL [curl…] — la petición, SIN el secreto, tiene que dar 404.
expect_404() {
	_desc="$1"
	shift
	http "$@"
	if [ "$HTTP_CODE" = "404" ]; then
		ok "$_desc → 404"
	else
		bad "$_desc → HTTP $HTTP_CODE (debería ser 404): WordPress atiende sin el secreto interno"
	fi
}

# hmac_hex CLAVE CUERPO / hmac_b64 CLAVE CUERPO
hmac_hex() { printf '%s' "$2" | openssl dgst -sha256 -hmac "$1" 2>/dev/null | sed 's/^.* //'; }
hmac_b64() { printf '%s' "$2" | openssl dgst -sha256 -hmac "$1" -binary 2>/dev/null | openssl base64 -A 2>/dev/null; }

HAVE_OPENSSL=0
command -v openssl >/dev/null 2>&1 && HAVE_OPENSSL=1

echo "verify-security.sh — comprobaciones de seguridad de la pila"
echo "  WordPress: $WP_URL"
echo "  BFF:       $BFF_URL"
[ -n "$PUBLIC_URL" ] && echo "  Público:   $PUBLIC_URL"

# ── 0. La pila está viva ─────────────────────────────────────────────────────
# Sin esto, un WordPress caído respondería «404»/error a todo y parecería cerrado.
section "0. La pila responde"
http GET "$WP_URL/wp-json/hwe/v1/config"
if [ "$HTTP_CODE" = "200" ]; then
	ok "WordPress responde (hwe/v1/config → 200)"
else
	bad "WordPress no responde en $WP_URL (hwe/v1/config → HTTP $HTTP_CODE): ¿está la pila levantada?"
	echo "RESULTADO: $PASS correctas, $FAIL fallos, $SKIP omitidas" >&2
	exit 2
fi
http GET "$BFF_URL/api/health/live"
if [ "$HTTP_CODE" = "200" ]; then
	ok "El BFF responde (/api/health/live → 200)"
else
	bad "El BFF no responde en $BFF_URL (/api/health/live → HTTP $HTTP_CODE): ¿está el frontend levantado?"
	echo "RESULTADO: $PASS correctas, $FAIL fallos, $SKIP omitidas" >&2
	exit 2
fi

# ── 1. WordPress solo atiende al BFF ─────────────────────────────────────────
section "1. WordPress solo atiende al BFF (hwe-bff-only)"
GQL='{"query":"{ __typename }"}'
expect_404 "GraphQL por parámetro (/?graphql)" GET "$WP_URL/?graphql&query=%7B__typename%7D"
expect_404 "GraphQL por ruta (POST /graphql)" POST "$WP_URL/graphql" -H 'Content-Type: application/json' --data-binary "$GQL"
expect_404 "Store API por parámetro (?rest_route=/wc/store)" GET "$WP_URL/wp-json/?rest_route=/wc/store/v1/cart"
expect_404 "Store API por ruta (/wp-json/wc/store)" GET "$WP_URL/wp-json/wc/store/v1/cart"
expect_404 "hwe/v1/auth por ruta" GET "$WP_URL/wp-json/hwe/v1/auth/me"
expect_404 "GraphQL con un secreto INCORRECTO" POST "$WP_URL/graphql" -H 'X-HWE-Internal-Secret: secreto-incorrecto' -H 'Content-Type: application/json' --data-binary "$GQL"

if [ -n "$SECRET" ]; then
	printf 'X-HWE-Internal-Secret: %s\n' "$SECRET" >"$TMP/hsecret"
	http POST "$WP_URL/graphql" -H "@$TMP/hsecret" -H 'Content-Type: application/json' --data-binary "$GQL"
	if [ "$HTTP_CODE" = "200" ] && body_has '"data"'; then
		ok "Control positivo: con el secreto correcto GraphQL SÍ responde (los 404 de arriba son del mu-plugin, no de una pila rota)"
	else
		bad "Control positivo: con el secreto correcto GraphQL no responde (HTTP $HTTP_CODE): los 404 de arriba no prueban nada. ¿Coincide HWE_REVALIDATION_SECRET en wp-config.php y en el frontend?"
	fi
else
	skip "Control positivo (sin HWE_REVALIDATION_SECRET no se puede comprobar que con el secreto SÍ responde)"
fi

# ── 2. Webhooks y barrera de Origin ──────────────────────────────────────────
section "2. Webhooks firmados y barrera de Origin (a través del proxy)"
HWE_BODY='{"tag":"site-config"}'
WC_BODY='{"id":1}'

if [ -n "$SECRET" ] && [ "$HAVE_OPENSSL" = 1 ]; then
	SIG="sha256=$(hmac_hex "$SECRET" "$HWE_BODY")"
	http POST "$BFF_URL/api/revalidate" -H "X-HWE-Signature: $SIG" -H 'Content-Type: application/json' --data-binary "$HWE_BODY"
	if [ "$HTTP_CODE" = "200" ]; then
		ok "Webhook HWE firmado atraviesa el proxy sin Origin → 200"
	else
		bad "Webhook HWE firmado → HTTP $HTTP_CODE (debería ser 200): el proxy bloquea una ruta que se autentica por firma"
	fi
else
	skip "Webhook HWE firmado (necesita HWE_REVALIDATION_SECRET y openssl)"
fi

if [ -n "$WC_SECRET" ] && [ "$HAVE_OPENSSL" = 1 ]; then
	SIG="$(hmac_b64 "$WC_SECRET" "$WC_BODY")"
	http POST "$BFF_URL/api/revalidate" -H "X-WC-Webhook-Signature: $SIG" -H 'Content-Type: application/json' --data-binary "$WC_BODY"
	if [ "$HTTP_CODE" = "200" ]; then
		ok "Webhook de WooCommerce firmado atraviesa el proxy sin Origin → 200"
	else
		bad "Webhook de WooCommerce firmado → HTTP $HTTP_CODE (debería ser 200)"
	fi
else
	skip "Webhook de WooCommerce firmado (necesita WC_WEBHOOK_SECRET y openssl)"
fi

http POST "$BFF_URL/api/revalidate" -H 'Content-Type: application/json' --data-binary "$HWE_BODY"
if [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "403" ]; then
	ok "Un webhook sin firma se rechaza → $HTTP_CODE"
else
	bad "Un webhook sin firma → HTTP $HTTP_CODE (debería rechazarse): /api/revalidate acepta peticiones sin firma"
fi

http POST "$BFF_URL/api/revalidate" -H 'X-HWE-Signature: sha256=0000000000000000000000000000000000000000000000000000000000000000' -H 'Content-Type: application/json' --data-binary "$HWE_BODY"
if [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "403" ]; then
	ok "Un webhook con firma falsa se rechaza → $HTTP_CODE"
else
	bad "Un webhook con firma falsa → HTTP $HTTP_CODE (debería rechazarse)"
fi

http POST "$BFF_URL/api/store/cart/items" -H 'Content-Type: application/json' --data-binary '{"id":1,"quantity":1}'
if [ "$HTTP_CODE" = "403" ]; then
	ok "Una escritura normal sin Origin ni CSRF se rechaza → 403"
else
	bad "Una escritura sin Origin ni CSRF → HTTP $HTTP_CODE (debería ser 403): la barrera de Origin no actúa. ¿Está ALLOWED_ORIGIN definida?"
fi

# ── 3. Login con 2FA ─────────────────────────────────────────────────────────
section "3. El login con 2FA no entrega tokens antes del segundo factor"
if [ -n "$USER_2FA" ] && [ -n "$PASS_2FA" ]; then
	http GET "$BFF_URL/api/csrf" -c "$TMP/jar"
	CSRF="$(sed -n 's/.*"csrfToken"[ ]*:[ ]*"\([^"]*\)".*/\1/p' "$TMP/body")"
	if [ "$HTTP_CODE" != "200" ] || [ -z "$CSRF" ]; then
		bad "No se pudo obtener el token CSRF (/api/csrf → HTTP $HTTP_CODE): no se puede probar el login"
	else
		# Credenciales por stdin (no en los argumentos de curl).
		printf '{"username":"%s","password":"%s"}' "$USER_2FA" "$PASS_2FA" |
			curl -sS -m 15 -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X POST \
				-b "$TMP/jar" -H "Origin: $BFF_ORIGIN" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
				--data-binary @- "$BFF_URL/api/auth/login" >"$TMP/payload" 2>"$TMP/err" || printf '000' >"$TMP/payload"
		HTTP_CODE="$(cat "$TMP/payload")"

		LEAKED=""
		grep -Eq '"(authToken|refreshToken|accessToken)"' "$TMP/body" && LEAKED="el cuerpo trae authToken/refreshToken"
		grep -Eq 'eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.' "$TMP/body" && LEAKED="el cuerpo contiene un JWT"
		if grep -i '^set-cookie:' "$TMP/headers" | grep -viq 'hwe_csrf'; then
			LEAKED="${LEAKED:+$LEAKED y }la respuesta fija una cookie de sesión"
		fi

		if [ "$HTTP_CODE" != "200" ]; then
			bad "Login del usuario con 2FA → HTTP $HTTP_CODE (debería ser 200 con requires_2fa): ¿credenciales de prueba correctas? (rate-limit del login: 5/min)"
		elif [ -n "$LEAKED" ]; then
			bad "Login con 2FA: $LEAKED ANTES del segundo factor (cualquiera podría saltarse el TOTP)"
		elif ! body_has '"requires_2fa"[ ]*:[ ]*true' || ! body_has '"ephemeralToken"'; then
			bad "Login con 2FA: la respuesta no pide el segundo factor (requires_2fa ausente): ¿el usuario de prueba tiene el 2FA activo?"
		else
			ok "Login con 2FA → requires_2fa + id opaco, sin tokens ni cookies de sesión"
		fi
	fi
else
	skip "Login con 2FA (sin VERIFY_2FA_USERNAME / VERIFY_2FA_PASSWORD de un usuario con 2FA activo)"
fi

# ── 4. Cabeceras de seguridad ────────────────────────────────────────────────
section "4. Cabeceras de seguridad"
http GET "$BFF_URL/"
if [ "$HTTP_CODE" != "200" ]; then
	bad "No se pudo leer la portada del frontend (HTTP $HTTP_CODE): no se comprueban sus cabeceras"
else
	MISSING=""
	for h in Content-Security-Policy X-Content-Type-Options X-Frame-Options Referrer-Policy Permissions-Policy; do
		[ -n "$(header_of "$h")" ] || MISSING="$MISSING $h"
	done
	if [ -n "$MISSING" ]; then
		bad "Frontend: cabecera ausente:$MISSING"
	else
		ok "Frontend: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy y Permissions-Policy presentes"
	fi

	NOSNIFF="$(header_of X-Content-Type-Options | tr 'A-Z' 'a-z')"
	XFO="$(header_of X-Frame-Options | tr 'a-z' 'A-Z')"
	if [ -n "$NOSNIFF" ] && [ "$NOSNIFF" != "nosniff" ]; then
		bad "Frontend: X-Content-Type-Options es «$NOSNIFF» (debería ser nosniff)"
	fi
	if [ -n "$XFO" ] && [ "$XFO" != "DENY" ]; then
		bad "Frontend: X-Frame-Options es «$XFO» (debería ser DENY)"
	fi

	if header_of Content-Security-Policy | grep -qi 'qrserver'; then
		bad "Frontend: la CSP permite api.qrserver.com — el secreto TOTP del alta de 2FA podría salir a un tercero"
	elif [ -n "$(header_of Content-Security-Policy)" ]; then
		ok "Frontend: la CSP no permite generadores de QR externos (api.qrserver.com)"
	fi

	if [ -n "$(header_of X-Powered-By)" ]; then
		bad "Frontend: X-Powered-By expuesto ($(header_of X-Powered-By)): revela la tecnología del servidor"
	else
		ok "Frontend: sin X-Powered-By"
	fi

	case "$BFF_URL" in
		https://*)
			if [ -n "$(header_of Strict-Transport-Security)" ]; then
				ok "Frontend: HSTS presente"
			else
				bad "Frontend: sin Strict-Transport-Security sobre HTTPS"
			fi
			;;
	esac
fi

if [ -n "$PUBLIC_URL" ]; then
	http GET "$PUBLIC_URL/wp-login.php"
	if [ "$HTTP_CODE" != "200" ]; then
		bad "WordPress tras Caddy: /wp-login.php → HTTP $HTTP_CODE: no se comprueban sus cabeceras"
	else
		WPMISSING=""
		[ "$(header_of X-Content-Type-Options | tr 'A-Z' 'a-z')" = "nosniff" ] || WPMISSING="$WPMISSING X-Content-Type-Options"
		[ -n "$(header_of Referrer-Policy)" ] || WPMISSING="$WPMISSING Referrer-Policy"
		[ "$(header_of X-Frame-Options | tr 'a-z' 'A-Z')" = "SAMEORIGIN" ] || WPMISSING="$WPMISSING X-Frame-Options(SAMEORIGIN)"
		case "$PUBLIC_URL" in
			https://*) [ -n "$(header_of Strict-Transport-Security)" ] || WPMISSING="$WPMISSING Strict-Transport-Security" ;;
		esac
		if [ -n "$WPMISSING" ]; then
			bad "Cabeceras de seguridad ausentes en las rutas de WordPress (/wp-login.php):$WPMISSING"
		else
			ok "Cabeceras de seguridad presentes en las rutas que sirve WordPress (/wp-login.php)"
		fi
	fi
else
	skip "Cabeceras de las rutas de WordPress (sin PUBLIC_URL: dominio público tras Caddy)"
fi

# ── Resumen ──────────────────────────────────────────────────────────────────
echo ""
echo "RESULTADO: $PASS correctas, $FAIL fallos, $SKIP omitidas"
if [ "$FAIL" -gt 0 ]; then
	exit 1
fi
if [ "$STRICT" = 1 ] && [ "$SKIP" -gt 0 ]; then
	echo "--strict: las comprobaciones omitidas cuentan como fallo." >&2
	exit 1
fi
exit 0
