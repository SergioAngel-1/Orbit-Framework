<?php

namespace HWE\ControlCenter;

/**
 * Sincroniza el país base nativo de WooCommerce (`woocommerce_default_country`,
 * Ajustes → General → «País/región de la tienda») con `ecommerce.country` de este panel.
 *
 * Sin esto, una tienda de otro país seguía con `ES` en WooCommerce: los impuestos y las
 * tarifas de envío se calculan contra el país equivocado aunque el frontend enseñe el
 * correcto. El valor nativo puede llevar provincia (`CO:ANT`): si el país ya coincide no se
 * toca, para conservar la provincia que haya puesto el dueño.
 */
class WooCountrySync {

    public static function sync(string $country): void {
        // Solo códigos ISO de dos letras MAYÚSCULAS; vacío o texto libre no se tocan.
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return;
        }

        $native        = (string) get_option('woocommerce_default_country', '');
        $nativeCountry = explode(':', $native, 2)[0];

        if ($nativeCountry === $country) {
            return;
        }

        if (!update_option('woocommerce_default_country', $country)) {
            error_log(sprintf(
                '[hwe] control-center: no se pudo sincronizar woocommerce_default_country a "%s" (update_option falló).',
                $country
            ));
        }
    }
}
