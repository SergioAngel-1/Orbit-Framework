<?php

namespace HWE\Banners;

/**
 * Guía de medidas sugeridas por tipo de banner / plantilla de slide.
 * Filtrable vía `hwe_banners_size_guide` para que la instancia ajuste tipografías
 * o resoluciones sin tocar el plugin.
 *
 * Forma: [
 *   'banner_type_slug' => [
 *     'summary' => '…',           // texto corto (paso 1)
 *     'rows' => [                 // filas de la tabla (paso 2)
 *       [ 'label' => '…', 'desktop' => '…', 'mobile' => '…', 'notes' => '…' ],
 *     ],
 *   ],
 * ]
 */
class SizeGuide {

	/**
	 * @return array<string,array{summary:string,rows:array<int,array{label:string,desktop:string,mobile:string,notes:string}>}>
	 */
	public static function all(): array {
		$defaults = [
			'carousel'  => [
				'summary' => __( 'Carrusel: exporta rectángulos opacos; la forma, el arco o el sangrado los aplica el CSS del frontend (no recortes con transparencia).', 'hwe-banners' ),
				'rows'    => [
					[
						'label'   => __( 'Ancho completo', 'hwe-banners' ),
						'desktop' => '2560×1097 (21:9)',
						'mobile'  => '1080×1350 (4:5)',
						'notes'   => __( 'La banda es muy apaisada, no 16:9: un 16:9 se recorta casi un tercio por arriba y por abajo. En móvil manda la imagen móvil (4:5); deja el sujeto centrado. El texto lo pinta la web.', 'hwe-banners' ),
					],
					[
						'label'   => __( 'Foto enmarcada', 'hwe-banners' ),
						'desktop' => '1200×1000 (6:5)',
						'mobile'  => '1040×1300 (4:5)',
						'notes'   => __( 'Para plantillas con la foto en un marco con forma: el sujeto en el centro, porque las esquinas se redondean. Rectángulo opaco, sin transparencias con forma.', 'hwe-banners' ),
					],
					[
						'label'   => __( 'Visual decorativo (SVG)', 'hwe-banners' ),
						'desktop' => 'SVG vectorial',
						'mobile'  => 'mismo SVG',
						'notes'   => __( 'En lugar de una foto: un SVG decorativo (de la plantilla o propio). No hace falta imagen.', 'hwe-banners' ),
					],
				],
			],
			'band'      => [
				'summary' => __( 'Franja: una sola imagen, retrato o foto a sangrado de fondo según la locación.', 'hwe-banners' ),
				'rows'    => [
					[
						'label'   => __( 'Retrato', 'hwe-banners' ),
						'desktop' => '1200×1200',
						'mobile'  => '—',
						'notes'   => __( 'Cuadrado; el recorte (circular u otro) lo aplica el frontend.', 'hwe-banners' ),
					],
					[
						'label'   => __( 'Fondo a sangrado', 'hwe-banners' ),
						'desktop' => '1920×1080 (16:9)',
						'mobile'  => '1080×1350 (opc.)',
						'notes'   => __( 'Fondo bajo el copy de la sección; prioriza sujeto o ambiente centrados.', 'hwe-banners' ),
					],
				],
			],
			'allies'    => [
				'summary' => __( 'Aliados: logo horizontal PNG/SVG con fondo transparente.', 'hwe-banners' ),
				'rows'    => [
					[
						'label'   => __( 'Logo', 'hwe-banners' ),
						'desktop' => '400×200',
						'mobile'  => '—',
						'notes'   => __( 'PNG o SVG; sin texto pequeño ilegible.', 'hwe-banners' ),
					],
				],
			],
			'page_hero' => [
				'summary' => __( 'Hero de página interna: solo foto (el copy lo pone la web). La forma la aplica el CSS según la locación.', 'hwe-banners' ),
				'rows'    => [
					[
						'label'   => __( 'Cuadrado / tarjeta', 'hwe-banners' ),
						'desktop' => '1200×1200 (1:1)',
						'mobile'  => '1080×1350 (4:5)',
						'notes'   => __( 'Columna media con radio de tarjeta. Sujeto centrado: el CSS puede recortar las esquinas.', 'hwe-banners' ),
					],
					[
						'label'   => __( 'Apaisado', 'hwe-banners' ),
						'desktop' => '1200×880 (≈3:2)',
						'mobile'  => '1080×1200 (opc.)',
						'notes'   => __( 'Para locaciones con un marco apaisado. Rectángulo opaco.', 'hwe-banners' ),
					],
				],
			],
			'landing'   => [
				'summary' => __( 'Banner de landing: imagen a sangrado completo (sin recorte).', 'hwe-banners' ),
				'rows'    => [
					[
						'label'   => __( 'Landing (sangrado)', 'hwe-banners' ),
						'desktop' => '1774×887',
						'mobile'  => '1080×1350 (opc.)',
						'notes'   => __( 'Imagen íntegra a lo ancho; el asset puede traer su propio fondo.', 'hwe-banners' ),
					],
				],
			],
		];

		$guide = apply_filters( 'hwe_banners_size_guide', $defaults );
		$clean = [];
		foreach ( (array) $guide as $type => $cfg ) {
			$type = sanitize_key( (string) $type );
			if ( $type === '' || ! is_array( $cfg ) ) {
				continue;
			}
			$rows = [];
			foreach ( (array) ( $cfg['rows'] ?? [] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[] = [
					'label'   => (string) ( $row['label'] ?? '' ),
					'desktop' => (string) ( $row['desktop'] ?? '' ),
					'mobile'  => (string) ( $row['mobile'] ?? '' ),
					'notes'   => (string) ( $row['notes'] ?? '' ),
				];
			}
			$clean[ $type ] = [
				'summary' => (string) ( $cfg['summary'] ?? '' ),
				'rows'    => $rows,
			];
		}
		return $clean !== [] ? $clean : $defaults;
	}

	/** @return array{summary:string,rows:array<int,array{label:string,desktop:string,mobile:string,notes:string}>}|null */
	public static function forType( string $type ): ?array {
		return self::all()[ $type ] ?? null;
	}

	/** HTML de la caja informativa (paso 2 o resumen en paso 1). */
	public static function renderBox( ?string $type = null, bool $compact = false ): void {
		if ( $type !== null ) {
			$guide = self::forType( $type );
			if ( ! $guide ) {
				return;
			}
			self::renderSingle( $type, $guide, $compact );
			return;
		}
		echo '<div class="hwe-size-guide hwe-size-guide--all">';
		echo '<p class="hwe-size-guide__title"><strong>' . esc_html__( 'Medidas sugeridas', 'hwe-banners' ) . '</strong></p>';
		foreach ( self::all() as $slug => $guide ) {
			$label = BannerTypes::labels()[ $slug ] ?? $slug;
			echo '<p class="hwe-size-guide__summary"><strong>' . esc_html( $label ) . ':</strong> ';
			echo esc_html( $guide['summary'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param array{summary:string,rows:array<int,array{label:string,desktop:string,mobile:string,notes:string}>} $guide
	 */
	private static function renderSingle( string $type, array $guide, bool $compact ): void {
		$label = BannerTypes::labels()[ $type ] ?? $type;
		?>
		<div class="hwe-size-guide">
			<p class="hwe-size-guide__title">
				<strong><?php echo esc_html( sprintf( __( 'Medidas sugeridas · %s', 'hwe-banners' ), $label ) ); ?></strong>
			</p>
			<?php if ( $guide['summary'] !== '' ) : ?>
				<p class="description"><?php echo esc_html( $guide['summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! $compact && $guide['rows'] !== [] ) : ?>
			<table class="widefat striped hwe-size-guide__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plantilla / uso', 'hwe-banners' ); ?></th>
						<th><?php esc_html_e( 'Desktop', 'hwe-banners' ); ?></th>
						<th><?php esc_html_e( 'Móvil', 'hwe-banners' ); ?></th>
						<th><?php esc_html_e( 'Notas', 'hwe-banners' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $guide['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><code><?php echo esc_html( $row['desktop'] ); ?></code></td>
						<td><code><?php echo esc_html( $row['mobile'] ); ?></code></td>
						<td><?php echo esc_html( $row['notes'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
