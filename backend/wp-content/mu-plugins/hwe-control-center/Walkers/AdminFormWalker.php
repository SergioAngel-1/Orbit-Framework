<?php

namespace HWE\ControlCenter\Walkers;

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

    private function renderInput(array $field, string $id, string $name, mixed $value, array $path): string {
        return match ($field['type']) {
            'textarea' => $this->renderTextarea($id, $name, (string) ($value ?? '')),
            'color'    => $this->renderColor($id, $name, (string) ($value ?? ($field['default'] ?? '#000000'))),
            'boolean'  => $this->renderToggle($id, $name, (bool) $value, $field),
            'select'   => $this->renderSelect($id, $name, (string) ($value ?? ''), $field),
            'secret'   => $this->renderSecret($id, $name, $path),
            'url'      => $this->renderText($id, $name, (string) ($value ?? ''), 'url'),
            'email'    => $this->renderText($id, $name, (string) ($value ?? ''), 'email'),
            default    => $this->renderText($id, $name, (string) ($value ?? ''), 'text'),
        };
    }

    private function renderText(string $id, string $name, string $value, string $type): string {
        $v = esc_attr($value);
        return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\">";
    }

    private function renderTextarea(string $id, string $name, string $value): string {
        $v = esc_textarea($value);
        return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"3\">{$v}</textarea>";
    }

    private function renderColor(string $id, string $name, string $value): string {
        $v = esc_attr($value);
        return <<<HTML
<span class="hwe-color-picker">
    <span class="hwe-color-preview" style="background:{$v}"></span>
    <input type="color" id="{$id}" name="{$name}" value="{$v}">
    <input type="text" id="{$id}_text" name="{$name}" value="{$v}" class="small-text" maxlength="7"
        aria-label="Código hex del color">
</span>
HTML;
    }

    /**
     * Toggle switch moderno para campos booleanos.
     * Reemplaza el checkbox nativo de WordPress.
     */
    private function renderToggle(string $id, string $name, bool $checked, array $field): string {
        $c     = $checked ? ' checked' : '';
        $label = esc_html($field['label'] ?? '');
        return <<<HTML
<label class="hwe-toggle">
    <input type="hidden" name="{$name}" value="0">
    <input type="checkbox" id="{$id}" name="{$name}" value="1"{$c}>
    <span class="hwe-toggle-track">
        <span class="hwe-toggle-thumb"></span>
    </span>
    <span class="hwe-toggle-label">{$label}</span>
</label>
HTML;
    }

    private function renderSelect(string $id, string $name, string $current, array $field): string {
        $options = $field['options'] ?? [];
        $opts    = '';
        foreach ($options as $val => $label) {
            $selected = ($current === (string) $val) ? ' selected' : '';
            $opts    .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $val),
                $selected,
                esc_html($label)
            );
        }
        return "<select id=\"{$id}\" name=\"{$name}\">{$opts}</select>";
    }

    private function renderSecret(string $id, string $name, array $path): string {
        $hasValue = $this->secretExists($path);
        $ph       = $hasValue
            ? esc_attr(__('●●●●●●●● (dejar en blanco para conservar)', 'hwe'))
            : esc_attr(__('Introducir valor...', 'hwe'));
        return "<input type=\"password\" id=\"{$id}\" name=\"{$name}\" value=\"\" autocomplete=\"new-password\" placeholder=\"{$ph}\">";
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

    /** Comprueba si ya hay un secreto almacenado en la ruta dada. */
    private function secretExists(array $path): bool {
        $current = $this->secrets;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }
        return is_string($current) && $current !== '';
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
