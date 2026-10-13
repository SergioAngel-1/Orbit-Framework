<?php
/**
 * Plugin Name: HWE Auth Endpoints
 * Description: Endpoints REST personalizados para recuperación de contraseña, verificación de email (con expiración), 2FA (secreto cifrado en reposo + códigos de recuperación) y datos de usuario.
 */

defined('ABSPATH') || exit;

// La clase se reparte por responsabilidad en traits de hwe-auth/ (misma API pública).
require_once __DIR__ . '/hwe-auth/Access.php';
require_once __DIR__ . '/hwe-auth/Mail.php';
require_once __DIR__ . '/hwe-auth/Crypto.php';
require_once __DIR__ . '/hwe-auth/Account.php';
require_once __DIR__ . '/hwe-auth/Phone.php';
require_once __DIR__ . '/hwe-auth/TwoFactor.php';
require_once __DIR__ . '/hwe-auth/Session.php';

class HWE_Auth_Endpoints {
    use HWE_Auth_Access;
    use HWE_Auth_Mail;
    use HWE_Auth_Crypto;
    use HWE_Auth_Account;
    use HWE_Auth_Phone;
    use HWE_Auth_TwoFactor;
    use HWE_Auth_Session;

    private const NAMESPACE = 'hwe/v1';

    /** Validez del token de verificación de email (segundos). */
    private const EMAIL_TOKEN_TTL = 86400; // 24 h

    /** Número de códigos de recuperación 2FA generados al activar. */
    private const RECOVERY_CODES_COUNT = 10;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        // El plugin JWT no compara el secreto de un refresh token con el vigente:
        // sin esto, rotarlo (logout-all, cambio de contraseña) no cierra ninguna sesión.
        add_filter('graphql_jwt_auth_validate_token', [self::class, 'enforceRefreshTokenSecret']);
    }

    public static function registerRoutes(): void {
        // 1. Forgot password — envía email con enlace de reseteo
        register_rest_route(self::NAMESPACE, '/auth/forgot-password', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleForgotPassword'],
            'permission_callback' => '__return_true',
            'args'                => [
                'user_login'   => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                // Solo orígenes de la allowlist headless: sin esto el enlace de
                // reseteo (con una clave REAL) podía apuntar al dominio de un atacante.
                'frontend_url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => [self::class, 'isAllowedFrontendOrigin'],
                ],
                // URL ya localizada de la página de restablecer (`/en/reset-password`):
                // la decide el BFF, que sabe en qué idioma navega el visitante. Misma
                // allowlist de orígenes. Sin ella, `<frontend_url>/reset-password`.
                'reset_url'    => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => [self::class, 'isOptionalAllowedFrontendUrl'],
                ],
                'locale'       => self::localeArg(),
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
                'verification_url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => [self::class, 'isAllowedFrontendOrigin'],
                ],
                'locale'           => self::localeArg(),
            ],
        ]);

        // 4. Verify email with token
        // Sin sesión: el enlace del correo se abre muchas veces en otro navegador o
        // dispositivo. El token (aleatorio, 256 bits) identifica al usuario. Solo el
        // BFF llega aquí (hwe-bff-only) y el BFF lo limita por IP.
        register_rest_route(self::NAMESPACE, '/auth/verify-email', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleVerifyEmail'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => static fn ($v) => is_string($v) && (bool) preg_match('/^[a-f0-9]{64}$/', $v),
                ],
            ],
        ]);

        // Login y registro por teléfono (`auth.phone_login_enabled`): solo el BFF, con
        // el secreto interno, y solo con el flag encendido.
        foreach ([
            '/auth/phone-available' => 'handlePhoneAvailable',
            '/auth/resolve-login'   => 'handleResolveLogin',
            '/auth/attach-profile'  => 'handleAttachProfile',
        ] as $route => $handler) {
            register_rest_route(self::NAMESPACE, $route, [
                'methods'             => 'POST',
                'callback'            => [self::class, $handler],
                'permission_callback' => [self::class, 'permitPhoneLogin'],
                'args'                => [
                    'phone' => [
                        'required'          => true,
                        'type'              => 'string',
                        'validate_callback' => [self::class, 'isE164'],
                    ],
                ],
            ]);
        }

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
        //    Activar y desactivar exigen PRUEBA de posesión: `code` (TOTP) y, para
        //    desactivar, alternativamente `recovery_code`. Este endpoint es
        //    alcanzable sin pasar por el BFF, así que un token robado no basta.
        register_rest_route(self::NAMESPACE, '/auth/2fa-secret', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'handleUpdate2faSecret'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'secret'        => ['required' => false, 'type' => ['string', 'null']],
                'code'          => ['required' => false, 'type' => 'string'],
                'recovery_code' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        // 7b. Cambiar la contraseña de quien llama. Solo el BFF, con sesión: WordPress
        //     verifica la contraseña ACTUAL y fija la nueva. No se usa `wp/v2/users/<id>`
        //     porque `security.php` retira esa ruta a quien no puede listar usuarios.
        register_rest_route(self::NAMESPACE, '/auth/change-password', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleChangePassword'],
            'permission_callback' => [self::class, 'permitInternalWithSession'],
            'args'                => [
                'current_password' => ['required' => true, 'type' => 'string'],
                'new_password'     => ['required' => true, 'type' => 'string'],
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

}

HWE_Auth_Endpoints::register();

/*
 * Registro headless: la mutación `registerUser` de WPGraphQL llama a `register_new_user()`,
 * cuyo hook por defecto (`wp_send_new_user_notifications`) manda al CLIENTE el correo
 * nativo «Login Details»: texto plano, sin marca y con un enlace a wp-login.php del dominio
 * de WordPress para «establecer contraseña», que ya eligió en el formulario. El correo de
 * bienvenida real es el de verificación (`/auth/send-verification`, lo pide el BFF tras el
 * alta). El aviso al ADMIN de «nuevo usuario» se conserva.
 */
function hwe_auth_notify_new_user_admin_only($user_id): void {
    wp_send_new_user_notifications((int) $user_id, 'admin');
}
remove_action('register_new_user', 'wp_send_new_user_notifications');
add_action('register_new_user', 'hwe_auth_notify_new_user_admin_only');
