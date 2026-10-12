<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Secreto (`<input type="password">` siempre vacío; el guardado conserva el valor si se deja
 * en blanco). Persistencia `secret`: se cifra y jamás sale por la API pública.
 */
final class SecretType implements FieldType {

    public function id(): string {
        return 'secret';
    }

    public function persistence(): string {
        return self::SECRET;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        // NOTA(T29): el mapa que construye `AdminPage` trae booleanos (`true` = guardado), y
        // `secretExists` exige hoja de texto no vacía: hoy SIEMPRE sale «Introducir valor…»,
        // incluso con secreto guardado. No se toca aquí para no cambiar el comportamiento en
        // esta tarea; la visualización de secretos se rehace en la tarea 29.
        $hasValue = $this->secretExists((array) ($context['secrets'] ?? []), $path);
        $ph       = $hasValue
            ? esc_attr(__('●●●●●●●● (dejar en blanco para conservar)', 'hwe'))
            : esc_attr(__('Introducir valor...', 'hwe'));
        return "<input type=\"password\" id=\"{$id}\" name=\"{$name}\" value=\"\" autocomplete=\"new-password\" placeholder=\"{$ph}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_text_field((string) ($raw ?? ''));
    }

    /** ¿Hay ya un secreto almacenado en la ruta dada? (Mapa anidado del walker.) */
    private function secretExists(array $secrets, array $path): bool {
        $current = $secrets;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }
        return is_string($current) && $current !== '';
    }
}
