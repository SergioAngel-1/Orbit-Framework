<?php
/**
 * Plugin Name: HWE — Endpoints solo para el BFF
 * Description: GraphQL, la Store API de WooCommerce, /hwe/v1/auth/*, /hwe/v1/consent/*,
 *              /hwe/v1/loyalty/* y /hwe/v1/memberships/* solo atienden al BFF (Next.js), que se
 *              identifica con el secreto interno compartido (cabecera X-HWE-Internal-Secret), o a
 *              WP-CLI. Cualquier otra petición recibe un 404, llegue por la ruta o por parámetro.
 * Author:      Sergio Jauregui
 * Version:     2.1.0
 *
 * POR QUÉ AQUÍ Y NO EN CADDY
 * --------------------------
 * Bloquear POR RUTA en el proxy no basta en WordPress: la misma petición entra también
 * por parámetro, en cualquier URL que llegue a PHP.
 *
 *   <dominio>/?graphql&query=…                      → GraphQL respondía
 *   <dominio>/wp-json/?rest_route=/wc/store/v1/cart → la Store API respondía
 *
 * Lo primero es lo grave: la mutación `login` de GraphQL emite un token sin pasar por el
 * 2FA del BFF, y `/?graphql` no casa con ninguna regla de `rate-limit.php` (que busca
 * `/graphql` o `/wp-json/` en la URL), así que se podían probar contraseñas sin límite.
 * Filtrar el parámetro en el proxy tampoco cierra el hueco: PHP convierte `rest.route` o
 * `rest route` en `rest_route`, y WordPress lee también el cuerpo del POST.
 *
 * Por eso se decide aquí, sobre lo que WordPress YA ha interpretado:
 *   · GraphQL: en el filtro con el que WPGraphQL decide que una petición es suya
 *     (`graphql_is_graphql_http_request`), cubra la ruta o el parámetro.
 *   · REST: en `rest_pre_dispatch`, con la ruta ya resuelta, llegue por `/wp-json/…` o
 *     por `?rest_route=`. Cada sub-petición de `/batch/v1` pasa también por este filtro.
 *
 * CÓMO SE IDENTIFICA EL BFF
 * -------------------------
 * Con un secreto compartido: el BFF añade `X-HWE-Internal-Secret` con el valor de
 * `HWE_REVALIDATION_SECRET` (la misma constante que ya usa el Control Center para firmar la
 * revalidación) en TODAS sus llamadas a WordPress. Es una credencial, no una suposición
 * sobre la red: la heurística de red privada (sin X-Forwarded-For y desde una IP privada) confía en
 * cualquier contenedor, VPN o proxy mal configurado que comparta red con WordPress.
 *
 * La comprobación vive en `hwe-bff-secret.php` (compartida con `rate-limit.php`, donde las
 * llamadas del BFF no consumen el presupuesto por IP).
 *
 * Si `HWE_REVALIDATION_SECRET` no está definida no hay forma de identificar al BFF: FALLA
 * CERRADO (solo atiende WP-CLI) y se registra, en vez de abrir la puerta.
 *
 * LA EXCEPCIÓN DE LA INTEGRACIÓN
 * -------------------------------
 * El módulo `external-integration` abre GraphQL (y SOLO GraphQL) a las IPs de
 * `HWE_INTEGRATION_ALLOWED_IPS`. La puerta de red vive aquí, junto a las demás; lo que puede
 * pedir quien pasa lo decide el módulo (`hwe_integration_policy`). Falla cerrado: sin el módulo
 * activo (`hwe_integration_gate_enabled`) una IP de la lista no abre nada, y la Store API y
 * `hwe/v1/auth|consent` siguen cerradas también para ella.
 *
 * Qué NO cambia: el panel de administración no usa nada de esto (WooCommerce Admin es
 * `wc/v3`, `wc-admin` y `wc-analytics`, que siguen igual y se protegen con sus claves).
 * Sí deja de funcionar lo que dependía de llamar a estas rutas desde un navegador: el IDE
 * GraphiQL de WPGraphQL y los bloques de carrito de WooCommerce en el editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

// La comprobación del secreto vive en un archivo compartido con `rate-limit.php`:
// las dos puertas tienen que aplicar exactamente la misma regla (ver su cabecera).
require_once __DIR__ . '/hwe-bff-secret.php';

/**
 * Heurística de red de la versión anterior: llamada interna = sin `X-Forwarded-For` y
 * desde una IP privada o de loopback. Caddy añade SIEMPRE `X-Forwarded-For` con la IP
 * real del cliente y `mod_remoteip` la vuelca en `REMOTE_ADDR`, que entonces es pública.
 *
 * Solo se consulta con `HWE_BFF_TRUST_PRIVATE_NETWORK` activada (ver abajo).
 */
