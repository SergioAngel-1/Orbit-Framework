<?php

namespace HWE\ControlCenter;

/**
 * Persistencia cifrada de secretos (claves de pasarela, SMTP, etc.).
 *
 * Los secretos NUNCA aparecen en la API REST pública ni en el HTML. La UI de admin
 * muestra un campo de contraseña vacío; si se deja en blanco al guardar, el valor
 * existente se conserva.
 *
 * Cifrado: AES-256-GCM autenticado (formato `v2:`). La clave se deriva de una
 * constante de wp-config.php y NO hay clave de reserva: si no hay ninguna,
 * cifrar y descifrar fallan cerrados y lo dicen en el log. Antes degradaba en
 * silencio a una literal escrita en este archivo, es decir, pública e idéntica en
 * cualquier instalación que cayera ahí, con lo que las credenciales de las
 * pasarelas de pago y del SMTP quedaban cifradas con una clave predecible.
 *
 * Sin clave NUNCA se escribe: guardar cadenas vacías encima de los secretos ya
 * guardados los destruiría para siempre aunque la clave solo faltara por una mala
 * configuración del entorno y se restaurase después.
 */
class SecretsStorage {

    private const OPTION_KEY = 'hwe_config_secrets';

    /**
     * Mensajes ya registrados en ESTA petición (en PHP cada petición es un
     * proceso nuevo, así que el estático se reinicia solo).
     *
     * El árbol de secretos se lee muchas veces por petición y cada hoja pasa por
     * el cifrado: sin deduplicar, una instalación sin clave (o con un secreto que
     * no descifra) escribiría decenas de líneas idénticas por petición. Eso no es
     * diagnóstico, es ruido que llena el log y esconde lo que sí importa. Con una
     * línea basta para saber que algo está roto.
     *
     * Ojo: deduplicar silencia el SÍNTOMA, no la causa.
     *
     * @var array<string,true>
     */
    private static $logged = [];

    /** Devuelve todos los secretos descifrados (solo para uso interno). */
    public static function getAll(): array {
        $stored = (array) get_option(self::OPTION_KEY, []);
        return self::decryptTree($stored);
    }

