<?php

namespace HWE\ControlCenter;

/**
 * Sincroniza el ajuste nativo de WooCommerce «Permitir a los clientes realizar
 * pedidos sin una cuenta» (`woocommerce_enable_guest_checkout`, Ajustes →
 * Cuentas y privacidad) con `ecommerce.checkout_login_required` de este panel.
 *
 * El BFF es quien aplica la regla (la página de checkout y `/api/store/checkout` leen el
 * flag de `getSiteConfig()`), pero la Store API también la hace cumplir por su cuenta: con
 * el ajuste en `no` rechaza un checkout sin usuario siempre que el registro desde el
 * checkout esté apagado, que es el caso headless. Tenerlos alineados es defensa en
 * profundidad, y evita el caso contrario: con el flag desactivado aquí y el ajuste nativo
 * en `no`, todo pedido de invitado fallaría con un 403 de WooCommerce.
 *
 * Mismo patrón que `WooCurrencySync`: una clase por ajuste nativo.
 */
class WooGuestCheckoutSync {

    private const OPTION = 'woocommerce_enable_guest_checkout';

    public static function register(): void {
        add_action('admin_notices', [self::class, 'maybeWarnDivergence']);
    }

    /** Valor nativo que corresponde al flag del panel (`yes`/`no`). */
    private static function expectedNative(bool $loginRequired): string {
        return $loginRequired ? 'no' : 'yes';
    }

    private static function configuredLoginRequired(): bool {
        return (bool) Storage::get(['ecommerce', 'checkout_login_required'], true);
    }

    /**
     * Se lee el valor YA fusionado en Storage, no el del formulario: un
     * `wp hwe setup` con un JSON que no trae el campo debe sincronizar lo que
     * hay guardado, no el default.
     */
    public static function sync(): void {
        $expected = self::expectedNative(self::configuredLoginRequired());
        if (get_option(self::OPTION) === $expected) {
            return;
        }
        if (!update_option(self::OPTION, $expected)) {
            error_log(sprintf(
                '[hwe] control-center: no se pudo sincronizar %s a "%s" (update_option falló).',
                self::OPTION,
                $expected
            ));
        }
    }

    /**
     * Un admin puede cambiar el ajuste directamente en WooCommerce sin pasar
     * por este panel; el frontend seguiría aplicando el flag del panel y la
     * Store API el suyo. Se avisa en wp-admin, igual que con la moneda.
     */
    public static function maybeWarnDivergence(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $loginRequired = self::configuredLoginRequired();
        $native        = (string) get_option(self::OPTION, '');
        if ($native === '' || $native === self::expectedNative($loginRequired)) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                'HWE: HWE Control Center → Tienda %s el inicio de sesión para comprar, pero WooCommerce (Ajustes → Cuentas y privacidad) %s pedidos sin cuenta. Guarda de nuevo la configuración en Control Center para resincronizarlos.',
                $loginRequired ? 'exige' : 'no exige',
                $native === 'yes' ? 'permite' : 'no permite'
            ))
        );
    }
}
