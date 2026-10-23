<?php

namespace HWE\ControlCenter;

/**
 * Árbol de configuración de la plantilla — fuente única de verdad.
 *
 * Los grupos del núcleo viven por tema en `SchemaGroups/` (traits de esta clase: identidad,
 * contacto, diseño, tienda, pagos, integraciones, SEO, envío, GEO, landings, fidelización y
 * membresías); `core()` los junta en el orden de las pestañas del panel y añade el grupo
 * `instance`.
 *
 * Tipos de nodo:
 *   'group'    → contenedor con 'children' (recursivo).
 *   'text'     → input de texto de una línea.
 *   'textarea' → área de texto multilínea.
 *   'color'    → selector de color (#rrggbb).
 *   'url'      → input de URL con validación.
 *   'email'    → input de email con validación.
 *   'select'   → desplegable; requiere 'options' => ['value' => 'Etiqueta'].
 *   'boolean'  → checkbox (true/false).
 *   'secret'   → cifrado en BD; nunca aparece en la API pública.
 *
 * Atributos de campo:
 *   'label'       → etiqueta legible para humanos en la UI de admin.
 *   'default'     → valor por defecto cuando no hay nada almacenado.
 *   'public'      → bool: si true, el campo se incluye en la API REST pública.
 *   'token'       → nombre de la propiedad CSS (p. ej. '--color-brand').
 *   'options'     → ['value' => 'Etiqueta'] para el tipo 'select'.
 *   'description' → texto de ayuda mostrado en la UI de admin.
 *   'translatable' → bool (text, textarea, lines, richtext): el esquema efectivo añade
 *                    un hermano `<clave>_<locale>` por cada idioma secundario de
 *                    `i18n.locales` (vacío = se usa el texto del idioma por defecto).
 *                    El frontend lo resuelve con `pickLocalized(entity, campo, locale)`.
 *   'panel'       → ['tab' => <clave raíz>, 'section' => 'Título'] (solo admin): pinta el campo
 *                    en otra pestaña, dentro de un bloque con ese título. La ruta del dato, el
 *                    `name` del input y la API no cambian.
 *
 * Atributos de grupo:
 *   'slots'       → ['add_label', 'remove_label', 'empty'] (solo admin): cada hijo es un
 *                    hueco opcional (p. ej. las sedes). El panel oculta los huecos vacíos y
 *                    ofrece «añadir» hasta agotarlos; «quitar» vacía el hueco al guardar.
 *
 * CONTRATO CON LA INSTANCIA
 * -------------------------
 * `get()` devuelve el esquema del núcleo tras aplicar el filtro `hwe_control_center_schema`,
 * con el que una instancia añade sus ajustes. El filtro solo puede poblar el grupo raíz
 * `instance` (la pestaña «Instancia»):
 *   · una clave raíz que no sea del núcleo ni `instance` se descarta y se registra;
 *   · un grupo del núcleo que el filtro elimine se restaura y se registra;
 *   · si `instance` deja de ser un grupo, o el filtro no devuelve un array, se descarta lo que
 *     devolvió y se registra.
 * Los avisos salen UNA vez por problema y proceso: el esquema se evalúa varias veces por
 * petición. Ver mu-plugins/instance/README.md.
 */
class Schema {
    use SchemaGroups\Identity;
    use SchemaGroups\Contact;
    use SchemaGroups\Design;
    use SchemaGroups\Store;
    use SchemaGroups\Payments;
    use SchemaGroups\Integrations;
    use SchemaGroups\Seo;
    use SchemaGroups\Shipping;
    use SchemaGroups\Geo;
    use SchemaGroups\Landings;
    use SchemaGroups\Loyalty;
    use SchemaGroups\Memberships;

    /**
     * Esquema efectivo: el del núcleo más lo que aporte la instancia, ya validado.
     *
     * @return array<string,array>
     */
    public static function get(): array {
        $core     = self::core();
        $filtered = apply_filters('hwe_control_center_schema', $core);

        if (!is_array($filtered)) {
            Log::once(
                'schema:not-array',
                sprintf(
                    'el filtro hwe_control_center_schema debe devolver un array (devolvió %s); se usa el esquema del núcleo.',
                    get_debug_type($filtered)
                )
            );
            return self::expandTranslatable($core, self::secondaryLocales());
        }

        return self::expandTranslatable(self::enforceContract($core, $filtered), self::secondaryLocales());
    }

    /** Idiomas secundarios por defecto (los de `frontend/src/i18n/routing.ts` del framework). */
    public const DEFAULT_SECONDARY_LOCALES = 'en';

    /** Tipos que admiten `'translatable' => true`. */
    private const TRANSLATABLE_TYPES = ['text', 'textarea', 'lines', 'richtext'];