    /**
     * Reemplaza todos los secretos (los cifra antes de guardar).
     *
     * @return bool `false` si NO se guardó nada: no hay clave de cifrado, o algún
     *              valor no se pudo cifrar. Lo guardado hasta entonces no se toca.
     */
    public static function setAll(array $data): bool {
        if (self::encryptionKey() === '') {
            self::logOnce(
                'no-save',
                'no se guardó ningún secreto: falta la clave de cifrado en wp-config.php (HWE_SECRETS_KEY, AUTH_KEY o SECURE_AUTH_KEY). Lo ya guardado no se ha modificado.'
            );
            return false;
        }

        $failed    = false;
        $encrypted = self::encryptTree($data, $failed);
        if ($failed) {
            self::logOnce('no-save-encrypt', 'no se guardó ningún secreto: algún valor no se pudo cifrar. Lo ya guardado no se ha modificado.');
            return false;
        }

        update_option(self::OPTION_KEY, $encrypted, false);
        return true;
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

    /** Registra una línea `[hwe] control-center: …` una sola vez por petición. */
    private static function logOnce(string $id, string $message): void {
        if (isset(self::$logged[$id])) {
            return;
        }
        self::$logged[$id] = true;
        error_log('[hwe] control-center: ' . $message);
    }

    /**
     * Clave de cifrado actual (AES-256-GCM). DESACOPLADA del secreto JWT: rotar
     * el JWT ya no inutiliza los secretos. Prioridad: HWE_SECRETS_KEY → AUTH_KEY →
     * SECURE_AUTH_KEY. Sin ninguna, devuelve '' (falla cerrado) y lo registra.
     */
    private static function encryptionKey(): string {
        if (defined('HWE_SECRETS_KEY') && HWE_SECRETS_KEY) {
            $source = HWE_SECRETS_KEY;
        } elseif (defined('AUTH_KEY') && AUTH_KEY) {
            $source = AUTH_KEY;
        } elseif (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) {
            $source = SECURE_AUTH_KEY;
        } else {
            self::logOnce(
                'no-key',
                'no hay ninguna clave definida (HWE_SECRETS_KEY, AUTH_KEY o SECURE_AUTH_KEY): los secretos no se pueden cifrar ni descifrar y se tratan como vacíos.'
            );
            return '';
        }
        return hash('sha256', 'hwe-secrets-v2:' . $source, true);
    }

    /**
     * Clave LEGACY (AES-256-CBC) para descifrar secretos guardados antes de la
     * migración a GCM. Se mantiene solo para lectura/migración transparente. Mismo
     * criterio: sin clave de reserva.
     */
    private static function legacyKey(): string {
        if (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && GRAPHQL_JWT_AUTH_SECRET_KEY) {
            $source = GRAPHQL_JWT_AUTH_SECRET_KEY;
        } elseif (defined('AUTH_KEY') && AUTH_KEY) {
            $source = AUTH_KEY;
        } else {
            self::logOnce(
                'no-legacy-key',
                'no hay ninguna clave definida (GRAPHQL_JWT_AUTH_SECRET_KEY o AUTH_KEY) para descifrar secretos en formato legacy.'
            );
            return '';
        }
        return hash('sha256', 'hwe-secrets:' . $source, true);
    }

    private static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }
        $key = self::encryptionKey();
        if ($key === '') {
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            return '';
        }
        // Formato v2: prefijo + base64(iv(12) . tag(16) . ciphertext).
        return 'v2:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @param string $path Ruta punteada (p. ej. "payments.wompi.secret_key"), solo
     *                     para diagnóstico en el log: nunca se registra el valor.
     */
    private static function decrypt(string $encoded, string $path = ''): string {
        if ($encoded === '') {
            return '';
        }

        // Formato v2 (GCM autenticado).
        if (strpos($encoded, 'v2:') === 0) {
            $key = self::encryptionKey();
            if ($key === '') {
                return ''; // Ya registrado en encryptionKey().
            }
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) < 29) {
                self::logDecryptFailure($path, 'payload v2 corrupto o truncado');
                return '';
            }
            $iv         = substr($raw, 0, 12);
            $tag        = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            $result     = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($result === false) {
                self::logDecryptFailure($path, 'falló el descifrado v2 (GCM): ¿clave rotada o dato manipulado?');
                return '';
            }
            return $result;
        }

        // Legacy (AES-256-CBC, sin prefijo): base64(iv(16) . ciphertext).
        $legacyKey = self::legacyKey();
        if ($legacyKey === '') {
            return ''; // Ya registrado en legacyKey().
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            self::logDecryptFailure($path, 'payload legacy corrupto o truncado');
            return '';
        }
        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $result     = openssl_decrypt($ciphertext, 'aes-256-cbc', $legacyKey, OPENSSL_RAW_DATA, $iv);
        if ($result === false) {
            self::logDecryptFailure($path, 'falló el descifrado legacy (CBC): ¿clave rotada o dato manipulado?');
            return '';
        }
        return $result;
    }

    /**
     * Un secreto que no descifra lo hace en CADA lectura: se deduplica por ruta y
     * motivo (ver `$logged`). Si este aviso aparece, el secreto sigue sin
     * descifrar y la integración que lo use sigue rota: hay que volver a
     * guardarlo en HWE Control Center.
     */
    private static function logDecryptFailure(string $path, string $reason): void {
        self::logOnce(
            'decrypt|' . $path . '|' . $reason,
            sprintf('no se pudo descifrar el secreto en "%s": %s. El valor se trata como vacío.', $path !== '' ? $path : '(raíz)', $reason)
        );
    }

    /**
     * @param bool $failed Se pone a `true` si algún valor NO vacío no se pudo cifrar.
     */
    private static function encryptTree(array $tree, bool &$failed): array {
        $result = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::encryptTree($value, $failed);
                continue;
            }
            $plain = (string) $value;
            $enc   = self::encrypt($plain);
            if ($plain !== '' && $enc === '') {
                $failed = true;
            }
            $result[$key] = $enc;
        }
        return $result;
    }

    private static function decryptTree(array $tree, string $path = ''): array {
        $result = [];
        foreach ($tree as $key => $value) {
            $childPath     = $path !== '' ? "{$path}.{$key}" : (string) $key;
            $result[$key] = is_array($value)
                ? self::decryptTree($value, $childPath)
                : self::decrypt((string) $value, $childPath);
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
