<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Archivo de la medioteca que NO es una imagen (p. ej. un PDF).
 *
 * Comparte picker y saneado con `image` (la URL es lo que se guarda); cambia el picker
 * (el nodo puede traer `accept: "pdf,zip"` para validar la extensión al elegir, ya que la
 * medioteca filtrada por `type: image` no listaría estos archivos) y la vista previa (el
 * nombre del archivo, no un `<img>` que se vería roto).
 */
final class FileType implements FieldType {

    public function id(): string {
        return 'file';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return MediaPicker::render($id, $name, (string) ($value ?? ''), $field, 'file');
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return MediaPicker::sanitize($raw);
    }
}
