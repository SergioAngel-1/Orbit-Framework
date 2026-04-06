<?php
/**
 * Sitemap Generator — Constantes y helpers de ruta.
 *
 * Bug #7 corregido: STARTER_SITEMAP_BASE_URL se define aquí; todos los echo
 *   del valor usarán esc_html() en admin-menu.php.
 * Bug #8 corregido: starter_sitemap_excluded_slugs() centraliza el array de
 *   slugs excluidos, eliminando la duplicación entre generators/categories.php
 *   y admin-menu.php.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Constantes ───────────────────────────────────────────────────────────────

if (!defined('STARTER_SITEMAP_BASE_URL')) {
    // Leer URL del frontend desde Site Settings, HEADLESS_MODE_CLIENT_URL, o fallback
    $sitemap_base = '';
    if (function_exists('site_get_option')) {
        $sitemap_base = site_get_option('frontend_url', '');
    }
    if (empty($sitemap_base) && defined('HEADLESS_MODE_CLIENT_URL')) {
        $sitemap_base = HEADLESS_MODE_CLIENT_URL;
    }
    if (empty($sitemap_base)) {
        $sitemap_base = 'https://example.com';
    }
    define('STARTER_SITEMAP_BASE_URL', rtrim($sitemap_base, '/'));
}

if (!defined('STARTER_SITEMAP_CRON_HOOK')) {
    define('STARTER_SITEMAP_CRON_HOOK', 'starter_generate_sitemaps_cron');
}

// ─── Helpers de ruta ──────────────────────────────────────────────────────────

/**
 * Ruta al directorio de sitemaps del frontend.
 * Prueba candidatos en orden; retorna el primero que exista.
 */
function starter_get_sitemaps_dir() {
    // 1. Ruta explícita desde wp-config.php: define('STARTER_SITEMAPS_DIR', '/path/to/sitemaps');
    if (defined('STARTER_SITEMAPS_DIR') && is_dir(STARTER_SITEMAPS_DIR)) {
        return STARTER_SITEMAPS_DIR;
    }
    
    $candidates = [
        // Local (Docker/dev): subir desde inc/sitemap-generator/
        dirname(__FILE__, 3) . '/frontend/public/sitemaps',
        // Fallback: relativo a ABSPATH
        dirname(ABSPATH, 3) . '/frontend/public/sitemaps',
        // Producción genérica: relativo al document root
        $_SERVER['DOCUMENT_ROOT'] . '/sitemaps',
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    return $candidates[0];
}

/**
 * Ruta al sitemap-index.xml del frontend.
 */
function starter_get_sitemap_index_path() {
    // 1. Ruta explícita desde wp-config.php: define('STARTER_SITEMAP_INDEX_PATH', '/path/to/sitemap-index.xml');
    if (defined('STARTER_SITEMAP_INDEX_PATH') && file_exists(STARTER_SITEMAP_INDEX_PATH)) {
        return STARTER_SITEMAP_INDEX_PATH;
    }
    
    $candidates = [
        // Local (Docker/dev)
        dirname(__FILE__, 3) . '/frontend/public/sitemap-index.xml',
        // Fallback: relativo a ABSPATH
        dirname(ABSPATH, 3) . '/frontend/public/sitemap-index.xml',
        // Producción genérica: relativo al document root
        $_SERVER['DOCUMENT_ROOT'] . '/sitemap-index.xml',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return $candidates[0];
}

/**
 * Slugs de categorías que deben excluirse de todos los sitemaps.
 * Fuente de verdad única — Bug #8.
 *
 * @return string[]
 */
function starter_sitemap_excluded_slugs() {
    return ['uncategorized', 'sin-categorizar', 'paquetes-virtual-coins', 'membresias'];
}
