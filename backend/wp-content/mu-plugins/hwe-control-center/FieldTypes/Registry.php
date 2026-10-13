<?php

namespace HWE\ControlCenter\FieldTypes;

use HWE\ControlCenter\Log;

/**
 * Mapa id → FieldType efectivo: los tipos del núcleo más los que aporte una instancia con el
 * filtro `hwe_control_center_field_types`.
 *
 * Reglas del contrato (ver `FieldType`):
 *  · el filtro debe devolver un mapa; si no, se usa el del núcleo y se registra;
 *  · una entrada que no sea un `FieldType` se descarta y se registra;
 *  · un id del núcleo sobrescrito o eliminado se restaura y se registra: el saneado del
 *    núcleo no se puede debilitar ni quitar desde un filtro.
 *
 * Un tipo desconocido (`Registry::get('loquesea')`) devuelve un `UnknownFieldType`, que se
 * pinta como texto deshabilitado y se registra: el formulario nunca rompe por un tipo que
 * no existe (p. ej. de un módulo desactivado).
 */
final class Registry {

    /** @return array<string,FieldType> */
    public static function all(): array {
        $core = self::coreTypes();

        $filtered = apply_filters('hwe_control_center_field_types', $core);
        if (!is_array($filtered)) {
            Log::once(
                'field-types:not-array',
                sprintf(
                    'el filtro hwe_control_center_field_types debe devolver un mapa id → FieldType (devolvió %s); se usan los tipos del núcleo.',
                    get_debug_type($filtered)
                )
            );
            return $core;
        }

        $types = $core;
        foreach ($filtered as $id => $type) {
            if (!is_string($id) || !($type instanceof FieldType)) {
                Log::once(
                    'field-types:bad-entry',
                    'entrada descartada de hwe_control_center_field_types: debe ser id (texto) → FieldType.'
                );
                continue;
            }
            if ($id !== $type->id()) {
                Log::once(
                    'field-types:id-mismatch:' . $id,
                    sprintf('entrada descartada de hwe_control_center_field_types: la clave «%s» no coincide con el id «%s» del tipo.', $id, $type->id())
                );
                continue;
            }
            if (array_key_exists($id, $core)) {
                if ($type === $core[$id]) {
                    continue;
                }
                Log::once(
                    'field-types:core-override:' . $id,
                    sprintf('tipo «%s» descartado de hwe_control_center_field_types: los tipos del núcleo no se pueden sobrescribir.', $id)
                );
                continue;
            }
            $types[$id] = $type;
        }

        foreach (array_keys($core) as $id) {
            if (!array_key_exists($id, $filtered)) {
                Log::once(
                    'field-types:removed:' . $id,
                    sprintf('el tipo «%s» del núcleo fue eliminado por un filtro de hwe_control_center_field_types; se restaura (el núcleo no se puede quitar).', $id)
                );
            }
        }

        return $types;
    }

    public static function get(string $id): FieldType {
        return self::all()[$id] ?? new UnknownFieldType($id);
    }

    /** Los tipos del núcleo, antes del filtro (ver `FieldType` para el contrato). */
    private static function coreTypes(): array {
        return [
            'text'     => new TextType(),
            'textarea' => new TextareaType(),
            'email'    => new EmailType(),
            'url'      => new UrlType(),
            'color'    => new ColorType(),
            'boolean'  => new BooleanType(),
            'select'   => new SelectType(),
            'secret'   => new SecretType(),
            'image'    => new ImageType(),
            'file'     => new FileType(),
            'number'   => new NumberType(),
            'richtext' => new RichTextType(),
            'link'     => new LinkType(),
            'slug'     => new SlugType(),
            'lines'    => new LinesType(),
            'pairs'    => new PairsType(),
            'product_multiselect' => new ProductMultiselectType(),
            'product_fields_list' => new ProductFieldsListType(),
        ];
    }
}
