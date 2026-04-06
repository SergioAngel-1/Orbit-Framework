<?php
/**
 * Sitemap Generator — Generador de productos públicos.
 *
 * Bug #2 corregido: el algoritmo de "categoría más profunda" recorre la cadena
 *   de padres (hasta 10 niveles) para encontrar la categoría con mayor profundidad
 *   real, en lugar de hacer break en la primera hija encontrada.
 * Bug #3 corregido: file_put_contents usa LOCK_EX.
 * Bug #4 corregido: productos sin categoría válida se omiten del sitemap con
 *   error_log descriptivo, en lugar de generar URLs /catalogo/productos/{slug}
 *   que no existen en el router React.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calcula la profundidad de un término en su jerarquía de taxonomía.
 * Máximo de $max_depth niveles para evitar bucles infinitos.
 *
 * @param int    $term_id   ID del término.
 * @param string $taxonomy  Nombre de la taxonomía.
 * @param int    $max_depth Límite de profundidad.
 * @return int Profundidad (0 = raíz).
 */
function starter_sitemap_term_depth( int $term_id, string $taxonomy, int $max_depth = 10 ): int {
    $depth = 0;
    $seen  = [];

    while ($depth < $max_depth) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term) || $term->parent === 0) {
            break;
        }
        if (in_array($term->parent, $seen, true)) {
            break; // prevenir bucle por datos corruptos
        }
        $seen[]  = $term_id;
        $term_id = $term->parent;
        $depth++;
    }

    return $depth;
}

/**
 * Genera el sitemap XML de productos públicos (en categorías sin membresía).
 * Solo incluye productos publicados con _stock_status = instock.
 * Incluye hreflang ES/EN para cada producto.
 *
 * @return array{success: bool, message: string, count: int}
 */
function starter_generate_products_sitemap() {
    // 1. IDs de categorías públicas (nivel 0 o sin meta)
    $public_categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'fields'     => 'ids',
        'meta_query' => [
            'relation' => 'OR',
            [
                'key'     => '_min_membership_level',
                'value'   => '0',
                'compare' => '=',
            ],
            [
                'key'     => '_min_membership_level',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    if (is_wp_error($public_categories) || empty($public_categories)) {
        return [
            'success' => false,
            'message' => 'No se encontraron categorías públicas.',
            'count'   => 0,
        ];
    }

    // 2. IDs de categorías con membresía (nivel > 0)
    $membership_categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'fields'     => 'ids',
        'meta_query' => [
            [
                'key'     => '_min_membership_level',
                'value'   => '0',
                'compare' => '>',
                'type'    => 'NUMERIC',
            ],
        ],
    ]);
    $membership_cat_ids = is_wp_error($membership_categories) ? [] : $membership_categories;

    // 3. Productos publicados en categorías públicas con stock
    $product_ids = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => [
            [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $public_categories,
                'operator' => 'IN',
            ],
        ],
        'meta_query'     => [
            'relation' => 'AND',
            [
                'relation' => 'OR',
                ['key' => '_is_membership_product', 'compare' => 'NOT EXISTS'],
                ['key' => '_is_membership_product', 'value' => 'yes', 'compare' => '!='],
            ],
            [
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ],
        ],
    ]);

    if (empty($product_ids)) {
        return [
            'success' => false,
            'message' => 'No se encontraron productos públicos.',
            'count'   => 0,
        ];
    }

    // 4. Filtrar: excluir productos que pertenecen a categorías con membresía
    $filtered_ids = [];
    foreach ($product_ids as $product_id) {
        $product_cat_ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($product_cat_ids)) {
            continue;
        }

        $has_membership_cat = false;
        foreach ($product_cat_ids as $cat_id) {
            if (in_array($cat_id, $membership_cat_ids, true)) {
                $has_membership_cat = true;
                break;
            }
        }

        if (!$has_membership_cat) {
            $filtered_ids[] = $product_id;
        }
    }

    if (empty($filtered_ids)) {
        return [
            'success' => false,
            'message' => 'Todos los productos encontrados también pertenecen a categorías con membresía.',
            'count'   => 0,
        ];
    }

    // 5. Generar XML
    $base_url = STARTER_SITEMAP_BASE_URL;

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    $count = 0;
    foreach ($filtered_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }

        $slug = $product->get_slug();

        // Obtener la categoría más profunda del producto para construir la URL
        $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);
        $category_slug = '';

        if (!is_wp_error($product_cats) && !empty($product_cats)) {
            // Bug #2: recorrer toda la lista y elegir la categoría con mayor profundidad real
            $deepest_cat   = null;
            $deepest_depth = -1;

            foreach ($product_cats as $cat) {
                $depth = starter_sitemap_term_depth($cat->term_id, 'product_cat');
                if ($depth > $deepest_depth) {
                    $deepest_depth = $depth;
                    $deepest_cat   = $cat;
                }
            }

            if ($deepest_cat !== null) {
                $category_slug = $deepest_cat->slug;
            }
        }

        // Bug #4: omitir del sitemap si no hay categoría válida
        if (!$category_slug) {
            error_log(
                "[Starter Sitemap] Producto ID {$product_id} ('{$slug}') omitido del sitemap: " .
                'no tiene categoría pública válida.'
            );
            continue;
        }

        $es_url  = "{$base_url}/catalogo/{$category_slug}/{$slug}";
        $en_url  = "{$base_url}/en/catalog/{$category_slug}/{$slug}";
        $lastmod = get_post_modified_time('Y-m-d', true, $product_id) ?: date('Y-m-d');

        $priority = '0.4';
        if ($product->get_image_id() && $product->get_price()) {
            $priority = '0.5';
        }

        // Entrada ES
        $xml .= "\n  <url>\n";
        $xml .= "    <loc>{$es_url}</loc>\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"es\" href=\"{$es_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$en_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$es_url}\" />\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";

        // Entrada EN
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$en_url}</loc>\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"es\" href=\"{$es_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$en_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$es_url}\" />\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";

        $count++;
    }

    $xml .= "\n</urlset>\n";

    $sitemaps_dir = starter_get_sitemaps_dir();
    if (!is_dir($sitemaps_dir)) {
        return [
            'success' => false,
            'message' => "Directorio de sitemaps no encontrado: {$sitemaps_dir}",
            'count'   => 0,
        ];
    }

    $file_path = $sitemaps_dir . '/products.xml';

    // No sobreescribir si el contenido es idéntico: preserva mtime
    if (file_exists($file_path) && file_get_contents($file_path) === $xml) {
        return [
            'success' => true,
            'message' => "Sitemap de productos sin cambios — {$count} productos en stock (no se sobreescribió).",
            'count'   => $count,
        ];
    }

    // Bug #3: LOCK_EX previene XML truncado en cron paralelo
    $result = file_put_contents($file_path, $xml, LOCK_EX);

    if ($result === false) {
        return [
            'success' => false,
            'message' => "Error al escribir el archivo: {$file_path}",
            'count'   => 0,
        ];
    }

    return [
        'success' => true,
        'message' => "Sitemap de productos generado: {$count} productos públicos en stock ({$count} × 2 URLs con ES/EN).",
        'count'   => $count,
    ];
}
