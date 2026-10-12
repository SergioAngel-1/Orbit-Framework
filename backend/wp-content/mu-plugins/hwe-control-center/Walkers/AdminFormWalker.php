<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\Walker;

/**
 * Genera el HTML del formulario de wp-admin recorriendo el esquema.
 *
 * Salida por nivel de profundidad:
 *   Profundidad 1 → contenedor <div class="hwe-fields"> con campos hijos.
 *   Profundidad 2+ → subgrupo <div class="hwe-subgroup"> con header + campos.
 *   Campos leaf  → fila <div class="hwe-field"> con label + control.
 *
 * Los secretos renderizan un <input type="password"> vacío con placeholder;
 * el handler de guardado conserva el valor existente si se deja en blanco.
 *
 * Uso:
 *   $walker   = new AdminFormWalker($secretsExist);
 *   $sections = $walker->walk(Schema::get(), $merged);
 *   // $sections se usa en tab panels del AdminPage.
 */
class AdminFormWalker extends Walker {

    /**
     * @param array $secrets Mapa anidado que indica si ya existe un secreto en
     *                       cada ruta (true = ya hay valor almacenado).
     */
    public function __construct(private readonly array $secrets = []) {}

    // -------------------------------------------------------------------------
    // Walker implementations
    // -------------------------------------------------------------------------

    protected function visitField(array $field, string $key, array $path, mixed $value): mixed {
        $id    = 'hwe_config_' . implode('_', $path);
        $name  = $this->fieldName($path);
        $label = esc_html($field['label'] ?? $key);
        $desc  = isset($field['description'])
            ? '<p class="description">' . esc_html($field['description']) . '</p>'
            : '';

        $input = $this->renderInput($field, $id, $name, $value, $path);

        return <<<HTML
<div class="hwe-field">
    <label for="{$id}">{$label}</label>
    <div class="hwe-field-control">
        {$input}
        {$desc}
    </div>
</div>
HTML;
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        $label   = esc_html($group['label'] ?? $key);
        $content = implode("\n", array_values($children));
        $depth   = count($path);

        // Depth 1 groups are rendered as tab panels by AdminPage.
        if ($depth === 1) {
            return $this->renderFormGroup($key, $label, $content);
        }

        // Subgrupos anidados: card con borde izquierdo azul.
        $icon = $this->subgroupIcon($key);

        // Payment provider subgroups get a data attribute for conditional display.
        $providerAttr = '';
        if ($path[0] === 'payments' && $key !== 'provider') {
            $providerAttr = ' data-provider="' . esc_attr($key) . '"';
        }

        return <<<HTML
<div class="hwe-subgroup{$providerAttr}">
    <h3 class="hwe-subgroup-title">
        <span class="dashicons {$icon}"></span>
        {$label}
    </h3>
    <div class="hwe-fields">
        {$content}
    </div>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers de renderizado
    // -------------------------------------------------------------------------

    private function renderFormGroup(string $key, string $label, string $tableBody): string {
        return <<<HTML
<div class="hwe-fields">
{$tableBody}
</div>
HTML;
    }

    /**
     * El control de cada campo lo pinta su tipo (`FieldTypes\Registry`): el walker solo pone
     * la fila con el label. Un tipo desconocido sale deshabilitado, nunca rompe el panel.
     */
    private function renderInput(array $field, string $id, string $name, mixed $value, array $path): string {
        return Registry::get($field['type'] ?? 'text')->render(
            $field,
            $id,
            $name,
            $value,
            $path,
            ['secrets' => $this->secrets]
        );
    }

    // -------------------------------------------------------------------------
    // Utilidades
    // -------------------------------------------------------------------------

    private function fieldName(array $path): string {
        if (empty($path)) {
            return 'hwe_config';
        }
        $first = array_shift($path);
        $rest  = implode('', array_map(fn($s) => "[{$s}]", $path));
        return "hwe_config[{$first}]{$rest}";
    }

    /**
     * Mapa de iconos para subgrupos conocidos.
     */
    private function subgroupIcon(string $key): string {
        return match ($key) {
            'colors'       => 'dashicons-art',
            'typography'   => 'dashicons-editor-paste-text',
            'wompi'        => 'dashicons-awards',
            'payu'         => 'dashicons-money-alt',
            'bold'         => 'dashicons-admin-generic',
            default        => 'dashicons-admin-generic',
        };
    }
}