    /**
     * Sufijos de los idiomas secundarios configurados (`i18n.locales`), sin el idioma por
     * defecto (`brand.locale`) y normalizados para usarse en una clave: minúsculas y `-`
     * → `_` (`pt-BR` → `pt_br`). El frontend aplica la misma normalización.
     *
     * @return list<string>
     */
    public static function secondaryLocales(): array {
        $raw = Storage::get(['i18n', 'locales']);
        if (!is_string($raw)) {
            $raw = self::DEFAULT_SECONDARY_LOCALES;
        }
        $default = self::localeSuffix((string) Storage::get(['brand', 'locale'], 'es'));

        $suffixes = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $code) {
            if ($code === '') {
                continue;
            }
            if (!preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $code)) {
                Log::once(
                    'schema:locale:' . $code,
                    sprintf('código de idioma «%s» ignorado en i18n.locales: usa códigos como «en» o «pt-BR».', $code)
                );
                continue;
            }
            $suffix = self::localeSuffix($code);
            if ($suffix !== $default && !in_array($suffix, $suffixes, true)) {
                $suffixes[] = $suffix;
            }
        }
        return $suffixes;
    }

    /** `pt-BR` → `pt_br`: forma del idioma dentro de una clave del esquema. */
    public static function localeSuffix(string $locale): string {
        return str_replace('-', '_', strtolower(trim($locale)));
    }

    /**
     * Añade, junto a cada nodo `translatable`, una variante `<clave>_<sufijo>` por idioma
     * secundario: mismo tipo y visibilidad, vacía por defecto (vacío = se hereda el texto
     * del idioma por defecto). Se aplica también a los campos de la instancia.
     *
     * Una variante declarada a mano en el esquema manda sobre la generada. Quitar un idioma
     * de `i18n.locales` oculta sus variantes, pero no borra lo guardado.
     *
     * @param array<string,mixed> $nodes
     * @param list<string>        $locales
     * @return array<string,mixed>
     */
    private static function expandTranslatable(array $nodes, array $locales): array {
        $result = [];
        foreach ($nodes as $key => $node) {
            if (self::isGroup($node)) {
                $node['children'] = self::expandTranslatable($node['children'], $locales);
                $result[$key]     = $node;
                continue;
            }
            $result[$key] = $node;
            if (!is_array($node) || empty($node['translatable'])) {
                continue;
            }
            $type = (string) ($node['type'] ?? 'text');
            if (!in_array($type, self::TRANSLATABLE_TYPES, true)) {
                Log::once(
                    'schema:translatable:' . $key,
                    sprintf('«translatable» ignorado en «%s»: solo admiten traducción los tipos %s.', (string) $key, implode(', ', self::TRANSLATABLE_TYPES))
                );
                continue;
            }
            foreach ($locales as $suffix) {
                $variant = $key . '_' . $suffix;
                if (array_key_exists($variant, $nodes)) {
                    continue;
                }
                $copy = $node;
                unset($copy['translatable'], $copy['token'], $copy['default'], $copy['description']);
                $result[$variant] = [
                    'label'       => sprintf('%s (%s)', (string) ($node['label'] ?? $key), strtoupper(str_replace('_', '-', $suffix))),
                    'default'     => '',
                    'description' => 'Vacío = se usa el texto del idioma por defecto.',
                ] + $copy;
            }
        }
        return $result;
    }

    /**
     * Aplica las reglas del contrato (ver el docblock de la clase) a lo que devolvió el filtro.
     *
     * @param array<string,array> $core
     * @param array<mixed>        $filtered
     * @return array<string,array>
     */
    private static function enforceContract(array $core, array $filtered): array {
        foreach (array_keys($filtered) as $key) {
            if (!array_key_exists($key, $core)) {
                Log::once(
                    'schema:root:' . (string) $key,
                    sprintf(
                        'clave raíz «%s» descartada de hwe_control_center_schema: los ajustes de la instancia van dentro del grupo «instance» ($schema[\'instance\'][\'children\'][\'%s\']).',
                        (string) $key,
                        (string) $key
                    )
                );
            }
        }

        $result = [];
        foreach ($core as $key => $coreNode) {
            if (!array_key_exists($key, $filtered)) {
                Log::once(
                    'schema:removed:' . $key,
                    sprintf('el grupo «%s» del núcleo fue eliminado por un filtro de hwe_control_center_schema; se restaura (el núcleo no se puede quitar).', $key)
                );
                $result[$key] = $coreNode;
                continue;
            }
            $result[$key] = $filtered[$key];
        }

        if (!self::isGroup($result['instance'])) {
            Log::once(
                'schema:instance-invalid',
                'el nodo «instance» de hwe_control_center_schema debe seguir siendo un grupo con «children» (array); se restaura vacío.'
            );
            $result['instance'] = $core['instance'];
        }

        return $result;
    }

    private static function isGroup(mixed $node): bool {
        return is_array($node)
            && ($node['type'] ?? null) === 'group'
            && is_array($node['children'] ?? null);
    }

    /**
     * El esquema del NÚCLEO, sin filtros: la fuente única de todos los campos.
     *
     * @return array<string,array>
     */
    public static function core(): array {
        return [
            ...self::identityGroups(),
            ...self::contactGroups(),
            ...self::designGroups(),
            ...self::storeGroups(),
            ...self::paymentGroups(),
            ...self::integrationGroups(),
            ...self::seoGroups(),
            ...self::shippingGroups(),
            ...self::geoGroups(),
            ...self::landingGroups(),
            ...self::loyaltyGroups(),
            ...self::membershipsGroups(),

            /* ------------------------------------------------------------------ */
            /* INSTANCIA                                                           */
            /* Vacío en el núcleo. Una instancia añade aquí sus ajustes con el      */
            /* filtro `hwe_control_center_schema` (ver «CONTRATO CON LA INSTANCIA»). */
            /* El panel lo pinta como la pestaña «Instancia» en cuanto tiene hijos.  */
            /* ------------------------------------------------------------------ */
            'instance' => [
                'type'     => 'group',
                'label'    => 'Instancia',
                'children' => [],
            ],
        ];
    }



}
