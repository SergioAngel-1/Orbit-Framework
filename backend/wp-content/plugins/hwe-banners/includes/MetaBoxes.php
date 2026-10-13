<?php

namespace HWE\Banners;

/**
 * Metabox del banner en dos pasos:
 *   Paso 1: elegir el TIPO (carousel / band / allies) y guardar.
 *   Paso 2: locación (filtrada por tipo), intervalo si aplica, slides.
 */
class MetaBoxes {

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
		add_action( 'admin_notices', [ self::class, 'duplicateNotice' ] );
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
		wp_nonce_field( 'hwe_banner_meta', 'hwe_banner_nonce' );

		$type = (string) get_post_meta( $post->ID, '_hwe_banner_type', true );
		if ( $type === '' || ! BannerTypes::isValid( $type ) ) {
			self::renderTypeStep();
			return;
		}
		self::renderConfigStep( $post, $type );
	}

	/** Aviso si hay otro banner publicado en la misma celda (type + location). */
	public static function duplicateNotice(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== PostType::SLUG || $screen->base !== 'post' ) {
			return;
		}
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $postId <= 0 ) {
			return;
		}
		$type = (string) get_post_meta( $postId, '_hwe_banner_type', true );
		$loc  = (string) get_post_meta( $postId, '_hwe_banner_location', true );
		if ( $type === '' || $loc === '' || get_post_status( $postId ) !== 'publish' ) {
			return;
		}
		$others = get_posts( [
			'post_type'      => PostType::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 2,
			'post__not_in'   => [ $postId ],
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_hwe_banner_type', 'value' => $type, 'compare' => '=' ],
				[ 'key' => '_hwe_banner_location', 'value' => $loc, 'compare' => '=' ],
			],
		] );
		if ( $others === [] ) {
			return;
		}
		$winner = Serializer::resolvePost( $type, $loc );
		$winnerLabel = $winner
			? ( $winner->post_title !== '' ? $winner->post_title : ( '#' . $winner->ID ) )
			: '—';
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: banner type label, 2: location label, 3: winning post title */
				__( 'Hay más de un banner publicado para «%1$s» en «%2$s». En el frontend solo se sirve uno: «%3$s» (el más reciente).', 'hwe-banners' ),
				BannerTypes::labels()[ $type ] ?? $type,
				Locations::label( $loc ),
				$winnerLabel
			)
		);
		echo '</p></div>';
	}

	private static function renderTypeStep(): void {
		$default = BannerTypes::defaultSlug();
		?>
		<div class="hwe-banner-admin">
			<p><strong><?php esc_html_e( 'Paso 1 de 2 · Elige el tipo de banner', 'hwe-banners' ); ?></strong></p>
			<p class="description">
				<?php esc_html_e( 'El tipo define el rol (carrusel, franja, aliados), qué campos tienen sus slides y en qué locaciones puede mostrarse. Queda fijado al guardar; para cambiarlo, crea un banner nuevo.', 'hwe-banners' ); ?>
			</p>
			<?php foreach ( BannerTypes::all() as $slug => $cfg ) : ?>
				<p class="hwe-field hwe-placement-option">
					<label>
						<input type="radio" name="hwe_banner_type" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $slug, $default ); ?>>
						<strong><?php echo esc_html( $cfg['label'] ); ?></strong>
					</label>
					<?php if ( $cfg['description'] !== '' ) : ?>
						<span class="description hwe-placement-desc"><?php echo esc_html( $cfg['description'] ); ?></span>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
			<p class="hwe-step-note">
				<?php esc_html_e( 'Guarda el banner (Publicar o Guardar borrador) para elegir la locación y configurar los slides.', 'hwe-banners' ); ?>
			</p>
			<?php SizeGuide::renderBox( null, true ); ?>
		</div>
		<?php
	}

	private static function renderConfigStep( \WP_Post $post, string $type ): void {
		$cfg       = BannerTypes::config( $type );
		$location  = (string) get_post_meta( $post->ID, '_hwe_banner_location', true );
		if ( $location === '' || ! BannerTypes::isLocationAllowed( $type, $location ) ) {
			$location = BannerTypes::defaultLocationFor( $type );
		}
		$interval  = (int) ( get_post_meta( $post->ID, '_hwe_banner_interval_ms', true ) ?: 6000 );
		$slides    = get_post_meta( $post->ID, '_hwe_banner_slides', true );
		$slides    = is_array( $slides ) ? array_map( static fn( $s ) => LegacySlide::upgrade( (array) $s ), $slides ) : [];
		$secondary = Plugin::secondaryLocales();
		$max       = $cfg['max_slides'];
		$showOrder = $max === null || $max > 1;
		$locLabels = Locations::all();

		wp_enqueue_media();
		?>
		<div class="hwe-banner-admin">
			<p class="hwe-field">
				<strong><?php esc_html_e( 'Tipo de banner', 'hwe-banners' ); ?>:</strong>
				<span class="hwe-placement-lock"><?php echo esc_html( $cfg['label'] ); ?></span>
				<input type="hidden" name="hwe_banner_type" value="<?php echo esc_attr( $type ); ?>">
				<?php if ( $cfg['description'] !== '' ) : ?>
					<span class="description hwe-placement-desc"><?php echo esc_html( $cfg['description'] ); ?></span>
				<?php endif; ?>
			</p>

			<p class="hwe-field">
				<label for="hwe_banner_location"><strong><?php esc_html_e( 'Locación', 'hwe-banners' ); ?></strong></label><br>
				<select name="hwe_banner_location" id="hwe_banner_location">
					<?php foreach ( $cfg['locations'] as $locSlug ) : ?>
						<option value="<?php echo esc_attr( $locSlug ); ?>" <?php selected( $location, $locSlug ); ?>>
							<?php echo esc_html( $locLabels[ $locSlug ] ?? $locSlug ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Página o superficie donde se muestra este banner. Solo puede haber uno publicado por tipo y locación.', 'hwe-banners' ); ?></span>
			</p>

			<?php SizeGuide::renderBox( $type, false ); ?>

			<?php if ( $cfg['interval'] ) : ?>
			<p class="hwe-field">
				<label for="hwe_banner_interval_ms"><strong><?php esc_html_e( 'Intervalo del carrusel (ms)', 'hwe-banners' ); ?></strong></label><br>
				<input type="number" name="hwe_banner_interval_ms" id="hwe_banner_interval_ms" value="<?php echo esc_attr( (string) $interval ); ?>" min="1000" step="500">
			</p>
			<?php endif; ?>

			<h3>
				<?php esc_html_e( 'Slides', 'hwe-banners' ); ?>
				<?php if ( $max !== null ) : ?>
					<span class="hwe-max-note">
						<?php echo esc_html( sprintf( _n( '(máximo %d slide)', '(máximo %d slides)', $max, 'hwe-banners' ), $max ) ); ?>
					</span>
				<?php endif; ?>
			</h3>
			<div id="hwe-slides"
				data-next-index="<?php echo esc_attr( (string) count( $slides ) ); ?>"
				<?php if ( $max !== null ) : ?>data-max-slides="<?php echo esc_attr( (string) $max ); ?>"<?php endif; ?>>
				<?php foreach ( $slides as $i => $slide ) {
					self::renderSlide( (int) $i, (array) $slide, $secondary, $type, $showOrder );
				} ?>
			</div>
			<div class="hwe-slides-toolbar">
				<button type="button" class="button button-primary" id="hwe-add-slide">
					<?php esc_html_e( '+ Añadir slide', 'hwe-banners' ); ?>
				</button>
				<span class="hwe-bulk">
					<a href="#" id="hwe-expand-all"><?php esc_html_e( 'Expandir todos', 'hwe-banners' ); ?></a>
					<span class="hwe-bulk-sep">·</span>
					<a href="#" id="hwe-collapse-all"><?php esc_html_e( 'Colapsar todos', 'hwe-banners' ); ?></a>
				</span>
			</div>

			<script type="text/html" id="hwe-slide-template">
				<?php self::renderSlide( -1, [], $secondary, $type, $showOrder ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * @param array<int,string> $secondary
	 */
	private static function renderSlide( int $index, array $slide, array $secondary, string $bannerType, bool $showOrder ): void {
		$idx     = $index === -1 ? '__INDEX__' : (string) $index;
		$get     = static fn( string $k ): string => (string) ( $slide[ $k ] ?? '' );
		$val     = static fn( string $k ): string => esc_attr( $get( $k ) );
		$name    = static fn( string $k ): string => 'hwe_banner_slides[' . $idx . '][' . $k . ']';
		$allowed = BannerTypes::allowedSlideTypes( $bannerType );
		$type    = (string) ( $slide['type'] ?? BannerTypes::defaultSlideTypeFor( $bannerType ) );
		if ( ! isset( $allowed[ $type ] ) ) {
			$type = BannerTypes::defaultSlideTypeFor( $bannerType );
		}
		?>
		<div class="hwe-slide">
			<div class="hwe-slide-head">
				<button type="button" class="hwe-slide-toggle" aria-expanded="true">
					<span class="hwe-chevron" aria-hidden="true"></span>
					<span class="hwe-slide-title"><?php esc_html_e( 'Slide', 'hwe-banners' ); ?></span>
					<span class="hwe-slide-preview"></span>
					<span class="hwe-slide-typechip"></span>
				</button>
				<a href="#" class="hwe-remove-slide"><?php esc_html_e( 'Eliminar', 'hwe-banners' ); ?></a>
			</div>

			<div class="hwe-slide-body">

				<?php if ( count( $allowed ) > 1 ) : ?>
				<p class="hwe-field hwe-type-field"><label><?php esc_html_e( 'Tipo de plantilla', 'hwe-banners' ); ?></label><br>
					<select class="hwe-type-select" name="<?php echo esc_attr( $name( 'type' ) ); ?>">
						<?php foreach ( $allowed as $slug => $typeCfg ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
								<?php echo esc_html( $typeCfg['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php else : ?>
				<input type="hidden" class="hwe-type-select" name="<?php echo esc_attr( $name( 'type' ) ); ?>" value="<?php echo esc_attr( $type ); ?>">
				<?php endif; ?>
				<p class="hwe-type-desc description"></p>

				<?php
				$decorMode = (string) ( $slide['decor_mode'] ?? 'none' );
				if ( ! in_array( $decorMode, [ 'none', 'default', 'custom' ], true ) ) {
					$decorMode = 'none';
				}
				?>
				<p class="hwe-field" data-hwe-field="decor_mode">
					<label for="<?php echo esc_attr( 'hwe_decor_mode_' . $idx ); ?>"><strong><?php esc_html_e( 'Visual lateral', 'hwe-banners' ); ?></strong></label><br>
					<select class="hwe-decor-mode" name="<?php echo esc_attr( $name( 'decor_mode' ) ); ?>" id="<?php echo esc_attr( 'hwe_decor_mode_' . $idx ); ?>">
						<option value="none" <?php selected( $decorMode, 'none' ); ?>><?php esc_html_e( 'Imagen (foto)', 'hwe-banners' ); ?></option>
						<option value="default" <?php selected( $decorMode, 'default' ); ?>><?php esc_html_e( 'Visual decorativo por defecto (plantilla)', 'hwe-banners' ); ?></option>
						<option value="custom" <?php selected( $decorMode, 'custom' ); ?>><?php esc_html_e( 'Visual decorativo (subir SVG)', 'hwe-banners' ); ?></option>
					</select>
					<span class="description"><?php esc_html_e( 'Imagen y visual decorativo son excluyentes: la foto no se anima; solo el SVG decorativo.', 'hwe-banners' ); ?></span>
				</p>
				<?php
				// Qué visual decorativo de plantilla, cuando el visual lateral es el de
				// por defecto. Solo se pinta si la INSTANCIA declaró más de uno
				// (filtro `hwe_banners_decor_presets`): con uno solo, o con
				// ninguno, un desplegable no es una elección. El JS del admin lo
				// muestra/oculta junto al modo — ver `applyDecorMode`.
				$presets = DecorPresets::all();
				if ( count( $presets ) > 1 ) :
					$preset = DecorPresets::sanitize( (string) ( $slide['decor_preset'] ?? '' ) );
				?>
				<p class="hwe-field" data-hwe-field="decor_preset">
					<label for="<?php echo esc_attr( 'hwe_decor_preset_' . $idx ); ?>"><strong><?php esc_html_e( 'Visual decorativo de plantilla', 'hwe-banners' ); ?></strong></label><br>
					<select class="hwe-decor-preset" name="<?php echo esc_attr( $name( 'decor_preset' ) ); ?>" id="<?php echo esc_attr( 'hwe_decor_preset_' . $idx ); ?>">
						<?php foreach ( $presets as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $preset, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Cuál de los visuales decorativos de la marca se muestra en este slide. Solo aplica con «Visual decorativo por defecto».', 'hwe-banners' ); ?></span>
				</p>
				<?php endif; ?>
				<?php
				self::mediaField(
					$name( 'decor_svg' ),
					__( 'SVG decorativo', 'hwe-banners' ),
					$val( 'decor_svg' ),
					'decor_svg',
					true
				);
				self::mediaField( $name( 'image' ), __( 'Imagen (desktop)', 'hwe-banners' ), $val( 'image' ), 'image' );
				self::mediaField( $name( 'image_mobile' ), __( 'Imagen (móvil, opcional)', 'hwe-banners' ), $val( 'image_mobile' ), 'image_mobile' );
				self::textField( 'image_alt', $name( 'image_alt' ), __( 'Texto alternativo de la imagen', 'hwe-banners' ), $get( 'image_alt' ) );

				// Default on: ausente o distinto de '0'.
				$showCopy = ! array_key_exists( 'show_copy', $slide ) || (string) ( $slide['show_copy'] ?? '1' ) !== '0';
				?>
				<p class="hwe-field hwe-checkbox" data-hwe-field="show_copy">
					<label>
						<input type="hidden" name="<?php echo esc_attr( $name( 'show_copy' ) ); ?>" value="0">
						<input type="checkbox" class="hwe-show-copy" name="<?php echo esc_attr( $name( 'show_copy' ) ); ?>" value="1" <?php checked( $showCopy ); ?>>
						<?php esc_html_e( 'Habilitar sección de copy', 'hwe-banners' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'Si está desactivado, el frontend solo muestra la imagen a sangrado.', 'hwe-banners' ); ?></span>
				</p>
				<?php
				self::textField( 'title', $name( 'title' ), __( 'Título', 'hwe-banners' ), $get( 'title' ), [ 'class' => 'hwe-title-input' ] );
				self::textField( 'subtitle', $name( 'subtitle' ), __( 'Subtítulo', 'hwe-banners' ), $get( 'subtitle' ) );
				self::textField( 'cta', $name( 'cta' ), __( 'Texto del botón (CTA)', 'hwe-banners' ), $get( 'cta' ) );
				self::textField( 'cta_href', $name( 'cta_href' ), __( 'URL del botón (CTA)', 'hwe-banners' ), $get( 'cta_href' ), [ 'placeholder' => '/products' ] );
				self::textField( 'cta2', $name( 'cta2' ), __( 'Texto del botón secundario (CTA 2)', 'hwe-banners' ), $get( 'cta2' ) );
				self::textField( 'cta2_href', $name( 'cta2_href' ), __( 'URL del botón secundario (CTA 2)', 'hwe-banners' ), $get( 'cta2_href' ), [ 'placeholder' => '/products' ] );
				self::textField( 'badge', $name( 'badge' ), __( 'Badge', 'hwe-banners' ), $get( 'badge' ) );
				self::textField( 'link', $name( 'link' ), __( 'URL (clic en la imagen / slide)', 'hwe-banners' ), $get( 'link' ), [ 'placeholder' => '/products' ] );
				?>
				<?php if ( $showOrder ) : ?>
				<p class="hwe-field" data-hwe-field="order"><label><?php esc_html_e( 'Orden', 'hwe-banners' ); ?></label>
					<input type="number" name="<?php echo esc_attr( $name( 'order' ) ); ?>" value="<?php echo esc_attr( (string) ( $slide['order'] ?? 0 ) ); ?>" min="0" step="1" style="width:90px"></p>
				<?php endif; ?>
				<p class="hwe-field hwe-checkbox" data-hwe-field="hide_overlay">
					<label><input type="checkbox" name="<?php echo esc_attr( $name( 'hide_overlay' ) ); ?>" value="1" <?php checked( ! empty( $slide['hide_overlay'] ) ); ?>>
					<?php esc_html_e( 'Ocultar overlay de texto (solo imagen)', 'hwe-banners' ); ?></label>
				</p>

				<?php foreach ( $secondary as $loc ) :
					$iget = static fn( string $k ): string => (string) ( $slide['i18n'][ $loc ][ $k ] ?? '' );
					$iv   = static fn( string $k ): string => esc_attr( $iget( $k ) );
					$in   = static fn( string $k ): string => 'hwe_banner_slides[' . $idx . '][i18n][' . $loc . '][' . $k . ']';
				?>
				<fieldset class="hwe-i18n">
					<legend><?php echo esc_html( sprintf( __( 'Overrides idioma: %s', 'hwe-banners' ), strtoupper( str_replace( '_', '-', $loc ) ) ) ); ?></legend>
					<p class="description"><?php esc_html_e( 'Solo textos e imágenes. Las URL no se repiten por idioma: la del slide vale para todos y el sitio la traduce sola.', 'hwe-banners' ); ?></p>
					<?php
					self::mediaField( $in( 'image' ), __( 'Imagen (desktop)', 'hwe-banners' ), $iv( 'image' ), 'image' );
					self::mediaField( $in( 'image_mobile' ), __( 'Imagen (móvil)', 'hwe-banners' ), $iv( 'image_mobile' ), 'image_mobile' );
					self::textField( 'image_alt', $in( 'image_alt' ), __( 'Texto alternativo de la imagen', 'hwe-banners' ), $iget( 'image_alt' ) );
					self::textField( 'title', $in( 'title' ), __( 'Título', 'hwe-banners' ), $iget( 'title' ) );
					self::textField( 'subtitle', $in( 'subtitle' ), __( 'Subtítulo', 'hwe-banners' ), $iget( 'subtitle' ) );
					self::textField( 'badge', $in( 'badge' ), __( 'Badge', 'hwe-banners' ), $iget( 'badge' ) );
					self::textField( 'cta', $in( 'cta' ), __( 'Texto del botón (CTA)', 'hwe-banners' ), $iget( 'cta' ) );
					self::textField( 'cta2', $in( 'cta2' ), __( 'Texto del botón secundario (CTA 2)', 'hwe-banners' ), $iget( 'cta2' ) );
					?>
				</fieldset>
				<?php endforeach; ?>

			</div>
		</div>
		<?php
	}

	/**
	 * Campo de texto con disclaimer de longitud/relleno (soft UX).
	 *
	 * @param array{class?:string,placeholder?:string} $opts
	 */
	private static function textField( string $field, string $name, string $label, string $value, array $opts = [] ): void {
		$class = trim( ' ' . (string) ( $opts['class'] ?? '' ) );
		$ph    = (string) ( $opts['placeholder'] ?? '' );
		$hint  = FieldHints::for( $field );
		?>
		<p class="hwe-field" data-hwe-field="<?php echo esc_attr( $field ); ?>">
			<label><?php echo esc_html( $label ); ?></label>
			<input type="text"
				class="<?php echo esc_attr( ltrim( $class ) ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				<?php if ( $ph !== '' ) : ?>placeholder="<?php echo esc_attr( $ph ); ?>"<?php endif; ?>>
			<?php if ( $hint !== '' ) : ?>
				<span class="description hwe-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function mediaField( string $name, string $label, string $value, string $field = '', bool $svgOnly = false ): void {
		$pickClass = $svgOnly ? 'button hwe-media-pick hwe-media-pick-svg' : 'button hwe-media-pick';
		?>
		<div class="hwe-field hwe-media"<?php echo $field !== '' ? ' data-hwe-field="' . esc_attr( $field ) . '"' : ''; ?>>
			<label><?php echo esc_html( $label ); ?></label>
			<span class="hwe-media-row">
				<input type="text" class="hwe-media-url" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" readonly>
				<button type="button" class="<?php echo esc_attr( $pickClass ); ?>"><?php esc_html_e( 'Seleccionar', 'hwe-banners' ); ?></button>
				<button type="button" class="button hwe-media-clear"><?php esc_html_e( 'Quitar', 'hwe-banners' ); ?></button>
			</span>
			<span class="hwe-media-preview"><?php if ( $value !== '' ) : ?><img src="<?php echo esc_url( $value ); ?>" alt=""><?php endif; ?></span>
		</div>
		<?php
	}
}
