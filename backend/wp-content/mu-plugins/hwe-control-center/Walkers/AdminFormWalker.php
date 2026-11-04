<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\Walker;

/**
 * Genera el HTML de cada campo del formulario de wp-admin recorriendo el esquema.
 *
 * El walker PINTA; `PanelLayout` decide dónde va cada cosa (pestaña y sección) según `PanelMap`.
 * Tras `walk()`, `fragments()` da el HTML de cada nodo por su ruta con puntos:
 *   Campo (hoja) → fila `<div class="hwe-field">` con label + control.
 *   Grupo (profundidad 2+) → bloque `<details class="hwe-subgroup">` plegado, con título + campos.
 *
 * Los secretos renderizan un <input type="password"> vacío con placeholder;
 * el handler de guardado conserva el valor existente si se deja en blanco.
 *
 * Un grupo con `slots` envuelve cada hijo como hueco opcional (`assets/admin.js` oculta los
 * vacíos y gestiona «añadir»/«quitar»). Su fragmento `bare` es solo el contenedor de huecos: la
 * sección que lo coloca ya lleva el título.
 *
 * Uso:
 *   $walker = new AdminFormWalker($secretsExist);
 *   $walker->walk(Schema::get(), $merged);
 *   $tabs = PanelLayout::compose($schema, $walker->fragments());
 */
class AdminFormWalker extends Walker {

    /**
     * @param array $secrets Mapa anidado que indica si ya existe un secreto en
     *                       cada ruta (true = ya hay valor almacenado).
     */
    public function __construct(private readonly array $secrets = []) {}

    /**
     * Fragmento de cada nodo visitado, por ruta con puntos (`legal.locations.location_1`).
     * `PanelLayout` los coloca en las secciones del panel: el walker pinta, el mapa decide dónde.
     *
     * @var array<string,array{html:string,bare:string,group:bool}>
     */
    private array $fragments = [];

    /**
     * Fragmentos del último `walk()`. Un campo es su fila; un grupo, su bloque con título
     * (`html`) y su contenido sin el título (`bare`), que se usa cuando la sección ya lo lleva.
     *
     * @return array<string,array{html:string,bare:string,group:bool}>
     */
    public function fragments(): array {
        return $this->fragments;
    }

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
        // El interruptor ya lleva su texto al lado: la etiqueta de la fila solo queda para lectores de pantalla.
        $modifier = ($field['type'] ?? 'text') === 'boolean' ? ' hwe-field--toggle' : '';

        $html = <<<HTML
<div class="hwe-field{$modifier}">
    <label for="{$id}">{$label}</label>
    <div class="hwe-field-control">
        {$input}
        {$desc}
    </div>
</div>
HTML;

        $this->fragments[implode('.', $path)] = ['html' => $html, 'bare' => $html, 'group' => false];

        return $html;
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        $label   = esc_html($group['label'] ?? $key);
        $content = implode("\n", array_values($children));
        $depth   = count($path);

        // Las raíces las reparte PanelLayout entre pestañas; aquí solo importa el contenido.
        if ($depth === 1) {
            return $content;
        }

        if (!empty($group['slots']) && is_array($group['slots'])) {
            $content = $this->renderSlots($group['slots'], $children);
        }

        // Los grupos de pasarela llevan un atributo para mostrar solo el de la pasarela activa.
        $providerAttr = '';
        if ($path[0] === 'payments' && $key !== 'provider') {
            $providerAttr = ' data-provider="' . esc_attr($key) . '"';
        }

        $html = $this->renderSubgroup($label, $content, $providerAttr);
        $this->fragments[implode('.', $path)] = [
            'html'  => $html,
            'bare'  => !empty($group['slots']) ? $content : $html,
            'group' => true,
        ];
        return $html;
    }

    // -------------------------------------------------------------------------
    // Helpers de renderizado
    // -------------------------------------------------------------------------

    /**
     * Bloque anidado (sede, pasarela, grupo de un módulo): un `<details>` plegado de serie. Se
     * pliega sin JS; `admin.js` lo abre si algo dentro tiene el foco o un error, marca con un punto
     * el que guarda cambios sin guardar y escribe en `data-hwe-summary-out` el resumen de su estado.
     * `$label` ya va escapado.
     */
    private function renderSubgroup(string $label, string $content, string $attrs): string {
        $dirty = esc_html(__('con cambios sin guardar', 'hwe'));
        return <<<HTML
<details class="hwe-subgroup" data-hwe-fold{$attrs}>
    <summary class="hwe-subgroup__head">
        <h4 class="hwe-subgroup-title">{$label}</h4>
        <span class="hwe-fold__summary" data-hwe-summary-out></span>
        <span class="hwe-fold__dirty" data-hwe-fold-dirty hidden><span class="screen-reader-text">{$dirty}</span></span>
    </summary>
    <div class="hwe-fields">
        {$content}
    </div>
</details>
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
        <span class="dashicons dashicons-trash" aria-hidden="true"></span> {$remove}
    </button>
</div>
HTML;
        }

        return <<<HTML
<div class="hwe-slots" data-hwe-slots>
    <p class="description hwe-slots-empty" data-hwe-slots-empty hidden>{$empty}</p>
    {$slots}
    <button type="button" class="button hwe-slots-add" data-hwe-slots-add hidden>
        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> {$add}
    </button>
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
}
