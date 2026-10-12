<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\Walker;

/**
 * Extrae solo los campos marcados como `'public' => true` del árbol de valores.
 *
 * Los campos con `'type' => 'secret'` o sin `'public' => true` quedan excluidos
 * del resultado incluso si tienen valor almacenado. Así la API REST pública
 * nunca puede filtrar credenciales.
 *
 * Uso:
 *   $public = (new PublicConfigWalker)->walk(Schema::get(), Storage::getAll());
 */
class PublicConfigWalker extends Walker {

    private const SKIP = "\x00hwe_skip\x00";

    protected function visitField(array $field, string $key, array $path, mixed $value): mixed {
        if ($field['type'] === 'secret' || empty($field['public'])) {
            return self::SKIP;
        }
        // Booleanos se normalizan para JSON.
        if ($field['type'] === 'boolean') {
            return (bool) $value;
        }
        return $value ?? ($field['default'] ?? null);
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        $filtered = array_filter(
            $children,
            static fn($v) => $v !== self::SKIP
        );
        return empty($filtered) ? self::SKIP : $filtered;
    }

    /**
     * Quita los marcadores SKIP también en la RAÍZ. `visitGroup` los quita de los hijos de
     * cada grupo, pero un grupo raíz sin campos públicos (p. ej. `instance` mientras la
     * instancia no añade nada, o uno con solo secretos) devolvía el marcador y llegaba tal
     * cual al JSON de /wp-json/hwe/v1/config.
     */
    protected function finalize(array $result): array {
        return array_filter($result, static fn($v) => $v !== self::SKIP);
    }
}
