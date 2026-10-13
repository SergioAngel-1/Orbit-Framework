<?php

namespace HWE\Landings;

/**
 * Opciones de los selectores de producto y de categoría del contenido de una landing.
 *
 * Consultas de dos columnas, no objetos `WC_Product`: el esquema se construye también fuera del
 * panel (la API pública de la config lo recorre). Lo ya guardado que ya no exista en la lista se
 * conserva: el saneado de un `select` descarta lo que no esté en sus opciones y el primer
 * guardado del panel borraría la selección en silencio.
 */
final class Options {

	/** @var array<string,array<string,string>> */
	private static array $cache = array();

	/**
	 * Categorías de producto: slug → nombre.
	 *
	 * @return array<string,string>
	 */
	public static function categories( string $stored = '' ): array {
		if ( ! isset( self::$cache['categories'] ) ) {
			$options = array();
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_cat' ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( $term instanceof \WP_Term ) {
							$options[ $term->slug ] = ( $term->parent ? '— ' : '' ) . $term->name;
						}
					}
				}
			}
			self::$cache['categories'] = $options;
		}
		return self::withStored( self::$cache['categories'], array( $stored ) );
	}

	/**
	 * Productos publicados de una categoría (todos si está vacía): slug → nombre, más los
	 * `$selected` ya elegidos aunque queden fuera.
	 *
	 * @param list<string> $selected
	 * @return array<string,string>
	 */
	public static function products( string $category = '', array $selected = array() ): array {
		$key = 'products:' . $category;
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = self::queryProducts( $category );
		}
		return self::withStored( self::$cache[ $key ], $selected );
	}

	/** @return array<string,string> */
	private static function queryProducts( string $category ): array {
		$options = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$args = array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'objects',
			);
			if ( '' !== $category ) {
				$args['category'] = array( $category );
			}
			foreach ( (array) wc_get_products( $args ) as $product ) {
				if ( $product instanceof \WC_Product ) {
					$options[ $product->get_slug() ] = $product->get_name();
				}
			}
		}
		return $options;
	}

	/**
	 * Las claves guardadas que falten se añaden con su propio texto (lo guardado va primero).
	 *
	 * @param array<string,string> $options
	 * @param list<string>         $stored
	 * @return array<string,string>
	 */
	private static function withStored( array $options, array $stored ): array {
		$extra = array();
		foreach ( $stored as $slug ) {
			$slug = trim( $slug );
			if ( '' !== $slug && ! array_key_exists( $slug, $options ) ) {
				$extra[ $slug ] = $slug;
			}
		}
		return $extra + $options;
	}

	/** Solo para los tests: la lista de categorías se guarda por petición. */
	public static function reset(): void {
		self::$cache = array();
	}
}
