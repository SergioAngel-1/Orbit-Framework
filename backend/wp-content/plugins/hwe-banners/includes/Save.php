<?php

namespace HWE\Banners;

/**
 * Guardado de la meta del banner. El tipo es inmutable tras el primer guardado.
 * Valida locación contra el tipo; i18n en textos e imágenes (las URL son
 * una sola por slide: la localiza el frontend).
 */
class Save {

	public static function register(): void {
		add_action( 'save_post_' . PostType::SLUG, [ self::class, 'save' ], 10, 1 );
		add_action( 'admin_notices', [ self::class, 'maybeShowDiscardedNotice' ] );
	}

	/**
	 * Un slide vacío (sin imagen, sin visual decorativo y sin una línea de copy) o con
	 * SVG inválido (decor_mode=custom) se descartaba en save() sin ningún
	 * aviso: un editor podía perder un slide completo tras guardar sin saber
	 * por qué desapareció. Igual el recorte por max_slides. Se avisa vía
	 * transient (save_post redirige antes de volver a renderizar la pantalla).
	 */
	public static function maybeShowDiscardedNotice(): void {
		global $post;
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostType::SLUG ) {
			return;
		}
		$key   = 'hwe_banner_discarded_' . $post->ID;
		$count = get_transient( $key );
		if ( ! $count ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of discarded slides */
				_n(
					'Se descartó %d slide al guardar (vacío, SVG inválido, o por encima del máximo permitido para este tipo de banner).',
					'Se descartaron %d slides al guardar (vacíos, SVG inválidos, o por encima del máximo permitido para este tipo de banner).',
					$count,
					'hwe-banners'
				),
				$count
			) )
		);
	}

	public static function save( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['hwe_banner_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_banner_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hwe_banner_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$existing = (string) get_post_meta( $postId, '_hwe_banner_type', true );
		$type     = isset( $_POST['hwe_banner_type'] ) ? sanitize_key( wp_unslash( $_POST['hwe_banner_type'] ) ) : '';
		if ( $existing !== '' && BannerTypes::isValid( $existing ) ) {
			$type = $existing;
		} elseif ( ! BannerTypes::isValid( $type ) ) {
			return;
		}
		update_post_meta( $postId, '_hwe_banner_type', $type );
		$cfg = BannerTypes::config( $type );

		$location = isset( $_POST['hwe_banner_location'] ) ? sanitize_key( wp_unslash( $_POST['hwe_banner_location'] ) ) : '';
		if ( ! BannerTypes::isLocationAllowed( $type, $location ) ) {
			$location = BannerTypes::defaultLocationFor( $type );
		}
		update_post_meta( $postId, '_hwe_banner_location', $location );

		// Limpieza de metas legacy (corte limpio; no hay deploy previo).
		delete_post_meta( $postId, '_hwe_banner_placement' );
		delete_post_meta( $postId, '_hwe_banner_order' );

		if ( $cfg['interval'] ) {
			update_post_meta( $postId, '_hwe_banner_interval_ms', isset( $_POST['hwe_banner_interval_ms'] ) ? max( 1000, absint( $_POST['hwe_banner_interval_ms'] ) ) : 6000 );
		} else {
			delete_post_meta( $postId, '_hwe_banner_interval_ms' );
		}

		$rawSlides = isset( $_POST['hwe_banner_slides'] ) && is_array( $_POST['hwe_banner_slides'] )
			? wp_unslash( $_POST['hwe_banner_slides'] )
			: [];

		$slides    = [];
		$discarded = 0;
		$secondary = Plugin::secondaryLocales();
		foreach ( $rawSlides as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$slideType = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : '';
			$slideType = BannerTypes::isSlideTypeAllowed( $type, $slideType )
				? $slideType
				: BannerTypes::defaultSlideTypeFor( $type );

			$fields          = SlideTypes::all()[ $slideType ]['fields'] ?? null;
			$supportsDecor = $fields === null || in_array( 'decor_mode', $fields, true );

			$decorMode = sanitize_key( (string) ( $raw['decor_mode'] ?? 'none' ) );
			if ( ! $supportsDecor || ! in_array( $decorMode, [ 'none', 'default', 'custom' ], true ) ) {
				$decorMode = 'none';
			}

			$image        = esc_url_raw( $raw['image'] ?? '' );
			$imageMobile  = esc_url_raw( $raw['image_mobile'] ?? '' );
			$decorSvg   = esc_url_raw( $raw['decor_svg'] ?? '' );

			// Cuál de los visuales decorativos de plantilla, solo en modo `default`. Se
			// sanea SIEMPRE contra la lista de la instancia (una clave que ya
			// no exista cae a la primera) y se vacía en los otros dos modos:
			// guardar la elección de un visual que no se está usando deja un
			// dato que nadie lee y que reaparece al volver a `default`.
			$decorPreset = $supportsDecor && $decorMode === 'default'
				? DecorPresets::sanitize( (string) ( $raw['decor_preset'] ?? '' ) )
				: '';

			// XOR: visual decorativo ↔ imagen.
			if ( $decorMode === 'default' ) {
				$image       = '';
				$imageMobile = '';
				$decorSvg  = '';
			} elseif ( $decorMode === 'custom' ) {
				$image       = '';
				$imageMobile = '';
				if ( $decorSvg === '' || ! self::isSvgUrl( $decorSvg ) ) {
					$discarded++;
					continue;
				}
			} else {
				$decorSvg = '';
				/* Sin imagen, el slide solo se descarta si TAMPOCO tiene copy.

				   Antes bastaba con no tener fotografía para perderlo, y eso
				   borraba el titular, la entradilla y los botones ya escritos
				   aquí mientras el arte no estuviera entregado: bastaba con
				   abrir el banner y pulsar Actualizar para que el slide se
				   esfumara. Las plantillas del frontend saben pintar su hueco
				   (placeholder de rayas), así que un slide con mensaje es
				   servible sin foto. Mismo criterio que `mapSlide` en el
				   frontend (`lib/banners/normalize.ts`): se descarta lo que no
				   tiene NADA que pintar, no lo que no tiene imagen. */
				$tieneCopy = '' !== trim( (string) (
					( $raw['title'] ?? '' ) . ( $raw['subtitle'] ?? '' ) .
					( $raw['badge'] ?? '' ) . ( $raw['cta'] ?? '' )
				) );
				if ( $image === '' && ! $tieneCopy ) {
					$discarded++;
					continue;
				}
			}

			$supportsShowCopy = $fields === null || in_array( 'show_copy', $fields, true );
			// Default on (landing). Hidden "0" + checkbox "1" en admin.
			$showCopy = '1';
			if ( $supportsShowCopy ) {
				$showCopy = empty( $raw['show_copy'] ) || (string) $raw['show_copy'] === '0' ? '0' : '1';
			}

			$slide = [
				'image'         => $image,
				'image_mobile'  => $imageMobile,
				'decor_mode'  => $decorMode,
				'decor_preset' => $decorPreset,
				'decor_svg'   => $decorSvg,
				'image_alt'     => sanitize_text_field( $raw['image_alt'] ?? '' ),
				'title'         => sanitize_text_field( $raw['title'] ?? '' ),
				'subtitle'      => sanitize_text_field( $raw['subtitle'] ?? '' ),
				'cta'           => sanitize_text_field( $raw['cta'] ?? '' ),
				'cta_href'      => self::sanitizeHref( $raw['cta_href'] ?? '' ),
				'cta2'          => sanitize_text_field( $raw['cta2'] ?? '' ),
				'cta2_href'     => self::sanitizeHref( $raw['cta2_href'] ?? '' ),
				'badge'         => sanitize_text_field( $raw['badge'] ?? '' ),
				'link'          => self::sanitizeHref( $raw['link'] ?? '' ),
				'order'         => isset( $raw['order'] ) ? absint( $raw['order'] ) : 0,
				'hide_overlay'  => empty( $raw['hide_overlay'] ) ? '0' : '1',
				'show_copy'     => $showCopy,
				'i18n'          => [],
				'type'          => $slideType,
			];

			$rawI18n = ( isset( $raw['i18n'] ) && is_array( $raw['i18n'] ) ) ? $raw['i18n'] : [];
			foreach ( $secondary as $loc ) {
				$t = is_array( $rawI18n[ $loc ] ?? null ) ? $rawI18n[ $loc ] : [];
				$i18nImage = $decorMode === 'none' ? esc_url_raw( $t['image'] ?? '' ) : '';
				$i18nMobile = $decorMode === 'none' ? esc_url_raw( $t['image_mobile'] ?? '' ) : '';
				$slide['i18n'][ $loc ] = [
					'image'        => $i18nImage,
					'image_mobile' => $i18nMobile,
					'image_alt'    => sanitize_text_field( $t['image_alt'] ?? '' ),
					'title'        => sanitize_text_field( $t['title'] ?? '' ),
					'subtitle'     => sanitize_text_field( $t['subtitle'] ?? '' ),
					'badge'        => sanitize_text_field( $t['badge'] ?? '' ),
					'cta'          => sanitize_text_field( $t['cta'] ?? '' ),
					'cta2'         => sanitize_text_field( $t['cta2'] ?? '' ),
				];
				// Las URL (`cta_href`, `cta2_href`, `link`) NO tienen override por
				// idioma: se escribe UNA ruta interna y el frontend la localiza.
			}
			$slides[] = $slide;
		}

		usort( $slides, static fn( $a, $b ) => ( $a['order'] ?: 999 ) <=> ( $b['order'] ?: 999 ) );

		if ( $cfg['max_slides'] !== null && count( $slides ) > $cfg['max_slides'] ) {
			$discarded += count( $slides ) - $cfg['max_slides'];
			$slides     = array_slice( $slides, 0, $cfg['max_slides'] );
		}

		if ( $slides === [] ) {
			delete_post_meta( $postId, '_hwe_banner_slides' );
		} else {
			update_post_meta( $postId, '_hwe_banner_slides', $slides );
		}

		if ( $discarded > 0 ) {
			set_transient( 'hwe_banner_discarded_' . $postId, $discarded, 60 );
		}
	}

	private static function sanitizeHref( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		// "//host/path" (protocol-relative) también empieza por "/" pero es
		// una URL ABSOLUTA hacia otro host, no una ruta interna: si se
		// tratara como ruta interna, se guardaría tal cual (solo
		// sanitize_text_field, sin validar esquema/host) y bypasearía por
		// completo la validación de esc_url_raw. Solo "/algo" (una barra) es
		// ruta interna segura.
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			return sanitize_text_field( $value );
		}
		return esc_url_raw( $value );
	}

	/** Acepta URLs cuyo path termina en .svg (querystring permitido). */
	private static function isSvgUrl( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && str_ends_with( strtolower( $path ), '.svg' );
	}
}
