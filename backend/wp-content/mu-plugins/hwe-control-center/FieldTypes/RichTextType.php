<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Texto enriquecido (editor con quicktags, SIN TinyMCE).
 *
 * Los paneles de pestañas del panel se ocultan con `display:none`, y TinyMCE inicializado
 * en un contenedor oculto arranca con altura 0 y se rompe al cambiar de pestaña. Quicktags
 * es un `<textarea>` con botones y funciona igual oculto.
 *
 * Se sanea con `wp_kses` y una lista conservadora (párrafos, énfasis, enlaces, listas y tablas):
 * es contenido de la tienda que acaba en el frontend, no un post de un editor de confianza. Las
 * tablas solo admiten sus atributos de estructura; quicktags no tiene botón para ellas y se
 * escriben a mano.
 */
final class RichTextType implements FieldType {

    /** Etiquetas y atributos permitidos (lista conservadora). */
    public const ALLOWED_HTML = [
        'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'table' => [],
        'caption' => [],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'th' => ['scope' => true, 'colspan' => true, 'rowspan' => true],
        'td' => ['colspan' => true, 'rowspan' => true],
    ];

    public function id(): string {
        return 'richtext';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        // `wp_editor` lo usa como id de `<textarea>` y clave de Quicktags: solo [a-z0-9_-].
        // El POST viaja en `textarea_name`, así que la ruta de guardado no cambia.
        $editorId = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '_', $id));
        ob_start();
        wp_editor((string) ($value ?? ''), $editorId, [
            'textarea_name' => $name,
            'tinymce'       => false,
            'quicktags'     => ['buttons' => 'strong,em,link,ul,ol,li,close'],
            'media_buttons' => false,
            'textarea_rows' => 8,
        ]);
        return (string) ob_get_clean();
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return wp_kses((string) ($raw ?? ''), self::ALLOWED_HTML);
    }
}
