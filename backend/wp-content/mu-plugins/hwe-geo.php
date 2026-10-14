<?php
/**
 * Plugin Name: HWE Geo
 * Description: Paquetes por país para los formularios de dirección y de documento: divisiones
 *              administrativas, ciudades con su división, tipos de documento, prefijo telefónico y
 *              política de la dirección. Sirve el paquete de `ecommerce.country` en
 *              `GET /wp-json/hwe/v1/geo`. El núcleo trae Colombia (`CO`) y un paquete genérico de
 *              texto libre; un país sin paquete cae al genérico y se registra.
 *
 * Filtro: `hwe_geo_packs` (añadir o sustituir paquetes). Tag ISR: `geo`.
 *
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress no carga las subcarpetas de `mu-plugins/`: este archivo las incluye.
require_once __DIR__ . '/hwe-geo/packs.php';
require_once __DIR__ . '/hwe-geo/rest.php';
