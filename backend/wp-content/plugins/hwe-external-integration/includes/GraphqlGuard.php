<?php

namespace HWE\ExternalIntegration;

use GraphQL\Error\UserError;

/**
 * Lo que puede pedir y cambiar quien llega a GraphQL como integración
 * (`Access::isRestrictedRequest()`): la política de `Policy` hecha filtros de WPGraphQL.
 *
 * El BFF (secreto interno) y WP-CLI no se ven afectados: para ellos
 * `isRestrictedRequest()` es `false`.
 *
 * Por qué cada pieza:
 *   · Operaciones: sin lista, la IP autorizada alcanzaría `login` (emite token sin el 2FA del
 *     BFF), `registerUser`, `users`, `posts`…
 *   · updateOrder: la mutación de WooGraphQL acepta cambiar facturación, líneas, envío…; la
 *     integración solo cambia el estado.
 *   · metaData: `Order.metaData` devuelve TODO el meta del pedido, incluidos los `_hwe_*`
 *     (documento de identidad, consentimiento, IP del comprador).
 */
final class GraphqlGuard {

	public static function register(): void {
		add_filter( 'graphql_pre_resolve_field', array( self::class, 'preResolveField' ), 10, 5 );
		add_filter( 'graphql_mutation_input', array( self::class, 'mutationInput' ), 10, 4 );
		add_filter( 'graphql_pre_mutate_and_get_payload', array( self::class, 'updateOrder' ), 10, 6 );
		add_filter( 'graphql_resolve_field', array( self::class, 'metaData' ), 10, 7 );
		add_filter( 'customer_restricted_cap', array( self::class, 'customerCap' ) );
	}

	/**
	 * Operaciones raíz y campos vetados. La introspección (`__schema`, `__typename`) pasa.
	 *
	 * @param mixed $nil
	 * @param mixed $source
	 * @param mixed $args
	 * @param mixed $context
	 * @param mixed $info
	 * @return mixed
	 */
	public static function preResolveField( $nil, $source, $args, $context, $info ) {
		if ( ! Access::isRestrictedRequest() ) {
			return $nil;
		}
		$policy = Policy::get();
		$parent = isset( $info->parentType->name ) ? (string) $info->parentType->name : '';
		$field  = isset( $info->fieldName ) ? (string) $info->fieldName : '';

		$blocked = $policy['blocked_fields'][ $parent ] ?? array();
		if ( in_array( $field, $blocked, true ) ) {
			throw new UserError( sprintf( 'Campo no permitido para esta integración: %1$s.%2$s.', $parent, $field ) );
		}
		if ( 'RootQuery' !== $parent && 'RootMutation' !== $parent ) {
			return $nil;
		}
		if ( str_starts_with( $field, '__' ) ) {
			return $nil;
		}
		$allowed = 'RootQuery' === $parent ? $policy['queries'] : $policy['mutations'];
		if ( ! in_array( $field, $allowed, true ) ) {
			throw new UserError( sprintf( 'Operación no permitida para esta integración: %s.', $field ) );
		}
		return $nil;
	}

	/**
	 * `updateOrder`: solo identificar el pedido y cambiar su estado.
	 *
	 * @param mixed $input
	 * @param mixed $context
	 * @param mixed $info
	 * @param mixed $mutationName
	 * @return mixed
	 */
	public static function mutationInput( $input, $context, $info, $mutationName ) {
		if ( 'updateOrder' !== $mutationName || ! Access::isRestrictedRequest() ) {
			return $input;
		}
		$extra = array_diff( array_keys( (array) $input ), Policy::get()['update_order_fields'] );
		if ( ! empty( $extra ) ) {
			throw new UserError(
				sprintf( 'updateOrder solo admite cambiar el estado; campos no permitidos: %s.', implode( ', ', $extra ) )
			);
		}
		if ( empty( $input['status'] ) ) {
			throw new UserError( 'updateOrder exige «status».' );
		}
		return $input;
	}

