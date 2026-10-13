<?php

namespace HWE\ControlCenter\FieldTypes;

use HWE\ControlCenter\Log;
use HWE\ControlCenter\Schema;

/**
 * Edición de metas de producto por ítem (persistencia `external`).
 *
 * El nodo declara, por ítem, qué claves meta edita y de qué tipo:
 *
 * ```php
 * 'badges' => [
 *     'type'        => 'product_fields_list',
 *     'label'       => 'Distintivos',
 *     'items'       => ['camiseta' => 'Camiseta'], // slug => etiqueta (la instancia los resuelve)
 *     'meta_fields' => [
 *         'badge' => ['label' => 'Distintivo', 'type' => 'text', 'meta_key' => '_hwe_badge'],
 *     ],
 *     'item_meta_values' => ['camiseta' => ['badge' => 'Nuevo']], // valores actuales
 *     'item_links'       => ['camiseta' => 'https://…/wp-admin/post.php?…'], // opcional
 * ],
 * ```
 *
 * Los inputs viajan DENTRO del árbol `hwe_config`, con nombres derivados del path del nodo
 * (`hwe_config[instance][tienda][badges][camiseta][badge]`): no existen claves POST con
 * nombre de dominio. `sanitizeAndSplit` omite los nodos `external`; el propio tipo los
 * guarda al recibir `hwe_control_center_saved` (ver `saveAllFromSchema`, enganchado en
 * `hwe-control-center.php`).
 *
 * Cada producto se guarda con `$product->save()` (no `update_post_meta` directo) para que
 * dispare el webhook normal de WooCommerce y el frontend revalide el tag `products`, igual
 * que si se hubiera editado desde la ficha. Sin cambios no se guarda ni se dispara nada.
 * Un producto inexistente se ignora y se registra.
 */
final class ProductFieldsListType implements FieldType {

    public function id(): string {
        return 'product_fields_list';
    }

    public function persistence(): string {
        return self::EXTERNAL;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $items = $field['items'] ?? [];
        if ($items === []) {
            return '<p class="description">' . esc_html(__('No hay productos para editar.', 'hwe')) . '</p>';
        }

        $metaFields = $field['meta_fields'] ?? [];
        $values     = $field['item_meta_values'] ?? [];
        $links      = $field['item_links'] ?? [];

        $out = '';
        $i   = 0;
        foreach ($items as $slug => $label) {
            $slug    = (string) $slug;
            $link    = '';
            if (!empty($links[$slug])) {
                $link = sprintf(
                    ' <a class="hwe-product-item-link" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url((string) $links[$slug]),
                    esc_html(__('Editar ficha ↗', 'hwe'))
                );
            }
            $rows = '';
            foreach ($metaFields as $key => $meta) {
                $key       = (string) $key;
                $fieldId   = esc_attr("{$id}_{$i}_{$key}");
                $fieldName = $name . '[' . esc_attr($slug) . '][' . esc_attr($key) . ']';
                $current   = (string) (($values[$slug][$key] ?? ''));
                $control   = Registry::get((string) ($meta['type'] ?? 'textarea'))->render(
                    ['label' => $meta['label'] ?? $key],
                    $fieldId,
                    $fieldName,
                    $current,
                    [...$path, $slug, $key]
                );
                $rows .= '<div class="hwe-item-field">'
                    . '<label for="' . $fieldId . '">' . esc_html((string) ($meta['label'] ?? $key)) . '</label>'
                    . $control
                    . '</div>';
            }
            $out .= '<div class="hwe-product-item"><p class="hwe-product-item-label"><span>'
                . esc_html((string) $label) . '</span>' . $link . '</p>'
                . '<div class="hwe-item-fields">' . $rows . '</div></div>';
            $i++;
        }

        return "<div class=\"hwe-checkbox-list hwe-product-fields-list\" id=\"{$id}\">{$out}</div>";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        // Los `external` no se persisten aquí (los guarda `saveAllFromSchema`); por si alguien
        // llama directamente, no devolver nada que parezca un valor.
        return self::SKIP;
    }

