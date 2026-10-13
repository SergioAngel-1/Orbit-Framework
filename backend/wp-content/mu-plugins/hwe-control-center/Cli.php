<?php

namespace HWE\ControlCenter;

/**
 * Comando WP-CLI `wp hwe setup <archivo.json>`.
 *
 * Seedea la configuración de una instancia nueva (marca, diseño, redes,
 * legal, e-commerce, SEO, envíos, GEO) desde un archivo JSON, reutilizando
 * la misma sanitización/validación por tipo de campo que usa el guardado
 * desde wp-admin (AdminSave::sanitizeAndSplit). Pensado para ejecutarse una
 * vez desde backend/scripts/setup.sh durante la inicialización de la
 * instancia, pero es idempotente y se puede reejecutar en cualquier momento
 * (p. ej. para reaplicar config en un entorno reproducible/CI).
 *
 * Solo se registra si WP-CLI está presente (ver hwe-control-center.php).
 *
 * Clave `modules` (opcional): lista de ids de módulo a dejar ACTIVOS
 * (`["banners"]`). `Modules::applySetup()` activa los listados y desactiva los
 * demás del catálogo. Sin esta clave no se toca ningún plugin. El estado de los
 * módulos no se guarda en la config: la fuente de verdad es si el plugin está
 * activo (ver `Modules` y `RestApi`).
 */
class Cli {

    /**
     * Aplica un archivo de configuración de instancia.
     *
     * ## OPTIONS
     *
     * <file>
     * : Ruta al archivo JSON con la configuración (ver
     *   backend/scripts/instance.config.example.json).
     *
     * ## EXAMPLES
     *
     *     wp hwe setup /scripts/instance.config.json
     *
     * @param list<string>        $args       Argumentos posicionales.
     * @param array<string,mixed> $assoc_args Argumentos con nombre (sin usar por ahora).
     */
    public static function setup(array $args, array $assoc_args): void {
        $path = $args[0] ?? null;

        if (!$path || !is_readable($path)) {
            \WP_CLI::error("No se pudo leer el archivo de configuración: " . ($path ?? '(sin ruta)'));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            \WP_CLI::error("No se pudo leer el contenido de {$path}.");
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \WP_CLI::error("JSON inválido en {$path}: " . json_last_error_msg());
        }
        if (!is_array($data)) {
            \WP_CLI::error("El archivo {$path} debe contener un objeto JSON en su raíz.");
        }

        // Modo *sparse*: el JSON trae solo lo que la instancia quiere fijar; lo que no
        // trae se omite (no se sanea a vacío), así un JSON parcial no borra el resto.
        [$publicData, $secretData] = AdminSave::sanitizeAndSplit(Schema::get(), $data, [], true);

        Storage::merge($publicData);
        if (!empty($secretData)) {
            SecretsStorage::mergeNonEmpty($secretData);
        }
        // Storage::merge() es un merge profundo (no reemplaza toda la config),
        // así que se relee el bloque 'backups' ya fusionado en vez de usar
        // $publicData['backups'] directamente: si el JSON no trae ese bloque,
        // esto evita sobreescribir con un array vacío la config existente.
        BackupConfig::write(Storage::get(['backups'], []));

        // Módulos opcionales: solo si el JSON trae la clave. `activate_plugin` vive en
        // wp-admin/includes/plugin.php, que WP-CLI no siempre tiene cargado.
        if (array_key_exists('modules', $data)) {
            if (!function_exists('activate_plugin') && defined('ABSPATH')) {
                $pluginApi = ABSPATH . 'wp-admin/includes/plugin.php';
                if (is_readable($pluginApi)) {
                    require_once $pluginApi;
                }
            }
            $modules = is_array($data['modules']) ? array_values($data['modules']) : [];
            $applied = Modules::applySetup($modules);
            \WP_CLI::log(sprintf(
                'Módulos: activados [%s], desactivados [%s].',
                implode(', ', $applied['activated']),
                implode(', ', $applied['deactivated'])
            ));
        }

        Revalidation::trigger();

        \WP_CLI::success("Configuración de instancia aplicada desde {$path}.");
    }
}
