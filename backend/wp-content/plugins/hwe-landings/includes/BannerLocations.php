<?php

namespace HWE\Landings;

/**
 * El hero de cada landing es un banner de tipo «Landing» en la locación `landing-<id>`: se
 * añade una locación por landing registrada (las estáticas y las del panel) al catálogo de
 * `hwe-banners`.
 */
final class BannerLocations {

	/**
	 * @param mixed $locations Mapa `slug => etiqueta` del filtro `hwe_banners_locations`.
	 * @return mixed
	 */
	public static function add( $locations ) {
		if ( ! is_array( $locations ) ) {
			return $locations;
		}
		foreach ( Registry::all() as $id => $meta ) {
			$locations[ 'landing-' . $id ] = 'Landing · ' . $meta['label'];
		}
		return $locations;
	}
}
