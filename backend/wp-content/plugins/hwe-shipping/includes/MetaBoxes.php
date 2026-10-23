<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\DeliveryOption;

/**
 * Meta boxes de los dos CPTs: método de envío (estado + valor agregado) y ciudad (tipo, opciones de
 * entrega y, si es por distancia, la dirección de referencia y el radio).
 *
 * El repetidor de opciones y la visibilidad de los campos según el tipo los hace
 * `assets/city-editor.js` (encolado por `Admin\CityAssets`). El HTML sale completo, así que sin JS
 * los campos siguen ahí y el guardado funciona igual.
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
			__( 'Configuración de la ciudad', 'hwe-shipping' ),
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
		wp_nonce_field( Save::RATE_NONCE_ACTION, Save::RATE_NONCE_FIELD );
		$type    = (string) get_post_meta( $post->ID, Cities::META_TYPE, true );
		$address = (string) get_post_meta( $post->ID, Cities::META_ORIGIN_ADDRESS, true );
		$lat     = (string) get_post_meta( $post->ID, Cities::META_ORIGIN_LAT, true );
		$lng     = (string) get_post_meta( $post->ID, Cities::META_ORIGIN_LNG, true );
		$label   = (string) get_post_meta( $post->ID, Cities::META_ORIGIN_LABEL, true );
		$cover   = (string) get_post_meta( $post->ID, Cities::META_COVERAGE, true );
		$options = self::storedOptions( $post->ID );
		if ( $options === [] ) {
			$options = [ null ];
		}
		?>
		<div class="hwe-shipping-admin hwe-shipping-city" id="hwe-shipping-city">
			<p class="description">
				<?php esc_html_e( 'El título de esta ciudad es su nombre (p. ej. "Madrid"); la coincidencia con la ciudad del cliente ignora mayúsculas y tildes. Una ciudad solo se publica si está completa: si no, queda en borrador y aquí verás el motivo.', 'hwe-shipping' ); ?>
			</p>

			<p class="hwe-field">
				<label for="hwe_shipping_city_type"><strong><?php esc_html_e( 'Tipo de tarifa', 'hwe-shipping' ); ?></strong></label><br>
				<select id="hwe_shipping_city_type" name="hwe_shipping_city_type">
					<option value="" <?php selected( $type, '' ); ?>><?php esc_html_e( 'Elige un tipo…', 'hwe-shipping' ); ?></option>
					<option value="fixed" <?php selected( $type, City::TYPE_FIXED ); ?>><?php esc_html_e( 'Tarifa fija', 'hwe-shipping' ); ?></option>
					<option value="distance" <?php selected( $type, City::TYPE_DISTANCE ); ?>><?php esc_html_e( 'Por distancia', 'hwe-shipping' ); ?></option>
				</select>
			</p>

			<fieldset class="hwe-shipping-origin" data-hwe-for="distance">
				<legend><strong><?php esc_html_e( 'Dirección de referencia (punto de salida)', 'hwe-shipping' ); ?></strong></legend>
				<p class="hwe-field">
					<label for="hwe_shipping_city_origin_address"><?php esc_html_e( 'Dirección', 'hwe-shipping' ); ?></label><br>
					<input type="text" class="regular-text" id="hwe_shipping_city_origin_address" name="hwe_shipping_city_origin_address" value="<?php echo esc_attr( $address ); ?>">
					<button type="button" class="button" id="hwe_shipping_city_validate" disabled data-hwe-validate><?php esc_html_e( 'Validar', 'hwe-shipping' ); ?></button>
					<span class="description hwe-shipping-validate-status" id="hwe_shipping_city_validate_status" role="status" aria-live="polite"></span>
				</p>
				<p class="hwe-field hwe-shipping-fields">
					<label for="hwe_shipping_city_origin_lat"><?php esc_html_e( 'Latitud', 'hwe-shipping' ); ?></label>
					<input type="number" step="any" min="-90" max="90" id="hwe_shipping_city_origin_lat" name="hwe_shipping_city_origin_lat" value="<?php echo esc_attr( $lat ); ?>">
					<label for="hwe_shipping_city_origin_lng"><?php esc_html_e( 'Longitud', 'hwe-shipping' ); ?></label>
					<input type="number" step="any" min="-180" max="180" id="hwe_shipping_city_origin_lng" name="hwe_shipping_city_origin_lng" value="<?php echo esc_attr( $lng ); ?>">
				</p>
				<p class="hwe-field">
					<label for="hwe_shipping_city_origin_label"><?php esc_html_e( 'Dirección resuelta', 'hwe-shipping' ); ?></label><br>
					<input type="text" class="regular-text" id="hwe_shipping_city_origin_label" name="hwe_shipping_city_origin_label" value="<?php echo esc_attr( $label ); ?>" readonly>
				</p>
				<p class="hwe-field">
					<label for="hwe_shipping_city_coverage_km"><strong><?php esc_html_e( 'Radio de cobertura (km)', 'hwe-shipping' ); ?></strong></label><br>
					<input type="number" min="0" step="any" id="hwe_shipping_city_coverage_km" name="hwe_shipping_city_coverage_km" value="<?php echo esc_attr( $cover ); ?>">
					<span class="description"><?php esc_html_e( 'Fuera de este radio desde el punto de salida no hay envío. Debe ser mayor que 0.', 'hwe-shipping' ); ?></span>
				</p>
			</fieldset>

			<fieldset class="hwe-shipping-options">
				<legend><strong><?php esc_html_e( 'Opciones de entrega', 'hwe-shipping' ); ?></strong></legend>
				<p class="description">
					<?php esc_html_e( 'Cada opción conserva su identificador aunque cambies su nombre. «Aplica envío gratis» la marca como elegible para la regla de envío gratis de la tienda.', 'hwe-shipping' ); ?>
				</p>
				<div id="hwe-shipping-option-list" class="hwe-shipping-option-list" data-next-index="<?php echo esc_attr( (string) count( $options ) ); ?>" data-option-label="<?php echo esc_attr__( 'Opción', 'hwe-shipping' ); ?>">
					<?php
					foreach ( $options as $position => $option ) {
						self::renderOption( (string) $position, $option, $position + 1 );
					}
					?>
				</div>
				<p>
					<button type="button" class="button" id="hwe-shipping-option-add"><?php esc_html_e( 'Añadir opción', 'hwe-shipping' ); ?></button>
				</p>
				<template id="hwe-shipping-option-template">
					<?php self::renderOption( '__INDEX__', null, 1 ); ?>
				</template>
			</fieldset>
		</div>
		<?php
	}

	/** Una fila del repetidor. `$index` va en los `name` (el JS usa `__INDEX__` para las nuevas). */
	private static function renderOption( string $index, ?DeliveryOption $option, int $position ): void {
		$prefix = 'hwe_shipping_city_options[' . $index . ']';
		?>
		<fieldset class="hwe-shipping-option" data-hwe-option>
			<legend><?php echo esc_html( __( 'Opción', 'hwe-shipping' ) . ' ' . $position ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $prefix . '[id]' ); ?>" value="<?php echo esc_attr( $option ? $option->id() : '' ); ?>">
			<p class="hwe-field">
				<label><?php esc_html_e( 'Nombre', 'hwe-shipping' ); ?>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( $option ? $option->name() : '' ); ?>">
				</label>
			</p>
			<p class="hwe-field hwe-shipping-fields" data-hwe-for="fixed">
				<label><?php esc_html_e( 'Importe', 'hwe-shipping' ); ?>
					<input type="number" min="0" step="any" name="<?php echo esc_attr( $prefix . '[amount]' ); ?>" value="<?php echo esc_attr( self::number( $option ? $option->amount() : null ) ); ?>">
				</label>
			</p>
			<p class="hwe-field hwe-shipping-fields" data-hwe-for="distance">
				<label><?php esc_html_e( 'Precio por km', 'hwe-shipping' ); ?>
					<input type="number" min="0" step="any" name="<?php echo esc_attr( $prefix . '[price_per_km]' ); ?>" value="<?php echo esc_attr( self::number( $option ? $option->pricePerKm() : null ) ); ?>">
				</label>
				<label><?php esc_html_e( 'Mínimo', 'hwe-shipping' ); ?>
					<input type="number" min="0" step="any" name="<?php echo esc_attr( $prefix . '[min_cost]' ); ?>" value="<?php echo esc_attr( self::number( $option ? $option->minCost() : null ) ); ?>">
				</label>
				<label><?php esc_html_e( 'Máximo (0 = sin límite)', 'hwe-shipping' ); ?>
					<input type="number" min="0" step="any" name="<?php echo esc_attr( $prefix . '[max_cost]' ); ?>" value="<?php echo esc_attr( self::number( $option ? $option->maxCost() : null ) ); ?>">
				</label>
			</p>
			<p class="hwe-field hwe-checkbox">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $prefix . '[free_eligible]' ); ?>" value="1" <?php checked( $option && $option->isFreeEligible() ); ?>>
					<?php esc_html_e( 'Aplica envío gratis', 'hwe-shipping' ); ?>
				</label>
			</p>
			<p class="hwe-shipping-option-actions">
				<button type="button" class="button" data-hwe-action="up"><?php esc_html_e( 'Subir', 'hwe-shipping' ); ?></button>
				<button type="button" class="button" data-hwe-action="down"><?php esc_html_e( 'Bajar', 'hwe-shipping' ); ?></button>
				<button type="button" class="button-link-delete" data-hwe-action="remove"><?php esc_html_e( 'Quitar', 'hwe-shipping' ); ?></button>
			</p>
		</fieldset>
		<?php
	}

	/** @return DeliveryOption[] Las opciones guardadas; [] si no hay o si el JSON está roto (se registra). */
	private static function storedOptions( int $postId ): array {
		$raw = (string) get_post_meta( $postId, Cities::META_OPTIONS, true );
		if ( '' === $raw ) {
			return [];
		}
		try {
			return DeliveryOption::listFromJson( $raw );
		} catch ( \InvalidArgumentException $e ) {
			error_log( '[hwe] shipping: opciones ilegibles en la ciudad ' . $postId . ': ' . $e->getMessage() );
			return [];
		}
	}

	private static function number( ?float $value ): string {
		return null === $value ? '' : (string) $value;
	}
}
