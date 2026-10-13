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
        $hasValue = $this->secretExists((array) ($context['secrets'] ?? []), $path);
        $ph       = $hasValue
            ? esc_attr(__('●●●●●●●● (dejar en blanco para conservar)', 'hwe'))
            : esc_attr(__('Introducir valor...', 'hwe'));
        return "<input type=\"password\" id=\"{$id}\" name=\"{$name}\" value=\"\" autocomplete=\"new-password\" placeholder=\"{$ph}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_text_field((string) ($raw ?? ''));
    }

    /**
     * ¿Hay ya un secreto almacenado en la ruta dada?
     *
     * El mapa es de EXISTENCIA (`AdminPage::buildSecretExistenceMap`): sus hojas son
     * booleanos, nunca el valor. Se admite también una cadena no vacía por si algún
     * llamante pasa el árbol en claro. El `value` del input sigue vacío: la clave no viaja
     * al HTML; un secreto guardado se muestra como «guardado» sin revelarse.
     */
    private function secretExists(array $secrets, array $path): bool {
        $current = $secrets;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }
        return $current === true || (is_string($current) && $current !== '');
    }
}
