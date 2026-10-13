<?php

namespace HWE\ControlCenter;

/**
 * GET /wp-json/hwe/v1/payments/credentials — solo para el BFF.
 *
 * Devuelve la pasarela elegida en el Control Center y las credenciales de cada pasarela,
 * con los secretos DESCIFRADOS. El BFF lo lee server-to-server al crear un cobro y al
 * verificar cada webhook (ver `lib/payments/credentials.ts` en el frontend, tarea 40).
 *
 * - Exige el secreto interno del BFF (`X-HWE-Internal-Secret`): sin él, 401 sin cuerpo útil.
 * - Nunca pasa por la config pública (`/hwe/v1/config`) ni por sus cachés.
 * - `provider` es el valor GUARDADO (null si nadie lo ha elegido nunca): el BFF decide
 *   entonces con su entorno, no con el default del esquema.
 * - Un campo vacío se devuelve vacío: el BFF cae a su variable de entorno.
 * - Se devuelven las credenciales de TODAS las pasarelas, no solo la activa: una
 *   notificación tardía de la anterior tras cambiar de pasarela sigue necesitando su
 *   secreto para verificarse.
 * - Añadir una pasarela = una entrada en `CREDENTIALS` (`text` sale de `Storage`, `secret`
 *   descifrado de `SecretsStorage`).
 */
class PaymentsInternalApi {

    private const NAMESPACE = 'hwe/v1';
    private const ROUTE     = '/payments/credentials';

    /**
     * Credenciales por pasarela: campo => fuente (`text` = público en `Storage`,
     * `secret` = cifrado en `SecretsStorage`). Debe coincidir con los grupos del esquema
     * (`Schema`: `payments.<pasarela>`).
     *
     * @var array<string,array<string,string>>
     */
    private const CREDENTIALS = [
        'wompi' => [
            'public_key'     => 'text',
            'secret_key'     => 'secret',
            'webhook_secret' => 'secret',
        ],
        'payu' => [
            'merchant_id'    => 'text',
            'account_id'     => 'text',
            'api_login'      => 'text',
            'api_key'        => 'secret',
            'webhook_secret' => 'secret',
        ],
        'bold' => [
            'api_key'       => 'secret',
            'integrity_key' => 'secret',
        ],
    ];

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'GET',
            'permission_callback' => static function (\WP_REST_Request $request): bool {
                return function_exists('hwe_has_internal_secret') && hwe_has_internal_secret($request);
            },
            'callback'            => [self::class, 'handleGet'],
        ]);
    }

    public static function handleGet(): \WP_REST_Response {
        $provider = Storage::get(['payments', 'provider']);

        $data = [
            'provider' => is_string($provider) && $provider !== '' ? $provider : null,
        ];
        foreach (self::CREDENTIALS as $gateway => $fields) {
            $creds = [];
            foreach ($fields as $field => $source) {
                $creds[$field] = $source === 'secret'
                    ? SecretsStorage::get(['payments', $gateway, $field])
                    : self::text(['payments', $gateway, $field]);
            }
            $data[$gateway] = $creds;
        }

        $response = new \WP_REST_Response($data, 200);
        // Secretos: ni proxies ni navegadores deben guardar esta respuesta.
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /** @param list<string> $path */
    private static function text(array $path): string {
        $value = Storage::get($path);
        return is_string($value) ? trim($value) : '';
    }
}
