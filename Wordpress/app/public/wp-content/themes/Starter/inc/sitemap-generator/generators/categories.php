<?php
/**
 * Sitemap Generator — Generador de categorías públicas.
 *
 * Bug #1 corregido: $category_lastmod se inicializa ANTES del bloque
 *   if (!empty($category_term_ids)) para evitar E_WARNING en PHP 8
 *   cuando no hay categorías con stock.
 * Bug #3 corregido: file_put_contents usa LOCK_EX para prevenir XML truncado
 *   en ejecuciones paralelas del cron.
 * Bug #6 corregido: changefreq cambiado de 'daily' a 'weekly' (más preciso
 *   para catálogos de productos).
 * Bug #8 aplicado: usa starter_sitemap_excluded_slugs() como fuente de verdad.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Genera el sitemap XML de categorías públicas (sin membresía).
 * Incluye hreflang ES/EN para cada categoría.
 *
 * @return array{success: bool, message: string, count: int}
 */
function starter_generate_categories_sitemap() {
    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
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

    if (is_wp_error($categories)) {
        return [
            'success' => false,
            'message' => 'Error al consultar categorías: ' . $categories->get_error_message(),
            'count'   => 0,
        ];
    }

    // Filtrar slugs de sistema / membresía (Bug #8: fuente centralizada)
    $excluded_slugs = starter_sitemap_excluded_slugs();
    $categories = array_filter($categories, function ($cat) use ($excluded_slugs) {
        return !in_array($cat->slug, $excluded_slugs, true);
    });

    $category_term_ids = array_map(function ($cat) { return $cat->term_id; }, $categories);

    // Bug #1: inicializar ANTES del bloque condicional para evitar E_WARNING en PHP 8
    $category_lastmod = [];

    if (!empty($category_term_ids)) {
        $in_stock_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_term_ids,
                'operator' => 'IN',
            ]],
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

        $cats_with_stock = [];
        if (!empty($in_stock_ids)) {
            $term_ids_with_stock = wp_get_object_terms($in_stock_ids, 'product_cat', ['fields' => 'ids']);
            if (!is_wp_error($term_ids_with_stock)) {
                $cats_with_stock = array_fill_keys($term_ids_with_stock, true);
            }

            // Construir mapa term_id → fecha máxima de modificación de sus productos en stock
            foreach ($in_stock_ids as $pid) {
                $modified  = get_post_modified_time('Y-m-d', true, $pid) ?: date('Y-m-d');
                $prod_cats = wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']);
                if (!is_wp_error($prod_cats)) {
                    foreach ($prod_cats as $cid) {
                        if (!isset($category_lastmod[$cid]) || $modified > $category_lastmod[$cid]) {
                            $category_lastmod[$cid] = $modified;
                        }
                    }
                }
            }
        }

        $categories = array_filter($categories, function ($cat) use ($cats_with_stock) {
            return isset($cats_with_stock[$cat->term_id]);
        });
    }

    $base_url = STARTER_SITEMAP_BASE_URL;

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    $count = 0;
    foreach ($categories as $category) {
        $slug    = $category->slug;
        $es_url  = "{$base_url}/catalogo/{$slug}";
        $en_url  = "{$base_url}/en/catalog/{$slug}";
        $lastmod = $category_lastmod[$category->term_id] ?? date('Y-m-d');

        // Prioridad: padres 0.8, hijos 0.7
        $priority = $category->parent === 0 ? '0.8' : '0.7';

        // Bug #6: weekly (las categorías no cambian a diario)
        $changefreq = 'weekly';

        // Entrada ES
        $xml .= "\n  <url>\n";
        $xml .= "    <loc>{$es_url}</loc>\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"es\" href=\"{$es_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$en_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$es_url}\" />\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";

        // Entrada EN
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$en_url}</loc>\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"es\" href=\"{$es_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$en_url}\" />\n";
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$es_url}\" />\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
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

    $file_path = $sitemaps_dir . '/categories.xml';

    // No sobreescribir si el contenido es idéntico: preserva mtime
    if (file_exists($file_path) && file_get_contents($file_path) === $xml) {
        return [
            'success' => true,
            'message' => "Sitemap de categorías sin cambios — {$count} categorías (no se sobreescribió).",
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
        'message' => "Sitemap de categorías generado: {$count} categorías públicas con stock ({$count} × 2 URLs con ES/EN).",
        'count'   => $count,
    ];
}
