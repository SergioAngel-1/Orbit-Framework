<?php
/**
 * Login y registro por teléfono (`auth.phone_login_enabled`).
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Phone {
    // ------------------------------------------------------------------------
    //  Login y registro por teléfono (`auth.phone_login_enabled`)
    //
    //  El teléfono de la cuenta es el `billing_phone` de WooCommerce en E.164 y es
    //  único: el registro lo comprueba y `attach-profile` lo vuelve a comprobar.
    // ------------------------------------------------------------------------

    /** @return list<WP_User> */
    private static function usersByBillingPhone(string $phone): array {
        $users = get_users([
            'meta_key'   => 'billing_phone',
            'meta_value' => $phone,
            'number'     => 2,
            'fields'     => 'all',
        ]);
        return is_array($users) ? array_values($users) : [];
    }

    public static function handlePhoneAvailable(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(['available' => self::usersByBillingPhone((string) $request->get_param('phone')) === []], 200);
    }

    /**
     * Teléfono → identificador de login (el email, que es el más estable). Si el
     * teléfono no existe o lo comparten varias cuentas, 404: no se adivina.
     */
    public static function handleResolveLogin(WP_REST_Request $request): WP_REST_Response {
        $users = self::usersByBillingPhone((string) $request->get_param('phone'));
        if (count($users) !== 1) {
            return new WP_REST_Response(['error' => 'No encontrado.'], 404);
        }
        $user = $users[0];
        return new WP_REST_Response(['login' => $user->user_email ?: $user->user_login], 200);
    }

    /** Guarda teléfono y nombre de una cuenta recién creada. 409 si el teléfono ya es de otra. */
    public static function handleAttachProfile(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int) $request->get_param('user_id');
        $phone   = (string) $request->get_param('phone');
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new WP_REST_Response(['error' => 'Usuario no encontrado.'], 404);
        }

        foreach (self::usersByBillingPhone($phone) as $other) {
            if ((int) $other->ID !== $user_id) {
                return new WP_REST_Response(['error' => 'Teléfono en uso.'], 409);
            }
        }

        update_user_meta($user_id, 'billing_phone', $phone);
        $first = sanitize_text_field((string) ($request->get_param('first_name') ?? ''));
        $last  = sanitize_text_field((string) ($request->get_param('last_name') ?? ''));
        if ($first !== '' || $last !== '') {
            update_user_meta($user_id, 'first_name', $first);
            update_user_meta($user_id, 'last_name', $last);
            wp_update_user(['ID' => $user_id, 'display_name' => trim($first . ' ' . $last)]);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
