<?php
/**
 * Plugin Name: HWE Auth Endpoints
 * Description: Endpoints REST personalizados para recuperación de contraseña, verificación de email (con expiración), 2FA (secreto cifrado en reposo + códigos de recuperación) y datos de usuario.
 */

defined('ABSPATH') || exit;

class HWE_Auth_Endpoints {
    private const NAMESPACE = 'hwe/v1';

    /** Validez del token de verificación de email (segundos). */
    private const EMAIL_TOKEN_TTL = 86400; // 24 h

    /** Número de códigos de recuperación 2FA generados al activar. */
    private const RECOVERY_CODES_COUNT = 10;

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

        // 5. 2FA status — solo dice si está habilitado. Protegido: requiere el
        //    secreto interno del BFF (server-to-server) para evitar enumeración
        //    pública de qué usuarios tienen 2FA.
        register_rest_route(self::NAMESPACE, '/auth/2fa-status/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGet2faStatus'],
            'permission_callback' => [self::class, 'permitInternalOrSelf'],
            'args'                => [
                'user_id' => ['required' => true, 'type' => 'integer', 'validate_callback' => function ($v) { return is_numeric($v) && (int) $v > 0; }],
            ],
        ]);

        // 6. Get 2FA secret (authenticated) — devuelve el secreto DESCIFRADO.
        register_rest_route(self::NAMESPACE, '/auth/2fa-secret', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGet2faSecret'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // 7. Update 2FA secret (authenticated) — cifra antes de guardar y, al
        //    activar, genera y DEVUELVE (una sola vez) los códigos de recuperación.
        register_rest_route(self::NAMESPACE, '/auth/2fa-secret', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'handleUpdate2faSecret'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'secret' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        // 8. Consume a 2FA recovery code (authenticated). Lo usa el BFF cuando el
        //    usuario no tiene el dispositivo TOTP. Idempotente por código: cada
        //    código solo sirve una vez.
        register_rest_route(self::NAMESPACE, '/auth/2fa-recovery/verify', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleConsumeRecoveryCode'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'code' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // 9. Current user info (authenticated)
        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGetMe'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // 10. Logout de TODAS las sesiones: rota el secreto JWT del usuario, lo
        //     que invalida sus refresh tokens existentes (no se podrán renovar).
        register_rest_route(self::NAMESPACE, '/auth/logout-all', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleLogoutAll'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    // ------------------------------------------------------------------------
    //  Permisos
    // ------------------------------------------------------------------------

    /**
     * Permite la petición si trae el secreto interno del BFF (server-to-server)
     * o si el usuario autenticado consulta su propio id.
     */
    public static function permitInternalOrSelf(WP_REST_Request $request): bool {
        if (self::hasInternalSecret($request)) {
            return true;
        }
        $req_id = (int) $request['user_id'];
        return is_user_logged_in() && get_current_user_id() === $req_id;
    }

    private static function hasInternalSecret(WP_REST_Request $request): bool {
        if (!defined('HWE_REVALIDATION_SECRET') || !HWE_REVALIDATION_SECRET) {
            return false;
        }
        $provided = $request->get_header('x_hwe_internal_secret');
        if (!is_string($provided) || $provided === '') {
            return false;
        }
        return hash_equals((string) HWE_REVALIDATION_SECRET, $provided);
    }

    // ------------------------------------------------------------------------
    //  Cifrado en reposo del secreto 2FA (AES-256-GCM autenticado)
    // ------------------------------------------------------------------------

    private static function cryptoKey(): string {
        if (defined('HWE_2FA_KEY') && HWE_2FA_KEY) {
            $src = HWE_2FA_KEY;
        } elseif (defined('AUTH_KEY') && AUTH_KEY) {
            $src = AUTH_KEY;
        } elseif (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && GRAPHQL_JWT_AUTH_SECRET_KEY) {
            $src = GRAPHQL_JWT_AUTH_SECRET_KEY;
        } else {
            $src = 'hwe-insecure-2fa-key';
        }
        return hash('sha256', 'hwe-2fa:' . $src, true); // 32 bytes
    }

    private static function encryptSecret(string $plain): string {
        if ($plain === '') {
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            return '';
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    private static function decryptSecret(string $stored): string {
        if ($stored === '') {
            return '';
        }
        // Compatibilidad: valores legacy en texto plano (base32) se devuelven tal cual.
        if (strpos($stored, 'v1:') !== 0) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return ($pt === false) ? '' : $pt;
    }

    // ------------------------------------------------------------------------
    //  Handlers
    // ------------------------------------------------------------------------

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

        // Token aleatorio + marca temporal (para poder expirarlo).
        $token = bin2hex(random_bytes(32));
        update_user_meta($user_id, 'hwe_email_verification_token', $token);
        update_user_meta($user_id, 'hwe_email_verification_token_time', time());

        $verify_link = add_query_arg('token', rawurlencode($token), $verification_url);
        $subject = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES) . ' — Verifica tu email';
        $message = "Hola {$user->display_name},\n\n";
        $message .= "Gracias por crear tu cuenta. Haz clic en el siguiente enlace para verificar tu dirección de email:\n\n";
        $message .= $verify_link . "\n\n";
        $message .= "Este enlace expirará en 24 horas.\n";
        $message .= "Si no creaste esta cuenta, ignora este mensaje.\n";

        wp_mail($user->user_email, $subject, $message);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleVerifyEmail(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'Usuario no autenticado.'], 401);
        }

        $token   = $request->get_param('token');
        $stored  = get_user_meta($user_id, 'hwe_email_verification_token', true);
        $issued  = (int) get_user_meta($user_id, 'hwe_email_verification_token_time', true);

        if (!$stored || !hash_equals($stored, (string) $token)) {
            return new WP_REST_Response(['error' => 'Token inválido.'], 400);
        }

        // Expiración del token.
        if ($issued > 0 && (time() - $issued) > self::EMAIL_TOKEN_TTL) {
            delete_user_meta($user_id, 'hwe_email_verification_token');
            delete_user_meta($user_id, 'hwe_email_verification_token_time');
            return new WP_REST_Response(['error' => 'El enlace ha expirado. Solicita uno nuevo.'], 400);
        }

        update_user_meta($user_id, 'hwe_email_verified', '1');
        delete_user_meta($user_id, 'hwe_email_verification_token');
        delete_user_meta($user_id, 'hwe_email_verification_token_time');

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handleGet2faStatus(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int) $request->get_param('user_id');
        $secret  = get_user_meta($user_id, 'hwe_2fa_secret', true);
        return new WP_REST_Response(['enabled' => !empty($secret)], 200);
    }

    public static function handleGet2faSecret(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $stored  = (string) get_user_meta($user_id, 'hwe_2fa_secret', true);
        $secret  = self::decryptSecret($stored);
        return new WP_REST_Response([
            'secret'  => $secret !== '' ? $secret : null,
            'enabled' => $stored !== '',
        ], 200);
    }

    public static function handleUpdate2faSecret(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $secret  = $request->get_param('secret');

        if ($secret === null || $secret === '') {
            // Desactivar 2FA: limpiar secreto y códigos de recuperación.
            delete_user_meta($user_id, 'hwe_2fa_secret');
            delete_user_meta($user_id, 'hwe_2fa_recovery');
            update_user_meta($user_id, 'hwe_2fa_enabled', '0');
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Activar: cifrar el secreto y generar códigos de recuperación.
        update_user_meta($user_id, 'hwe_2fa_secret', self::encryptSecret(sanitize_text_field($secret)));
        update_user_meta($user_id, 'hwe_2fa_enabled', '1');

        $codes  = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES_COUNT; $i++) {
            $code     = strtolower(bin2hex(random_bytes(5))); // 10 hex chars
            $codes[]  = substr($code, 0, 5) . '-' . substr($code, 5);
            $hashes[] = hash('sha256', $code);
        }
        update_user_meta($user_id, 'hwe_2fa_recovery', $hashes);

        // Los códigos en claro se devuelven UNA sola vez (no se vuelven a mostrar).
        return new WP_REST_Response(['ok' => true, 'recovery_codes' => $codes], 200);
    }

    public static function handleConsumeRecoveryCode(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $raw     = strtolower(str_replace('-', '', (string) $request->get_param('code')));

        $hashes = get_user_meta($user_id, 'hwe_2fa_recovery', true);
        if (!is_array($hashes) || empty($hashes)) {
            return new WP_REST_Response(['error' => 'No hay códigos de recuperación.'], 400);
        }

        $candidate = hash('sha256', $raw);
        $matched   = false;
        $remaining = [];
        foreach ($hashes as $h) {
            if (!$matched && hash_equals((string) $h, $candidate)) {
                $matched = true; // consumimos este código (no lo conservamos)
                continue;
            }
            $remaining[] = $h;
        }

        if (!$matched) {
            return new WP_REST_Response(['error' => 'Código de recuperación inválido.'], 401);
        }

        update_user_meta($user_id, 'hwe_2fa_recovery', $remaining);
        return new WP_REST_Response(['ok' => true, 'remaining' => count($remaining)], 200);
    }

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

HWE_Auth_Endpoints::register();
