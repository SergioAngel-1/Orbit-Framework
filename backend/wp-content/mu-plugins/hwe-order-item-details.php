<?php
/**
 * Plugin Name: HWE Order Item Details
 * Description: Datos de una línea de pedido además del nombre del producto, DECLARATIVOS. La
 *              instancia los declara con el filtro `hwe_order_item_details` (clave de extensión,
 *              etiqueta, validador y a qué productos aplica); el núcleo los toma de
 *              `extensions` en `cart/add-item` de la Store API, los valida en el servidor (si la
 *              validación falla, el ítem NO entra al carrito) y los guarda como meta VISIBLE de la
 *              línea, para que lleguen a los correos de WooCommerce (tienda y cliente), al detalle
 *              del pedido en wp-admin y a la API de pedidos. El núcleo no declara ninguno.
 *
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Clave del ítem del carrito donde viajan los detalles validados. */
const HWE_ITEM_DETAILS_CART_KEY = 'hwe_item_details';
/** Prefijo de la entrada oculta del `item_data` del carrito (la lee el BFF por clave). */
const HWE_ITEM_DETAILS_ITEM_DATA_PREFIX = 'hwe_item_';
/** Prefijo de la meta OCULTA de la línea de pedido (el dato máquina). */
const HWE_ITEM_DETAILS_HIDDEN_META_PREFIX = '_hwe_item_';
/** Tope del valor visible de un detalle. */
const HWE_ITEM_DETAILS_MAX_LENGTH = 1000;

/** Aviso de configuración, una sola vez por petición y clave. */
function hwe_item_details_log( string $key, string $message ): void {
	if ( isset( $GLOBALS['hwe_item_details_logged'][ $key ] ) ) {
		return;
	}
	$GLOBALS['hwe_item_details_logged'][ $key ] = true;
	error_log( '[hwe] order-item-details: ' . $message );
}

/** Olvida el registro validado (los tests declaran detalles distintos en cada caso). */
function hwe_item_details_reset(): void {
	unset( $GLOBALS['hwe_item_details_cache'], $GLOBALS['hwe_item_details_logged'] );
}

/**
 * Detalles declarados y válidos, por clave de extensión.
 *
 * Forma de una entrada (clave → datos):
 *
 *   label       etiqueta visible (correos, wp-admin, API) — obligatoria
 *   validate    callable( $raw, int $productId ): string|array|WP_Error — obligatorio
 *               · string  → el valor visible ya normalizado;
 *               · array   → `['value' => string, 'data' => mixed]`: valor visible y dato máquina
 *                           (se guarda como meta oculta `_hwe_item_<clave>`);
 *               · WP_Error → rechaza: el ítem no entra al carrito (400 con su mensaje).
 *   applies_to  list<string> de slugs de producto, o callable( int $productId ): bool — obligatorio
 *               (nada se aplica a todo el catálogo por descuido)
 *   required    bool: sin el dato el ítem no entra ni se paga (por defecto true)
 *   meta_key    meta VISIBLE de la línea (por defecto la propia clave)
 *
 * @return array<string,array{label:string,validate:callable,applies_to:mixed,required:bool,meta_key:string}>
 */
