<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\Walker;

/**
 * Genera el HTML del formulario de wp-admin recorriendo el esquema.
 *
 * Salida por nivel de profundidad:
 *   Profundidad 1 → postbox completo con <table> interna.
 *   Profundidad 2+ → filas <tr> de sección dentro de la tabla del padre.
 *   Campos leaf  → fila <tr> estándar con <label> + input.
 *
 * Los secretos renderizan un <input type="password"> vacío con placeholder;
 * el handler de guardado conserva el valor existente si se deja en blanco.
 *
 * Uso:
 *   $walker   = new AdminFormWalker($storedPublicValues, $hasSecretByPath);
 *   $sections = $walker->walk(Schema::get(), $storedPublicValues);
 *   echo implode("\n", array_values($sections));
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
<tr>
    <th scope="row"><label for="{$id}">{$label}</label></th>
    <td>{$input}{$desc}</td>
</tr>
HTML;
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        $label   = esc_html($group['label'] ?? $key);
        $content = implode("\n", array_values($children));
        $depth   = count($path);

        if ($depth === 1) {
            return $this->renderPostbox($key, $label, $content);
        }

        // Grupos anidados: encabezado de sección + filas de hijos.
        return <<<HTML
<tr class="hwe-subgroup-header">
    <th colspan="2" style="padding-top:1.5em;padding-bottom:0.25em;border-top:1px solid #e5e7eb;">
        <h3 style="margin:0;font-size:13px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.04em;">{$label}</h3>
    </th>
</tr>
{$content}
HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers de renderizado
    // -------------------------------------------------------------------------

    private function renderPostbox(string $key, string $label, string $tableBody): string {
        return <<<HTML
<div class="postbox hwe-section" id="hwe-section-{$key}" style="margin-bottom:1.5em;">
    <div class="postbox-header" style="border-bottom:1px solid #e5e7eb;">
        <h2 class="hndle" style="padding:1em 1.5em;font-size:14px;font-weight:600;">{$label}</h2>
    </div>
    <div class="inside" style="padding:0 1.5em 1em;">
        <table class="form-table" role="presentation">
            <tbody>{$tableBody}</tbody>
        </table>
    </div>
</div>
HTML;
    }

    private function renderInput(array $field, string $id, string $name, mixed $value, array $path): string {
        return match ($field['type']) {
            'textarea' => $this->renderTextarea($id, $name, (string) ($value ?? '')),
            'color'    => $this->renderColor($id, $name, (string) ($value ?? ($field['default'] ?? '#000000'))),
            'boolean'  => $this->renderCheckbox($id, $name, (bool) $value, $field),
            'select'   => $this->renderSelect($id, $name, (string) ($value ?? ''), $field),
            'secret'   => $this->renderSecret($id, $name, $path),
            'url'      => $this->renderText($id, $name, (string) ($value ?? ''), 'url'),
            'email'    => $this->renderText($id, $name, (string) ($value ?? ''), 'email'),
            default    => $this->renderText($id, $name, (string) ($value ?? ''), 'text'),
        };
    }

    private function renderText(string $id, string $name, string $value, string $type): string {
        $v = esc_attr($value);
        return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\" class=\"regular-text\">";
    }

    private function renderTextarea(string $id, string $name, string $value): string {
        $v = esc_textarea($value);
        return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"3\" class=\"large-text\">{$v}</textarea>";
    }

    private function renderColor(string $id, string $name, string $value): string {
        $v = esc_attr($value);
        return <<<HTML
<span style="display:flex;align-items:center;gap:.75em;">
    <input type="color" id="{$id}" name="{$name}" value="{$v}" style="width:48px;height:36px;padding:2px;border:1px solid #8c8f94;border-radius:4px;cursor:pointer;">
    <input type="text" id="{$id}_text" name="{$name}" value="{$v}" class="small-text" maxlength="7" style="width:90px;"
        oninput="document.getElementById('{$id}').value=this.value"
        aria-label="Código hex del color">
</span>
<script>
document.getElementById('{$id}').addEventListener('input',function(){
    var t=document.getElementById('{$id}_text');if(t)t.value=this.value;
});
</script>
HTML;
    }

    private function renderCheckbox(string $id, string $name, bool $checked, array $field): string {
        $c     = $checked ? ' checked' : '';
        $label = esc_html($field['label'] ?? '');
        return <<<HTML
<label>
    <input type="hidden" name="{$name}" value="0">
    <input type="checkbox" id="{$id}" name="{$name}" value="1"{$c}> {$label}
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
        return "<input type=\"password\" id=\"{$id}\" name=\"{$name}\" value=\"\" class=\"regular-text\" autocomplete=\"new-password\" placeholder=\"{$ph}\">";
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
}
