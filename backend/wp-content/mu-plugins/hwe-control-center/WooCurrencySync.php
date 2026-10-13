<?php

namespace HWE\ControlCenter;

/**
 * Sincroniza la moneda nativa de WooCommerce (`woocommerce_currency`, Ajustes
 * → General → Moneda) con `ecommerce.currency` de este panel.
 *
 * Sin esto, la moneda que ve el frontend (`getSiteConfig()`) puede quedar
 * desalineada de la que reporta la Store API (`cart.totals.currency_code`,
 * que WooCommerce deriva de su propio ajuste) y de la que reciben las
 * pasarelas de pago — el carrito/checkout mostraría una moneda distinta al
 * resto del sitio, y el pedido podría informarse a la pasarela en la moneda
 * por defecto de WooCommerce en vez de la configurada aquí.
 */
class WooCurrencySync {

    public static function register(): void {
        add_action('admin_notices', [self::class, 'maybeWarnDivergence']);
    }

    public static function sync(string $currency): void {
        if ($currency === '') {
            return;
        }

        if (get_option('woocommerce_currency') !== $currency) {
            if (!update_option('woocommerce_currency', $currency)) {
                error_log(sprintf(
                    '[hwe] control-center: no se pudo sincronizar woocommerce_currency a "%s" (update_option falló).',
                    $currency
                ));
            }
        }
    }

    /**
     * Ambos ajustes son independientes: si un admin cambia la moneda nativa directo en
     * WooCommerce → Ajustes → General sin pasar por este panel, quedan divergentes hasta
     * el próximo guardado del Control Center. Antes esto pasaba inadvertido; ahora se
     * avisa en wp-admin.
     */
    public static function maybeWarnDivergence(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $configured = (string) Storage::get(['ecommerce', 'currency'], '');
        $native     = (string) get_option('woocommerce_currency', '');
        if ($configured === '' || $native === '' || $configured === $native) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                'HWE: la moneda de HWE Control Center ("%s") y la moneda nativa de WooCommerce ("%s") están desalineadas. El carrito puede mostrar una moneda distinta al resto del sitio. Guarda de nuevo la configuración en Control Center para resincronizar, o revisa cuál de las dos es la correcta.',
                $configured,
                $native
            ))
        );
    }
}
