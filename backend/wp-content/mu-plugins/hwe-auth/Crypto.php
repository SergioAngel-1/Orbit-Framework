<?php
/**
 * Cifrado en reposo del secreto 2FA (AES-256-GCM autenticado). Sin clave configurada falla cerrado y lo registra.
 *
 * Es un trait de `HWE_Auth_Endpoints` (hwe-auth.php): la clase se parte por responsabilidad
 * para que cada archivo se lea de una vez. Los métodos usan `self::` y las constantes de la
 * clase que lo incluye.
 */

defined('ABSPATH') || exit;

trait HWE_Auth_Crypto {
    /**
     * Deriva la clave de cifrado de una constante de wp-config.php. SIN clave de
     * reserva: si ninguna está definida devuelve '' (falla cerrado) y lo registra.
     * Antes degradaba en silencio a una literal escrita en el código fuente, es
     * decir, pública e idéntica en cualquier instalación que cayera ahí, con lo que
     * el secreto TOTP quedaba cifrado con una clave predecible.
     */
    private static function cryptoKey(): string {
        if (defined('HWE_2FA_KEY') && HWE_2FA_KEY) {
            $src = HWE_2FA_KEY;
        } elseif (defined('AUTH_KEY') && AUTH_KEY) {
            $src = AUTH_KEY;
        } elseif (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && GRAPHQL_JWT_AUTH_SECRET_KEY) {
            $src = GRAPHQL_JWT_AUTH_SECRET_KEY;
        } else {
            error_log('[hwe] auth: no hay ninguna clave definida (HWE_2FA_KEY, AUTH_KEY o GRAPHQL_JWT_AUTH_SECRET_KEY): el secreto 2FA no se puede cifrar ni descifrar y la operación falla.');
            return '';
        }
        return hash('sha256', 'hwe-2fa:' . $src, true); // 32 bytes
    }

    private static function encryptSecret(string $plain): string {
        if ($plain === '') {
            return '';
        }
        $key = self::cryptoKey();
        if ($key === '') {
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
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
        $key = self::cryptoKey();
        if ($key === '') {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return ($pt === false) ? '' : $pt;
    }
}
