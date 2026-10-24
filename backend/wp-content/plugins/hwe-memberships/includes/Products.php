<?php
/**
 * Productos de membresía (spec §5.1).
 *
 * Un producto de membresía es un producto **simple y virtual** de WooCommerce que se liga a un
 * nivel y a una duración en días (`0` = indefinida). Puede haber varios por nivel (mensual,
 * anual…) y el producto no guarda beneficios: esos viven en el nivel. La pestaña «Membresía» de
 * la ficha y la columna del listado son la única UI de esta pieza.
 *
 * Reglas:
 *  · Al guardar se fuerza `virtual` y se exige que el nivel exista. Un nivel publicado pero no
 *    comprable conserva el vínculo (el día que se ponga a la venta el producto ya está listo),
 *    pero el producto queda en borrador para que no se venda mientras el nivel no se pueda
 *    comprar.
 *  · El tipo simple no se puede cambiar en el objeto `WC_Product` (no hay `set_type`): el
 *    data-store vuelve a escribir el término `product_type` a partir del tipo del objeto. Por eso
 *    la conversión se hace **después** de guardar, en `woocommerce_update_product` y
 *    `woocommerce_new_product`.
 *  · `membershipOf()` devuelve el vínculo aunque el nivel ya no exista: así la tienda lo trata
 *    como no comprable (falla cerrada) en vez de convertirlo en un producto normal por sorpresa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Products {

	/** Meta del nivel ligado al producto. */
	public const LEVEL_META = '_hwe_membership_level_id';

	/** Meta de la duración en días (`0` = indefinida). */
	public const DAYS_META = '_hwe_membership_days';

	/** Clave de la pestaña de datos del producto. */
	public const TAB_KEY = 'hwe_membership';

	/** Id del panel de la pestaña. */
	public const PANEL_ID = 'hwe_membership_product_data';

	/** Clave de la columna del listado de productos. */
	public const COLUMN = 'hwe_membership';

	/** Engancha la pestaña, el guardado y la columna. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( self::class, 'addTab' ) );
		add_action( 'woocommerce_product_data_panels', array( self::class, 'renderPanel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'saveProduct' ) );
		add_action( 'woocommerce_update_product', array( self::class, 'enforceSimpleType' ) );
		add_action( 'woocommerce_new_product', array( self::class, 'enforceSimpleType' ) );
		add_filter( 'manage_edit-product_columns', array( self::class, 'addColumn' ) );
		add_action( 'manage_product_posts_custom_column', array( self::class, 'renderColumn' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'gatePurchasable' ), 10, 2 );
	}

	// ------------------------------------------------------------------------
	//  Lectura del vínculo
	// ------------------------------------------------------------------------

	/**
	 * Vínculo del producto con un nivel, o `null` si no es un producto de membresía.
	 *
	 * `days` es `null` cuando la membresía es indefinida (duración guardada `0`).
	 *
	 * @return array{level_id:int,days:?int}|null
	 */
	public static function membershipOf( int $productId ): ?array {
		if ( $productId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return null;
		}

		$levelId = (int) get_post_meta( $productId, self::LEVEL_META, true );
		if ( $levelId <= 0 ) {
			return null;
		}

		$raw  = get_post_meta( $productId, self::DAYS_META, true );
		$days = is_numeric( $raw ) ? max( 0, (int) floor( (float) $raw ) ) : 0;

		return array(
			'level_id' => $levelId,
			'days'     => $days > 0 ? $days : null,
		);
	}

	/** ¿El producto está ligado a un nivel? Lo usa el resto del módulo para no darle beneficios. */
	public static function isMembershipProduct( int $productId ): bool {
		return null !== self::membershipOf( $productId );
	}

	// ------------------------------------------------------------------------
	//  Pestaña de la ficha del producto
	// ------------------------------------------------------------------------

	/**
	 * Añade la pestaña «Membresía». Se muestra también en productos variables para poder
	 * convertirlos: al guardar se quedan como simples.
	 *
	 * @param mixed $tabs
	 * @return mixed
	 */
	public static function addTab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$tabs[ self::TAB_KEY ] = array(
			'label'    => __( 'Membresía', 'hwe' ),
			'target'   => self::PANEL_ID,
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/** Contenido de la pestaña: nivel y duración. */
	public static function renderPanel(): void {
		global $post;

		$productId  = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
		$membership = self::membershipOf( $productId );
		$levelId    = null === $membership ? 0 : (int) $membership['level_id'];
		$days       = null === $membership || null === $membership['days'] ? 0 : (int) $membership['days'];
		$levels     = class_exists( 'HWE_Memberships_Levels' ) ? HWE_Memberships_Levels::all() : array();

		echo '<div id="' . esc_attr( self::PANEL_ID ) . '" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';

		echo '<p class="form-field"><label for="hwe_membership_level">' . esc_html__( 'Nivel', 'hwe' ) . '</label>';
		echo '<select id="hwe_membership_level" name="hwe_membership_level">';
		echo '<option value="">' . esc_html__( '— No es un producto de membresía —', 'hwe' ) . '</option>';
		foreach ( $levels as $level ) {
			$id = (int) ( $level['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $levelId, $id, false ) . '>'
				. esc_html( (string) ( $level['name'] ?? '' ) ) . '</option>';
		}
		echo '</select>';
		if ( array() === $levels ) {
			echo '<span class="description">' . esc_html__( 'Todavía no hay niveles publicados; créalos en Membresías → Niveles.', 'hwe' ) . '</span>';
		}
		echo '</p>';

		echo '<p class="form-field"><label for="hwe_membership_days">' . esc_html__( 'Duración (días)', 'hwe' ) . '</label>';
		echo '<input type="number" min="0" step="1" id="hwe_membership_days" name="hwe_membership_days" value="' . esc_attr( (string) $days ) . '" class="short">';
		echo '<span class="description">' . esc_html__( '0 = indefinida. El producto se guarda siempre como simple y virtual.', 'hwe' ) . '</span></p>';

		echo '</div></div>';
	}

	// ------------------------------------------------------------------------
	//  Guardado
	// ------------------------------------------------------------------------

	/**
	 * Guarda el vínculo. WooCommerce ya ha verificado el nonce y el permiso de edición antes de
	 * este hook; aquí solo se valida el dato y se deja constancia de lo que no se puede vender.
	 *
	 * @param mixed $product
	 */
	public static function saveProduct( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$productId = (int) $product->get_id();
		if ( $productId <= 0 ) {
			return;
		}

		// Edición rápida, importaciones y guardados parciales no tocan el vínculo.
		if ( ! isset( $_POST['hwe_membership_level'] ) ) {
			return;
		}

		$levelId = isset( $_POST['hwe_membership_level'] ) && is_scalar( $_POST['hwe_membership_level'] )
			? (int) sanitize_text_field( wp_unslash( (string) $_POST['hwe_membership_level'] ) )
			: 0;
		if ( $levelId <= 0 ) {
			$product->delete_meta_data( self::LEVEL_META );
			$product->delete_meta_data( self::DAYS_META );
			return;
		}

		$level = class_exists( 'HWE_Memberships_Levels' ) ? HWE_Memberships_Levels::get( $levelId ) : null;
		if ( null === $level ) {
			$product->delete_meta_data( self::LEVEL_META );
			$product->delete_meta_data( self::DAYS_META );
			if ( method_exists( $product, 'set_status' ) ) {
				$product->set_status( 'draft' );
			}
			self::notice( 'El nivel ' . $levelId . ' no existe: el producto no se guardó como membresía y queda en borrador.' );
			return;
		}

		$rawDays = isset( $_POST['hwe_membership_days'] ) && is_scalar( $_POST['hwe_membership_days'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['hwe_membership_days'] ) )
			: '0';
		$days    = is_numeric( $rawDays ) ? max( 0, (int) floor( (float) $rawDays ) ) : 0;

		$product->update_meta_data( self::LEVEL_META, $levelId );
		$product->update_meta_data( self::DAYS_META, $days );
		if ( method_exists( $product, 'set_virtual' ) ) {
			$product->set_virtual( true );
		}

		if ( empty( $level['published'] ) || empty( $level['purchasable'] ) ) {
			if ( method_exists( $product, 'set_status' ) ) {
				$product->set_status( 'draft' );
			}
			self::notice( 'El nivel «' . (string) ( $level['name'] ?? '' ) . '» no está a la venta: el producto queda en borrador hasta que se pueda comprar.' );
		}
	}

	/**
	 * Deja el producto como simple después de guardarlo. El data-store de WooCommerce escribe el
	 * término `product_type` al final de su actualización, así que este es el único punto en el
	 * que la conversión no se sobrescribe.
	 */
	public static function enforceSimpleType( int $productId ): void {
		if ( $productId <= 0 || ! self::isMembershipProduct( $productId ) ) {
			return;
		}
		if ( function_exists( 'wp_set_object_terms' ) ) {
			wp_set_object_terms( $productId, 'simple', 'product_type' );
		}
	}

	// ------------------------------------------------------------------------
	//  Listado de productos
	// ------------------------------------------------------------------------

	/**
	 * Añade la columna «Membresía» junto al nombre.
	 *
	 * @param mixed $columns
	 * @return mixed
	 */
	public static function addColumn( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out[ self::COLUMN ] = __( 'Membresía', 'hwe' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Membresía', 'hwe' );
		}

		return $out;
	}

	/** Pinta la celda: nivel y duración, o un guion si el producto no es de membresía. */
	public static function renderColumn( string $column, int $postId ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$membership = self::membershipOf( $postId );
		if ( null === $membership ) {
			echo '—';
			return;
		}

		$level = class_exists( 'HWE_Memberships_Levels' ) ? HWE_Memberships_Levels::get( $membership['level_id'] ) : null;
		$name  = null !== $level && '' !== (string) ( $level['name'] ?? '' )
			? (string) $level['name']
			: '#' . (int) $membership['level_id'];

		$duration = null === $membership['days']
			? __( 'Indefinida', 'hwe' )
			: sprintf( __( '%d días', 'hwe' ), (int) $membership['days'] );

		echo esc_html( $name ) . ' · ' . esc_html( $duration );
	}

	// ------------------------------------------------------------------------
	//  Compra
	// ------------------------------------------------------------------------

	/**
	 * Un producto de membresía solo es comprable si su nivel lo es. El resto de reglas de compra
	 * (sesión, una línea, antigüedad, cuenta pendiente, nivel ya indefinido) las aplica
	 * `HWE_Memberships_Purchase`.
	 *
	 * @param mixed $purchasable
	 * @param mixed $product
	 * @return mixed
	 */
	public static function gatePurchasable( $purchasable, $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $purchasable;
		}
		$membership = self::membershipOf( (int) $product->get_id() );
		if ( null === $membership ) {
			return $purchasable;
		}

		return (bool) $purchasable
			&& class_exists( 'HWE_Memberships_Levels' )
			&& HWE_Memberships_Levels::isPurchasable( $membership['level_id'] );
	}

	/** Aviso en el panel; sin él, al log. No hay fallo silencioso. */
	private static function notice( string $message ): void {
		if ( class_exists( 'WC_Admin_Meta_Boxes' ) && method_exists( 'WC_Admin_Meta_Boxes', 'add_error' ) ) {
			WC_Admin_Meta_Boxes::add_error( $message );
			return;
		}
		error_log( '[hwe] memberships: ' . $message );
	}
}
