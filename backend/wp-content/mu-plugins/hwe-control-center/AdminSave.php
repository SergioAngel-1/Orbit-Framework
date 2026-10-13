<?php

namespace HWE\ControlCenter;

use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\ControlCenter\FieldTypes\Registry;

/**
 * Guardado del Control Center (wp-admin → HWE Config y `wp hwe setup`).
 *
 * Responsabilidades:
 *  · comprobar el nonce y que el formulario llegó COMPLETO (ver `handle()`);
 *  · sanear y separar públicos/secretos (`sanitizeAndSplit()`, que también usa `Cli`);
 *  · persistir con `merge` (nunca `setAll`: una sección que sanee a vacío conservaría el
 *    valor anterior en vez de borrarlo) y fusionar secretos sin pisar con vacíos;
 *  · disparar `hwe_control_center_saved` y revalidar el frontend.
 */
final class AdminSave {

    public const NONCE_KEY = 'hwe_config_save';

    /**
     * Guarda el POST de HWE Config. Devuelve el aviso HTML para el panel.
     *
     * Defensa contra un POST incompleto: un guardado real de wp-admin es SIEMPRE un único
     * `<form>` con TODAS las pestañas de nivel superior en el DOM (las no activas solo se
     * ocultan por CSS), así que faltar una sección entera solo puede significar un
     * formulario roto o una petición manipulada — nunca una edición legítima. Sin este
     * check, lo ausente se sanearía a vacío y el guardado lo persistiría, borrando de facto
     * cualquier sección no incluida en el POST.
     *
     * Excepción: una sección de nivel superior con `children` vacío (p. ej. `instance`
     * mientras la instancia no añade nada) no pinta ni un solo `<input>`, así que el
     * navegador JAMÁS envía esa clave — su ausencia es normal, no una señal de manipulación.
     */
    public static function handle(): string {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hwe_nonce'] ?? '')), self::NONCE_KEY)) {
            return AdminNotices::notice('error', __('Nonce inválido. Por favor recarga la página e inténtalo de nuevo.', 'hwe'));
        }

        $raw = isset($_POST['hwe_config']) && is_array($_POST['hwe_config'])
            ? wp_unslash($_POST['hwe_config'])
            : [];
        $raw = (array) $raw;

        $schema      = Schema::get();
        $missingTabs = self::missingTabs($schema, $raw);
        if ($missingTabs !== []) {
            return AdminNotices::notice('error', sprintf(
                /* translators: %s: lista de secciones de nivel superior ausentes, separadas por comas */
                __('Guardado cancelado: el formulario llegó incompleto (faltan las secciones: %s). Recarga la página e inténtalo de nuevo; si persiste, no lo reintentes sin avisar — podría indicar una petición manipulada.', 'hwe'),
                implode(', ', $missingTabs)
            ));
        }

        [$publicData, $secretData] = self::sanitizeAndSplit($schema, $raw);

        self::persist($publicData, $secretData, $raw);

        return AdminNotices::notice('success', __('Configuración guardada correctamente.', 'hwe'));
    }

    /**
     * Persiste un guardado ya saneado y avisa a quien escuche. Camino ÚNICO para el
     * formulario de wp-admin, `wp hwe setup` y la importación de config: así los tres
     * sincronizan Woo, guardan los `external` de los módulos y revalidan igual.
     *
     *  · `merge` de lo público (nunca `setAll`) y secretos sin pisar con vacíos;
     *  · la config de backups se relee ya fusionada (un guardado *sparse* sin
     *    `backups` no debe vaciarla);
     *  · `hwe_control_center_saved($publicData, $raw)`: contrato con la instancia y
     *    los módulos. Sustituye a las acciones con nombre de instancia, que obligaban
     *    a leer sus claves del POST. Va ANTES de revalidar: un handler que cambie
     *    datos propios debe haber terminado cuando el frontend vuelva a leer.
     *
     * Quien llama ya comprobó permisos (nonce y capacidad, o WP-CLI).
     *
     * @param array<string,mixed> $publicData Saneado por `sanitizeAndSplit`.
     * @param array<string,mixed> $secretData Saneado por `sanitizeAndSplit`.
     * @param array<string,mixed> $raw        Árbol `hwe_config` tal como llegó.
     */
    public static function persist(array $publicData, array $secretData, array $raw): void {
        Storage::merge($publicData);
        if ($secretData !== []) {
            SecretsStorage::mergeNonEmpty($secretData);
        }
        BackupConfig::write((array) Storage::get(['backups'], []));

        do_action('hwe_control_center_saved', $publicData, $raw);

        Revalidation::trigger();
    }

    /**
     * Secciones de nivel superior con contenido que el POST no trae.
     *
     * @param array<string,array> $schema
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function missingTabs(array $schema, array $raw): array {
        $expected = [];
        foreach ($schema as $key => $section) {
            if (is_array($section) && !empty($section['children'] ?? [])) {
                $expected[] = (string) $key;
            }
        }
        return array_values(array_diff($expected, array_keys($raw)));
    }

    /**
     * Recorre el esquema y los datos enviados de forma recursiva.
     * Devuelve [publicData, secretData] separados.
     *
     * El saneado de cada campo lo hace su tipo (`FieldTypes\Registry`); la persistencia
     * (`config`/`secret`/`external`) decide en qué cubo cae. Los `external` no se persisten
     * aquí: los guarda su módulo al recibir `hwe_control_center_saved`.
     *
     * Modo `$sparse` (para `wp hwe setup` con un JSON parcial): las claves que NO llegaron
     * se omiten en vez de sanearse a vacío, así un JSON con solo `brand` no borra el resto.
     * El formulario de admin siempre envía el árbol completo, así que guarda en modo normal.
     *
     * Público para que Cli::setup() reutilice la misma sanitización/validación
     * por tipo de campo que usa el guardado desde wp-admin.
     */
    public static function sanitizeAndSplit(array $schema, array $submitted, array $path = [], bool $sparse = false): array {
        $publicData = [];
        $secretData = [];

        foreach ($schema as $key => $node) {
            $currentPath = array_merge($path, [$key]);
            $present     = array_key_exists($key, $submitted);
            $rawValue    = $submitted[$key] ?? null;
            $nodeType    = $node['type'] ?? 'text';

            if ($nodeType === 'group') {
                $groupRaw = is_array($rawValue) ? $rawValue : [];
                [$gPublic, $gSecrets] = self::sanitizeAndSplit($node['children'] ?? [], $groupRaw, $currentPath, $sparse);
                if (!empty($gPublic))   $publicData[$key]  = $gPublic;
                if (!empty($gSecrets))   $secretData[$key]  = $gSecrets;
                continue;
            }

            $type = Registry::get($nodeType);
            if ($type->persistence() === FieldType::EXTERNAL) {
                continue;
            }
            if ($sparse && !$present) {
                continue;
            }
            $clean = $type->sanitize($node, $rawValue);
            if ($clean === FieldType::SKIP) {
                continue;
            }

            if ($type->persistence() === FieldType::SECRET) {
                if ($clean !== '') {
                    $secretData[$key] = $clean;
                }
            } else {
                $publicData[$key] = $clean;
            }
        }

        return [$publicData, $secretData];
    }
}
