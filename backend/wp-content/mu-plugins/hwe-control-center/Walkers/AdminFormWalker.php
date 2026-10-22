<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\Log;
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
 * Un campo con `panel` (ver Schema) no sale en su sección: se guarda aparte y
 * `withRelocated()` lo añade al final de su pestaña destino. Un grupo con `slots` envuelve cada hijo como hueco opcional
 * (`assets/admin.js` oculta los vacíos y gestiona «añadir»/«quitar»).
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

    /** @var array<string,array<string,list<string>>> pestaña → sección → filas HTML. */
    private array $relocated = [];

    /** @var array<string,string> pestaña destino → pestaña de origen (respaldo si no existe). */
    private array $relocatedFrom = [];

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

        $html = <<<HTML
<div class="hwe-field">
    <label for="{$id}">{$label}</label>
    <div class="hwe-field-control">
        {$input}
        {$desc}
    </div>
</div>
HTML;

        $tab = (string) ($field['panel']['tab'] ?? '');
        if ($tab !== '' && $tab !== $path[0]) {
            $section = (string) ($field['panel']['section'] ?? '');
            $this->relocated[$tab][$section][] = $html;
            $this->relocatedFrom[$tab] ??= $path[0];
            return '';
        }
        return $html;
    }

    /**
     * Añade a `$sections` (la salida de `walk()`) los campos reubicados con `panel`, envueltos
     * en un bloque por sección al final de su pestaña destino. Si esa pestaña no existe, se
     * quedan en la de origen y se registra: un campo nunca desaparece del formulario.
     *
     * @param array<string,string> $sections HTML por clave raíz.
     * @return array<string,string>
     */
    public function withRelocated(array $sections): array {
        foreach ($this->relocated as $tab => $groups) {
            $target = $tab;
            if (!isset($sections[$tab])) {
                $target = $this->relocatedFrom[$tab];
                Log::once(
                    'admin:panel:' . $tab,
                    sprintf('«panel» apunta a la pestaña «%s», que no existe; los campos se quedan en «%s».', $tab, $target)
                );
            }
            foreach ($groups as $section => $rows) {
                $sections[$target] = ($sections[$target] ?? '')
                    . $this->renderSubgroup(esc_html($section), implode("\n", $rows), 'dashicons-id', '');
            }
        }
        return $sections;
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        $label   = esc_html($group['label'] ?? $key);
        $content = implode("\n", array_values($children));
        $depth   = count($path);

        // Depth 1 groups are rendered as tab panels by AdminPage.
        if ($depth === 1) {
            return $this->renderFormGroup($key, $label, $content);
        }

        if (!empty($group['slots']) && is_array($group['slots'])) {
            $content = $this->renderSlots($group['slots'], $children);
        }

        // Payment provider subgroups get a data attribute for conditional display.
        $providerAttr = '';
        if ($path[0] === 'payments' && $key !== 'provider') {
            $providerAttr = ' data-provider="' . esc_attr($key) . '"';
        }

        return $this->renderSubgroup($label, $content, $this->subgroupIcon($key), $providerAttr);
    }

    // -------------------------------------------------------------------------
    // Helpers de renderizado
    // -------------------------------------------------------------------------

    /** Subgrupo anidado: card con borde izquierdo azul. `$label` ya va escapado. */
    private function renderSubgroup(string $label, string $content, string $icon, string $attrs): string {
        return <<<HTML
<div class="hwe-subgroup"{$attrs}>
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

    /**
     * Cada hijo como hueco opcional. Sin JS se ven todos y el botón «añadir» sigue oculto.
     *
     * @param array<string,string> $meta     `add_label`, `remove_label`, `empty`.
     * @param array<string,string> $children HTML de cada hijo.
     */
    private function renderSlots(array $meta, array $children): string {
        $add    = esc_html((string) ($meta['add_label'] ?? __('Añadir', 'hwe')));
        $remove = esc_html((string) ($meta['remove_label'] ?? __('Quitar', 'hwe')));
        $empty  = esc_html((string) ($meta['empty'] ?? ''));

        $slots = '';
        foreach ($children as $html) {
            $slots .= <<<HTML
<div class="hwe-slot" data-hwe-slot>
    {$html}
    <button type="button" class="button-link button-link-delete hwe-slot-remove" data-hwe-slot-remove hidden>
        <span class="dashicons dashicons-trash"></span> {$remove}
    </button>
</div>
HTML;
        }

        return <<<HTML
<div class="hwe-slots" data-hwe-slots>
    <p class="description hwe-slots-empty" data-hwe-slots-empty hidden>{$empty}</p>
    {$slots}
    <button type="button" class="button hwe-slots-add" data-hwe-slots-add hidden>
        <span class="dashicons dashicons-plus-alt2"></span> {$add}
    </button>
</div>
HTML;
    }

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
