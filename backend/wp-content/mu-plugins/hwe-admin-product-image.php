<?php
/**
 * Plugin Name: HWE Admin · Imagen de producto
 * Description: En la lista de productos de wp-admin: filtro «Sin imagen / Con
 *              imagen» y columna de imagen ORDENABLE. Sirve para encontrar de
 *              un vistazo los productos a los que les falta la foto — el hueco
 *              de contenido que decide si un producto se publica o se queda
 *              privado.
 *
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Parámetro GET del filtro. Con prefijo para no chocar con los de Woo. */
const HWE_PRODUCT_IMAGE_FILTER = 'hwe_image';
/** Valor de `orderby` que representa «ordenar por tener imagen». */
const HWE_PRODUCT_IMAGE_ORDERBY = 'hwe_has_image';

/**
 * ¿Estamos en la consulta principal de la lista de productos de wp-admin?
 *
 * No se usa `get_current_screen()`: `pre_get_posts` puede correr antes de que
 * la pantalla exista, y devolver `null` ahí desactivaría el filtro en silencio.
 */
function hwe_product_image_is_list_query( WP_Query $query ): bool {
	global $pagenow;
	if ( ! is_admin() || ! $query->is_main_query() || $pagenow !== 'edit.php' ) {
		return false;
	}
	return $query->get( 'post_type' ) === 'product';
}

/**
 * Cuántos productos no tienen imagen destacada.
 *
 * El criterio es el MISMO que pinta la columna de Woo: la imagen destacada. Un
 * producto puede tener galería y seguir saliendo aquí — en la lista se ve el
 * marcador de imagen ausente igualmente, que es lo que se viene a buscar.
 *
 * Cuenta todos los estados menos papelera y autoborradores: los productos sin
 * foto suelen estar precisamente en borrador o en privado.
 */
function hwe_product_image_missing_count(): int {
	global $wpdb;
	$sql = "SELECT COUNT(*)
		FROM {$wpdb->posts} AS p
		LEFT JOIN {$wpdb->postmeta} AS m
			ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
		WHERE p.post_type = 'product'
			AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			AND ( m.meta_value IS NULL OR m.meta_value = '' OR m.meta_value = '0' )";
	return (int) $wpdb->get_var( $sql );
}

// ── Filtro desplegable, junto a los de categoría y tipo de producto ─────────
add_action( 'restrict_manage_posts', function ( string $post_type ): void {
	if ( $post_type !== 'product' ) {
		return;
	}
	$current = isset( $_GET[ HWE_PRODUCT_IMAGE_FILTER ] )
		? sanitize_key( wp_unslash( $_GET[ HWE_PRODUCT_IMAGE_FILTER ] ) )
		: '';
	$missing = hwe_product_image_missing_count();

	echo '<select name="' . esc_attr( HWE_PRODUCT_IMAGE_FILTER ) . '" id="' . esc_attr( HWE_PRODUCT_IMAGE_FILTER ) . '">';
	echo '<option value="">' . esc_html__( 'Imagen: todas', 'hwe' ) . '</option>';
	echo '<option value="none"' . selected( $current, 'none', false ) . '>';
	/* translators: %d = número de productos sin imagen destacada. */
	echo esc_html( sprintf( __( 'Sin imagen (%d)', 'hwe' ), $missing ) );
	echo '</option>';
	echo '<option value="with"' . selected( $current, 'with', false ) . '>' . esc_html__( 'Con imagen', 'hwe' ) . '</option>';
	echo '</select>';
} );

// ── Columna «imagen» ordenable ─────────────────────────────────────────────
add_filter( 'manage_edit-product_sortable_columns', function ( array $columns ): array {
	// `thumb` es la clave que le da WooCommerce a la columna de la miniatura
	// (WC_Admin_List_Table_Products::define_columns). Si Woo la renombrara,
	// esto deja de hacer efecto sin romper nada.
	$columns['thumb'] = HWE_PRODUCT_IMAGE_ORDERBY;
	return $columns;
} );

// ── Filtrado ───────────────────────────────────────────────────────────────
add_action( 'pre_get_posts', function ( WP_Query $query ): void {
	if ( ! hwe_product_image_is_list_query( $query ) ) {
		return;
	}
	$filter = isset( $_GET[ HWE_PRODUCT_IMAGE_FILTER ] )
		? sanitize_key( wp_unslash( $_GET[ HWE_PRODUCT_IMAGE_FILTER ] ) )
		: '';
	if ( $filter !== 'none' && $filter !== 'with' ) {
		return;
	}

	// Se AÑADE a la `meta_query` que ya haya. Sobrescribirla se llevaría por
	// delante los filtros de stock de WooCommerce, que viven ahí mismo.
	$meta_query = (array) $query->get( 'meta_query' );

	if ( $filter === 'with' ) {
		$meta_query[] = [
			'key'     => '_thumbnail_id',
			'compare' => 'EXISTS',
		];
	} else {
		// Sin imagen = sin el meta, o con él vacío / a cero. Los tres casos
		// existen: un producto que nunca tuvo foto no guarda el meta, y uno al
		// que se la quitaron puede conservarlo a '0'.
		$meta_query[] = [
			'relation' => 'OR',
			[
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_thumbnail_id',
				'value'   => [ '', '0' ],
				'compare' => 'IN',
			],
		];
	}

	$query->set( 'meta_query', $meta_query );
} );

// ── Ordenación ─────────────────────────────────────────────────────────────
//
// No se resuelve con `meta_key` + `orderby => meta_value`: eso genera un INNER
// JOIN y DEJA FUERA justo a los productos sin el meta, que son los que se
// quieren ver primero. Hace falta un LEFT JOIN propio y ordenar por si la
// columna resultó nula.
add_filter( 'posts_clauses', function ( array $clauses, WP_Query $query ): array {
	if ( ! hwe_product_image_is_list_query( $query ) ) {
		return $clauses;
	}
	if ( $query->get( 'orderby' ) !== HWE_PRODUCT_IMAGE_ORDERBY ) {
		return $clauses;
	}
	global $wpdb;

	$order = strtoupper( (string) $query->get( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';

	$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS hwe_img"
		. " ON ( hwe_img.post_id = {$wpdb->posts}.ID AND hwe_img.meta_key = '_thumbnail_id' )";

	// 0 = sin imagen, 1 = con imagen. ASC (el primer clic) deja arriba los que
	// faltan, que es el motivo de existir de esta ordenación. El desempate por
	// fecha mantiene el orden por defecto de la lista dentro de cada grupo.
	$clauses['orderby'] = "( CASE WHEN hwe_img.meta_value IS NULL"
		. " OR hwe_img.meta_value = '' OR hwe_img.meta_value = '0'"
		. " THEN 0 ELSE 1 END ) {$order}, {$wpdb->posts}.post_date DESC";

	return $clauses;
}, 10, 2 );
