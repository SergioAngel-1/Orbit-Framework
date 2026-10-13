<?php
/**
 * Ejemplo: una landing con diseño propio (módulo `landings`).
 *
 * La declara la instancia con `hwe_landings_static_entries`; su CONTENIDO se edita en el mismo
 * panel que las landings del registro (Control Center → Landings), y su pieza de código (una
 * sección única) vive en el frontend (`unique-sections.tsx`). La clave, el `base` y el tipo deben
 * coincidir con `instanceRegistry.landings.staticEntries` de `frontend/src/instance/registry.ts`.
 */

add_filter( 'hwe_landings_static_entries', static function ( $entries ) {
	if ( ! is_array( $entries ) ) {
		return $entries;
	}
	$entries['example-guide'] = array(
		'label'  => 'Guía de ejemplo',
		'jsonld' => 'article',
		'base'   => 'area',
	);
	return $entries;
} );
