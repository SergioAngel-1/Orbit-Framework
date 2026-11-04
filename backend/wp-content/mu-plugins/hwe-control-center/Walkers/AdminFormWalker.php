<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\FieldTypes\Registry;
use HWE\ControlCenter\Schema;
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
 * Atributos de presentación solo-admin (documentados en el docblock de `Schema`; no tocan el dato
 * ni la API): `show_if` en un campo, y `summary`, `master`, `blocks`, `switcher` y `slots.keys` en un
 * grupo. Pintarlos solo añade marcado (`data-hwe-*`): quien los interpreta es `assets/admin.js`, y
 * con el JS apagado todo se ve.
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
     * Contenido de cada grupo anidado SIN su `<details>`, y los atributos de su cabecera, por ruta con
     * puntos: lo reutilizan los bloques con otro título y el selector de grupos.
     *
     * @var array<string,string>
     */
    private array $inner = [];

    /** @var array<string,string> */
    private array $groupAttrs = [];

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
        $id    = $this->fieldId($path);
        $name  = $this->fieldName($path);
        $label = esc_html($field['label'] ?? $key);
        $desc  = isset($field['description'])
            ? '<p class="description">' . esc_html($field['description']) . '</p>'
            : '';

        $input = $this->renderInput($field, $id, $name, $value, $path);
        // El interruptor ya lleva su texto al lado: la etiqueta de la fila solo queda para lectores de pantalla.
        $modifier = ($field['type'] ?? 'text') === 'boolean' ? ' hwe-field--toggle' : '';

        $attrs = $this->languageAttrs($field, $path) . $this->showIfAttr($field, $path);

        $html = <<<HTML
