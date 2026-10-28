<?php
/**
 * 2FA: estado, secreto cifrado, códigos de recuperación y verificación TOTP (RFC 6238).
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_TwoFactor {
    /**
     * Marca de la migración que invalida los códigos de recuperación del formato
     * antiguo (40 bits, `sha256` sin pimienta). Ver `migrateRecoveryCodesV2()`.
     */
    private const RECOVERY_MIGRATION_OPTION = 'hwe_2fa_recovery_v2_migrated';

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

    /**
     * Activa o desactiva el 2FA. Ambas operaciones exigen PRUEBA de posesión del
     * segundo factor: un access-token robado no basta por sí solo para activar o
     * desactivar el 2FA de la víctima. (WordPress solo atiende al BFF —`hwe-bff-only`—,
     * pero la prueba de posesión no depende de ello: es la defensa propia del endpoint.)
     *
     *  - Activar: `code` TOTP válido para el `secret` que se guarda.
     *  - Desactivar (`secret` vacío): `code` válido para el secreto YA activo, o un
     *    `recovery_code` sin consumir.
     */
    public static function handleUpdate2faSecret(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $secret  = $request->get_param('secret');
        $code    = $request->get_param('code');
        $stored  = (string) get_user_meta($user_id, 'hwe_2fa_secret', true);

        if ($secret === null || $secret === '') {
            $recovery_code = $request->get_param('recovery_code');

            $reauthed = false;
            if (is_string($recovery_code) && $recovery_code !== '') {
                $reauthed = self::verifyAndConsumeRecoveryCode($user_id, $recovery_code);
            } elseif (is_string($code) && $code !== '') {
                $active   = self::decryptSecret($stored);
                $reauthed = $active !== '' && self::verifyTotp($active, $code);
            }

            if (!$reauthed) {
                return new WP_REST_Response(['error' => 'Código de verificación inválido.'], 403);
            }

            // Desactivar 2FA: limpiar secreto y códigos de recuperación.
            delete_user_meta($user_id, 'hwe_2fa_secret');
            delete_user_meta($user_id, 'hwe_2fa_recovery');
            update_user_meta($user_id, 'hwe_2fa_enabled', '0');
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Con el 2FA ya activo NO se puede registrar otro secreto encima: con un
        // token robado, el atacante aportaría SU secreto (y su código válido) y se
        // quedaría con el segundo factor de la víctima. Para cambiarlo hay que
        // desactivarlo antes, lo que sí exige prueba del factor actual.
        if ($stored !== '') {
            return new WP_REST_Response(['error' => 'El 2FA ya está activo. Desactívalo antes de volver a configurarlo.'], 409);
        }

        // Activar: prueba de que quien llama controla el autenticador, no solo un
        // access-token robado.
        $secret = sanitize_text_field((string) $secret);
        if (!is_string($code) || $code === '' || !self::verifyTotp($secret, $code)) {
            return new WP_REST_Response(['error' => 'Código de verificación inválido.'], 403);
        }

        $encrypted = self::encryptSecret($secret);
        if ($encrypted === '') {
            // No se guarda un secreto "cifrado" vacío como si fuera válido.
            return new WP_REST_Response(['error' => 'No se pudo activar 2FA: falta configurar una clave de cifrado en el servidor.'], 500);
        }

        // 128 bits por código (16 bytes en hex), mostrados en grupos de 4. El
        // verificador es un HMAC con clave derivada de un secreto del servidor:
        // una copia de la base no permite recorrer el espacio offline.
        $codes  = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES_COUNT; $i++) {
            $recovery = bin2hex(random_bytes(16)); // 32 hex chars
            $hash     = self::recoveryCodeHash($recovery);
            if ($hash === '') {
                return new WP_REST_Response(['error' => 'No se pudo activar 2FA: falta configurar una clave de cifrado en el servidor.'], 500);
            }
            $codes[]  = implode('-', str_split($recovery, 4));
            $hashes[] = $hash;
        }

        update_user_meta($user_id, 'hwe_2fa_secret', $encrypted);
        update_user_meta($user_id, 'hwe_2fa_enabled', '1');
        update_user_meta($user_id, 'hwe_2fa_recovery', $hashes);

        // Los códigos en claro se devuelven UNA sola vez (no se vuelven a mostrar).
        return new WP_REST_Response(['ok' => true, 'recovery_codes' => $codes], 200);
    }

    public static function handleConsumeRecoveryCode(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $raw     = (string) $request->get_param('code');

        if (!self::verifyAndConsumeRecoveryCode($user_id, $raw)) {
            $hashes = get_user_meta($user_id, 'hwe_2fa_recovery', true);
            if (!is_array($hashes) || empty($hashes)) {
                return new WP_REST_Response(['error' => 'No hay códigos de recuperación.'], 400);
            }
            return new WP_REST_Response(['error' => 'Código de recuperación inválido.'], 401);
        }

        $remaining = get_user_meta($user_id, 'hwe_2fa_recovery', true);
        return new WP_REST_Response(['ok' => true, 'remaining' => is_array($remaining) ? count($remaining) : 0], 200);
    }

    /**
     * Verificador de un código de recuperación: HMAC-SHA-256 con una pimienta
     * derivada de la clave de servidor (`cryptoKey()`, fuera de la base de datos).
     * Sin clave devuelve '' y, por tanto, la verificación falla cerrada.
     *
     * El SHA-256 desnudo anterior permitía recomputar el hash desde `wp_usermeta`
     * y recorrer los 2^40 códigos offline. Con la pimienta, sin la clave del
     * servidor no se puede fabricar el verificador.
     */
    private static function recoveryCodeHash(string $raw): string {
        $key = self::cryptoKey();
        if ($key === '') {
            return '';
        }
        $pepper = hash_hmac('sha256', 'hwe-2fa-recovery-pepper', $key, true);
        return hash_hmac('sha256', strtolower($raw), $pepper);
    }

    /**
     * Invalida una sola vez los códigos de recuperación del formato antiguo
     * (40 bits, `sha256` sin pimienta). Los códigos nuevos exigen re-alta; el
     * TOTP sigue funcionando. Idempotente: la opción marca la migración hecha y
     * no se vuelve a ejecutar (si no, borraría los códigos nuevos en cada carga).
     */
    public static function migrateRecoveryCodesV2(): void {
        // En una instalación NUEVA, WP-CLI carga los mu-plugins y dispara `init` antes
        // de que existan `wp_options`/`wp_usermeta`: leer o borrar metas ahí falla y
        // WordPress escribe errores de «tabla inexistente» en el log de `setup.sh`.
        // No se marca nada: la migración se ejecuta en la primera carga ya instalada.
        if (function_exists('wp_installing') && wp_installing()) {
            return;
        }
        if (function_exists('is_blog_installed') && !is_blog_installed()) {
            return;
        }

        if (get_option(self::RECOVERY_MIGRATION_OPTION, false)) {
            return;
        }

        if (!function_exists('delete_metadata')) {
            // No marcamos la migración como hecha: se reintentará en la próxima carga.
            error_log('[hwe] auth: no se pudieron invalidar los códigos de recuperación 2FA antiguos: falta delete_metadata().');
            return;
        }

        // Borra `hwe_2fa_recovery` de todos los usuarios (y la caché de meta).
        delete_metadata('user', 0, 'hwe_2fa_recovery', '', true);
        if (!update_option(self::RECOVERY_MIGRATION_OPTION, '1', true)) {
            // Sin la marca, la migración se reintenta en la próxima carga.
            error_log('[hwe] auth: no se pudo marcar la migración de códigos de recuperación 2FA; se reintentará.');
        }
    }

    /**
     * Verifica un código de recuperación 2FA y, si es válido, lo consume (lo
     * elimina de la lista para que no pueda reutilizarse).
     */
    private static function verifyAndConsumeRecoveryCode(int $user_id, string $raw): bool {
        $raw = strtolower(str_replace('-', '', trim($raw)));
        // Solo el formato nuevo (32 hex = 128 bits). Un código antiguo de 10 hex
        // no llega a compararse: se rechaza sin consumir nada.
        if (!preg_match('/^[0-9a-f]{32}$/', $raw)) {
            return false;
        }

        $hashes = get_user_meta($user_id, 'hwe_2fa_recovery', true);
        if (!is_array($hashes) || empty($hashes)) {
            return false;
        }

        $candidate = self::recoveryCodeHash($raw);
        if ($candidate === '') {
            return false;
        }

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
            return false;
        }

        update_user_meta($user_id, 'hwe_2fa_recovery', $remaining);
        return true;
    }

    // ------------------------------------------------------------------------
    //  TOTP (RFC 6238)
    // ------------------------------------------------------------------------

    /**
     * Verifica un código TOTP (RFC 6238: HMAC-SHA1, pasos de 30 s, 6 dígitos: los
     * mismos parámetros que `otplib` en el frontend) contra un secreto base32, con
     * una ventana de ±1 paso para tolerar el desfase de reloj del cliente.
     *
     * Público para poder probarlo con los vectores oficiales; `$at` fija la hora
     * (segundos Unix) y por defecto es la actual.
     */
    public static function verifyTotp(string $secretBase32, string $code, ?int $at = null): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = self::base32Decode($secretBase32);
        if ($key === '') {
            return false;
        }
        $step = (int) floor(($at ?? time()) / 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if ($step + $offset < 0) {
                continue;
            }
            if (hash_equals(self::totpAt($key, $step + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    /** Código TOTP de 6 dígitos para un contador (paso de 30 s) y una clave binaria. */
    public static function totpAt(string $key, int $counter): string {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true); // contador de 8 bytes, big-endian
        $off  = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$off]) & 0x7F) << 24) |
            ((ord($hash[$off + 1]) & 0xFF) << 16) |
            ((ord($hash[$off + 2]) & 0xFF) << 8) |
            (ord($hash[$off + 3]) & 0xFF)
        );
        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Decodifica base32 (RFC 4648): ignora mayúsculas, espacios y relleno. */
    public static function base32Decode(string $b32): string {
        $b32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($b32) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $bytes .= chr((int) bindec($byte));
        }
        return $bytes;
    }
}
