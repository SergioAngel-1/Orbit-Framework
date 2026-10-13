<?php
/**
 * Cierre de todas las sesiones y datos del usuario autenticado.
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Session {
    public static function handleLogoutAll(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        // Rotar el secreto JWT del usuario invalida sus refresh tokens. WPGraphQL
        // JWT Authentication lee este meta; si cambia, los tokens previos dejan de
        // poder renovarse. Los access tokens vivos caducan solos (corta duración),
        // y el BFF además los revoca vía blocklist.
        update_user_meta($user_id, 'graphql_jwt_auth_secret', wp_generate_password(64, true, true));

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleGetMe(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $user    = get_userdata($user_id);

        if (!$user) {
            return new WP_REST_Response(['error' => 'Usuario no encontrado.'], 404);
        }

        return new WP_REST_Response([
            'id'             => $user->ID,
            'email'          => $user->user_email,
            'display_name'   => $user->display_name,
            'email_verified' => (bool) get_user_meta($user_id, 'hwe_email_verified', true),
        ], 200);
    }
}
