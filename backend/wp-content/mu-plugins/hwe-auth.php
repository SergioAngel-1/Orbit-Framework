<?php
/**
 * Plugin Name: HWE Auth Endpoints
 * Description: Endpoints REST personalizados para recuperación de contraseña, verificación de email, 2FA y datos de usuario.
 */

defined('ABSPATH') || exit;

class HWE_Auth_Endpoints {
    private const NAMESPACE = 'hwe/v1';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void {
        // 1. Forgot password — envía email con enlace de reseteo
        register_rest_route(self::NAMESPACE, '/auth/forgot-password', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleForgotPassword'],
            'permission_callback' => '__return_true',
            'args'                => [
                'user_login'   => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'frontend_url' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        // 2. Reset password — valida key y establece nueva contraseña
        register_rest_route(self::NAMESPACE, '/auth/reset-password', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleResetPassword'],
            'permission_callback' => '__return_true',
            'args'                => [
                'key'           => ['required' => true, 'type' => 'string'],
                'login'         => ['required' => true, 'type' => 'string'],
                'new_password'  => ['required' => true, 'type' => 'string', 'validate_callback' => function ($v) { return strlen($v) >= 8 && strlen($v) <= 200; }],
            ],
        ]);

        // 3. Send verification email
        register_rest_route(self::NAMESPACE, '/auth/send-verification', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleSendVerification'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'verification_url' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        // 4. Verify email with token
        register_rest_route(self::NAMESPACE, '/auth/verify-email', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleVerifyEmail'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'token' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // 5. 2FA status (unauthenticated — solo dice si está habilitado)
        register_rest_route(self::NAMESPACE, '/auth/2fa-status/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGet2faStatus'],
            'permission_callback' => '__return_true',
            'args'                => [
                'user_id' => ['required' => true, 'type' => 'integer', 'validate_callback' => function ($v) { return is_numeric($v) && (int) $v > 0; }],
            ],
        ]);

        // 6. Get 2FA secret (authenticated)
        register_rest_route(self::NAMESPACE, '/auth/2fa-secret', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGet2faSecret'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // 7. Update 2FA secret (authenticated)
        register_rest_route(self::NAMESPACE, '/auth/2fa-secret', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'handleUpdate2faSecret'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'secret' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        // 8. Current user info (authenticated)
        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGetMe'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    // ---- Handlers ----

    public static function handleForgotPassword(WP_REST_Request $request): WP_REST_Response {
        $user_login  = $request->get_param('user_login');
        $frontend_url = untrailingslashit($request->get_param('frontend_url'));

        $user = get_user_by('login', $user_login);
        if (!$user) {
            $user = get_user_by('email', $user_login);
        }
        if (!$user) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $reset_link = add_query_arg(
            ['key' => rawurlencode($key), 'login' => rawurlencode($user->user_login)],
            $frontend_url . '/reset-password'
        );

        $subject = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES) . ' — Restablecer contraseña';
        $message = "Hola {$user->display_name},\n\n";
        $message .= "Recibiste este correo porque solicitaste restablecer tu contraseña.\n\n";
        $message .= "Haz clic en el siguiente enlace para crear una nueva contraseña:\n";
        $message .= $reset_link . "\n\n";
        $message .= "Si no solicitaste este cambio, ignora este mensaje.\n";
        $message .= "Este enlace expirará en 24 horas.\n";

        wp_mail($user->user_email, $subject, $message);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleResetPassword(WP_REST_Request $request): WP_REST_Response {
        $key         = $request->get_param('key');
        $login       = $request->get_param('login');
        $new_password = $request->get_param('new_password');

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            $code = $user->get_error_code();
            return new WP_REST_Response(
                ['error' => $code === 'expired_key' ? 'El enlace ha expirado.' : 'El enlace no es válido.'],
                400
            );
        }

        reset_password($user, $new_password);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleSendVerification(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        $already_verified = get_user_meta($user_id, 'hwe_email_verified', true);
        if ($already_verified) {
            return new WP_REST_Response(['error' => 'El email ya está verificado.'], 400);
        }

        $verification_url = $request->get_param('verification_url');
        $user = get_userdata($user_id);

        // Generate random verification token
        $token = bin2hex(random_bytes(32));
        update_user_meta($user_id, 'hwe_email_verification_token', $token);

        $verify_link = add_query_arg('token', rawurlencode($token), $verification_url);
        $subject = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES) . ' — Verifica tu email';
        $message = "Hola {$user->display_name},\n\n";
        $message .= "Gracias por crear tu cuenta. Haz clic en el siguiente enlace para verificar tu dirección de email:\n\n";
        $message .= $verify_link . "\n\n";
        $message .= "Si no creaste esta cuenta, ignora este mensaje.\n";

        wp_mail($user->user_email, $subject, $message);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleVerifyEmail(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        $token      = $request->get_param('token');
        $stored     = get_user_meta($user_id, 'hwe_email_verification_token', true);

        if (!$stored || !hash_equals($stored, $token)) {
            return new WP_REST_Response(['error' => 'Token inválido o expirado.'], 400);
        }

        update_user_meta($user_id, 'hwe_email_verified', '1');
        delete_user_meta($user_id, 'hwe_email_verification_token');

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleGet2faStatus(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int) $request->get_param('user_id');
        $secret  = get_user_meta($user_id, 'hwe_2fa_secret', true);
        return new WP_REST_Response(['enabled' => !empty($secret)], 200);
    }

    public static function handleGet2faSecret(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $secret  = get_user_meta($user_id, 'hwe_2fa_secret', true);
        return new WP_REST_Response([
            'secret'  => $secret ?: null,
            'enabled' => !empty($secret),
        ], 200);
    }

    public static function handleUpdate2faSecret(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $secret  = $request->get_param('secret');

        if ($secret === null || $secret === '') {
            delete_user_meta($user_id, 'hwe_2fa_secret');
            update_user_meta($user_id, 'hwe_2fa_enabled', '0');
        } else {
            update_user_meta($user_id, 'hwe_2fa_secret', sanitize_text_field($secret));
            update_user_meta($user_id, 'hwe_2fa_enabled', '1');
        }

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

HWE_Auth_Endpoints::register();
