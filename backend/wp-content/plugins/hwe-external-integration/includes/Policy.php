<?php

namespace HWE\ExternalIntegration;

/**
 * La política de la integración: qué puede pedir y qué puede cambiar.
 *
 * Es el filtro `hwe_integration_policy` sobre un valor por defecto CERRADO. La instancia solo
 * puede ABRIR lo que su integrador necesita; hay un suelo que ningún filtro rebaja:
 *
 *   · nunca se permiten operaciones raíz de autenticación o de gestión de usuarios (`login`,
 *     `registerUser`, `users`…): emitirían un token sin el 2FA del BFF o listarían cuentas;
 *   · los campos vetados del defecto no se pueden sacar de la lista;
 *   · un pedido sin pagar solo se puede cancelar (el cobro lo afirma el webhook de la pasarela).
 *
 * Forma (todas las listas son de textos; lo que no cumpla se descarta y se registra):
 *
 *   queries              operaciones raíz de lectura permitidas
 *   mutations            mutaciones raíz permitidas (solo `updateOrder` está implementada)
 *   update_order_fields  campos de la entrada de `updateOrder` (el estado y el identificador)
 *   blocked_fields       tipo → campos que no se pueden leer aunque el tipo sea legible
 *   order_meta_keys      claves de `metaData` visibles en el pedido y sus reembolsos
 *   order_meta_types     tipos cuyo `metaData` va por la lista cerrada (el resto, el meta
 *                        visible: sin `_`)
 *   unpaid_statuses      estados «sin pagar» desde los que solo se puede cancelar
 */
final class Policy {

	/** Operaciones que ninguna política puede abrir. */
	public const DENIED_ROOT = array(
		'login',
		'refreshJwtAuthToken',
		'registerUser',
		'resetUserPassword',
		'sendPasswordResetEmail',
		'createUser',
		'updateUser',
		'deleteUser',
		'users',
		'user',
		'viewer',
		'customers',
		'customer',
	);

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/**
	 * Claves de seguimiento del pedido: el contrato de `hwe-order-tracking`.
	 *
	 * @return list<string>
	 */
	public static function trackingKeys(): array {
		if ( function_exists( 'hwe_order_tracking_fields' ) ) {
			$keys = array_keys( (array) hwe_order_tracking_fields() );
			if ( array() !== $keys ) {
				return array_values( array_map( 'strval', $keys ) );
			}
		}
		return array( '_carrier', '_tracking_number', '_tracking_url' );
	}

	/**
	 * Campos que la integración NO puede leer aunque su tipo sí esté permitido. Por tipo.
	 *   · Order: `orderKey` es la prueba de propiedad de un pedido de invitado en el BFF;
	 *     `cartHash`, `customerIpAddress` y `customerUserAgent` son datos del comprador que un
	 *     integrador no necesita; `orderNotes` exige `moderate_comments` (todos los comentarios del
	 *     sitio) y sus notas internas pueden traer datos de pasarela.
	 *   · Customer: credenciales y sesión (`jwt*`, `session*`) y medios de pago guardados.
	 *     `metaData` es el user meta de la cuenta: por él saldrían el secreto JWT del usuario, el
	 *     token de verificación de correo y las direcciones guardadas.
	 *
	 * @return array<string,list<string>>
	 */
	public static function defaultBlockedFields(): array {
		return array(
			'Order'    => array( 'orderKey', 'cartHash', 'customerIpAddress', 'customerUserAgent', 'orderNotes' ),
			'Customer' => array(
				'jwtAuthToken',
				'jwtRefreshToken',
				'jwtUserSecret',
				'isJwtAuthSecretRevoked',
				'jwtAuthExpiration',
				'session',
				'sessionToken',
				'availablePaymentMethods',
				'availablePaymentMethodsCC',
				'availablePaymentMethodsEC',
				'metaData',
			),
		);
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			// `order` y `orders`: leer pedidos. El cliente llega por `order.customer`, `billing` y
			// `shipping`; los productos de las líneas, por `lineItems`.
			'queries'             => array( 'order', 'orders' ),
			'mutations'           => array( 'updateOrder' ),
			'update_order_fields' => array( 'id', 'orderId', 'status', 'clientMutationId' ),
			'blocked_fields'      => self::defaultBlockedFields(),
			'order_meta_keys'     => self::trackingKeys(),
			'order_meta_types'    => array( 'Order', 'Refund' ),
			'unpaid_statuses'     => array( 'pending', 'failed' ),
		);
	}

	/**
	 * La política efectiva, validada. Se calcula una vez por petición.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$defaults = self::defaults();
		$filtered = apply_filters( 'hwe_integration_policy', $defaults );
		if ( ! is_array( $filtered ) ) {
			error_log( '[hwe] external-integration: el filtro hwe_integration_policy debe devolver una lista; se usa la política por defecto.' );
			return self::$cache = $defaults;
		}

		$policy = array();
		foreach ( array( 'queries', 'mutations' ) as $key ) {
			$list           = self::strings( $filtered[ $key ] ?? $defaults[ $key ], $key );
			$policy[ $key ] = array_values( array_diff( $list, self::DENIED_ROOT ) );
		}
		foreach ( array( 'update_order_fields', 'order_meta_keys', 'order_meta_types' ) as $key ) {
			$policy[ $key ] = self::strings( $filtered[ $key ] ?? $defaults[ $key ], $key );
		}
		// El suelo: la instancia puede AÑADIR campos vetados, no quitar los del defecto.
		$policy['blocked_fields'] = self::blocked( $filtered['blocked_fields'] ?? array(), $defaults['blocked_fields'] );
		$policy['unpaid_statuses'] = array_values(
			array_unique( array_merge( $defaults['unpaid_statuses'], self::strings( $filtered['unpaid_statuses'] ?? array(), 'unpaid_statuses' ) ) )
		);

		return self::$cache = $policy;
	}

	/** Solo para los tests: la política se guarda por petición. */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function strings( $value, string $key ): array {
		if ( ! is_array( $value ) ) {
			error_log( sprintf( '[hwe] external-integration: «%s» de hwe_integration_policy debe ser una lista de textos; se ignora.', $key ) );
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed                      $extra
	 * @param array<string,list<string>> $floor
	 * @return array<string,list<string>>
	 */
	private static function blocked( $extra, array $floor ): array {
		$out = $floor;
		if ( ! is_array( $extra ) ) {
			return $out;
		}
		foreach ( $extra as $type => $fields ) {
			if ( ! is_string( $type ) || ! is_array( $fields ) ) {
				continue;
			}
			$out[ $type ] = array_values(
				array_unique( array_merge( $out[ $type ] ?? array(), self::strings( $fields, 'blocked_fields.' . $type ) ) )
			);
		}
		return $out;
	}
}
