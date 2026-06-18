<?php

namespace HWE\ControlCenter;

/**
 * Persistencia cifrada de secretos (claves de pasarela, SMTP, etc.).
 *
 * Usa AES-256-CBC con una clave derivada del secreto JWT compartido.
 * Los secretos NUNCA aparecen en la API REST pública ni en el HTML.
 * La UI de admin muestra un campo de contraseña vacío; si se deja en blanco
 * al guardar, el valor existente se conserva.
 */
class SecretsStorage {

    private const OPTION_KEY = 'hwe_config_secrets';

    /** Devuelve todos los secretos descifrados (solo para uso interno). */
    public static function getAll(): array {
        $stored = (array) get_option(self::OPTION_KEY, []);
        return self::decryptTree($stored);
    }

    /** Reemplaza todos los secretos (los cifra antes de guardar). */
    public static function setAll(array $data): void {
        update_option(self::OPTION_KEY, self::encryptTree($data), false);
    }

    /**
     * Fusiona en profundidad omitiendo entradas vacías.
     * Si un valor en $newData es '', no sobreescribe el existente.
     */
    public static function mergeNonEmpty(array $newData): void {
        $existing = self::getAll();
        $merged   = self::deepMergeNonEmpty($existing, $newData);
        self::setAll($merged);
    }

    /**
     * Devuelve un secreto por ruta, o el fallback si no existe.
     *
     * @param list<string> $path P. ej. ['payments','wompi','secret_key'].
     */
    public static function get(array $path, string $default = ''): string {
        $current = self::getAll();
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }
        return is_string($current) ? $current : $default;
    }

    // -------------------------------------------------------------------------
    // Cifrado / descifrado
    // -------------------------------------------------------------------------

    private static function encryptionKey(): string {
        $source = '';
        if (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && GRAPHQL_JWT_AUTH_SECRET_KEY) {
            $source = GRAPHQL_JWT_AUTH_SECRET_KEY;
        } elseif (defined('AUTH_KEY') && AUTH_KEY) {
            $source = AUTH_KEY;
        } else {
            $source = 'hwe-fallback-insecure-key';
        }
        return hash('sha256', 'hwe-secrets:' . $source, true);
    }

    private static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }
        $key = self::encryptionKey();
        $iv  = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    private static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }
        $key  = self::encryptionKey();
        $raw  = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $result     = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return ($result === false) ? '' : $result;
    }

    private static function encryptTree(array $tree): array {
        $result = [];
        foreach ($tree as $key => $value) {
            $result[$key] = is_array($value)
                ? self::encryptTree($value)
                : self::encrypt((string) $value);
        }
        return $result;
    }

    private static function decryptTree(array $tree): array {
        $result = [];
        foreach ($tree as $key => $value) {
            $result[$key] = is_array($value)
                ? self::decryptTree($value)
                : self::decrypt((string) $value);
        }
        return $result;
    }

    private static function deepMergeNonEmpty(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMergeNonEmpty($base[$key], $value);
            } elseif ($value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
