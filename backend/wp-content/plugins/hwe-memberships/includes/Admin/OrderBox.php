<?php
/**
 * Caja de membresía en el pedido (spec §11.2): concesión creada o revocada, descuento de miembro,
 * envío gratis usado y puntos por activación, con su estado.
 *
 * Lee las metas **por las constantes de sus dueños**: `HWE_Memberships_Purchase::META_LINE_*` y
 * `META_ORDER_STATE` para lo que ya escribe la compra, y las metas `_hwe_membership_*` que
 * añadirán las tareas de descuento y envío. Cualquier meta del módulo que esta entrega aún no
 * conozca se muestra igualmente (con una etiqueta legible si está mapeada), de modo que la caja
 * no se queda muda cuando se añade una meta nueva.
 *
 * El punto de extensión es el filtro `hwe_memberships_order_box_rows`: recibe las filas
 * (`label`, `value`, `state`) y el pedido, y devuelve las filas definitivas. Así las tareas de
 * descuento, envío y puntos añaden sus filas sin tocar esta clase.
 *
 * Compatible con HPOS: se registra en la pantalla clásica (`shop_order`) y en la que devuelve
 * `wc_get_page_screen_id( 'shop-order' )`. El callback acepta el `WP_Post` clásico o el
 * `WC_Order` de HPOS.
 *
 * Los puntos por activación se leen del historial del módulo (`points_skipped`); esta clase no
 * llama a ninguna clase interna de `loyalty`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Order_Box {

	/** Id de la caja. */
	public const ID = 'hwe-memberships-order';

	/** Filtro de filas: `( array $rows, \WC_Order $order ): array`. */
	public const ROWS_FILTER = 'hwe_memberships_order_box_rows';

	/** Metas de pedido del módulo que no son del estado ni del ciclo de compra. */
	private const META_LABELS = array(
		'_hwe_membership_discount'          => array( 'Descuento de miembro', 'money' ),
		'_hwe_membership_member_discount'   => array( 'Descuento de miembro', 'money' ),
		'_hwe_membership_free_shipping'     => array( 'Envío gratis', 'text' ),
		'_hwe_membership_shipping_free'     => array( 'Envío gratis', 'text' ),
		'_hwe_membership_shipping_used'     => array( 'Envío gratis', 'text' ),
	);

	/** Metas que ya se pintan en otras filas y no deben repetirse como genéricas. */
	private const HANDLED_METAS = array(
		HWE_Memberships_Purchase::META_ORDER_STATE,
	);

	/** Engancha la caja. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ) );
	}

	/** Pantallas donde vive el pedido, con y sin HPOS. */
	public static function screens(): array {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $hpos ) && '' !== $hpos ) {
				$screens[] = $hpos;
			}
		}

		return array_values( array_unique( $screens ) );
	}

	public static function add(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return;
		}

		foreach ( self::screens() as $screen ) {
			add_meta_box(
				self::ID,
				__( 'Membresía', 'hwe' ),
				array( self::class, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Callback de la caja: normaliza el `WP_Post` clásico o el `WC_Order` de HPOS.
	 *
	 * @param mixed $postOrOrder
	 */
	public static function render( mixed $postOrOrder ): void {
		$order = null;

		if ( is_object( $postOrOrder ) && $postOrOrder instanceof \WC_Order ) {
			$order = $postOrOrder;
		} elseif ( is_object( $postOrOrder ) && isset( $postOrOrder->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $postOrOrder->ID );
		}

		if ( ! is_object( $order ) || ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'No se pudo cargar el pedido.', 'hwe' ) . '</p>';
			return;
		}

		self::display( $order );
	}

	/** Pinta la caja para un pedido ya resuelto. Separado para poder probarlo sin pantalla. */
	public static function display( \WC_Order $order ): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return;
		}

		$rows = self::rows( $order );
		if ( array() === $rows ) {
			echo '<p><em>' . esc_html__( 'Sin membresía en este pedido.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<ul class="hwe-memberships-order-box">';
		foreach ( $rows as $row ) {
			$state = '' !== (string) ( $row['state'] ?? '' ) ? (string) $row['state'] : 'info';
			printf(
				'<li class="hwe-memberships-order-box__%s"><strong>%s:</strong> %s</li>',
				esc_attr( sanitize_html_class( $state ) ),
				esc_html( (string) $row['label'] ),
				wp_kses_post( (string) $row['value'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * Filas de la caja antes del filtro.
	 *
	 * @return list<array{label:string,value:string,state:string}>
	 */
	public static function rows( \WC_Order $order ): array {
		$rows = array();

		$state = self::state( $order );
		if ( '' !== (string) ( $state['status'] ?? '' ) ) {
			$rows[] = array(
				'label' => __( 'Activación', 'hwe' ),
				'value' => self::statusLabel( (string) $state['status'] ),
				'state' => (string) $state['status'],
			);
		}

		$grantIds = array();
		$lines    = self::lines( $order );

		if ( array() === $lines ) {
			// Sin líneas legibles, lo que quedó anotado en el estado del pedido.
			foreach ( (array) ( $state['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$rows[] = array(
					'label' => __( 'Nivel', 'hwe' ),
					'value' => self::lineLabel(
						(int) ( $item['level_id'] ?? 0 ),
						(int) ( $item['days'] ?? 0 ),
						(int) ( $item['grant_id'] ?? 0 ),
						''
					),
					'state' => 'granted',
				);
				$grantIds[] = (int) ( $item['grant_id'] ?? 0 );
			}
		} else {
			foreach ( $lines as $line ) {
				$grantIds[] = $line['grant_id'];
				$rows[]     = array(
					'label' => __( 'Nivel', 'hwe' ),
					'value' => self::lineLabel( $line['level_id'], $line['days'], $line['grant_id'], $line['revoked_at'] ),
					'state' => '' !== $line['revoked_at'] ? 'revoked' : 'granted',
				);
			}
		}

		foreach ( self::extraMeta( $order ) as $meta ) {
			$rows[] = $meta;
		}

		foreach ( self::skippedPoints( $grantIds ) as $row ) {
			$rows[] = $row;
		}

		$filtered = apply_filters( self::ROWS_FILTER, $rows, $order );
		if ( ! is_array( $filtered ) ) {
			error_log( '[hwe] memberships: el filtro hwe_memberships_order_box_rows no devolvió una lista; se usa la de la caja.' );
			$filtered = $rows;
		}

		$rows = array_values( array_filter(
			$filtered,
			static fn ( mixed $row ): bool => is_array( $row ) && isset( $row['label'], $row['value'] )
		) );

		$clean = array();
		foreach ( $rows as $row ) {
			$clean[] = array(
				'label' => is_scalar( $row['label'] ) ? (string) $row['label'] : '',
				'value' => is_scalar( $row['value'] ) ? (string) $row['value'] : '',
				'state' => isset( $row['state'] ) && is_scalar( $row['state'] ) ? (string) $row['state'] : 'info',
			);
		}

		return $clean;
	}

	/**
	 * Líneas de membresía del pedido, leídas por las constantes de `Purchase`.
	 *
	 * @return list<array{level_id:int,days:int,grant_id:int,revoked_at:string}>
	 */
	private static function lines( \WC_Order $order ): array {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return array();
		}

		$lines = array();
		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$levelId = (int) $item->get_meta( HWE_Memberships_Purchase::META_LINE_LEVEL );
			$grantId = (int) $item->get_meta( HWE_Memberships_Purchase::META_LINE_GRANT );
			if ( $levelId <= 0 && $grantId <= 0 ) {
				continue;
			}

			$revoked = $item->get_meta( HWE_Memberships_Purchase::META_LINE_REVOKED );
			$lines[] = array(
				'level_id'   => $levelId,
				'days'       => (int) $item->get_meta( HWE_Memberships_Purchase::META_LINE_DAYS ),
				'grant_id'   => $grantId,
				'revoked_at' => is_scalar( $revoked ) ? trim( (string) $revoked ) : '',
			);
		}

		return $lines;
	}

	/**
	 * Metas de pedido del módulo que no tienen fila propia (descuento, envío gratis, futuras).
	 *
	 * @return list<array{label:string,value:string,state:string}>
	 */
	private static function extraMeta( \WC_Order $order ): array {
		if ( ! method_exists( $order, 'get_meta_data' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) $order->get_meta_data() as $meta ) {
			if ( ! is_object( $meta ) || ! method_exists( $meta, 'get_data' ) ) {
				continue;
			}

			$data = (array) $meta->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( ! str_starts_with( $key, '_hwe_membership_' ) || in_array( $key, self::HANDLED_METAS, true ) ) {
				continue;
			}

			$value = $data['value'] ?? '';
			if ( ! is_scalar( $value ) && null !== $value ) {
				$value = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : '';
			}
			$value = (string) $value;
			if ( '' === trim( $value ) ) {
				continue;
			}

			[ $label, $format ] = self::META_LABELS[ $key ] ?? array( self::humanKey( $key ), 'text' );

			$rows[] = array(
				'label' => $label,
				'value' => 'money' === $format ? self::money( $value ) : esc_html( $value ),
				'state' => 'info',
			);
		}

		return $rows;
	}

	/**
	 * Puntos no abonados en las concesiones de este pedido (`points_skipped`).
	 *
	 * @param list<int> $grantIds
	 * @return list<array{label:string,value:string,state:string}>
	 */
	private static function skippedPoints( array $grantIds ): array {
		global $wpdb;

		$grantIds = array_values( array_unique( array_filter( array_map( 'intval', $grantIds ) ) ) );
		if ( array() === $grantIds || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$events     = HWE_Memberships_Schema::eventsTable();
		$placeholders = implode( ', ', array_fill( 0, count( $grantIds ), '%d' ) );
		$sql        = "SELECT grant_id, data FROM {$events} WHERE type = %s AND grant_id IN ({$placeholders})";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( array( HWE_Memberships_Events::POINTS_SKIPPED ), $grantIds ) )
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$grantId = (int) ( $row->grant_id ?? 0 );
			$data    = $row->data ?? array();
			if ( is_string( $data ) && '' !== $data ) {
				$decoded = json_decode( $data, true );
				$data    = is_array( $decoded ) ? $decoded : array();
			}
			$points = is_array( $data ) ? max( 0, (int) ( $data['points'] ?? 0 ) ) : 0;

			$out[] = array(
				'label' => __( 'Puntos por activación', 'hwe' ),
				'value' => sprintf(
					/* translators: 1: puntos omitidos, 2: id de la concesión. */
					__( 'omitidos: %1$d puntos (concesión #%2$d)', 'hwe' ),
					$points,
					$grantId
				),
				'state' => 'warning',
			);
		}

		return $out;
	}

	/** @return array<string,mixed> Estado de la activación guardado en el pedido. */
	private static function state( \WC_Order $order ): array {
		$raw = $order->get_meta( HWE_Memberships_Purchase::META_ORDER_STATE );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	private static function statusLabel( string $status ): string {
		return match ( $status ) {
			HWE_Memberships_Purchase::STATUS_PROCESSED => __( 'Activada', 'hwe' ),
			HWE_Memberships_Purchase::STATUS_REVERSED  => __( 'Revertida', 'hwe' ),
			default                                    => __( 'Sin procesar', 'hwe' ),
		};
	}

	private static function lineLabel( int $levelId, int $days, int $grantId, string $revokedAt ): string {
		$level = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;
		$name  = null === $level ? ( $levelId > 0 ? '#' . $levelId : '—' ) : (string) $level['name'];

		$parts = array( $name );
		if ( $days > 0 ) {
			/* translators: %d: días de la concesión. */
			$parts[] = sprintf( __( '%d días', 'hwe' ), $days );
		} else {
			$parts[] = __( 'indefinida', 'hwe' );
		}
		if ( $grantId > 0 ) {
			/* translators: %d: id de la concesión. */
			$parts[] = sprintf( __( 'concesión #%d', 'hwe' ), $grantId );
		}
		if ( '' !== $revokedAt ) {
			/* translators: %s: fecha de revocación. */
			$parts[] = sprintf( __( 'Revocada el %s', 'hwe' ), $revokedAt );
		}

		return esc_html( implode( ' · ', $parts ) );
	}

	/** Importe con la moneda de WooCommerce; el HTML de `wc_price` se filtra al pintarlo. */
	private static function money( string $amount ): string {
		if ( ! is_numeric( $amount ) ) {
			return esc_html( $amount );
		}

		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( (float) $amount );
		}

		return esc_html( number_format_i18n( (float) $amount, 2 ) );
	}

	/** Etiqueta legible de una clave técnica: `_hwe_membership_x_y` → «X y». */
	private static function humanKey( string $key ): string {
		$key = (string) preg_replace( '/^_hwe_membership_/', '', $key );
		$key = str_replace( array( '_', '-' ), ' ', $key );

		return ucfirst( trim( $key ) );
	}
}
