<?php

namespace HWE\ControlCenter;

/**
 * Módulos opcionales del framework.
 *
 * Un módulo es un plugin regular (`wp-content/plugins/hwe-<id>/`) que aporta una funcionalidad
 * que no toda instancia necesita (banners, envío, landings…). La instancia los elige en
 * `instance.config.json` (`modules: [<id>…]`) y `wp hwe setup` activa los listados y desactiva
 * los demás del catálogo. Sin la clave `modules`, el setup no toca ningún plugin.
 *
 * La fuente de verdad en tiempo de ejecución NO es la config almacenada, sino si el plugin
 * está activo: la config pública expone `modules: { <id>: boolean }` calculado al vuelo
 * (ver RestApi). Así un módulo activado a mano en wp-admin también se refleja en el frontend.
 *
 * El catálogo es extensible con el filtro `hwe_modules_catalog` (id → slug de plugin).
 */
final class Modules {

    /** Ids del catálogo del núcleo: los once plugins `plugins/hwe-<id>/` del framework. */
    public const CORE_IDS = [
        'banners',
        'shipping',
        'product-landing',
        'landings',
        'consent-log',
        'site-reviews',
        'coupon-rules',
        'customer-identity',
        'external-integration',
        'loyalty',
        'memberships',
    ];

    /**
     * Catálogo efectivo: id → slug de plugin.
     *
     * El filtro solo puede AÑADIR ids (un módulo de instancia) o cambiar el slug de uno
     * existente; un id del núcleo eliminado se restaura y una entrada mal formada se descarta,
     * en ambos casos registrándolo. El núcleo siempre sabe qué ids debe exponer.
     *
     * @return array<string,string>
     */
    public static function catalog(): array {
        $catalog = [];
        foreach (self::CORE_IDS as $id) {
            $catalog[$id] = 'hwe-' . $id;
        }

        $filtered = apply_filters('hwe_modules_catalog', $catalog);
        if (!is_array($filtered)) {
            Log::once(
                'modules:not-array',
                sprintf(
                    'el filtro hwe_modules_catalog debe devolver un mapa id → slug (devolvió %s); se usa el catálogo del núcleo.',
                    get_debug_type($filtered)
                )
            );
            return $catalog;
        }

        foreach ($filtered as $id => $slug) {
            if (!is_string($id) || !is_string($slug) || $slug === '') {
                Log::once(
                    'modules:bad-entry',
                    'entrada descartada de hwe_modules_catalog: el id y el slug deben ser texto no vacío.'
                );
                continue;
            }
            if (!array_key_exists($id, $catalog)) {
                $catalog[$id] = $slug;
            } elseif ($catalog[$id] !== $slug) {
                $catalog[$id] = $slug;
            }
        }

        // Un id del núcleo que el filtro elimine se restaura: el núcleo no se puede quitar.
        foreach (self::CORE_IDS as $id) {
            if (!array_key_exists($id, $filtered)) {
                Log::once(
                    'modules:removed:' . $id,
                    sprintf('el módulo «%s» del núcleo fue eliminado por un filtro de hwe_modules_catalog; se restaura (el núcleo no se puede quitar).', $id)
                );
            }
        }

        return $catalog;
    }

    /**
     * Activar o desactivar un módulo a mano (wp-admin → Plugins) cambia `config.modules`:
     * se revalida la config del frontend para que no siga gateando con el estado viejo
     * hasta que caduque la ventana ISR. `wp hwe setup` revalida por su cuenta.
     */
    public static function register(): void {
        add_action('activated_plugin', [self::class, 'onPluginToggled']);
        add_action('deactivated_plugin', [self::class, 'onPluginToggled']);
    }

    /** @param string $pluginFile Ruta relativa del plugin (`hwe-banners/hwe-banners.php`). */
    public static function onPluginToggled($pluginFile): void {
        foreach (self::catalog() as $slug) {
            if ($pluginFile === self::pluginFile($slug)) {
                Revalidation::trigger();
                return;
            }
        }
    }

    /** Archivo principal del plugin dentro de `wp-content/plugins/`. */
    public static function pluginFile(string $slug): string {
        return $slug . '/' . $slug . '.php';
    }

    /** ¿Está activo el plugin del módulo? Un id desconocido nunca está activo. */
    public static function isActive(string $id): bool {
        $catalog = self::catalog();
        if (!isset($catalog[$id])) {
            return false;
        }
        if (!function_exists('get_option')) {
            return false;
        }
        $active = (array) get_option('active_plugins', []);
        return in_array(self::pluginFile($catalog[$id]), $active, true);
    }

    /** Mapa id → activo, para la config pública. */
    public static function activeMap(): array {
        $map = [];
        foreach (self::catalog() as $id => $slug) {
            $map[$id] = self::isActive($id);
        }
        return $map;
    }

    /**
     * Aplica la lista `modules` de `instance.config.json`: activa los listados y desactiva los
     * demás módulos del catálogo. Los ids desconocidos se ignoran y se registran.
     *
     * Solo funciona donde existen las APIs de plugins de wp-admin (`activate_plugin`,
     * `deactivate_plugins`); fuera de ahí (un test sin ellas, un contexto sin wp-admin) no
     * toca nada y lo registra. Un plugin del catálogo que no esté instalado no aborta el
     * setup: se avisa y se sigue con los demás.
     *
     * @param list<mixed> $wanted Ids deseados, tal como vienen del JSON.
     * @return array{activated:list<string>,deactivated:list<string>}
     */
    public static function applySetup(array $wanted): array {
        $catalog = self::catalog();
        $keep    = [];
        foreach ($wanted as $id) {
            if (!is_string($id)) {
                continue;
            }
            if (!isset($catalog[$id])) {
                Log::once(
                    'modules:unknown:' . $id,
                    sprintf('módulo «%s» ignorado en la clave `modules`: no está en el catálogo.', $id)
                );
                continue;
            }
            $keep[$id] = true;
        }

        $canManage = function_exists('activate_plugin') && function_exists('deactivate_plugins');
        if (!$canManage) {
            Log::once(
                'modules:no-plugin-api',
                'no se pueden activar/desactivar módulos: faltan las APIs de plugins de wp-admin.'
            );
            return ['activated' => [], 'deactivated' => []];
        }

        $activated   = [];
        $deactivated = [];
        foreach ($catalog as $id => $slug) {
            $file   = self::pluginFile($slug);
            $active = self::isActive($id);
            if (isset($keep[$id])) {
                if ($active || in_array($id, $activated, true)) {
                    continue;
                }
                $result = activate_plugin($file);
                if (is_wp_error($result)) {
                    Log::once(
                        'modules:activate-failed:' . $id,
                        sprintf(
                            'no se pudo activar el módulo «%s» (%s): %s. Instala el plugin o quítalo de `modules`.',
                            $id,
                            $file,
                            $result->get_error_message()
                        )
                    );
                    continue;
                }
                $activated[] = $id;
            } elseif ($active) {
                deactivate_plugins($file);
                $deactivated[] = $id;
            }
        }

        return ['activated' => $activated, 'deactivated' => $deactivated];
    }
}
