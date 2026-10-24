<?php
/**
 * Ejemplo: un beneficio de membresía propio de la instancia (módulo `memberships`).
 *
 * Demuestra el contrato de §7.7 sin tocar el núcleo:
 *  · se registra por el filtro `hwe_memberships_benefit_types`;
 *  · declara sus campos por nivel (`orders` y `note`), que el panel, `wp hwe setup`, la API y el
 *    historial sanean y guardan como los de serie;
 *  · se engancha al cobro del pedido (`processing`/`completed`) y solo actúa si el socio tiene
 *    el beneficio activo y el módulo está encendido;
 *  · deja una nota PRIVADA en el pedido que completa cada tramo de pedidos cobrados y una marca
 *    en su meta para no repetirla (`processing` → `completed`, webhook repetido…).
 *
 * El archivo se carga como mu-plugin, ANTES que los plugins: la clase base todavía no existe.
 * Por eso la clase se declara dentro del callback del filtro, cuando `hwe-memberships` ya está
 * cargado, y una sola vez.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Añade el tipo del ejemplo al registro de beneficios.
 *
 * @param mixed $types Mapa `clave → HWE_Memberships_Benefit`.
 * @return mixed El mismo mapa con el tipo del ejemplo.
 */
function hwe_example_membership_benefit_types( $types ) {
	if ( ! is_array( $types ) || ! class_exists( 'HWE_Memberships_Benefit' ) ) {
		return $types;
	}

	if ( ! class_exists( 'HWE_Example_Membership_Order_Gift' ) ) {
		/**
		 * «Un regalo cada N pedidos cobrados»: el nivel elige el tramo y el texto de la nota.
		 */
		class HWE_Example_Membership_Order_Gift extends HWE_Memberships_Benefit {

			/** Clave estable del tipo. */
			public const KEY = 'example_order_gift';

			/** Meta privada del pedido: marca que la nota de este tramo ya se dejó. */
			public const META_NOTE = '_example_membership_gift_note';

			/** Texto de la nota si el nivel no configura ninguno. */
			private const DEFAULT_NOTE = 'Pedido de regalo del programa de ejemplo.';

			public function key(): string {
				return self::KEY;
			}

			public function fields(): array {
				return array(
					'orders' => array(
						'type'    => self::FIELD_NUMBER,
						'label'   => 'Pedidos cobrados por regalo',
						'default' => 3,
						'min'     => 1,
						'max'     => 1000,
					),
					'note'   => array(
						'type'    => self::FIELD_TEXT,
						'label'   => 'Nota privada del pedido',
						'default' => self::DEFAULT_NOTE,
						'max'     => 200,
					),
				);
			}

			public function publicData( int $levelId ): array {
				$config = $this->configFor( $levelId );

				return array(
					'key'    => $this->key(),
					'orders' => null === $config ? 0 : max( 0, (int) ( $config['orders'] ?? 0 ) ),
				);
			}

			protected function registerHooks(): void {
				add_action( 'woocommerce_order_status_processing', array( $this, 'onOrderCollected' ) );
				add_action( 'woocommerce_order_status_completed', array( $this, 'onOrderCollected' ) );
			}

			/**
			 * Pedido cobrado: si el socio tiene el beneficio y este pedido completa un tramo de
			 * `orders`, deja la nota privada una sola vez.
			 */
			public function onOrderCollected( int $orderId ): void {
				if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
					return;
				}

				$order = wc_get_order( $orderId );
				if ( ! $order instanceof WC_Order ) {
					return;
				}

				$userId = (int) $order->get_customer_id();
				if ( $userId <= 0 ) {
					return; // Un pedido de invitado no tiene socio.
				}

				if ( ! class_exists( 'HWE_Memberships_Purchase' ) || ! HWE_Memberships_Purchase::isCollected( $order ) ) {
					return;
				}

				if ( ! $this->isActiveFor( $userId ) ) {
					return;
				}

				if ( ! empty( $order->get_meta( self::META_NOTE ) ) ) {
					return;
				}

				$state   = class_exists( 'HWE_Memberships_Members' ) ? HWE_Memberships_Members::state( $userId ) : array();
				$levelId = (int) ( $state['level_id'] ?? 0 );
				$config  = $this->configFor( $levelId );
				if ( null === $config || empty( $config['enabled'] ) ) {
					return;
				}

				$every     = max( 1, (int) ( $config['orders'] ?? 1 ) );
				$collected = $this->collectedOrders( $userId );
				if ( $collected <= 0 || 0 !== $collected % $every ) {
					return;
				}

				$note = trim( (string) ( $config['note'] ?? '' ) );
				if ( '' === $note ) {
					$note = self::DEFAULT_NOTE;
				}

				$order->add_order_note( $note, 0, false );

				$order->update_meta_data( self::META_NOTE, array(
					'level_id' => $levelId,
					'orders'   => $collected,
					'every'    => $every,
					'at'       => gmdate( 'Y-m-d H:i:s' ),
				) );
				if ( method_exists( $order, 'save_meta_data' ) ) {
					$order->save_meta_data();
				}
			}

			/**
			 * Pedidos cobrados del socio. `paginate` devuelve el total sin cargar el historial
			 * completo: el ejemplo solo necesita saber si el pedido actual cierra un tramo.
			 */
			private function collectedOrders( int $userId ): int {
				if ( $userId <= 0 || ! function_exists( 'wc_get_orders' ) ) {
					return 0;
				}

				$statuses = function_exists( 'wc_get_is_paid_statuses' )
					? (array) wc_get_is_paid_statuses()
					: array( 'processing', 'completed' );

				$result = wc_get_orders( array(
					'customer_id' => $userId,
					'status'      => $statuses,
					'type'        => 'shop_order',
					'paginate'    => true,
					'limit'       => 1,
				) );

				if ( is_object( $result ) && isset( $result->total ) ) {
					return max( 0, (int) $result->total );
				}
				if ( is_array( $result ) ) {
					return count( $result );
				}

				return 0;
			}
		}
	}

	$types[ HWE_Example_Membership_Order_Gift::KEY ] = new HWE_Example_Membership_Order_Gift();

	return $types;
}

add_filter( 'hwe_memberships_benefit_types', 'hwe_example_membership_benefit_types' );
