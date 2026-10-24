<?php
/**
 * Precio de miembro en el carrito, el pedido y el BFF (spec §7.2 y §10.3).
 *
 * **Una sola decisión, tres consumidores.** `priceFor()` calcula el precio público, el precio que
 * la tienda cobraría (oferta incluida) y el precio de miembro para un usuario y un producto.
 * `applyCartDiscounts()` usa la misma configuración (`HWE_Memberships_Benefit_Member_Discount`)
 * para rebajar el **precio de línea** del carrito; `cartData()` la publica en la extensión de la
 * Store API. El catálogo sin sesión nunca se toca: no se usan filtros globales de precio.
 *
 * **Mecánica.** En `woocommerce_before_calculate_totals` (prioridad 999, después de WooCommerce y
 * de los plugins que fijan precios) se guarda el precio original de cada línea en el propio item
 * del carrito y se llama a `set_price()` con el precio rebajado. En cada recálculo se parte del
 * precio original guardado, no del ya rebajado: repetir el cálculo no encadena descuentos. Si el
 * socio pierde el nivel, el beneficio se apaga o la línea deja de estar en el alcance, el
 * recálculo devuelve el precio original y retira las metas del carrito (Foco de revisión 3). Las
 * líneas de membresía nunca se descuentan.
 *
 * **Tres propiedades.** Al rebajar el precio de línea, los impuestos se calculan sobre lo
 * rebajado, el cupón se aplica después (WooCommerce lo calcula sobre el precio de línea) y la
 * base de ganancias de `loyalty` (que lee los totales de línea del pedido) lo refleja sola.
 *
 * **Importes.** `priceFor()` devuelve strings decimales de WooCommerce (`wc_format_decimal`, con
 * `wc_get_price_decimals()`); la extensión de la Store API y las líneas del pedido usan esos
 * mismos decimales y la extensión los convierte a unidades menores al publicarlos.
 *
 * **Metas.** En el pedido, cada línea guarda el precio original y el descuento **por unidad**
 * (`META_LINE_ORIGINAL`, `META_LINE_DISCOUNT`), y el pedido el nivel y el porcentaje
 * (`META_ORDER_LEVEL`, `META_ORDER_PERCENT`). La caja del pedido compone una sola fila con el
 * filtro `hwe_memberships_order_box_rows` y retira las filas genéricas que la caja ya genera
 * para esas dos metas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Pricing {

	/** Precio original por unidad, guardado en la línea del pedido. */
	public const META_LINE_ORIGINAL = '_hwe_membership_line_original';

	/** Descuento de miembro por unidad, guardado en la línea del pedido. */
	public const META_LINE_DISCOUNT = '_hwe_membership_line_discount';

	/** Nivel que aportó el descuento, guardado en el pedido. */
	public const META_ORDER_LEVEL = '_hwe_membership_discount_level';

	/** Porcentaje aplicado, guardado en el pedido. */
	public const META_ORDER_PERCENT = '_hwe_membership_discount_percent';

	/** Precio original por unidad en el item del carrito (interno, para no encadenar rebajas). */
	public const CART_ORIGINAL = '_hwe_membership_original_price';

	/** Precio de miembro por unidad en el item del carrito. */
	public const CART_MEMBER = '_hwe_membership_member_price';

	/** Descuento por unidad en el item del carrito. */
	public const CART_DISCOUNT = '_hwe_membership_member_discount';

	/** Porcentaje aplicado en el item del carrito. */
	public const CART_PERCENT = '_hwe_membership_member_percent';

	/** Nivel aplicado en el item del carrito. */
	public const CART_LEVEL = '_hwe_membership_member_level';

	/** Filtro de filas de la caja del pedido (`Admin/OrderBox.php`). */
	public const ORDER_BOX_FILTER = 'hwe_memberships_order_box_rows';

	/** Engancha el cálculo del carrito, la copia al pedido, la extensión y la caja. */
	public static function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return;
		}

		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'applyCartDiscounts' ), 999, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'snapshotLineItem' ), 10, 4 );

		$cartFilter = class_exists( 'HWE_Memberships_StoreApi' )
			? HWE_Memberships_StoreApi::CART_DATA_FILTER
			: 'hwe_memberships_store_api_cart_data';
		add_filter( $cartFilter, array( self::class, 'cartData' ), 10, 2 );
		add_filter( self::ORDER_BOX_FILTER, array( self::class, 'orderBoxRows' ), 10, 2 );
	}

	// ------------------------------------------------------------------------
	//  Precio de referencia (carrito y BFF)
	// ------------------------------------------------------------------------

	/**
	 * Precio de un producto para un usuario.
	 *
	 * `regular` es el precio regular de WooCommerce; `price` es el que el carrito cobraría hoy
	 * (oferta incluida); `member_price` es el rebajado y `percent` el porcentaje aplicado (0 si
	 * no hay descuento). Importes como string decimal de WooCommerce.
	 *
	 * El carrito comparte con esta función la configuración (`activeConfig`/`appliesTo`) y el
	 * redondeo; aplica el porcentaje al precio que ya tiene la línea, que puede incluir ajustes
	 * por carrito de otros plugins.
	 *
	 * @return array{regular:string,price:string,member_price:string,percent:int}
	 */
	public static function priceFor( int $userId, int $productId, ?int $variationId = null ): array {
		$empty = array(
			'regular'      => self::decimal( 0 ),
			'price'        => self::decimal( 0 ),
			'member_price' => self::decimal( 0 ),
			'percent'      => 0,
		);

		if ( $productId <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return $empty;
		}

		$pricedId = null !== $variationId && $variationId > 0 ? $variationId : $productId;
		$product  = wc_get_product( $pricedId );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return $empty;
		}

		$price   = self::decimal( $product->get_price( 'edit' ) );
		$regular = method_exists( $product, 'get_regular_price' )
			? $product->get_regular_price( 'edit' )
			: $price;
		if ( ! is_numeric( $regular ) || (float) $regular <= 0.0 ) {
			$regular = $price;
		}

		$percent = 0;
		$benefit = self::benefit();
		if ( null !== $benefit ) {
			$config = $benefit->activeConfig( $userId );
			if (
				null !== $config
				&& ! self::isMembershipProduct( $productId )
				&& $benefit->appliesTo( $config, $productId )
			) {
				$percent = (int) $config['percent'];
			}
		}

		return array(
			'regular'      => self::decimal( $regular ),
			'price'        => $price,
			'member_price' => $percent > 0 ? self::discountedPrice( (float) $price, $percent ) : $price,
			'percent'      => $percent,
		);
	}

	// ------------------------------------------------------------------------
	//  Carrito
	// ------------------------------------------------------------------------

	/**
	 * Rebaja el precio de cada línea elegible del carrito y guarda el original y el descuento
	 * por unidad. Se ejecuta en cada recálculo: el precio de partida es siempre el original
	 * guardado, así que no se encadenan rebajas.
	 *
	 * @param mixed $cart Carrito que pasa `woocommerce_before_calculate_totals`.
	 */
	public static function applyCartDiscounts( mixed $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$benefit = self::benefit();
		$userId  = self::currentUserId();
		$config  = null;

		if ( $userId > 0 && null !== $benefit ) {
			$config = $benefit->activeConfig( $userId );
		}

		foreach ( (array) $cart->get_cart() as $key => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}

			$product = $item['data'];
			if ( ! method_exists( $product, 'get_price' ) || ! method_exists( $product, 'set_price' ) ) {
				continue;
			}

			$productId = (int) ( $item['product_id'] ?? 0 );
			if ( $productId <= 0 && method_exists( $product, 'get_id' ) ) {
				$productId = (int) $product->get_id();
			}
			if ( $productId <= 0 ) {
				continue;
			}

			$current        = (float) $product->get_price( 'edit' );
			$storedOriginal = isset( $item[ self::CART_ORIGINAL ] ) && is_numeric( $item[ self::CART_ORIGINAL ] )
				? (float) $item[ self::CART_ORIGINAL ]
				: null;
			$storedMember   = isset( $item[ self::CART_MEMBER ] ) && is_numeric( $item[ self::CART_MEMBER ] )
				? (float) $item[ self::CART_MEMBER ]
				: null;

			// Si el precio actual es el que dejamos rebajado, la base es el original guardado.
			// Si otro plugin lo cambió, ese precio nuevo pasa a ser la base (nunca se encadena).
			$base = null !== $storedOriginal && null !== $storedMember && abs( $current - $storedMember ) < 0.00001
				? $storedOriginal
				: $current;

			$percent = 0;
			$levelId = 0;
			if (
				null !== $config
				&& null !== $benefit
				&& ! self::isMembershipProduct( $productId )
				&& $benefit->appliesTo( $config, $productId )
			) {
				$percent = (int) $config['percent'];
				$levelId = (int) $config['level_id'];
			}

			$member = $percent > 0 ? (float) self::discountedPrice( $base, $percent ) : $base;

			if ( $percent <= 0 || $member >= $base ) {
				// Sin descuento: se devuelve el original solo si esta línea lo tenía rebajado.
				if ( null !== $storedOriginal ) {
					$product->set_price( $base );
				}
				self::clearCartItem( $cart, $key );
				continue;
			}

			$product->set_price( $member );
			self::writeCartItem(
				$cart,
				$key,
				array(
					self::CART_ORIGINAL => self::decimal( $base ),
					self::CART_MEMBER   => self::decimal( $member ),
					self::CART_DISCOUNT => self::decimal( $base - $member ),
					self::CART_PERCENT  => $percent,
					self::CART_LEVEL    => $levelId,
				)
			);
		}
	}

	/**
	 * Rellena `member_discount` en la extensión de la Store API con el total y el detalle por
	 * línea, en unidades menores y como string.
	 *
	 * @param mixed $data    Estado calculado por `HWE_Memberships_StoreApi::cartState()`.
	 * @param mixed $context Contexto del filtro (`cart`, `user_id`, `state`).
	 * @return array<string,mixed>
	 */
	public static function cartData( mixed $data, mixed $context = array() ): array {
		if ( ! is_array( $data ) ) {
			self::log( 'el filtro de la extensión recibió ' . get_debug_type( $data ) . '; se ignora.' );
			return array();
		}

		$context = is_array( $context ) ? $context : array();
		$userId  = (int) ( $context['user_id'] ?? 0 );
		$cart    = $context['cart'] ?? null;

		$data['member_discount'] = self::discountData( $userId, $cart );

		return $data;
	}

	// ------------------------------------------------------------------------
	//  Pedido y caja
	// ------------------------------------------------------------------------

	/**
	 * Copia a la línea del pedido el precio original y el descuento por unidad, y al pedido el
	 * nivel y el porcentaje. Las líneas de membresía no llevan metas de descuento.
	 *
	 * @param mixed $item        Línea del pedido.
	 * @param mixed $cartItemKey Clave del carrito.
	 * @param mixed $values      Valores del item del carrito.
	 * @param mixed $order       Pedido que se está creando.
	 */
	public static function snapshotLineItem( mixed $item, mixed $cartItemKey = '', mixed $values = array(), mixed $order = null ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$values = is_array( $values ) ? $values : array();
		if ( ! isset( $values[ self::CART_DISCOUNT ] ) || ! is_numeric( $values[ self::CART_DISCOUNT ] ) ) {
			$cart = self::cart();
			if (
				null !== $cart
				&& method_exists( $cart, 'get_cart_item' )
				&& is_scalar( $cartItemKey )
			) {
				$found = $cart->get_cart_item( (string) $cartItemKey );
				if ( is_array( $found ) ) {
					$values = $found;
				}
			}
		}

		$original = isset( $values[ self::CART_ORIGINAL ] ) && is_numeric( $values[ self::CART_ORIGINAL ] )
			? (float) $values[ self::CART_ORIGINAL ]
			: 0.0;
		$discount = isset( $values[ self::CART_DISCOUNT ] ) && is_numeric( $values[ self::CART_DISCOUNT ] )
			? (float) $values[ self::CART_DISCOUNT ]
			: 0.0;
		$percent  = isset( $values[ self::CART_PERCENT ] ) && is_numeric( $values[ self::CART_PERCENT ] )
			? max( 0, min( 100, (int) $values[ self::CART_PERCENT ] ) )
			: 0;
		$levelId  = isset( $values[ self::CART_LEVEL ] ) && is_numeric( $values[ self::CART_LEVEL ] )
			? (int) $values[ self::CART_LEVEL ]
			: 0;

		if ( $original <= 0.0 || $discount <= 0.0 ) {
			return;
		}

		$item->add_meta_data( self::META_LINE_ORIGINAL, self::decimal( $original ), true );
		$item->add_meta_data( self::META_LINE_DISCOUNT, self::decimal( $discount ), true );

		if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
			if ( $levelId > 0 ) {
				$order->update_meta_data( self::META_ORDER_LEVEL, $levelId );
			}
			if ( $percent > 0 ) {
				$order->update_meta_data( self::META_ORDER_PERCENT, $percent );
			}
		}
	}

	/**
	 * Añade la fila del descuento a la caja del pedido y retira las dos filas genéricas que la
	 * caja ya genera para las metas de nivel y porcentaje.
	 *
	 * @param mixed $rows  Filas de la caja.
	 * @param mixed $order Pedido.
	 * @return mixed
	 */
	public static function orderBoxRows( mixed $rows, mixed $order = null ): mixed {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return $rows;
		}

		$rawLevel   = $order->get_meta( self::META_ORDER_LEVEL );
		$rawPercent = $order->get_meta( self::META_ORDER_PERCENT );
		$levelId    = is_scalar( $rawLevel ) && is_numeric( $rawLevel ) ? (int) $rawLevel : 0;
		$percent    = is_scalar( $rawPercent ) && is_numeric( $rawPercent )
			? max( 0, min( 100, (int) $rawPercent ) )
			: 0;
		if ( $percent <= 0 ) {
			return $rows;
		}

		$genericLabels = array(
			self::humanLabel( self::META_ORDER_LEVEL ),
			self::humanLabel( self::META_ORDER_PERCENT ),
		);

		$filtered = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$filtered[] = $row;
				continue;
			}
			$label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( in_array( $label, $genericLabels, true ) ) {
				continue;
			}
			$filtered[] = $row;
		}

		$levelName = '';
		if ( $levelId > 0 && class_exists( 'HWE_Memberships_Levels' ) && method_exists( 'HWE_Memberships_Levels', 'get' ) ) {
			$level = HWE_Memberships_Levels::get( $levelId );
			$levelName = null === $level ? '' : trim( (string) ( $level['name'] ?? '' ) );
		}
		if ( '' === $levelName ) {
			$levelName = $levelId > 0 ? '#' . $levelId : __( 'Nivel', 'hwe' );
		}

		$filtered[] = array(
			'label' => __( 'Descuento de miembro', 'hwe' ),
			'value' => sprintf( '%1$s · %2$d%%', esc_html( $levelName ), $percent ),
			'state' => 'info',
		);

		return $filtered;
	}

	// ------------------------------------------------------------------------
	//  Cálculo interno
	// ------------------------------------------------------------------------

	/**
	 * Descuento del carrito para la extensión: total y detalle por línea en unidades menores.
	 *
	 * @return array{total:string,lines:object}
	 */
	private static function discountData( int $userId, mixed $cart ): array {
		$empty = array(
			'total' => '0',
			'lines' => (object) array(),
		);

		if ( $userId <= 0 || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return $empty;
		}

		$benefit = self::benefit();
		if ( null === $benefit ) {
			return $empty;
		}

		$config = $benefit->activeConfig( $userId );
		if ( null === $config ) {
			return $empty;
		}

		$lines = array();
		$total = 0.0;

		foreach ( (array) $cart->get_cart() as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$productId = (int) ( $item['product_id'] ?? 0 );
			if ( $productId <= 0 || self::isMembershipProduct( $productId ) ) {
				continue;
			}
			if ( ! $benefit->appliesTo( $config, $productId ) ) {
				continue;
			}

			$quantity      = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$unitDiscount  = 0.0;
			$storedPercent = isset( $item[ self::CART_PERCENT ] ) && is_numeric( $item[ self::CART_PERCENT ] )
				? (int) $item[ self::CART_PERCENT ]
				: -1;
			if (
				$storedPercent === (int) $config['percent']
				&& isset( $item[ self::CART_DISCOUNT ] )
				&& is_numeric( $item[ self::CART_DISCOUNT ] )
			) {
				$unitDiscount = max( 0.0, (float) $item[ self::CART_DISCOUNT ] );
			} else {
				$variationId = (int) ( $item['variation_id'] ?? 0 );
				$quote       = self::priceFor( $userId, $productId, $variationId > 0 ? $variationId : null );
				if ( $quote['percent'] > 0 ) {
					$unitDiscount = max( 0.0, (float) $quote['price'] - (float) $quote['member_price'] );
				}
			}

			if ( $unitDiscount <= 0.0 ) {
				continue;
			}

			$lineTotal = round( $unitDiscount * $quantity, self::decimals() );
			$lines[ (string) $key ] = self::minorUnits( $lineTotal );
			$total += $lineTotal;
		}

		return array(
			'total' => self::minorUnits( $total ),
			'lines' => (object) $lines,
		);
	}

	/** Instancia registrada del beneficio, o `null` si no está disponible. */
	private static function benefit(): ?HWE_Memberships_Benefit_Member_Discount {
		if (
			! class_exists( 'HWE_Memberships_Benefits' )
			|| ! class_exists( 'HWE_Memberships_Benefit_Member_Discount' )
		) {
			return null;
		}

		try {
			$benefit = HWE_Memberships_Benefits::get( HWE_Memberships_Benefit_Member_Discount::KEY );
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo leer el beneficio de descuento; no se aplica.' );
			return null;
		}

		return $benefit instanceof HWE_Memberships_Benefit_Member_Discount ? $benefit : null;
	}

	/** ¿El producto es una línea de membresía? Una variación hereda el vínculo de su padre. */
	private static function isMembershipProduct( int $productId ): bool {
		if ( $productId <= 0 || ! class_exists( 'HWE_Memberships_Products' ) ) {
			return false;
		}
		if ( HWE_Memberships_Products::isMembershipProduct( $productId ) ) {
			return true;
		}
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			$parentId = (int) wp_get_post_parent_id( $productId );
			if ( $parentId > 0 ) {
				return HWE_Memberships_Products::isMembershipProduct( $parentId );
			}
		}

		return false;
	}

	/** Precio rebajado con el redondeo de WooCommerce. */
	private static function discountedPrice( float $price, int $percent ): string {
		$percent = max( 0, min( 100, $percent ) );
		$value   = round( $price * ( 100 - $percent ) / 100, self::decimals() );

		return self::decimal( max( 0.0, $value ) );
	}

	/** Importe decimal de WooCommerce (string). */
	private static function decimal( mixed $value ): string {
		$amount = is_numeric( $value ) ? (float) $value : 0.0;

		return function_exists( 'wc_format_decimal' )
			? (string) wc_format_decimal( $amount, self::decimals() )
			: number_format( $amount, self::decimals(), '.', '' );
	}

	/** Importe en unidades menores como string (convención de la Store API). */
	private static function minorUnits( float $amount ): string {
		$factor = 10 ** self::decimals();

		return (string) (int) round( $amount * $factor );
	}

	private static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/** Escribe las metas internas de una línea del carrito (la propiedad pública de `WC_Cart`). */
	private static function writeCartItem( object $cart, int|string $key, array $meta ): void {
		if ( ! isset( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) || ! isset( $cart->cart_contents[ $key ] ) ) {
			self::log( 'no se pudo guardar el descuento de la línea ' . (string) $key . ' en el carrito.' );
			return;
		}

		foreach ( $meta as $name => $value ) {
			$cart->cart_contents[ $key ][ $name ] = $value;
		}
	}

	/** Retira las metas internas de una línea que ya no tiene descuento. */
	private static function clearCartItem( object $cart, int|string $key ): void {
		if ( ! isset( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) || ! isset( $cart->cart_contents[ $key ] ) ) {
			return;
		}

		foreach ( array( self::CART_ORIGINAL, self::CART_MEMBER, self::CART_DISCOUNT, self::CART_PERCENT, self::CART_LEVEL ) as $name ) {
			unset( $cart->cart_contents[ $key ][ $name ] );
		}
	}

	/** Etiqueta legible de una meta, igual que la caja del pedido (`humanKey`). */
	private static function humanLabel( string $key ): string {
		$key = (string) preg_replace( '/^_hwe_membership_/', '', $key );
		$key = str_replace( array( '_', '-' ), ' ', $key );

		return ucfirst( trim( $key ) );
	}

	private static function currentUserId(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** Carrito actual, o `null` fuera de una petición de WooCommerce. */
	private static function cart(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();

		return is_object( $woocommerce ) && isset( $woocommerce->cart ) && is_object( $woocommerce->cart )
			? $woocommerce->cart
			: null;
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
