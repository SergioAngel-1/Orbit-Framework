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

        // Rotar el secreto JWT del usuario invalida sus refresh tokens, pero SOLO porque
        // `enforceRefreshTokenSecret` los compara con él: el plugin WPGraphQL JWT
        // Authentication (0.7.0) no lo hace por sí mismo. Los access tokens vivos
        // caducan solos (corta duración), y el BFF además los revoca vía blocklist.
        update_user_meta($user_id, 'graphql_jwt_auth_secret', wp_generate_password(64, true, true));

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Filtro `graphql_jwt_auth_validate_token`: un refresh token solo vale si su
     * `user_secret` es el vigente del usuario.
     *
     * El plugin JWT solo mira el indicador «revocado» y nunca compara el secreto del
     * token con el guardado, así que tras `logout-all` o un cambio de contraseña el
     * refresh token anterior seguía renovando sesión durante los 365 días de su vida.
     * Un access token no lleva `user_secret` y pasa sin tocarse.
     *
     * @param mixed $token Token decodificado (objeto) o un `WP_Error` de un filtro anterior.
     * @return mixed El mismo token, o un `WP_Error` si el secreto ya no es el vigente.
     */
    public static function enforceRefreshTokenSecret($token) {
        if (!is_object($token) || is_wp_error($token)) {
            return $token;
        }
        $presented = $token->data->user->user_secret ?? null;
        if ($presented === null) {
            return $token;
        }

        $user_id = (int) ($token->data->user->id ?? 0);
        $current = $user_id > 0 ? get_user_meta($user_id, 'graphql_jwt_auth_secret', true) : '';
        if (!is_string($presented) || !is_string($current) || $current === '' || !hash_equals($current, $presented)) {
            return new WP_Error(
                'invalid-jwt',
                'The User Secret does not match or has been revoked for this user'
            );
        }

        return $token;
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
