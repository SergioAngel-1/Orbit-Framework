<?php

namespace HWE\ControlCenter\FieldTypes;

/** Imagen de la medioteca (picker con vista previa; persiste la URL). */
final class ImageType implements FieldType {

    public function id(): string {
        return 'image';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return MediaPicker::render($id, $name, (string) ($value ?? ''), $field, 'image');
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return MediaPicker::sanitize($raw);
    }
}
