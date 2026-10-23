<?php
/**
 * Caja de fidelización en el pedido (spec §12): puntos canjeados, descuento aplicado y
 * ganancias generadas con su estado.
 *
 * Lee las metas por las constantes de sus dueños —`HWE_Loyalty_Redemption::META_REDEEMED_POINTS`,
 * `HWE_Loyalty_Earnings::META_DISCOUNT`, `META_EARNINGS` y `META_PAID_WITH_POINTS`— para que un
 * cambio de nombre no deje la caja muda.
 *
 * Compatible con HPOS: se registra en la pantalla clásica (`shop_order`) y en la de
 * `wc_get_page_screen_id( 'shop-order' )`. El callback acepta tanto el `WP_Post` clásico como el
 * `WC_Order` que entrega HPOS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_OrderBox {

	public const ID = 'hwe-loyalty-order';

	/** Engancha la caja. La llama `HWE_Loyalty_Admin::register()`. */
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
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return;
		}

		foreach ( self::screens() as $screen ) {
			add_meta_box(
				self::ID,
				__( 'Fidelización', 'hwe' ),
				array( self::class, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/** Callback de la caja: normaliza el `WP_Post` clásico o el `WC_Order` de HPOS. */
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
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return;
		}

		$points   = (int) $order->get_meta( \HWE_Loyalty_Redemption::META_REDEEMED_POINTS );
		$discount = self::discount( $order );
		$paidWith = 'yes' === (string) $order->get_meta( \HWE_Loyalty_Earnings::META_PAID_WITH_POINTS );
		$state    = self::earningsState( $order );

		if ( $points <= 0 && $discount <= 0.0 && array() === $state ) {
			echo '<p><em>' . esc_html__( 'Sin movimientos de fidelización en este pedido.', 'hwe' ) . '</em></p>';
			return;
		}

		$unit = HWE_Loyalty_Settings::get()['currency_name_plural'];

		echo '<p>';
		printf(
			'<strong>%s</strong> %s %s<br />',
			esc_html__( 'Canjeado:', 'hwe' ),
			esc_html( number_format_i18n( $points ) ),
			esc_html( $unit )
		);
		printf(
			'<strong>%s</strong> %s<br />',
			esc_html__( 'Descuento aplicado:', 'hwe' ),
			wp_kses_post( self::money( $discount ) )
		);
		if ( $paidWith ) {
			echo '<strong>' . esc_html__( 'Pagado con el saldo:', 'hwe' ) . '</strong> ' . esc_html__( 'sí', 'hwe' ) . '<br />';
		}
		echo '</p>';

		if ( array() === $state ) {
			echo '<p><em>' . esc_html__( 'Las ganancias de este pedido todavía no se han procesado.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Ganancias:', 'hwe' ) . '</strong> '
			. esc_html( self::statusLabel( (string) ( $state['status'] ?? '' ) ) ) . '</p>';

		if ( isset( $state['base'] ) && is_numeric( $state['base'] ) ) {
			printf(
				'<p>%s %s</p>',
				esc_html__( 'Base de cálculo:', 'hwe' ),
				wp_kses_post( self::money( (float) $state['base'] ) )
			);
		}

		if ( isset( $state['reason'] ) && 'paid_with_points' === $state['reason'] ) {
			echo '<p><em>' . esc_html__( 'No genera ganancias: el pedido se pagó íntegramente con el saldo del programa.', 'hwe' ) . '</em></p>';
		}

		$beneficiaries = isset( $state['beneficiaries'] ) && is_array( $state['beneficiaries'] ) ? $state['beneficiaries'] : array();
		if ( array() === $beneficiaries ) {
			return;
		}

		echo '<table class="widefat striped" style="margin-top:8px"><thead><tr>'
			. '<th>' . esc_html__( 'Usuario', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Tipo', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Cantidad', 'hwe' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $beneficiaries as $beneficiary ) {
			if ( ! is_array( $beneficiary ) ) {
				continue;
			}
			$userId = (int) ( $beneficiary['user_id'] ?? 0 );
			echo '<tr>';
			echo '<td>';
			if ( $userId > 0 ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( HWE_Loyalty_Admin_Users::profileUrl( $userId ) ),
					esc_html( HWE_Loyalty_Admin_Users::label( $userId ) )
				);
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td>' . esc_html( HWE_Loyalty_Admin_Movements::typeLabel( (string) ( $beneficiary['type'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $beneficiary['points'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function discount( \WC_Order $order ): float {
		$raw = $order->get_meta( \HWE_Loyalty_Earnings::META_DISCOUNT );
		return is_scalar( $raw ) && is_numeric( $raw ) ? (float) $raw : 0.0;
	}

	/** @return array<string,mixed> */
	private static function earningsState( \WC_Order $order ): array {
		$raw = $order->get_meta( \HWE_Loyalty_Earnings::META_EARNINGS );
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
			\HWE_Loyalty_Earnings::STATUS_PROCESSED => __( 'Procesadas', 'hwe' ),
			\HWE_Loyalty_Earnings::STATUS_REVERSED  => __( 'Revertidas', 'hwe' ),
			\HWE_Loyalty_Earnings::STATUS_SKIPPED   => __( 'Omitidas', 'hwe' ),
			default                                 => __( 'Sin procesar', 'hwe' ),
		};
	}

	/** Importe con la moneda de WooCommerce; el HTML de `wc_price` se filtra al pintarlo. */
	private static function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( $amount );
		}

		return number_format_i18n( $amount, 2 );
	}
}