function hwe_order_item_details(): array {
	if ( isset( $GLOBALS['hwe_item_details_cache'] ) ) {
		return $GLOBALS['hwe_item_details_cache'];
	}

	$declared = apply_filters( 'hwe_order_item_details', array() );
	if ( ! is_array( $declared ) ) {
		hwe_item_details_log( 'not-array', 'el filtro hwe_order_item_details debe devolver un mapa clave → datos (devolvió ' . get_debug_type( $declared ) . ').' );
		return $GLOBALS['hwe_item_details_cache'] = array();
	}

	$details = array();
	foreach ( $declared as $key => $def ) {
		$name = is_string( $key ) ? $key : '';
		$why  = '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) ) {
			$why = 'la clave debe estar en minúsculas, con guion bajo (hasta 40 caracteres)';
		} elseif ( ! is_array( $def ) ) {
			$why = 'los datos deben ser una lista';
		} elseif ( ! isset( $def['label'] ) || ! is_string( $def['label'] ) || '' === trim( $def['label'] ) ) {
			$why = 'falta la etiqueta';
		} elseif ( ! isset( $def['validate'] ) || ! is_callable( $def['validate'] ) ) {
			$why = 'falta el validador (`validate` debe ser callable)';
		} elseif ( ! isset( $def['applies_to'] ) || ! ( is_callable( $def['applies_to'] ) || is_array( $def['applies_to'] ) ) ) {
			$why = 'falta `applies_to` (lista de slugs de producto o callable)';
		}
		if ( '' !== $why ) {
			hwe_item_details_log( 'detail:' . $name, 'detalle «' . $name . '» descartado de hwe_order_item_details: ' . $why . '.' );
			continue;
		}
		$metaKey = isset( $def['meta_key'] ) && is_string( $def['meta_key'] ) && '' !== trim( $def['meta_key'] )
			? trim( $def['meta_key'] )
			: $name;

		$details[ $name ] = array(
			'label'      => trim( $def['label'] ),
			'validate'   => $def['validate'],
			'applies_to' => $def['applies_to'],
			'required'   => ! array_key_exists( 'required', $def ) || (bool) $def['required'],
			// Una meta que empieza por `_` es oculta: aquí es la VISIBLE, la de los correos.
			'meta_key'   => ltrim( $metaKey, '_' ),
		);
	}

	return $GLOBALS['hwe_item_details_cache'] = $details;
}

/** ¿Este detalle aplica al producto? */
function hwe_item_details_applies( array $def, int $productId ): bool {
	if ( $productId <= 0 ) {
		return false;
	}
	if ( is_callable( $def['applies_to'] ) ) {
		return (bool) call_user_func( $def['applies_to'], $productId );
	}
	$slug = (string) get_post_field( 'post_name', $productId );
	return '' !== $slug && in_array( $slug, array_map( 'strval', (array) $def['applies_to'] ), true );
}

/**
 * Valida lo que manda el navegador con el validador del detalle. Falla CERRADO: una excepción
 * del validador, o una respuesta que no es válida, rechaza el ítem.
 *
 * @param mixed $raw
 * @return array{value:string,data:mixed}|WP_Error
 */
function hwe_item_details_resolve( string $key, array $def, $raw, int $productId ) {
	try {
		$result = call_user_func( $def['validate'], $raw, $productId );
	} catch ( \Throwable $e ) {
		hwe_item_details_log( 'throw:' . $key, 'el validador de «' . $key . '» lanzó una excepción: ' . $e->getMessage() );
		return new WP_Error( 'hwe_item_detail_failed', 'No se pudo validar «' . $def['label'] . '». Inténtalo de nuevo.' );
	}
	if ( $result instanceof WP_Error ) {
		return $result;
	}
	$data = null;
	if ( is_array( $result ) ) {
		$data   = $result['data'] ?? null;
		$result = $result['value'] ?? '';
	}
	$value = is_scalar( $result ) ? trim( sanitize_text_field( (string) $result ) ) : '';
	if ( '' === $value ) {
		return new WP_Error( 'hwe_item_detail_empty', 'Falta «' . $def['label'] . '».' );
	}
	return array( 'value' => mb_substr( $value, 0, HWE_ITEM_DETAILS_MAX_LENGTH ), 'data' => $data );
}

/** Un dato máquina como texto (la entrada oculta del carrito y la meta oculta del pedido). */
function hwe_item_details_data_string( $data, string $fallback ): string {
	if ( null === $data || '' === $data ) {
		return $fallback;
	}
	return is_string( $data ) ? $data : (string) wp_json_encode( $data );
}

/* Store API `cart/add-item`: los detalles entran como dato del ítem. Dos líneas con datos
   distintos son dos líneas (WooCommerce separa por el hash de `cart_item_data`). */
