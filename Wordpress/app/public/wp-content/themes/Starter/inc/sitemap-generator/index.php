<?php
/**
 * Sitemap Generator — Orquestador del módulo.
 * Carga los subarchivos en el orden correcto para respetar dependencias.
 *
 * Orden:
 *   1. config.php          → constantes, helpers de ruta, excluded_slugs
 *   2. generators/         → funciones de generación (dependen de config)
 *   3. cron.php            → hooks de WP-Cron (dependen de generators)
 *   4. admin-menu.php      → UI de administración (depende de generators + config)
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

$_sitemap_dir = dirname(__FILE__);

require_once $_sitemap_dir . '/config.php';
require_once $_sitemap_dir . '/generators/categories.php';
require_once $_sitemap_dir . '/generators/products.php';
require_once $_sitemap_dir . '/generators/sitemap-index.php';
require_once $_sitemap_dir . '/cron.php';
require_once $_sitemap_dir . '/admin-menu.php';

unset($_sitemap_dir);
