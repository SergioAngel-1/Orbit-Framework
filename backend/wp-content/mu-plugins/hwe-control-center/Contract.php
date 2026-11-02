<?php

namespace HWE\ControlCenter;

/**
 * El contrato de `GET /wp-json/hwe/v1/config` en formatos que lee una máquina.
 *
 * `Schema` es la fuente única de los campos; este módulo la traduce a:
 *   · un JSON Schema (draft 2020-12) de la config pública, el que el framework versiona en
 *     `contract/config.schema.json` (un test falla si el esquema cambia y el fichero no);
 *   · un `.d.ts` con la *module augmentation* de `InstanceConfig` para el grupo `instance`,
 *     de modo que una instancia no tipa sus campos a mano.
 *
 * Solo entra lo que publica `PublicConfigWalker` (campos `public`, nunca `secret`) más las
 * claves que `RestApi::handleGet` añade fuera del esquema. Los tipos de campo propios de
 * una instancia sin traducción conocida salen sin restricción de tipo, con `x-hwe-type`.
 *
 * Lo exponen `wp hwe contract config-schema` y `wp hwe contract instance-types` (`Cli`).
 */
final class Contract {

    /** Tipo de campo → tipo JSON del valor que publica `/config`. */
    private const JSON_TYPES = [
        'boolean'  => 'boolean',
        // `number` se guarda como texto ("24"), pero un campo nunca guardado sale con su
        // `default`, que puede ser un entero: el consumidor convierte en el borde.
        'number'   => ['string', 'integer'],
        'text'     => 'string',
        'textarea' => 'string',
        'richtext' => 'string',
        'color'    => 'string',
        'url'      => 'string',
        'email'    => 'string',
        'select'   => 'string',
        'slug'     => 'string',
        'lines'    => 'string',
    ];

    /** Tipo JSON → tipo TypeScript. */
    private const TS_TYPES = [
        'boolean' => 'boolean',
        'string'  => 'string',
    ];

    /**
     * JSON Schema de la config pública.
     *
     * @param array<string,array> $schema Esquema efectivo (`Schema::get()`).
     * @return array<string,mixed>
     */
    public static function publicConfigSchema(array $schema): array {
        $properties = self::properties($schema);

        // Claves que `RestApi::handleGet` añade fuera del esquema.
        $properties['modules'] = [
            'type'                 => 'object',
            'description'          => 'Módulos activos: id → encendido (la fuente es el plugin activo, no la config).',
            'additionalProperties' => ['type' => 'boolean'],
        ];
        $properties['ecommerce']['properties']['price_decimals'] = [
            'type'        => ['integer', 'null'],
            'description' => 'Decimales de precio de WooCommerce; null si WooCommerce no está activo.',
        ];
        $properties['brand']['properties']['icon_url'] = [
            'type'        => ['string', 'null'],
            'description' => 'Icono del sitio de WordPress (Ajustes → Identidad del sitio); null si no hay.',
        ];

        return [
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            '$id'                  => 'https://orbit.hwe/contract/config.schema.json',
            'title'                => 'GET /wp-json/hwe/v1/config',
            'description'          => 'Config pública del Control Center. Generado desde Schema.php (wp hwe contract config-schema); no se edita a mano.',
            'type'                 => 'object',
            'additionalProperties' => true,
            'properties'           => $properties,
        ];
    }

    /**
     * Declaración TypeScript del grupo `instance` (módulo de aumento de `InstanceConfig`).
     *
     * @param array<string,array> $schema Esquema efectivo (`Schema::get()`).
     */
    public static function instanceTypes(array $schema): string {
        $group = $schema['instance'] ?? null;
        $props = is_array($group) && ($group['type'] ?? null) === 'group'
            ? self::properties($group['children'] ?? [])
            : [];

        $body = $props === []
            ? "    // El grupo `instance` no tiene campos públicos.\n"
            : self::tsMembers($props, 2);

        return "// Generado por `wp hwe contract instance-types`: no lo edites a mano.\n"
            . "// Vuelve a generarlo cuando cambien los campos de `hwe_control_center_schema`.\n"
            . "// Todo es opcional: con WordPress caído la config son los defaults del framework,\n"
            . "// que no traen nada de la instancia.\n"
            . "declare module \"@/lib/config/types\" {\n"
            . "  interface InstanceConfig {\n"
            . $body
            . "  }\n"
            . "}\n\n"
            . "export {};\n";
    }

