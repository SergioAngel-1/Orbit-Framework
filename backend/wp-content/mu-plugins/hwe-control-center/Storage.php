<?php

namespace HWE\ControlCenter;

/**
 * Persistencia de la configuración pública en wp_options.
 *
 * Almacena un array anidado que replica la estructura del esquema.
 * Los secretos se almacenan por separado en SecretsStorage.
 */
class Storage {

    private const OPTION_KEY = 'hwe_config';

    /** Devuelve todos los valores públicos almacenados. */
    public static function getAll(): array {
        return (array) get_option(self::OPTION_KEY, []);
    }

    /** Reemplaza toda la configuración pública. */
    public static function setAll(array $data): void {
        update_option(self::OPTION_KEY, $data, false);
    }

    /**
     * Lee un valor por ruta de array.
     *
     * @param list<string> $path  P. ej. ['design','colors','brand'].
     * @param mixed        $default Valor si la ruta no existe.
     */
    public static function get(array $path, mixed $default = null): mixed {
        $current = self::getAll();
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Fusiona en profundidad los valores almacenados con los proporcionados.
     * Solo actualiza las claves presentes en $data.
     */
    public static function merge(array $data): void {
        $existing = self::getAll();
        self::setAll(self::deepMerge($existing, $data));
    }

    private static function deepMerge(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
