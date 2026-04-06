<?php
/**
 * Sitemap Generator — Actualizador del sitemap-index.xml.
 *
 * Bug #3 corregido: file_put_contents usa LOCK_EX para prevenir XML truncado
 *   en ejecuciones paralelas del cron.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Actualiza el sitemap-index.xml con los lastmod reales de cada sub-sitemap.
 *
 * @return array{success: bool, message: string}
 */
function starter_update_sitemap_index() {
    $base_url     = STARTER_SITEMAP_BASE_URL;
    $sitemaps_dir = starter_get_sitemaps_dir();

    // Fecha de modificación real desde el mtime del filesystem
    $file_lastmod = function (string $path): string {
        return file_exists($path) ? date('Y-m-d', filemtime($path)) : date('Y-m-d');
    };

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Páginas principales (estático)
    $xml .= "\n  <!-- Sitemap de páginas principales -->\n";
    $xml .= "  <sitemap>\n";
    $xml .= "    <loc>{$base_url}/sitemaps/pages.xml</loc>\n";
    $xml .= "    <lastmod>" . $file_lastmod($sitemaps_dir . '/pages.xml') . "</lastmod>\n";
    $xml .= "  </sitemap>\n";

    // Categorías (generado dinámicamente)
    if (file_exists($sitemaps_dir . '/categories.xml')) {
        $xml .= "\n  <!-- Sitemap de categorías del catálogo (generado dinámicamente) -->\n";
        $xml .= "  <sitemap>\n";
        $xml .= "    <loc>{$base_url}/sitemaps/categories.xml</loc>\n";
        $xml .= "    <lastmod>" . $file_lastmod($sitemaps_dir . '/categories.xml') . "</lastmod>\n";
        $xml .= "  </sitemap>\n";
    }

    // Productos (generado dinámicamente)
    if (file_exists($sitemaps_dir . '/products.xml')) {
        $xml .= "\n  <!-- Sitemap de productos públicos (generado dinámicamente) -->\n";
        $xml .= "  <sitemap>\n";
        $xml .= "    <loc>{$base_url}/sitemaps/products.xml</loc>\n";
        $xml .= "    <lastmod>" . $file_lastmod($sitemaps_dir . '/products.xml') . "</lastmod>\n";
        $xml .= "  </sitemap>\n";
    }

    // Legal (estático)
    $xml .= "\n  <!-- Sitemap de páginas legales -->\n";
    $xml .= "  <sitemap>\n";
    $xml .= "    <loc>{$base_url}/sitemaps/legal.xml</loc>\n";
    $xml .= "    <lastmod>" . $file_lastmod($sitemaps_dir . '/legal.xml') . "</lastmod>\n";
    $xml .= "  </sitemap>\n";

    $xml .= "\n</sitemapindex>\n";

    $index_path = starter_get_sitemap_index_path();

    // No sobreescribir si el contenido es idéntico
    if (file_exists($index_path) && file_get_contents($index_path) === $xml) {
        return [
            'success' => true,
            'message' => 'sitemap-index.xml sin cambios (no se sobreescribió).',
        ];
    }

    // Bug #3: LOCK_EX
    $result = file_put_contents($index_path, $xml, LOCK_EX);

    if ($result === false) {
        return [
            'success' => false,
            'message' => "Error al escribir sitemap-index.xml: {$index_path}",
        ];
    }

    $cats_date  = file_exists($sitemaps_dir . '/categories.xml')
        ? date('Y-m-d', filemtime($sitemaps_dir . '/categories.xml')) : '—';
    $prods_date = file_exists($sitemaps_dir . '/products.xml')
        ? date('Y-m-d', filemtime($sitemaps_dir . '/products.xml')) : '—';

    return [
        'success' => true,
        'message' => "sitemap-index.xml actualizado — categorías: {$cats_date}, productos: {$prods_date}.",
    ];
}
