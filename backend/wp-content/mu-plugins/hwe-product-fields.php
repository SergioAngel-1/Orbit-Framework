<?php
/**
 * Plugin Name: HWE Product Fields
 * Description: Campos de producto DECLARATIVOS. La instancia los declara con el filtro
 *              `hwe_product_fields` (clave, etiqueta, tipo, si es traducible y en qué caja va) y
 *              el núcleo pinta las cajas en la ficha del producto de WooCommerce, sanea al
 *              guardar y expone los valores por WPGraphQL como `Product.hweFields(locale)`:
 *              una lista de pares clave-valor ya resuelta al idioma. Los campos traducibles
 *              se editan en la caja «Traducciones» de `hwe-content-i18n`.
 *              El núcleo no declara ningún campo propio.
 *
 * Almacenamiento: meta `_hwe_<clave>` (idioma por defecto) y `_hwe_<clave>_<idioma>` (la
 * traducción: la misma convención de `hwe-content-i18n`, así que un dato heredado con claves
 * `_hwe_<clave>` y `_hwe_<clave>_en` vale sin migrar).
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress no carga las subcarpetas de `mu-plugins/`: este archivo las incluye.
require_once __DIR__ . '/hwe-product-fields/registry.php';
require_once __DIR__ . '/hwe-product-fields/admin.php';
require_once __DIR__ . '/hwe-product-fields/graphql.php';