<div class="hwe-field{$modifier}"{$attrs}>
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
        $dotted  = implode('.', $path);

        // Las raíces las reparte PanelLayout entre pestañas; aquí solo importa el contenido.
        if ($depth === 1) {
            return $content;
        }

        // Selector de grupos (una landing a la vez): sin cabecera plegable, el selector es la cabecera.
        if (!empty($group['switcher']) && is_array($group['switcher'])) {
            $html = $this->renderSwitcher($group, $path);
            $this->fragments[$dotted] = ['html' => $html, 'bare' => $html, 'group' => true];
            return $html;
        }

        if (!empty($group['blocks']) && is_array($group['blocks'])) {
            $content = $this->renderBlocks($group, $children, $path);
        } elseif (!empty($group['slots']) && is_array($group['slots'])) {
            $content = $this->renderSlots($group['slots'], $children);
        }

        // Los grupos de pasarela llevan un atributo para mostrar solo el de la pasarela activa.
        $providerAttr = '';
        if ($path[0] === 'payments' && $key !== 'provider') {
            $providerAttr = ' data-provider="' . esc_attr($key) . '"';
        }
        $attrs = $providerAttr . $this->foldAttrs($group, $path);

        $this->inner[$dotted]      = $content;
        $this->groupAttrs[$dotted] = $attrs;

        $html = $this->renderSubgroup($label, $content, $attrs);
        $this->fragments[$dotted] = [
            'html'  => $html,
            'bare'  => !empty($group['slots']) ? $content : $html,
            'group' => true,
        ];
        return $html;
    }

    // -------------------------------------------------------------------------
    // Helpers de renderizado
    // -------------------------------------------------------------------------

    /** Id HTML del campo de una ruta (el mismo que `visitField` pone al control). */
    private function fieldId(array $path): string {
        return 'hwe_config_' . implode('_', $path);
    }

    /**
     * Atributos de idioma de la fila de un campo, para el conmutador de `admin.js`:
     *   · un campo traducible lleva `data-hwe-translatable` (en el idioma por defecto se ve; en uno
     *     secundario queda como referencia);
     *   · su variante `<clave>_<idioma>` lleva `data-hwe-locale` y el id del campo original
     *     (`data-hwe-base`), de donde el panel saca el texto de referencia.
     * Sin idiomas secundarios no hay variantes: las filas salen sin atributos.
     *
     * @param array<string,mixed> $field
     * @param list<string>        $path
     */
    private function languageAttrs(array $field, array $path): string {
        if (!empty($field['locale']) && !empty($field['variant_of'])) {
            $base = $path;
            array_pop($base);
            $base[] = (string) $field['variant_of'];
            return ' data-hwe-locale="' . esc_attr((string) $field['locale']) . '" data-hwe-base="' . esc_attr($this->fieldId($base)) . '"';
        }
        return !empty($field['translatable']) ? ' data-hwe-translatable' : '';
    }

    /**
     * Condición de visibilidad de un campo según el valor de un HERMANO: `show_if => ['field' => 'layout',
     * 'in' => [...]]` (o `'not_in'`). `admin.js` esconde la fila cuando no se cumple; el control sigue en
     * el formulario y su valor no se toca.
     *
     * @param array<string,mixed> $field
     * @param list<string>        $path
     */
    private function showIfAttr(array $field, array $path): string {
        $cond = $field['show_if'] ?? null;
        if (!is_array($cond) || (string) ($cond['field'] ?? '') === '') {
            return '';
        }
        $sibling = $path;
        array_pop($sibling);
        $sibling[] = (string) $cond['field'];

        $data = ['f' => $this->fieldId($sibling)];
        if (isset($cond['in']) && is_array($cond['in'])) {
            $data['in'] = array_values(array_map('strval', $cond['in']));
        } elseif (isset($cond['not_in']) && is_array($cond['not_in'])) {
            $data['not_in'] = array_values(array_map('strval', $cond['not_in']));
        } else {
            return '';
        }
        return ' data-hwe-show-if="' . esc_attr((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    }

    /**
     * Atributos de la cabecera plegable de un grupo o bloque: resumen de su estado
     * (`data-hwe-summary`) y campo maestro (`data-hwe-master`: apagado, el bloque solo enseña ese campo).
     *
     * @param array<string,mixed> $spec Grupo o bloque con `summary` y/o `master`.
     * @param list<string>        $path Ruta del grupo al que se refieren las claves del resumen.
     */
    private function foldAttrs(array $spec, array $path): string {
        $out = '';
        if (!empty($spec['summary']) && is_array($spec['summary'])) {
            $out .= ' data-hwe-summary="' . esc_attr($this->summaryJson($spec['summary'], $path)) . '"';
        }
        if (!empty($spec['master'])) {
            $out .= ' data-hwe-master="' . esc_attr($this->fieldId([...$path, (string) $spec['master']])) . '"';
        }
        return $out;
    }

    /**
     * Resumen de un bloque, en la forma que lee `admin.js`: `t` (id del interruptor) con sus textos
     * `on`/`off`, `p` (partes: `as` = select | text | slots, `f` = id del campo, `q` = entre comillas,
     * `unit` = «secciones»…) y `empty` (texto si no hay nada que decir).
     *
     * @param array<string,mixed> $summary
     * @param list<string>        $path
     */
    private function summaryJson(array $summary, array $path): string {
        $data = [];
        if (!empty($summary['toggle'])) {
            $data['t']   = $this->fieldId([...$path, (string) $summary['toggle']]);
            $data['on']  = (string) ($summary['on'] ?? __('Visible', 'hwe'));
            $data['off'] = (string) ($summary['off'] ?? __('Oculto', 'hwe'));
        }
        $parts = [];
        foreach ((array) ($summary['parts'] ?? []) as $part) {
            if (!is_array($part)) {
                continue;
            }
            $item = ['as' => (string) ($part['as'] ?? 'text')];
            if (!empty($part['field'])) {
                $item['f'] = $this->fieldId([...$path, (string) $part['field']]);
            }
            if (!empty($part['quote'])) {
                $item['q'] = 1;
            }
            if (!empty($part['unit'])) {
                $item['unit'] = (string) $part['unit'];
            }
            $parts[] = $item;
        }
        if ($parts !== []) {
            $data['p'] = $parts;
        }
        if (!empty($summary['empty'])) {
            $data['empty'] = (string) $summary['empty'];
        }
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Hijos en el orden de `blocks`, cada bloque como un `<details>` con su resumen. Un bloque es:
     *   · una clave de un hijo (grupo: sale con su propia cabecera);
     *   · `['label' => …, 'keys' => [...], 'summary' => …, 'master' => …]`: varios hijos bajo una
     *     cabecera nueva (o `'keys' => ['grupo']` con otro título). Sin `label` salen sueltos.
     * Las variantes traducibles van pegadas a su campo y lo que ningún bloque nombra se añade al
     * final tal cual: reordenar la vista nunca pierde un campo ni toca el orden del dato.
     *
     * @param array<string,mixed>  $group
     * @param array<string,string> $children
     * @param list<string>         $path
     */
    private function renderBlocks(array $group, array $children, array $path): string {
        $defs = (array) ($group['children'] ?? []);
        $used = [];
        $out  = [];

        foreach ($group['blocks'] as $block) {
            $block = is_string($block) ? ['keys' => [$block]] : (array) $block;
            $keys  = [];
            foreach ((array) ($block['keys'] ?? []) as $key) {
                $key = (string) $key;
                if (!isset($children[$key]) || in_array($key, $used, true)) {
                    continue;
                }
                $keys[] = $key;
                $used[] = $key;
                foreach (Schema::secondaryLocales() as $suffix) {
                    $variant = $key . '_' . $suffix;
                    if (isset($children[$variant]) && !in_array($variant, $used, true)) {
                        $keys[] = $variant;
                        $used[] = $variant;
                    }
                }
            }
            if ($keys === []) {
                continue;
            }

            $label  = (string) ($block['label'] ?? '');
            $isGroup = count($keys) === 1 && ($defs[$keys[0]]['type'] ?? null) === 'group';
            if ($isGroup && $label !== '') {
                $dotted = implode('.', [...$path, $keys[0]]);
                $out[]  = $this->renderSubgroup(esc_html($label), $this->inner[$dotted] ?? $children[$keys[0]], $this->groupAttrs[$dotted] ?? '');
                continue;
            }
            if ($label === '') {
                foreach ($keys as $key) {
                    $out[] = $children[$key];
                }
                continue;
            }
            $out[] = $this->renderSubgroup(
                esc_html($label),
                implode("\n", array_map(static fn (string $key): string => $children[$key], $keys)),
                $this->foldAttrs($block, $path)
            );
        }

        foreach ($children as $key => $html) {
            if (!in_array((string) $key, $used, true)) {
                $out[] = $html;
            }
        }
        return implode("\n", $out);
    }

    /**
     * Selector de hijos de un grupo: «Editando: <hijo> ▾» y solo el elegido a la vista (el resto sigue
     * en el formulario y se guarda). Sin JS se ven todos, cada uno con su título; con JS el título lo
     * da el selector y, con un solo hijo, no hay selector.
     *
     * @param array<string,mixed> $group
     * @param list<string>        $path
     */
    private function renderSwitcher(array $group, array $path): string {
        $children = (array) ($group['children'] ?? []);
        if ($children === []) {
            return '';
        }
        $meta     = (array) $group['switcher'];
        $label    = esc_html((string) ($meta['label'] ?? __('Editando', 'hwe')));
        $selectId = esc_attr($this->fieldId($path) . '__switch');

        $options = '';
        $items   = '';
        foreach ($children as $key => $def) {
            $dotted  = implode('.', [...$path, (string) $key]);
            $content = $this->inner[$dotted] ?? ($this->fragments[$dotted]['html'] ?? '');
            $name    = esc_html((string) ($def['label'] ?? $key));
            $value   = esc_attr((string) $key);
            $options .= "<option value=\"{$value}\">{$name}</option>";
            $items   .= <<<HTML
<div class="hwe-switch-item" data-hwe-switch-item="{$value}">
    <h4 class="hwe-switch-title">{$name}</h4>
    {$content}
</div>
HTML;
        }

        return <<<HTML
<div class="hwe-switcher" data-hwe-switcher>
    <div class="hwe-switcher__bar" data-hwe-switcher-bar hidden>
        <label for="{$selectId}">{$label}</label>
        <select id="{$selectId}" data-hwe-switcher-select>{$options}</select>
    </div>
    {$items}
</div>
HTML;
    }

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
     * Cada hijo como hueco opcional (o solo los de `keys`, si se indica: los demás salen antes, tal
     * cual). Sin JS se ven todos y el botón «añadir» sigue oculto.
     *
     * @param array<string,mixed>  $meta     `add_label`, `remove_label`, `empty`, `keys`.
     * @param array<string,string> $children HTML de cada hijo.
     */
    private function renderSlots(array $meta, array $children): string {
        $add    = esc_html((string) ($meta['add_label'] ?? __('Añadir', 'hwe')));
        $remove = esc_html((string) ($meta['remove_label'] ?? __('Quitar', 'hwe')));
        $empty  = esc_html((string) ($meta['empty'] ?? ''));
        $only   = isset($meta['keys']) && is_array($meta['keys']) ? array_map('strval', $meta['keys']) : null;

        $plain = '';
        $slots = '';
        foreach ($children as $key => $html) {
            if ($only !== null && !in_array((string) $key, $only, true)) {
                $plain .= $html . "\n";
                continue;
            }
            $slots .= <<<HTML
<div class="hwe-slot" data-hwe-slot>
    {$html}
    <button type="button" class="button-link button-link-delete hwe-slot-remove" data-hwe-slot-remove hidden>
        <span class="dashicons dashicons-trash" aria-hidden="true"></span> {$remove}
    </button>
</div>
HTML;
        }

        return $plain . <<<HTML
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
