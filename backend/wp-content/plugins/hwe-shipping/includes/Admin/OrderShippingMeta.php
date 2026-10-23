<?php

namespace HWE\Shipping\Admin;

/**
 * Metas de la tarifa de envío y de la línea de envío del pedido (WooCommerce copia las de la tarifa
 * a la línea al crear el pedido).
 *
 * - Las del CONTRATO con el frontend van sin `_` y salen por la Store API
 *   (`shipping_rates[].meta_data`): `hwe_option_id`, `hwe_city_type` y, en distancia,
 *   `hwe_distance_km` y `hwe_fallback`. En wp-admin se ocultan como claves en crudo y se pintan
 *   legibles aquí.
 * - Las de OPERACIÓN (proveedor, motivo del respaldo, duración, punto de entrega, motivo del envío
 *   gratis) van con `_`: la Store API omite las metas protegidas, así que nunca llegan al navegador.
 *   Claves estables (no traducidas); la etiqueta se traduce al pintarlas.
 *
 * Solo se pintan en la ficha del pedido de wp-admin (`woocommerce_after_order_itemmeta`), no en los
 * correos ni en la cuenta del cliente.
 */
class OrderShippingMeta {

	public const OPTION_ID   = 'hwe_option_id';
	public const CITY_TYPE   = 'hwe_city_type';
	public const DISTANCE_KM = 'hwe_distance_km';
	public const FALLBACK    = 'hwe_fallback';

	public const PROVIDER        = '_hwe_distance_provider';
	public const FALLBACK_REASON = '_hwe_fallback_reason';
	public const DURATION_MIN    = '_hwe_duration_min';
	public const DELIVERY_POINT  = '_hwe_delivery_point';
	public const FREE_REASON     = '_hwe_free_shipping_reason';

	/** Metas del contrato: salen por la Store API y en wp-admin se ocultan como claves en crudo. */
	public const PUBLIC_KEYS = [ self::OPTION_ID, self::CITY_TYPE, self::DISTANCE_KM, self::FALLBACK ];

	public static function register(): void {
		add_filter( 'woocommerce_hidden_order_itemmeta', [ self::class, 'hide' ] );
		add_action( 'woocommerce_after_order_itemmeta', [ self::class, 'render' ], 10, 2 );
	}

	/**
	 * @param mixed $hidden Claves que WooCommerce no pinta en la ficha del pedido.
	 * @return array<int,string>
	 */
	public static function hide( $hidden ): array {
		$hidden = is_array( $hidden ) ? $hidden : [];
		return array_values( array_unique( array_merge( $hidden, self::PUBLIC_KEYS ) ) );
	}

	/**
	 * Resumen legible del envío bajo la línea de envío del pedido.
	 *
	 * @param int|string $itemId
	 * @param mixed      $item
	 */
	public static function render( $itemId, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Shipping ) {
			return;
		}

		$rows = self::rows( $item );
		if ( [] === $rows ) {
			return;
		}

		echo '<table class="display_meta hwe-shipping-order-meta">';
		foreach ( $rows as [ $label, $value ] ) {
			echo '<tr><th>' . esc_html( $label ) . ':</th><td>' . $value . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Filas `[etiqueta, valor HTML ya escapado]` de una línea de envío.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function rows( \WC_Order_Item_Shipping $item ): array {
		$meta = static fn ( string $key ): string => trim( (string) $item->get_meta( $key, true ) );
		$rows = [];

		$type = $meta( self::CITY_TYPE );
		if ( '' !== $type ) {
			$types = [
				'fixed'    => __( 'Tarifa fija', 'hwe-shipping' ),
				'distance' => __( 'Por distancia', 'hwe-shipping' ),
				'reserve'  => __( 'Tarifa de reserva', 'hwe-shipping' ),
			];
			$rows[] = [ __( 'Tipo de envío', 'hwe-shipping' ), esc_html( $types[ $type ] ?? $type ) ];
		}

		$km = $meta( self::DISTANCE_KM );
		if ( '' !== $km ) {
			$rows[] = [ __( 'Distancia', 'hwe-shipping' ), esc_html( $km . ' km' ) ];
		}

		$provider = $meta( self::PROVIDER );
		if ( '' !== $provider ) {
			$rows[] = [ __( 'Proveedor de distancia', 'hwe-shipping' ), esc_html( $provider ) ];
		}

		if ( 'yes' === $meta( self::FALLBACK ) ) {
			$reason = $meta( self::FALLBACK_REASON );
			$rows[] = [
				__( 'Respaldo en línea recta', 'hwe-shipping' ),
				esc_html( '' !== $reason ? $reason : __( 'sí', 'hwe-shipping' ) ),
			];
		}

		$duration = $meta( self::DURATION_MIN );
		if ( '' !== $duration ) {
			$rows[] = [ __( 'Duración estimada', 'hwe-shipping' ), esc_html( $duration . ' min' ) ];
		}

		$point = self::point( $meta( self::DELIVERY_POINT ) );
		if ( null !== $point ) {
			[ $lat, $lng ] = $point;
			$url    = sprintf( 'https://www.openstreetmap.org/?mlat=%1$.6F&mlon=%2$.6F#map=17/%1$.6F/%2$.6F', $lat, $lng );
			$rows[] = [
				__( 'Punto de entrega', 'hwe-shipping' ),
				esc_html( sprintf( '%.6F, %.6F', $lat, $lng ) ) . ' — <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ver en el mapa', 'hwe-shipping' ) . '</a>',
			];
		}

		$free = $meta( self::FREE_REASON );
		if ( '' !== $free ) {
			$rows[] = [ __( 'Envío gratis', 'hwe-shipping' ), esc_html( $free ) ];
		}

		return $rows;
	}

	/** Valor de la meta del punto (`lat,lng`) como par de números válido, o null. */
	public static function point( string $value ): ?array {
		$parts = array_map( 'trim', explode( ',', $value ) );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}
		$lat = (float) $parts[0];
		$lng = (float) $parts[1];
		if ( abs( $lat ) > 90 || abs( $lng ) > 180 ) {
			return null;
		}
		return [ $lat, $lng ];
	}

	/** Texto de la meta del punto a partir de sus coordenadas (6 decimales, punto decimal). */
	public static function pointValue( float $lat, float $lng ): string {
		return sprintf( '%.6F,%.6F', $lat, $lng );
	}
}
