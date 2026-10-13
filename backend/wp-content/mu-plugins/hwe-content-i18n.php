<?php
/**
 * Plugin Name: HWE Content i18n
 * Description: Traducción del contenido (entradas, páginas, productos, categorías y
 *              etiquetas) a N idiomas secundarios. Cada traducción vive en una meta
 *              `_hwe_<campo>_<idioma>` del post o del término (incluido `_hwe_slug_<idioma>`),
 *              se edita en la caja «Traducciones» de la ficha y en el editor del término, y
 *              sale por WPGraphQL como `translation(locale)` en cada tipo y
 *              `hweSlugFrom(postType, locale, slug)` para la búsqueda inversa del slug.
 *              Los idiomas son `i18n.locales` del Control Center; el idioma por defecto es el
 *              contenido nativo de WordPress. Con un solo idioma no registra nada.
 *              Sustituye a hwe-content-translation, hwe-slug-translation,
 *              hwe-category-translation, hwe-tag-translation y
 *              hwe-blog-category-translation de la versión anterior; sus claves `_en` valen
 *              sin migrar datos.
 *
 * Filtros: `hwe_content_i18n_locales` (idiomas), `hwe_translatable_fields` (campos por tipo).
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress no carga las subcarpetas de `mu-plugins/`: este archivo las incluye.
require_once __DIR__ . '/hwe-content-i18n/registry.php';
require_once __DIR__ . '/hwe-content-i18n/storage.php';
require_once __DIR__ . '/hwe-content-i18n/admin.php';
require_once __DIR__ . '/hwe-content-i18n/graphql.php';
require_once __DIR__ . '/hwe-content-i18n/search.php';