	/**
	 * `updateOrder` de la integración NO pasa por la mutación de WooGraphQL: el cambio de estado
	 * lo hace este filtro con `update_status()` y nada más.
	 *
	 * La mutación original, aunque solo reciba `status`, ejecuta siempre `set_created_via(
	 * 'graphql-api' )` y `calculate_totals( true )`. Lo primero pisa el origen del pedido; lo
	 * segundo recalcula el total desde las líneas, y un pedido migrado sin líneas se quedaba en 0.
	 *
	 * La autorización es la MISMA de WooGraphQL (`Order_Mutation::authorized`); si esa clase
	 * falta, falla cerrado. `graphql_mutation_input` ya validó la entrada.
	 *
	 * @param mixed $pre
	 * @param mixed $mutationName
	 * @param mixed $callback
	 * @param mixed $input
	 * @param mixed $context
	 * @param mixed $info
	 * @return mixed
	 */
	public static function updateOrder( $pre, $mutationName, $callback, $input, $context, $info ) {
		if ( 'updateOrder' !== $mutationName || ! Access::isRestrictedRequest() ) {
			return $pre;
		}
		$authorizer = '\\WPGraphQL\\WooCommerce\\Data\\Mutation\\Order_Mutation';
		if ( ! class_exists( $authorizer ) || ! function_exists( 'wc_get_order' ) ) {
			throw new UserError( 'updateOrder no está disponible.' );
		}
		$orderId = 0;
		if ( ! empty( $input['id'] ) ) {
			$orderId = (int) \WPGraphQL\Utils\Utils::get_database_id_from_id( $input['id'] );
		} elseif ( ! empty( $input['orderId'] ) ) {
			$orderId = absint( $input['orderId'] );
		}
		if ( ! $orderId ) {
			throw new UserError( 'updateOrder exige «orderId».' );
		}
		// Lanza si el pedido no existe; `false` si el usuario no puede editarlo.
		if ( ! $authorizer::authorized( $input, $context, $info, 'update', $orderId ) ) {
			throw new UserError( 'No tienes permiso para actualizar este pedido.' );
		}
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			throw new UserError( 'Pedido no encontrado.' );
		}

		self::assertCanTransition( (string) $order->get_status(), (string) ( $input['status'] ?? '' ), $orderId );

		// Como la mutación original: con las pasarelas cargadas, sus hooks de cambio de estado
		// se disparan.
		WC()->payment_gateways();
		$order->update_status( self::target( (string) $input['status'] ) );

		return array( 'id' => $order->get_id() );
	}

	/** `wc-processing` y `PROCESSING` → `processing`. */
	public static function target( string $status ): string {
		return strtolower( (string) preg_replace( '/^wc-/', '', trim( $status ) ) );
	}

	/**
	 * Un pedido sin pagar (`pending`, `failed`) solo puede cancelarse: pasarlo a «procesando» o
	 * «completado» le pone fecha de pago, descuenta inventario y envía al comprador el correo de
	 * un pedido cobrado, y eso solo lo puede afirmar el webhook de la pasarela. Un
	 * `checkout-draft` (carrito a medias) no se toca.
	 */
	public static function assertCanTransition( string $current, string $requested, int $orderId ): void {
		$target = self::target( $requested );
		$unpaid = Policy::get()['unpaid_statuses'];
		if ( 'checkout-draft' === $current || ( in_array( $current, $unpaid, true ) && 'cancelled' !== $target ) ) {
			throw new UserError(
				sprintf( 'El pedido %d está sin pagar: desde ese estado solo se puede cancelar.', $orderId )
			);
		}
	}

	/**
	 * `metaData`. En el pedido, solo las claves de la política: la regla «todo lo que no empiece
	 * por `_`» dejaba salir, en los pedidos heredados de otra web, la respuesta completa de la
	 * pasarela, las cookies de seguimiento del comprador y su documento. En las líneas, el meta
	 * visible (sin `_`).
	 *
	 * @param mixed $result
	 * @param mixed $source
	 * @param mixed $args
	 * @param mixed $context
	 * @param mixed $info
	 * @param mixed $typeName
	 * @param mixed $fieldKey
	 * @return mixed
	 */
	public static function metaData( $result, $source, $args, $context, $info, $typeName, $fieldKey ) {
		if ( 'metaData' !== $fieldKey || ! is_array( $result ) || ! Access::isRestrictedRequest() ) {
			return $result;
		}
		$policy    = Policy::get();
		$orderMeta = in_array( (string) $typeName, $policy['order_meta_types'], true );
		$kept      = array_values(
			array_filter(
				$result,
				static function ( $meta ) use ( $orderMeta, $policy ): bool {
					$data = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : (array) $meta;
					$key  = (string) ( $data['key'] ?? '' );
					return $orderMeta
						? in_array( $key, $policy['order_meta_keys'], true )
						: ! str_starts_with( $key, '_' );
				}
			)
		);
		return array() === $kept ? null : $kept;
	}

	/**
	 * `order.customer` devolvería `null` (id y nombre incluidos) para el rol mínimo: WooGraphQL
	 * restringe el objeto `Customer` a `list_users`. Un integrador necesita el id y el nombre del
	 * cliente de cada pedido, así que aquí, y solo para la integración, esa restricción se rebaja
	 * a la capacidad de pedidos. No abre más superficie que el pedido ya abierto: el único camino
	 * a un `Customer` es `order.customer` (las raíces `customers` y `users` están vetadas).
	 *
	 * @param mixed $cap
	 * @return mixed
	 */
	public static function customerCap( $cap ) {
		return ( 'list_users' === $cap && Access::isRestrictedRequest() ) ? 'edit_shop_orders' : $cap;
	}
}
