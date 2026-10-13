<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Editor de filas compartido por los tipos `lines` y `pairs`.
 *
 * El valor REAL sigue siendo un textarea con `name` (una entrada por línea; en `pairs`,
 * `valor | etiqueta`): `assets/admin.js` lo reescribe en cada cambio de las filas. El
 * guardado y el dato no cambian, y sin JS el textarea se ve y se edita a mano como antes.
 */
final class RowsEditor {

    /**
     * @param string               $kind `'lines'` (un input por fila) o `'pairs'` (dos).
     * @param array<string,mixed>  $meta `placeholder`/`placeholders`, `add_label`, `max`.
     */
    public static function render(string $id, string $name, string $current, string $kind, array $meta): string {
        $add = esc_html((string) ($meta['add_label'] ?? ($kind === 'pairs' ? __('+ Añadir dato', 'hwe') : __('+ Añadir línea', 'hwe'))));
        $max = (int) ($meta['max'] ?? 0);

        if ($kind === 'pairs') {
            $placeholders = [
                esc_attr((string) (($meta['placeholders'][0] ?? ''))),
                esc_attr((string) (($meta['placeholders'][1] ?? ''))),
            ];
        } else {
            $placeholders = [esc_attr((string) ($meta['placeholder'] ?? ''))];
        }

        $rows = '';
        foreach (explode("\n", str_replace("\r", '', $current)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($kind === 'pairs') {
                $parts = explode('|', $line, 2);
                $rows .= self::row([trim($parts[0]), trim($parts[1] ?? '')], $placeholders);
            } else {
                $rows .= self::row([trim($line)], $placeholders);
            }
        }
        $tpl = $kind === 'pairs' ? self::row(['', ''], $placeholders) : self::row([''], $placeholders);
        $v   = esc_textarea($current);

        return <<<HTML
<div class="hwe-rows-editor" data-kind="{$kind}" data-max="{$max}">
    <ol class="hwe-rows-editor-rows">{$rows}</ol>
    <button type="button" class="button hwe-rows-editor-add">{$add}</button>
    <template class="hwe-rows-editor-tpl">{$tpl}</template>
    <textarea id="{$id}" name="{$name}" rows="3" class="hwe-rows-editor-value">{$v}</textarea>
</div>
HTML;
    }

    /**
     * Una fila del editor. Los inputs NO llevan `name`: el dato viaja en el textarea.
     *
     * @param list<string> $values
     * @param list<string> $placeholders ya escapados
     */
    private static function row(array $values, array $placeholders): string {
        $inputs = '';
        foreach ($values as $i => $value) {
            $inputs .= sprintf(
                '<input type="text" class="hwe-rows-editor-input" value="%s" placeholder="%s">',
                esc_attr($value),
                $placeholders[$i] ?? ''
            );
        }
        $up     = esc_attr(__('Subir', 'hwe'));
        $down   = esc_attr(__('Bajar', 'hwe'));
        $remove = esc_attr(__('Quitar', 'hwe'));
        return <<<HTML
<li class="hwe-rows-editor-row">
    <span class="hwe-rows-editor-inputs">{$inputs}</span>
    <span class="hwe-rows-editor-actions">
        <button type="button" class="button-link hwe-rows-editor-up" title="{$up}" aria-label="{$up}">↑</button>
        <button type="button" class="button-link hwe-rows-editor-down" title="{$down}" aria-label="{$down}">↓</button>
        <button type="button" class="button-link hwe-rows-editor-remove" title="{$remove}" aria-label="{$remove}">✕</button>
    </span>
</li>
HTML;
    }
}
