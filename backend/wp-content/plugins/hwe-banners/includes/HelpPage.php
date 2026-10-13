<?php

namespace HWE\Banners;

/**
 * Página de Ayuda (submenú bajo «Banners»): muestra en un solo sitio las medidas
 * sugeridas de TODOS los tipos de banner y plantillas de slide.
 *
 * La fuente de verdad de las medidas es {@see SizeGuide} (filtrable vía
 * `hwe_banners_size_guide`); aquí solo se orquesta la página admin y se reutiliza
 * su renderizado de tablas, para no duplicar datos.
 */
class HelpPage {

	/** Slug del submenú (?post_type=hwe_banner&page=hwe-banners-help). */
	public const SLUG = 'hwe-banners-help';

	/** Hook suffix devuelto por add_submenu_page (para acotar el enqueue). */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addPage' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/** Registra la subpágina bajo el CPT Banner. */
	public static function addPage(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . PostType::SLUG,
			__( 'Ayuda · Medidas de banners', 'hwe-banners' ), // <title> de la página
			__( 'Ayuda', 'hwe-banners' ),                      // etiqueta del menú
			'edit_posts',
			self::SLUG,
			[ self::class, 'render' ]
		);
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/** Reutiliza el CSS del editor (estilos .hwe-size-guide) solo en esta pantalla. */
	public static function enqueue( string $hook ): void {
		if ( self::$hook === '' || $hook !== self::$hook ) {
			return;
		}
		$css = HWE_BANNERS_DIR . '/assets/admin.css';
		$ver = HWE_BANNERS_VERSION;
		if ( is_readable( $css ) ) {
			$mtime = filemtime( $css );
			if ( $mtime ) {
				$ver .= '.' . $mtime;
			}
		}
		wp_enqueue_style(
			'hwe-banners-admin',
			HWE_BANNERS_URL . 'assets/admin.css',
			[],
			$ver
		);
	}

	/** Contenido de la página. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap hwe-banners-help">
			<h1><?php esc_html_e( 'Ayuda · Medidas de banners', 'hwe-banners' ); ?></h1>
			<p class="description" style="max-width:820px;font-size:13px;">
				<?php esc_html_e( 'Medidas de exportación recomendadas por tipo de banner y plantilla. La forma final (arcos, sangrados, recortes) la aplica el CSS del frontend: exporta siempre rectángulos opacos, sin transparencias con forma. Los logos de «Aliados» sí van en PNG/SVG con fondo transparente.', 'hwe-banners' ); ?>
			</p>

			<?php
			$types = SizeGuide::all();
			if ( $types === [] ) {
				echo '<p>' . esc_html__( 'No hay medidas definidas.', 'hwe-banners' ) . '</p>';
			} else {
				foreach ( array_keys( $types ) as $slug ) {
					SizeGuide::renderBox( $slug, false );
				}
			}
			?>
		</div>
		<?php
	}
}