    /**
     * Propiedades JSON Schema de un nivel del árbol (solo lo público).
     *
     * @param array<string,array> $nodes
     * @return array<string,array<string,mixed>>
     */
    private static function properties(array $nodes): array {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? 'text';
            if ($type === 'group') {
                $children = self::properties($node['children'] ?? []);
                if ($children === []) {
                    continue;
                }
                $out[$key] = array_filter([
                    'type'                 => 'object',
                    'description'          => $node['label'] ?? null,
                    'additionalProperties' => true,
                    'properties'           => $children,
                ], static fn($v) => $v !== null);
                continue;
            }
            if ($type === 'secret' || empty($node['public'])) {
                continue;
            }
            $out[$key] = self::field($node, $type);
            if (!empty($node['translatable'])) {
                $out[$key]['x-hwe-translatable'] = true;
            }
        }
        // Variantes por idioma (`<campo>_<idioma>`, `Schema::expandTranslatable`): dependen de
        // `i18n.locales`, así que se marcan para que el consumidor las trate como tales.
        foreach (array_keys($out) as $key) {
            foreach ($nodes as $base => $node) {
                if (is_array($node) && !empty($node['translatable']) && str_starts_with((string) $key, $base . '_')) {
                    $out[$key]['x-hwe-translation-of'] = (string) $base;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function field(array $node, string $type): array {
        $schema = [];
        if (isset(self::JSON_TYPES[$type])) {
            $schema['type'] = self::JSON_TYPES[$type];
        }
        $schema['x-hwe-type'] = $type;
        if (!empty($node['label'])) {
            $schema['description'] = (string) $node['label'];
        }
        if (array_key_exists('default', $node)) {
            $schema['default'] = self::publicValue($type, $node['default']);
        }
        if ($type === 'number') {
            foreach (['min' => 'x-hwe-min', 'max' => 'x-hwe-max'] as $from => $to) {
                if (isset($node[$from])) {
                    $schema[$to] = (int) $node[$from];
                }
            }
        }
        return $schema;
    }

    /**
     * El valor tal como sale por `/config` (mismas reglas que `PublicConfigWalker`).
     */
    private static function publicValue(string $type, mixed $value): mixed {
        if ($type === 'boolean') {
            return (bool) $value;
        }
        return $value;
    }

    /** @param array<string,array<string,mixed>> $props */
    private static function tsMembers(array $props, int $depth): string {
        $indent = str_repeat('  ', $depth);
        $out    = '';
        foreach ($props as $key => $prop) {
            if (!empty($prop['description'])) {
                $out .= "{$indent}/** " . str_replace('*/', '* /', (string) $prop['description']) . " */\n";
            }
            $name = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', (string) $key) ? $key : json_encode((string) $key);
            if (($prop['type'] ?? null) === 'object') {
                $out .= "{$indent}{$name}?: {\n" . self::tsMembers($prop['properties'] ?? [], $depth + 1) . "{$indent}};\n";
                continue;
            }
            $ts = ($prop['x-hwe-type'] ?? null) === 'number'
                ? 'string | number' // entero como texto, o el `default` entero si nunca se guardó
                : (self::TS_TYPES[is_string($prop['type'] ?? null) ? $prop['type'] : ''] ?? 'unknown');
            $out .= "{$indent}{$name}?: {$ts};\n";
        }
        return $out;
    }
}
