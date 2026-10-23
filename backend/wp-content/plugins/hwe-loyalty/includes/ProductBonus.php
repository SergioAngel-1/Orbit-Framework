<?php
/**
 * Bono por producto al referidor de nivel 1 (spec §5.5 y §6.2).
 *
 * El bono se declara como **campo de producto del módulo** con `hwe_product_fields` (entero ≥ 0,
 * no traducible): así el núcleo pinta la caja en la ficha, sanea el valor al guardar y lo expone
 * por `Product.hweFields`. El meta es `_hwe_loyalty_referrer_bonus` (la convención
 * `hwe_product_field_meta_key()` del núcleo).
 *
 * Aquí no se decide a quién se paga ni cuándo: `HWE_Loyalty_Earnings` suma `bono × cantidad` por
 * línea y abona el total al referidor N1. Un valor negativo, decimal o no numérico nunca se
 * convierte en puntos (se redondea hacia abajo y se acota a 0).
 *
 * Sin el mu-plugin `hwe-product-fields` el campo no se pinta, pero la lectura sigue funcionando:
 * un sitio que ya tenga el meta guardado conserva su bono.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_ProductBonus {

	/** Clave del campo en `hwe_product_fields` (minúsculas, guion bajo). */
	public const FIELD = 'loyalty_referrer_bonus';

	/** Meta donde el núcleo guarda el valor: `_hwe_<clave>`. */
	public const META = '_hwe_loyalty_referrer_bonus';

	/** Engancha el campo. Lo llama `HWE_Loyalty::boot()` con WooCommerce presente. */
	public static function register(): void {
		add_filter( 'hwe_product_fields', array( self::class, 'field' ) );
	}

	/**
	 * Declara el campo del módulo. Respeta un campo con la misma clave declarado antes (el
	 * filtro se aplica en cadena) y devuelve el valor recibido si no es un mapa.
	 *
	 * @param mixed $fields
	 * @return mixed
	 */
	public static function field( $fields ) {
		if ( ! is_array( $fields ) || isset( $fields[ self::FIELD ] ) ) {
			return $fields;
		}

		$fields[ self::FIELD ] = array(
			'label'        => 'Bono al referidor por unidad comprada',
			'type'         => 'number',
			'translatable' => false,
			'box'          => 'Fidelización',
			'description'  => 'Unidades que recibe el referidor por cada unidad de este producto. 0 = sin bono.',
		);

		return $fields;
	}

	/**
	 * Bono por unidad del producto, ya saneado: entero ≥ 0, redondeado hacia abajo. `0` cuando no
	 * hay meta, el valor no es numérico o el id no es válido.
	 */
	public static function bonusFor( int $productId ): int {
		if ( $productId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return 0;
		}

		$key = function_exists( 'hwe_product_field_meta_key' )
			? hwe_product_field_meta_key( self::FIELD )
			: self::META;

		$raw = get_post_meta( $productId, $key, true );
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0;
		}

		return max( 0, (int) floor( (float) $raw ) );
	}
}
