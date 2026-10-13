<?php

namespace HWE\ControlCenter;

/**
 * Sincroniza la pasarela nativa de WooCommerce «Contra reembolso» (`cod`,
 * Ajustes → Pagos) con `payments.cash_enabled` de este panel: es el pago en
 * efectivo al recibir el pedido. WooCommerce la trae de serie; no hace falta
 * un gateway propio.
 *
 * Al activarla se fijan además dos ajustes suyos, porque con los de fábrica
 * falla en headless con «not available for this order»:
 *   - `enable_for_methods` vacío: sin restringir a ciertos métodos de envío
 *     (las tarifas del módulo `shipping` no tienen por qué coincidir con ellos).
 *   - `enable_for_virtual` = yes: también sin envío (p. ej. un producto virtual).
 * Título y descripción se rellenan solo si están vacíos: el operador puede
 * haberlos personalizado en WooCommerce.
 *
 * Mismo patrón que `WooCurrencySync` y `WooGuestCheckoutSync`.
 */
class WooCashGatewaySync {

    private const OPTION = 'woocommerce_cod_settings';

    public static function register(): void {
        add_action('admin_notices', [self::class, 'maybeWarnDivergence']);
    }

    public static function configuredEnabled(): bool {
        return (bool) Storage::get(['payments', 'cash_enabled'], false);
    }

    private static function nativeEnabled(): bool {
        $settings = get_option(self::OPTION);
        return is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes';
    }

    public static function sync(): void {
        $enabled  = self::configuredEnabled();
        $settings = get_option(self::OPTION);
        $settings = is_array($settings) ? $settings : [];
        $next     = $settings;

        $next['enabled'] = $enabled ? 'yes' : 'no';
        if ($enabled) {
            $next['enable_for_methods'] = [];
            $next['enable_for_virtual'] = 'yes';
            if (trim((string) ($next['title'] ?? '')) === '') {
                $next['title'] = 'Pago en efectivo';
            }
            if (trim((string) ($next['description'] ?? '')) === '') {
                $next['description'] = 'Pagas en efectivo al recibir tu pedido.';
            }
        }

        if ($next === $settings) {
            return;
        }
        if (!update_option(self::OPTION, $next)) {
            error_log('[hwe] control-center: no se pudo sincronizar woocommerce_cod_settings (update_option falló).');
        }
    }

    public static function maybeWarnDivergence(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $configured = self::configuredEnabled();
        if ($configured === self::nativeEnabled()) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                'HWE: HWE Control Center → Pasarelas de pago tiene el pago en efectivo %s, pero la pasarela «Contra reembolso» de WooCommerce está %s. Guarda de nuevo la configuración en Control Center para resincronizarlas.',
                $configured ? 'activado' : 'desactivado',
                $configured ? 'desactivada' : 'activada'
            ))
        );
    }
}
