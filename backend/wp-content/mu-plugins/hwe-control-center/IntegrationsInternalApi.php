<?php

namespace HWE\ControlCenter;

/**
 * GET /wp-json/hwe/v1/integrations/<id> — solo para el BFF.
 *
 * Credenciales de integraciones que el navegador no debe ver (DESCIFRADAS). Cada
 * integración futura sigue este patrón con su propio id (`meta-capi` es la primera).
 *
 * - Exige el secreto interno del BFF (`X-HWE-Internal-Secret`): sin él, 401 sin cuerpo útil.
 * - Nunca pasa por la config pública (`/hwe/v1/config`) ni por sus cachés.
 *
 * `meta-capi`: token de la Conversions API de Meta y código de prueba. El píxel (su id)
 * sigue en la config pública (el navegador lo necesita); el token no, y por eso vive aquí,
 * igual que las credenciales de las pasarelas (`PaymentsInternalApi`).
 */
class IntegrationsInternalApi {

    private const NAMESPACE = 'hwe/v1';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void {
        register_rest_route(self::NAMESPACE, '/integrations/meta-capi', [
            'methods'             => 'GET',
            'permission_callback' => static function (\WP_REST_Request $request): bool {
                return function_exists('hwe_has_internal_secret') && hwe_has_internal_secret($request);
            },
            'callback'            => [self::class, 'handleMetaCapi'],
        ]);
    }

    public static function handleMetaCapi(): \WP_REST_Response {
        $testCode = Storage::get(['integrations', 'meta_capi_test_event_code']);

        $response = new \WP_REST_Response([
            'access_token'    => SecretsStorage::get(['integrations', 'meta_capi_token']),
            'test_event_code' => is_string($testCode) ? trim($testCode) : '',
        ], 200);
        // Secreto: ni proxies ni navegadores deben guardar esta respuesta.
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
