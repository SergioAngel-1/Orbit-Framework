<?php

namespace HWE\ExternalIntegration;

/**
 * El rol `hwe_integration` y su capacidad sobre los pedidos.
 *
 * Capacidades: lo que WooGraphQL exige para LEER y cambiar el estado de un pedido
 * (`edit_shop_orders` para los publicados; `edit_others_shop_orders` para los `pending` y
 * `draft`). Sin `manage_woocommerce`, `list_users` ni permisos sobre productos, cupones o
 * ajustes.
 */
final class Role {

	public const SLUG    = 'hwe_integration';
	public const VERSION = 3;
	public const OPTION  = 'hwe_integration_role_version';

	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure' ) );
		add_filter( 'map_meta_cap', array( self::class, 'mapMetaCap' ), 10, 4 );
	}

	/** @return array<string,bool> */
	public static function capabilities(): array {
		return array(
			'read'                       => true,
			'read_private_shop_orders'   => true,
			'edit_shop_orders'           => true,
			'edit_others_shop_orders'    => true,
			'edit_private_shop_orders'   => true,
			'edit_published_shop_orders' => true,
		);
	}

	/** Crea el rol si falta o si cambió su versión. Idempotente y barato. */
	public static function ensure(): void {
		if ( (int) get_option( self::OPTION ) === self::VERSION && get_role( self::SLUG ) ) {
			return;
		}
		remove_role( self::SLUG );
		add_role( self::SLUG, 'Integración externa', self::capabilities() );
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Con HPOS, `get_post_type( $order_id )` devuelve `shop_order_placehold`, un tipo con las
	 * capacidades GENÉRICAS de «post» (`edit_post` → `edit_others_posts`…), y WooGraphQL autoriza
	 * `updateOrder` con `wc_rest_check_post_permissions` sobre él. Dar `edit_others_posts` al rol
	 * abriría las entradas del blog; en cambio se traduce la meta-capacidad SOLO para ese tipo y
	 * SOLO para este rol a las de pedidos que el rol ya tiene.
	 *
	 * @param mixed $caps
	 * @param mixed $cap
	 * @param mixed $userId
	 * @param mixed $args
	 * @return mixed
	 */
	public static function mapMetaCap( $caps, $cap, $userId, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'read_post' ), true ) || ! is_array( $args ) || empty( $args[0] ) ) {
			return $caps;
		}
		if ( ! self::userHasRole( get_userdata( (int) $userId ) ) ) {
			return $caps;
		}
		if ( 'shop_order_placehold' !== get_post_type( (int) $args[0] ) ) {
			return $caps;
		}
		return array( 'edit_post' === $cap ? 'edit_shop_orders' : 'read_private_shop_orders' );
	}

	/** @param mixed $user */
	public static function userHasRole( $user ): bool {
		return $user instanceof \WP_User && in_array( self::SLUG, (array) $user->roles, true );
	}
}
