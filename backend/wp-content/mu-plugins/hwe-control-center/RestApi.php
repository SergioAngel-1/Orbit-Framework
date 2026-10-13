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
 * en el esquema, nunca secretos. Además incluye dos valores calculados al vuelo:
 * `modules` (id → activo, según si cada plugin del catálogo está activo: la fuente
 * de verdad para gatear la UI y las rutas de cada módulo) y
 * `ecommerce.price_decimals` (los decimales de precio de WooCommerce).
 */
class RestApi {

    private const NAMESPACE = 'hwe/v1';
    private const ROUTE     = '/config';

    public static function register(): void {
        add_action('rest_api_init', static fn() => self::registerRoute());
        // Los decimales viajan en la config pública: si se cambian en WooCommerce →
        // Ajustes → General, el frontend debe enterarse sin esperar a la ventana ISR.
        add_action('update_option_woocommerce_price_num_decimals', static fn() => Revalidation::trigger());
    }

    /** Decimales de precio de WooCommerce, o null sin WooCommerce. */
    public static function priceDecimals(): ?int {
        if (!function_exists('wc_get_price_decimals')) {
            return null;
        }
        $decimals = (int) wc_get_price_decimals();
        return $decimals >= 0 && $decimals <= 20 ? $decimals : null;
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

        // Estado de los módulos (calculado, no almacenado): el frontend lo usa para
        // gatear la UI y las rutas BFF de cada módulo (`isModuleEnabled`/`requireModule`).
        $public['modules'] = Modules::activeMap();

        // Decimales de precio de WooCommerce (calculados, no almacenados): el frontend
        // formatea los precios de catálogo con ellos. Sin WooCommerce, null (los de Intl).
        $public['ecommerce']['price_decimals'] = self::priceDecimals();

        // Icono del sitio de WordPress (Ajustes → Generales → Icono del sitio):
        // calculado, no almacenado. El frontend lo sirve como favicon, icono de
        // PWA y apple-touch; sin él, genera uno desde la marca.
        $siteIcon = function_exists('get_site_icon_url') ? get_site_icon_url() : '';
        $public['brand']['icon_url'] = is_string($siteIcon) && $siteIcon !== '' ? $siteIcon : null;

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
