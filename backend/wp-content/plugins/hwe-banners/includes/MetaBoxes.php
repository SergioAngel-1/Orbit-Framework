<?php

namespace HWE\Banners;

/**
 * Metabox de configuración del banner: posición, orden, intervalo de carrusel y
 * un repeater de slides (imagen desktop/móvil + textos + overrides por idioma).
 */
class MetaBoxes {

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
	}

	public static function add(): void {
		add_meta_box(
			'hwe_banner_config',
			__( 'Configuración del banner', 'hwe-banners' ),
			[ self::class, 'render' ],
			PostType::SLUG,
			'normal',
			'high'
		);
	}

	public static function render( \WP_Post $post ): void {
		$placement    = get_post_meta( $post->ID, '_hwe_banner_placement', true ) ?: Placements::defaultSlug();
		$order        = (int) get_post_meta( $post->ID, '_hwe_banner_order', true );
		$interval     = (int) ( get_post_meta( $post->ID, '_hwe_banner_interval_ms', true ) ?: 6000 );
		$slides       = get_post_meta( $post->ID, '_hwe_banner_slides', true );
		$slides       = is_array( $slides ) ? $slides : [];
		$secondary    = Plugin::secondaryLocales();

		wp_nonce_field( 'hwe_banner_meta', 'hwe_banner_nonce' );
		wp_enqueue_media();
		?>
		<div class="hwe-banner-admin">
			<p class="hwe-field">
				<label for="hwe_banner_placement"><strong><?php esc_html_e( 'Posición', 'hwe-banners' ); ?></strong></label><br>
				<select name="hwe_banner_placement" id="hwe_banner_placement">
					<?php foreach ( Placements::all() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $placement, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Dónde coloca el frontend este banner.', 'hwe-banners' ); ?></span>
			</p>

			<p class="hwe-field">
				<label for="hwe_banner_order"><strong><?php esc_html_e( 'Orden', 'hwe-banners' ); ?></strong></label><br>
				<input type="number" name="hwe_banner_order" id="hwe_banner_order" value="<?php echo esc_attr( (string) $order ); ?>" min="0" step="1">
				<span class="description"><?php esc_html_e( 'Ordena banners de la misma posición (menor = primero).', 'hwe-banners' ); ?></span>
			</p>

			<p class="hwe-field">
				<label for="hwe_banner_interval_ms"><strong><?php esc_html_e( 'Intervalo del carrusel (ms)', 'hwe-banners' ); ?></strong></label><br>
				<input type="number" name="hwe_banner_interval_ms" id="hwe_banner_interval_ms" value="<?php echo esc_attr( (string) $interval ); ?>" min="1000" step="500">
			</p>

			<h3><?php esc_html_e( 'Slides', 'hwe-banners' ); ?></h3>
			<div id="hwe-slides" data-next-index="<?php echo esc_attr( (string) count( $slides ) ); ?>">
				<?php foreach ( $slides as $i => $slide ) {
					self::renderSlide( (int) $i, (array) $slide, $secondary );
				} ?>
			</div>
			<button type="button" class="button button-primary" id="hwe-add-slide">
				<?php esc_html_e( '+ Añadir slide', 'hwe-banners' ); ?>
			</button>

			<script type="text/html" id="hwe-slide-template">
				<?php self::renderSlide( -1, [], $secondary ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Renderiza un slide. Si $index === -1 usa el marcador __INDEX__ para el
	 * template JS (se reemplaza por el índice real al clonar).
	 *
	 * @param array<int,string> $secondary
	 */
	private static function renderSlide( int $index, array $slide, array $secondary ): void {
		$idx  = $index === -1 ? '__INDEX__' : (string) $index;
		$val  = static fn( string $k ): string => esc_attr( (string) ( $slide[ $k ] ?? '' ) );
		$name = static fn( string $k ): string => 'hwe_banner_slides[' . $idx . '][' . $k . ']';
		?>
		<div class="hwe-slide">
			<div class="hwe-slide-head">
				<span class="hwe-slide-title"><?php esc_html_e( 'Slide', 'hwe-banners' ); ?></span>
				<a href="#" class="hwe-remove-slide"><?php esc_html_e( 'Eliminar', 'hwe-banners' ); ?></a>
			</div>

			<?php
			self::mediaField( $name( 'image' ), __( 'Imagen (desktop)', 'hwe-banners' ), $val( 'image' ) );
			self::mediaField( $name( 'image_mobile' ), __( 'Imagen (móvil, opcional)', 'hwe-banners' ), $val( 'image_mobile' ) );
			?>

			<p class="hwe-field"><label><?php esc_html_e( 'Título', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'title' ) ); ?>" value="<?php echo $val( 'title' ); ?>"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'Subtítulo', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'subtitle' ) ); ?>" value="<?php echo $val( 'subtitle' ); ?>"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'Texto del botón (CTA)', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'cta' ) ); ?>" value="<?php echo $val( 'cta' ); ?>"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'URL del botón (CTA)', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'cta_href' ) ); ?>" value="<?php echo $val( 'cta_href' ); ?>" placeholder="/products"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'Badge', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'badge' ) ); ?>" value="<?php echo $val( 'badge' ); ?>"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'URL de todo el slide (opcional)', 'hwe-banners' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name( 'link' ) ); ?>" value="<?php echo $val( 'link' ); ?>"></p>
			<p class="hwe-field"><label><?php esc_html_e( 'Orden', 'hwe-banners' ); ?></label>
				<input type="number" name="<?php echo esc_attr( $name( 'order' ) ); ?>" value="<?php echo esc_attr( (string) ( $slide['order'] ?? 0 ) ); ?>" min="0" step="1" style="width:90px"></p>
			<p class="hwe-field hwe-checkbox">
				<label><input type="checkbox" name="<?php echo esc_attr( $name( 'hide_overlay' ) ); ?>" value="1" <?php checked( ! empty( $slide['hide_overlay'] ) ); ?>>
				<?php esc_html_e( 'Ocultar overlay de texto (solo imagen)', 'hwe-banners' ); ?></label>
			</p>

			<?php foreach ( $secondary as $loc ) :
				$iv = static fn( string $k ): string => esc_attr( (string) ( $slide['i18n'][ $loc ][ $k ] ?? '' ) );
				$in = static fn( string $k ): string => 'hwe_banner_slides[' . $idx . '][i18n][' . $loc . '][' . $k . ']';
			?>
			<fieldset class="hwe-i18n">
				<legend><?php echo esc_html( sprintf( __( 'Overrides idioma: %s', 'hwe-banners' ), strtoupper( $loc ) ) ); ?></legend>
				<?php
				self::mediaField( $in( 'image' ), __( 'Imagen (desktop)', 'hwe-banners' ), $iv( 'image' ) );
				self::mediaField( $in( 'image_mobile' ), __( 'Imagen (móvil)', 'hwe-banners' ), $iv( 'image_mobile' ) );
				?>
				<p class="hwe-field"><label><?php esc_html_e( 'Título', 'hwe-banners' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $in( 'title' ) ); ?>" value="<?php echo $iv( 'title' ); ?>"></p>
				<p class="hwe-field"><label><?php esc_html_e( 'Subtítulo', 'hwe-banners' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $in( 'subtitle' ) ); ?>" value="<?php echo $iv( 'subtitle' ); ?>"></p>
				<p class="hwe-field"><label><?php esc_html_e( 'Texto del botón (CTA)', 'hwe-banners' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $in( 'cta' ) ); ?>" value="<?php echo $iv( 'cta' ); ?>"></p>
			</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function mediaField( string $name, string $label, string $value ): void {
		?>
		<div class="hwe-field hwe-media">
			<label><?php echo esc_html( $label ); ?></label>
			<span class="hwe-media-row">
				<input type="text" class="hwe-media-url" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" readonly>
				<button type="button" class="button hwe-media-pick"><?php esc_html_e( 'Seleccionar', 'hwe-banners' ); ?></button>
				<button type="button" class="button hwe-media-clear"><?php esc_html_e( 'Quitar', 'hwe-banners' ); ?></button>
			</span>
			<span class="hwe-media-preview"><?php if ( $value !== '' ) : ?><img src="<?php echo esc_url( $value ); ?>" alt=""><?php endif; ?></span>
		</div>
		<?php
	}
}