    /**
     * Guarda todos los nodos `product_fields_list` del esquema con lo que trajo el POST.
     * Enganchado a `hwe_control_center_saved` (nonce y capacidad ya comprobados).
     */
    public static function saveAllFromSchema(array $publicData, array $rawPost): void {
        foreach (self::findNodes(Schema::get()) as [$path, $node]) {
            $submitted = $rawPost;
            foreach ($path as $step) {
                if (!is_array($submitted) || !array_key_exists($step, $submitted)) {
                    continue 2;
                }
                $submitted = $submitted[$step];
            }
            if (!is_array($submitted)) {
                continue;
            }
            self::saveSubmitted($submitted, (array) ($node['meta_fields'] ?? []));
        }
    }

    /**
     * @param array<string,array> $schema
     * @return list<array{list<string>,array<string,mixed>}> Pares (path, nodo).
     */
    private static function findNodes(array $schema, array $path = []): array {
        $found = [];
        foreach ($schema as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $current = [...$path, (string) $key];
            if (($node['type'] ?? '') === 'product_fields_list') {
                $found[] = [$current, $node];
            }
            if (($node['type'] ?? '') === 'group' && is_array($node['children'] ?? null)) {
                array_push($found, ...self::findNodes($node['children'], $current));
            }
        }
        return $found;
    }

    /**
     * Escribe las metas declaradas de cada producto.
     *
     * @param array<string,mixed> $submitted slug => [metaKey => valor crudo].
     * @param array<string,array> $metaFields key => ['meta_key' => …, 'type' => …].
     * @param callable|null       $resolveId (slug → id) Solo para tests; en producción es
     *                            una `WP_Query` por slug de producto publicado.
     */
    public static function saveSubmitted(array $submitted, array $metaFields, ?callable $resolveId = null): void {
        if ($metaFields === []) {
            return;
        }
        // Sin WooCommerce no hay productos que editar. Se comprueba la clase principal (que
        // `class_exists` resuelve sin tocar nada) y no `wc_get_product`: mantiene el guardado
        // a salvo también donde esa función aún no se ha cargado.
        if (!class_exists('WooCommerce')) {
            Log::once(
                'product-fields:no-woo',
                'product_fields_list sin efecto: WooCommerce no está activo.'
            );
            return;
        }
        $resolveId ??= [self::class, 'productIdBySlug'];

        foreach ($submitted as $slug => $fields) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '' || !is_array($fields)) {
                continue;
            }
            $productId = (int) $resolveId($slug);
            if ($productId === 0) {
                Log::once(
                    'product-fields:missing:' . $slug,
                    sprintf('product_fields_list: el producto «%s» no existe; se ignora su fila.', $slug)
                );
                continue;
            }
            $product = wc_get_product($productId);
            if (!is_object($product)
                || !method_exists($product, 'get_meta')
                || !method_exists($product, 'update_meta_data')
                || !method_exists($product, 'delete_meta_data')
                || !method_exists($product, 'save')
            ) {
                continue;
            }

            $values  = [];
            $changed = false;
            foreach ($metaFields as $key => $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $metaKey = (string) ($spec['meta_key'] ?? '');
                if ($metaKey === '') {
                    continue;
                }
                $clean = Registry::get((string) ($spec['type'] ?? 'textarea'))->sanitize(
                    [],
                    array_key_exists($key, $fields) ? (string) $fields[$key] : ''
                );
                if ($clean === FieldType::SKIP) {
                    continue;
                }
                $values[$metaKey] = (string) $clean;
                if ($values[$metaKey] !== (string) $product->get_meta($metaKey, true)) {
                    $changed = true;
                }
            }

            if (!$changed) {
                continue;
            }
            foreach ($values as $metaKey => $value) {
                if ($value === '') {
                    $product->delete_meta_data($metaKey);
                } else {
                    $product->update_meta_data($metaKey, $value);
                }
            }
            $product->save();
        }
    }

    private static function productIdBySlug(string $slug): int {
        if (!class_exists('WP_Query')) {
            return 0;
        }
        $query = new \WP_Query([
            'post_type'      => 'product',
            'name'           => $slug,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return (int) ($query->posts[0] ?? 0);
    }
}
