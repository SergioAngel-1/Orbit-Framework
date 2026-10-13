<?php
/**
 * Paquete de país genérico: el de reserva para un país sin paquete propio.
 *
 * Todo es texto libre: sin divisiones ni ciudades cerradas, sin tipos de documento y sin prefijo
 * telefónico. Ningún formulario puede suponer nada del país con este paquete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'country'        => 'generic',
	'name'           => 'Genérico',
	'dial_code'      => '',
	'division_label' => 'Provincia o estado',
	'address'        => array(
		'division'      => 'free',
		'postcode'      => 'optional',
		'city_division' => false,
	),
	'divisions'      => array(),
	'cities'         => array(),
	'document_types' => array(),
);
