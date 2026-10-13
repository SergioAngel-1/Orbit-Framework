<?php

namespace HWE\Shipping;

/**
 * Meta boxes de los dos CPTs: método de envío (estado + valor agregado) y
 * tarifa de envío (tarifa fija por ciudad).
 */
class MetaBoxes {

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
	}

	public static function add(): void {
		add_meta_box(
			'hwe_shipping_method_config',
			__( 'Configuración del método', 'hwe-shipping' ),
			[ self::class, 'renderMethod' ],
			PostTypes::METHOD_SLUG,
			'normal',
			'high'
		);
		add_meta_box(
			'hwe_shipping_rate_config',
			__( 'Configuración de la tarifa', 'hwe-shipping' ),
			[ self::class, 'renderRate' ],
			PostTypes::RATE_SLUG,
			'normal',
			'high'
		);
	}

	public static function renderMethod( \WP_Post $post ): void {
		wp_nonce_field( 'hwe_shipping_method_meta', 'hwe_shipping_method_nonce' );
		$enabled = get_post_meta( $post->ID, '_hwe_shipping_method_enabled', true );
		$enabled = $enabled === '' ? true : $enabled === '1';
		$added   = (string) get_post_meta( $post->ID, '_hwe_shipping_method_added_value', true );
		?>
		<div class="hwe-shipping-admin">
			<p class="hwe-field hwe-checkbox">
				<label>
					<input type="hidden" name="hwe_shipping_method_enabled" value="0">
					<input type="checkbox" name="hwe_shipping_method_enabled" value="1" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Activo (visible en el checkout)', 'hwe-shipping' ); ?>
				</label>
			</p>
			<p class="hwe-field">
				<label for="hwe_shipping_method_added_value"><strong><?php esc_html_e( 'Valor agregado (opcional)', 'hwe-shipping' ); ?></strong></label><br>
				<input type="number" min="0" step="1" id="hwe_shipping_method_added_value" name="hwe_shipping_method_added_value" value="<?php echo esc_attr( $added ); ?>" placeholder="0">
				<span class="description"><?php esc_html_e( 'Importe adicional en la moneda de la tienda (p. ej. 5). Vacío o 0 = sin costo extra.', 'hwe-shipping' ); ?></span>
			</p>
		</div>
		<?php
	}

	public static function renderRate( \WP_Post $post ): void {
		wp_nonce_field( 'hwe_shipping_rate_meta', 'hwe_shipping_rate_nonce' );
		$amount = (string) get_post_meta( $post->ID, '_hwe_shipping_rate_amount', true );
		$free   = get_post_meta( $post->ID, Save::RATE_FREE_META, true ) === '1';
		?>
		<div class="hwe-shipping-admin">
			<p class="hwe-field description">
				<?php esc_html_e( 'El título de esta tarifa es el nombre de la ciudad (p. ej. "Madrid"). La coincidencia con la ciudad que escribe el cliente en el checkout ignora mayúsculas y tildes.', 'hwe-shipping' ); ?>
			</p>
			<p class="hwe-field">
				<label for="hwe_shipping_rate_amount"><strong><?php esc_html_e( 'Tarifa fija', 'hwe-shipping' ); ?></strong></label><br>
				<input type="number" min="0" step="1" id="hwe_shipping_rate_amount" name="hwe_shipping_rate_amount" value="<?php echo esc_attr( $amount ); ?>" placeholder="0" required>
				<span class="description"><?php esc_html_e( 'Importe en la moneda de la tienda (p. ej. 8).', 'hwe-shipping' ); ?></span>
			</p>
			<p class="hwe-field hwe-checkbox">
				<label>
					<input type="hidden" name="hwe_shipping_rate_free_eligible" value="0">
					<input type="checkbox" name="hwe_shipping_rate_free_eligible" value="1" <?php checked( $free ); ?>>
					<?php esc_html_e( 'Aplica envío gratis', 'hwe-shipping' ); ?>
				</label><br>
				<span class="description"><?php esc_html_e( 'Los pedidos a esta ciudad no pagan envío cuando cumplen la regla de envío gratis de la tienda (valor mínimo y productos a los que aplica). Sin marcar, esta ciudad siempre paga su tarifa.', 'hwe-shipping' ); ?></span>
			</p>
		</div>
		<?php
	}
}
