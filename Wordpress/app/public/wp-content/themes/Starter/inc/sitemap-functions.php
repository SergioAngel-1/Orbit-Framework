<?php
/**
 * Sitemap Functions — wrapper de carga.
 * Sigue el patrón de banner-functions.php: carga el módulo completo desde su carpeta.
 *
 * @package Starter
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/sitemap-generator/index.php';
