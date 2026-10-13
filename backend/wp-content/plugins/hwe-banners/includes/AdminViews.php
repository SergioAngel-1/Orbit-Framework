<?php

namespace HWE\Banners;

/**
 * Vistas del listado de Banners (wp-admin → Banners).
 *
 * Los banners de tipo `landing` y `product_landing` son heros de landings
 * editoriales y de producto: hay uno por landing, así que
 * inundan el listado general y no compiten con los banners de portada y tienda. Se
 * sacan de la vista "Todos" y se agrupan en una vista propia "Landings"
 * (`?hwe_group=landings`).
 *
 *   - `views_edit-hwe_banner`: reescribe el contador de "Todos" (sin landings) y
 *     añade el enlace "Landings" con su contador real.
 *   - `pre_get_posts`: filtra la query principal del listado según el grupo.
 *
 * No toca el "Paso 1" del editor: esos tipos siguen disponibles al crear.
 */
class AdminViews {

	/** Tipos de banner que se agrupan fuera de "Todos". */
	private const LANDING_TYPES = [ 'landing', 'product_landing' ];

	public static function register(): void {
		add_filter( 'views_edit-' . PostType::SLUG, [ self::class, 'views' ] );
		add_action( 'pre_get_posts', [ self::class, 'filterList' ] );
	}

	/** ¿Estamos en la vista agrupada de landings? */
	private static function isLandingGroup(): bool {
		return isset( $_GET['hwe_group'] )
			&& sanitize_key( wp_unslash( $_GET['hwe_group'] ) ) === 'landings';
	}

	/**
	 * @param array<string,string> $views
	 * @return array<string,string>
	 */
	public static function views( array $views ): array {
		$landingCount = self::count( true );
		$restCount    = self::count( false );
		$inGroup      = self::isLandingGroup();
		$base         = admin_url( 'edit.php?post_type=' . PostType::SLUG );

		if ( isset( $views['all'] ) ) {
			$views['all'] = self::link(
				$base,
				__( 'Todos', 'hwe-banners' ),
				$restCount,
				! $inGroup && empty( $_GET['post_status'] )
			);
		}

		$views['hwe_landings'] = self::link(
			add_query_arg( 'hwe_group', 'landings', $base ),
			__( 'Landings', 'hwe-banners' ),
			$landingCount,
			$inGroup
		);

		return $views;
	}

	/**
	 * Filtra la query principal del listado: fuera de "Landings" se ocultan los
	 * tipos de landing; dentro solo se muestran esos. Los banners sin tipo
	 * (huérfanos de migración) permanecen visibles en "Todos".
	 */
	public static function filterList( \WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || $pagenow !== 'edit.php' || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== PostType::SLUG ) {
			return;
		}

		$metaQuery   = $query->get( 'meta_query' );
		$metaQuery   = is_array( $metaQuery ) ? $metaQuery : [];
		$metaQuery[] = self::isLandingGroup()
			? [ 'key' => '_hwe_banner_type', 'compare' => 'IN', 'value' => self::LANDING_TYPES ]
			: [
				'relation' => 'OR',
				[ 'key' => '_hwe_banner_type', 'compare' => 'NOT IN', 'value' => self::LANDING_TYPES ],
				[ 'key' => '_hwe_banner_type', 'compare' => 'NOT EXISTS' ],
			];
		$query->set( 'meta_query', $metaQuery );
	}

	/** Cuenta banners (cualquier estado salvo papelera) dentro / fuera del grupo landings. */
	private static function count( bool $landingGroup ): int {
		$meta = $landingGroup
			? [ [ 'key' => '_hwe_banner_type', 'compare' => 'IN', 'value' => self::LANDING_TYPES ] ]
			: [
				[
					'relation' => 'OR',
					[ 'key' => '_hwe_banner_type', 'compare' => 'NOT IN', 'value' => self::LANDING_TYPES ],
					[ 'key' => '_hwe_banner_type', 'compare' => 'NOT EXISTS' ],
				],
			];

		$q = new \WP_Query( [
			'post_type'              => PostType::SLUG,
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'nopaging'               => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => $meta,
		] );

		return (int) $q->found_posts;
	}

	private static function link( string $url, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			$count
		);
	}
}
