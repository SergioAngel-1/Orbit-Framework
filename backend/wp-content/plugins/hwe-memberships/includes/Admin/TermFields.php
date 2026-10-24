<?php
/**
 * Campos de requisito en el editor de categorías (`HWE_Memberships_Admin_TermFields`, spec §6.1).
 *
 * Cada categoría de producto puede exigir un rango mínimo de membresía (0 = pública). La
 * herencia NO se guarda en las hijas: `HWE_Memberships_Access` calcula al leer el mayor rango de
 * toda la cadena, así que cambiar el padre no reescribe nada. En la edición se avisa cuando la
 * categoría hereda un rango mayor que el suyo.
 *
 * El guardado exige la capacidad `manage_product_terms` y el nonce del formulario. La
 * invalidación de la caché y la revalidación de `products` las hace `Access` al enganchar
 * `created_product_cat` / `edited_product_cat`, para cubrir también los cambios que no pasan por
 * este formulario.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_TermFields {

	/** Campo y acción del nonce del formulario de categoría. */
	public const NONCE_FIELD  = 'hwe_membership_term_nonce';
	public const NONCE_ACTION = 'hwe_membership_save_term';

	/** Nombre del campo del rango mínimo. */
	public const FIELD = 'hwe_membership_min_rank';

	/** Capacidad de WordPress que puede editar categorías de producto. */
	public const CAP = 'manage_product_terms';

	/** Engancha los formularios de alta/edición y el guardado. */
	public static function register(): void {
		add_action( 'product_cat_add_form_fields', array( self::class, 'renderAddFields' ) );
		add_action( 'product_cat_edit_form_fields', array( self::class, 'renderEditFields' ) );
		add_action( 'created_product_cat', array( self::class, 'saveTerm' ) );
		add_action( 'edited_product_cat', array( self::class, 'saveTerm' ) );
	}

	/** Campo en el formulario de «añadir categoría». */
	public static function renderAddFields(): void {
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		}

		echo '<div class="form-field">';
		echo '<label for="hwe_membership_min_rank">' . esc_html__( 'Rango mínimo de membresía', 'hwe' ) . '</label>';
		echo '<input type="number" min="0" step="1" name="' . esc_attr( self::FIELD ) . '" id="hwe_membership_min_rank" value="0">';
		echo '<p class="description">'
			. esc_html__( '0 = categoría pública. Mayor rango = más acceso. El requisito efectivo de un producto es el mayor entre el suyo y el de toda su cadena de categorías.', 'hwe' )
			. '</p>';
		echo '</div>';
	}

	/**
	 * Campo en el formulario de «editar categoría», con el aviso de herencia.
	 *
	 * @param mixed $term
	 */
	public static function renderEditFields( $term ): void {
		$termId = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;
		$own    = self::ownRank( $termId );
		$chain  = class_exists( 'HWE_Memberships_Access' )
			? HWE_Memberships_Access::categoryRequirement( $termId )
			: array( 'min_rank' => 0 );
		$inherited = (int) ( $chain['min_rank'] ?? 0 );

		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		}

		echo '<tr class="form-field">';
		echo '<th scope="row"><label for="hwe_membership_min_rank">' . esc_html__( 'Rango mínimo de membresía', 'hwe' ) . '</label></th>';
		echo '<td>';
		echo '<input type="number" min="0" step="1" name="' . esc_attr( self::FIELD ) . '" id="hwe_membership_min_rank" value="' . esc_attr( (string) $own ) . '">';
		echo '<p class="description">' . esc_html__( '0 = categoría pública. La herencia se calcula al leer: cambiar esta categoría no reescribe sus hijas.', 'hwe' ) . '</p>';

		if ( $inherited > $own && $inherited > 0 ) {
			echo '<p class="description">'
				. esc_html(
					sprintf(
						/* translators: %d: rango mínimo heredado de la cadena de categorías. */
						__( 'Hereda un rango mínimo mayor (%d) de su categoría padre: el requisito efectivo es el de toda la cadena.', 'hwe' ),
						$inherited
					)
				)
				. '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Guarda el rango mínimo de la categoría. Sin capacidad o sin nonce no escribe nada.
	 */
	public static function saveTerm( int $termId ): void {
		if ( $termId <= 0 ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_scalar( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! array_key_exists( self::FIELD, $_POST ) ) {
			return;
		}

		$raw  = is_scalar( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : '';
		$rank = is_numeric( $raw ) ? max( 0, (int) floor( (float) $raw ) ) : 0;
		if ( '' !== $raw && ! is_numeric( $raw ) ) {
			error_log( '[hwe] memberships: el rango mínimo de la categoría ' . $termId . ' no es numérico; se guarda como público.' );
		}

		if ( $rank > 0 ) {
			update_term_meta( $termId, HWE_Memberships_Access::MIN_RANK_META, $rank );
		} else {
			delete_term_meta( $termId, HWE_Memberships_Access::MIN_RANK_META );
		}

		HWE_Memberships_Access::flush();
	}

	/** Rango propio del término, sin herencia. */
	private static function ownRank( int $termId ): int {
		if ( $termId <= 0 || ! function_exists( 'get_term_meta' ) ) {
			return 0;
		}

		$raw = get_term_meta( $termId, HWE_Memberships_Access::MIN_RANK_META, true );

		return is_scalar( $raw ) && is_numeric( $raw ) ? max( 0, (int) floor( (float) $raw ) ) : 0;
	}
}