function hwe_bff_only_is_private_network_call(): bool
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return false;
    }
    $remote = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '';
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) {
        return false;
    }
    return !filter_var(
        $remote,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * ¿Se confía en la red privada como identificación? DESACTIVADO por defecto: es una
 * suposición sobre la red, no una credencial. Solo para entornos cerrados donde el BFF no
 * puede enviar el secreto (`define('HWE_BFF_TRUST_PRIVATE_NETWORK', true);`).
 */
function hwe_bff_only_trusts_private_network(): bool
{
    return defined('HWE_BFF_TRUST_PRIVATE_NETWORK')
        && filter_var(HWE_BFF_TRUST_PRIVATE_NETWORK, FILTER_VALIDATE_BOOLEAN);
}

/** ¿Quien llama es el BFF (o WP-CLI)? */
function hwe_bff_only_is_trusted_call(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    if (hwe_bff_only_has_valid_secret()) {
        return true;
    }
    return hwe_bff_only_trusts_private_network() && hwe_bff_only_is_private_network_call();
}

/**
 * ¿Llama un integrador externo autorizado? = el módulo `external-integration` está activo
 * (`hwe_integration_gate_enabled`) Y el socket viene de una IP de `HWE_INTEGRATION_ALLOWED_IPS`
 * (IPs exactas, separadas por espacios o comas; sin CIDR).
 *
 * Se mira `REMOTE_ADDR` y NO `X-Forwarded-For`: el proxy (Caddy) fija este último con la IP real
 * del cliente y `mod_remoteip` la vuelca en `REMOTE_ADDR`, mientras que una cabecera que mande
 * el cliente a mano no pasa de ser texto. Sin la constante, o vacía, nadie entra.
 *
 * Esta función SOLO abre la puerta de GraphQL. Qué puede hacer quien pasa lo deciden el rol y la
 * política del módulo.
 */
function hwe_bff_only_is_integration_call(): bool
{
    if (!apply_filters('hwe_integration_gate_enabled', false)) {
        return false;
    }
    if (!defined('HWE_INTEGRATION_ALLOWED_IPS')) {
        return false;
    }
    $allowed = preg_split('/[\s,]+/', trim((string) HWE_INTEGRATION_ALLOWED_IPS), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($allowed)) {
        return false;
    }
    $remote = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '';
    return $remote !== '' && in_array($remote, $allowed, true);
}

/** ¿Es una ruta REST que solo debe atender al BFF? */
function hwe_bff_only_is_bff_rest_route(string $route): bool
{
    return str_starts_with($route, '/wc/store')
        || str_starts_with($route, '/hwe/v1/auth/')
        || str_starts_with($route, '/hwe/v1/consent/')
        || str_starts_with($route, '/hwe/v1/loyalty/')
        || str_starts_with($route, '/hwe/v1/memberships/');
}

/** Termina la petición. Aislado para que los tests puedan sustituirlo (`exit` mataría PHPUnit). */
function hwe_bff_only_terminate(): void
{
    exit;
}

/** Responde como una ruta inexistente: no confirma que el endpoint exista. */
function hwe_bff_only_deny_graphql(): void
{
    status_header(404);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo '{"errors":[{"message":"Not Found"}]}';
    hwe_bff_only_terminate();
}

/* ---------------------------------------------------------------------------
 * GraphQL
 * ------------------------------------------------------------------------ */
add_filter(
    'graphql_is_graphql_http_request',
    static function ($isGraphql) {
        if (!$isGraphql || hwe_bff_only_is_trusted_call() || hwe_bff_only_is_integration_call()) {
            return $isGraphql;
        }
        hwe_bff_only_deny_graphql();
        return $isGraphql;
    },
    PHP_INT_MAX
);

/* ---------------------------------------------------------------------------
 * REST: Store API (carrito/checkout) y autenticación/consentimiento propios
 * ------------------------------------------------------------------------ */
add_filter(
    'rest_pre_dispatch',
    static function ($result, $server, $request) {
        // Solo peticiones HTTP a la API: un `rest_do_request()` interno de WordPress
        // (precargas del editor, p. ej.) no es un cliente.
        if (null !== $result || !(defined('REST_REQUEST') && REST_REQUEST)) {
            return $result;
        }
        if (!hwe_bff_only_is_bff_rest_route((string) $request->get_route())
            || hwe_bff_only_is_trusted_call()) {
            return $result;
        }
        // El mismo error que una ruta inexistente.
        return new WP_Error(
            'rest_no_route',
            __('No route was found matching the URL and request method.'),
            ['status' => 404]
        );
    },
    0,
    3
);
