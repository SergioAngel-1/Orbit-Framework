<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\Walkers\DefaultsWalker;
use HWE\ControlCenter\Walkers\PublicConfigWalker;

/**
 * API REST pública: GET /wp-json/hwe/v1/config
 *
 * Devuelve la configuración pública efectiva (por defecto + almacenada),
 * filtrada para excluir secretos y campos internos. El frontend la consume
 * con ISR (Next.js `fetch` + `revalidate` + tag `site-config`).
 *
 * No requiere autenticación: solo expone datos marcados como `'public' => true`
 * en el esquema, nunca secretos.
 */
class RestApi {

    private const NAMESPACE = 'hwe/v1';
    private const ROUTE     = '/config';

    public static function register(): void {
        add_action('rest_api_init', static fn() => self::registerRoute());
    }

    private static function registerRoute(): void {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGet'],
            'permission_callback' => '__return_true',
            'args'                => [],
        ]);
    }

    public static function handleGet(\WP_REST_Request $request): \WP_REST_Response {
        $schema   = Schema::get();
        $stored   = Storage::getAll();
        $defaults = (new DefaultsWalker())->walk($schema);
        $merged   = self::deepMerge($defaults, $stored);

        // Extraemos solo campos públicos (nunca secretos).
        $public   = (new PublicConfigWalker())->walk($schema, $merged);

        $response = new \WP_REST_Response($public, 200);

        // Cabeceras de caché: ISR + CDN puede cachear 5 min; el tag invalidará.
        $response->header('Cache-Control', 'public, max-age=300, s-maxage=300, stale-while-revalidate=600');
        $response->header('X-HWE-Config-Version', (string) time());

        return $response;
    }

    private static function deepMerge(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } elseif ($value !== null) {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