add_filter(
	'woocommerce_store_api_add_to_cart_data',
	static function ( $data, $request ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$productId  = (int) ( $data['id'] ?? 0 );
		$extensions = $request instanceof WP_REST_Request ? $request->get_param( 'extensions' ) : null;
		$extensions = is_array( $extensions ) ? $extensions : array();

		foreach ( hwe_order_item_details() as $key => $def ) {
			if ( ! hwe_item_details_applies( $def, $productId ) ) {
				continue;
			}
			$raw     = $extensions[ $key ] ?? null;
			$missing = null === $raw || '' === $raw || array() === $raw;
			if ( $missing ) {
				if ( $def['required'] ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'hwe_item_detail_missing',
						esc_html( 'Falta «' . $def['label'] . '».' ),
						400
					);
				}
				continue;
			}
			$resolved = hwe_item_details_resolve( $key, $def, $raw, $productId );
			if ( $resolved instanceof WP_Error ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					esc_html( (string) $resolved->get_error_code() ),
					esc_html( $resolved->get_error_message() ),
					400
				);
			}
			$data['cart_item_data']                                     = (array) ( $data['cart_item_data'] ?? array() );
			$data['cart_item_data'][ HWE_ITEM_DETAILS_CART_KEY ][ $key ] = $resolved;
		}
		return $data;
	},
	10,
	2
);

/* `item_data` del carrito de la Store API: una entrada VISIBLE (el respaldo legible) y una
   OCULTA con el dato máquina, para que el BFF pinte lo que quiera en el idioma de la página. */
add_filter(
	'woocommerce_get_item_data',
	static function ( $itemData, $cartItem ) {
		if ( ! is_array( $itemData ) || ! is_array( $cartItem ) ) {
			return $itemData;
		}
		$stored = $cartItem[ HWE_ITEM_DETAILS_CART_KEY ] ?? null;
		if ( ! is_array( $stored ) ) {
			return $itemData;
		}
		foreach ( hwe_order_item_details() as $key => $def ) {
			if ( ! isset( $stored[ $key ]['value'] ) ) {
				continue;
			}
			$itemData[] = array( 'name' => $def['label'], 'value' => $stored[ $key ]['value'] );
			$itemData[] = array(
				'name'   => HWE_ITEM_DETAILS_ITEM_DATA_PREFIX . $key,
				'value'  => hwe_item_details_data_string( $stored[ $key ]['data'] ?? null, $stored[ $key ]['value'] ),
				'hidden' => true,
			);
		}
		return $itemData;
	},
	10,
	2
);

/* Checkout: una línea sin un detalle obligatorio no se paga. Cubre los carritos que ya la tenían
   de antes y cualquier alta que no pasara por el filtro de arriba. */
add_action(
	'woocommerce_store_api_cart_errors',
	static function ( $errors, $cart ): void {
		if ( ! $errors instanceof WP_Error || ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $cartItem ) {
			$productId = (int) ( $cartItem['product_id'] ?? 0 );
			$stored    = $cartItem[ HWE_ITEM_DETAILS_CART_KEY ] ?? array();
			foreach ( hwe_order_item_details() as $key => $def ) {
				if ( $def['required'] && hwe_item_details_applies( $def, $productId ) && empty( $stored[ $key ]['value'] ) ) {
					$errors->add(
						'hwe_item_detail_missing',
						'A una línea de tu carrito le falta «' . $def['label'] . '». Quítala y vuelve a añadirla.'
					);
					return;
				}
			}
		}
	},
	10,
	2
);

/* Pedido: la línea recibe la meta visible (y la oculta, si hay dato máquina). */
add_action(
	'woocommerce_checkout_create_order_line_item',
	static function ( $item, $cartItemKey, $values ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) ) {
			return;
		}
		$stored = $values[ HWE_ITEM_DETAILS_CART_KEY ] ?? null;
		if ( ! is_array( $stored ) ) {
			return;
		}
		foreach ( hwe_order_item_details() as $key => $def ) {
			if ( ! isset( $stored[ $key ]['value'] ) ) {
				continue;
			}
			$item->add_meta_data( $def['meta_key'], $stored[ $key ]['value'], true );
			$data = $stored[ $key ]['data'] ?? null;
			if ( null !== $data && '' !== $data ) {
				$item->add_meta_data( HWE_ITEM_DETAILS_HIDDEN_META_PREFIX . $key, $data, true );
			}
		}
	},
	10,
	3
);

/* Etiqueta de la meta visible en correos, wp-admin y `display_key` de wc/v3. */
add_filter(
	'woocommerce_order_item_display_meta_key',
	static function ( $displayKey, $meta ) {
		$metaKey = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
		if ( '' === $metaKey ) {
			return $displayKey;
		}
		foreach ( hwe_order_item_details() as $def ) {
			if ( $def['meta_key'] === $metaKey ) {
				return $def['label'];
			}
		}
		return $displayKey;
	},
	10,
	2
);
