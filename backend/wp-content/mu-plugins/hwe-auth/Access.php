<?php
/**
 * Permisos de las rutas de auth: secreto interno del BFF, teléfono y allowlist de orígenes del frontend (anti envenenamiento de enlaces).
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Access {
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

    /**
     * Rutas que actúan sobre la cuenta de quien llama (cambio de contraseña): el
     * secreto interno del BFF Y una sesión válida. `hwe-bff-only` ya devuelve 404 a
     * `hwe/v1/auth/*` sin el secreto; aquí se exige también por si esa capa falta.
     */
    public static function permitInternalWithSession(WP_REST_Request $request): bool {
        return self::hasInternalSecret($request) && is_user_logged_in();
    }

    /** Endpoints de teléfono: secreto interno del BFF y flag encendido. */
    public static function permitPhoneLogin(WP_REST_Request $request): bool {
        return self::hasInternalSecret($request) && self::phoneLoginEnabled();
    }

    public static function phoneLoginEnabled(): bool {
        return class_exists('HWE\\ControlCenter\\Storage')
            && (bool) HWE\ControlCenter\Storage::get(['auth', 'phone_login_enabled'], false);
    }

    /** Teléfono en formato E.164: `+` y de 8 a 15 cifras, sin empezar por 0. */
    public static function isE164($value): bool {
        return is_string($value) && (bool) preg_match('/^\+[1-9]\d{7,14}$/', $value);
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
    //  Origen del frontend (anti envenenamiento de enlaces)
    // ------------------------------------------------------------------------

    /**
     * Solo permite URLs cuyo origen (esquema + host + puerto) esté en la allowlist
     * de frontends headless (`hwe_allowed_origins()`: HEADLESS_ALLOWED_ORIGINS /
     * HEADLESS_FRONTEND_URL).
     *
     * Evita que /forgot-password y /send-verification sirvan para enviar enlaces de
     * reseteo o de verificación REALES hacia un dominio arbitrario controlado por un
     * atacante: pedir el reseteo de la víctima con un `frontend_url` propio hacía que
     * WordPress generase la clave y la enviase al correo legítimo con el enlace
     * apuntando al atacante (toma de cuenta).
     *
     * Las URLs con credenciales (`https://usuario@host/…`) se rechazan: es el truco
     * habitual para disfrazar el destino real de un enlace.
     *
     * @param mixed $url
     */
    public static function isAllowedFrontendOrigin($url): bool {
        if (!is_string($url) || $url === '') {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return in_array($origin, array_map('strtolower', hwe_allowed_origins()), true);
    }

    /** Como `isAllowedFrontendOrigin`, pero vacío (no enviado) también vale. */
    public static function isOptionalAllowedFrontendUrl($url): bool {
        return $url === null || $url === '' || self::isAllowedFrontendOrigin($url);
    }
}
