<?php
/**
 * Niveles de membresía (`HWE_Memberships_Levels`).
 *
 * Un nivel es un CPT **no público con UI** (`hwe_membership_level`): así hereda la medioteca y
 * la caja «Traducciones» de `hwe-content-i18n`, que guarda el nombre, la descripción y el slug
 * de cada idioma en sus metas `_hwe_<campo>_<sufijo>`. No hay niveles predefinidos: los crea el
 * editor y decide su rango, su icono, su color, si se puede comprar y la antigüedad mínima de la
 * cuenta.
 *
 * Reglas de lectura y guardado:
 *  · El rango es un entero ≥ 1 y ordena los niveles (mayor rango = más acceso). Es único entre
 *    los niveles publicados; un borrador puede repetirlo sin bloquear a nadie. Guardar un rango
 *    inválido o repetido no lo escribe: se conserva el anterior (o se asigna el siguiente libre
 *    si el nivel es nuevo) y se deja un aviso en el panel.
 *  · Un nivel con socios (concesiones activas o programadas) no se puede borrar ni despublicar:
 *    primero hay que quitarles el nivel o moverlos. La comprobación es de solo lectura contra
 *    `grants`; el historial no se toca.
 *  · `all(false)` solo devuelve publicados y omite los que no tengan un rango válido; `get()`
 *    encuentra también los borradores para poder editarlos.
 *
 * Campos del array que devuelve la lectura (contrato para el resto del módulo):
 *  `id` (int), `name` (string), `description` (string, HTML ya guardado), `slug` (string),
 *  `rank` (int ≥ 1), `icon` (string: URL o id de la medioteca; '' = sin icono), `color` (string
 *  `#rgb`/`#rrggbb`; '' = sin color), `purchasable` (bool), `min_account_age_days` (int ≥ 0),
 *  `published` (bool), `benefits` (array por clave de tipo, sin sanear), `translations`
 *  (idioma => {name?, description?, slug?} solo con lo traducido) y `slugs`
 *  (idioma => slug resuelto, con el nativo como respaldo).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Levels {

	/** Tipo de contenido de los niveles (no público, solo UI de wp-admin). */
	public const POST_TYPE = 'hwe_membership_level';

	/** Meta del rango: entero ≥ 1 que ordena los niveles. */
	public const RANK_META = '_hwe_membership_rank';

	/** Meta del icono: URL o id de la medioteca. */
	public const ICON_META = '_hwe_membership_icon';

	/** Meta del color de marca (hexadecimal opcional). */
	public const COLOR_META = '_hwe_membership_color';

	/** Meta de «se puede comprar» (`1`/`0`). */
	public const PURCHASABLE_META = '_hwe_membership_purchasable';

	/** Meta de la antigüedad mínima de la cuenta en días (0 = sin requisito). */
	public const MIN_AGE_META = '_hwe_membership_min_account_age_days';

	/** Meta de la configuración de beneficios por tipo (la sanea la Tarea 8 al guardar). */
	public const BENEFITS_META = '_hwe_membership_benefits';

	/** Campo y acción del nonce de la caja del nivel. */
	public const NONCE_FIELD  = 'hwe_membership_level_nonce';
	public const NONCE_ACTION = 'hwe_membership_save_level';

	/** Prefijo del transient con el aviso pendiente para el usuario actual. */
	public const NOTICE_TRANSIENT = 'hwe_memberships_level_notice_';

	/** Capacidad de lectura del módulo. */
	public const CAP_VIEW = 'hwe_memberships_view';

	/** Capacidad de gestión del módulo (niveles, beneficios y acciones). */
	public const CAP_MANAGE = 'hwe_memberships_manage';

	/** Engancha el CPT, sus campos traducibles, la caja y las protecciones. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'registerPostType' ), 5 );
		add_filter( 'hwe_translatable_fields', array( self::class, 'translatableFields' ) );
		add_action( 'add_meta_boxes', array( self::class, 'addMetaBox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'renderNotices' ) );
		add_filter( 'pre_trash_post', array( self::class, 'preventDelete' ), 10, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'preventDelete' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'guardUnpublish' ), 10, 2 );
	}

	// ------------------------------------------------------------------------
	//  Registro del CPT y de sus campos traducibles
	// ------------------------------------------------------------------------

	/** Registra el CPT no público que solo se gestiona desde wp-admin. */
	public static function registerPostType(): void {
		$labels = array(
			'name'               => __( 'Niveles', 'hwe' ),
			'singular_name'      => __( 'Nivel', 'hwe' ),
			'menu_name'          => __( 'Niveles', 'hwe' ),
			'add_new'            => __( 'Añadir nuevo', 'hwe' ),
			'add_new_item'       => __( 'Añadir nuevo nivel', 'hwe' ),
			'new_item'           => __( 'Nuevo nivel', 'hwe' ),
			'edit_item'          => __( 'Editar nivel', 'hwe' ),
			'view_item'          => __( 'Ver nivel', 'hwe' ),
			'all_items'          => __( 'Todos los niveles', 'hwe' ),
			'search_items'       => __( 'Buscar niveles', 'hwe' ),
			'not_found'          => __( 'No se encontraron niveles', 'hwe' ),
			'not_found_in_trash' => __( 'No hay niveles en la papelera', 'hwe' ),
		);

		$capabilities = array(
			'read'                   => self::CAP_VIEW,
			'edit_posts'             => self::CAP_MANAGE,
			'edit_others_posts'      => self::CAP_MANAGE,
			'edit_published_posts'   => self::CAP_MANAGE,
			'edit_private_posts'     => self::CAP_MANAGE,
			'publish_posts'          => self::CAP_MANAGE,
			'read_private_posts'     => self::CAP_VIEW,
			'delete_posts'           => self::CAP_MANAGE,
			'delete_others_posts'    => self::CAP_MANAGE,
			'delete_published_posts' => self::CAP_MANAGE,
			'delete_private_posts'   => self::CAP_MANAGE,
			'create_posts'           => self::CAP_MANAGE,
			'edit_post'              => self::CAP_MANAGE,
			'read_post'              => self::CAP_VIEW,
			'delete_post'            => self::CAP_MANAGE,
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'description'         => __( 'Niveles de membresía: rango, icono, color, compra y antigüedad mínima.', 'hwe' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'capabilities'        => $capabilities,
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-awards',
				'supports'            => array( 'title', 'editor' ),
				'delete_with_user'    => false,
			)
		);
	}

	/**
	 * Declara nombre, descripción y slug traducibles en `hwe-content-i18n`. El slug por idioma
	 * es lo que permite que cada idioma tenga su propia URL de nivel.
	 *
	 * @param mixed $registry
	 * @return mixed
	 */
	public static function translatableFields( $registry ) {
		if ( ! is_array( $registry ) ) {
			return $registry;
		}

		$registry[ self::POST_TYPE ] = array(
			'kind'       => 'post',
			'revalidate' => 'memberships',
			'fields'     => array(
				'title'   => array(
					'label'  => __( 'Nombre', 'hwe' ),
					'input'  => 'text',
					'search' => true,
				),
				'content' => array(
					'label'  => __( 'Descripción', 'hwe' ),
					'input'  => 'html',
					'search' => true,
				),
				'slug'    => array(
					'label' => __( 'Slug de la URL', 'hwe' ),
					'input' => 'slug',
				),
			),
		);

		return $registry;
	}

	// ------------------------------------------------------------------------
	//  Lectura
	// ------------------------------------------------------------------------

	/**
	 * Niveles ordenados por rango. Sin `$includeUnpublished` solo devuelve los publicados; en
	 * ambos casos omite los que no tienen un rango válido (≥ 1).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function all( bool $includeUnpublished = false ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$statuses = $includeUnpublished
			? array( 'publish', 'draft', 'pending', 'private', 'future' )
			: array( 'publish' );

		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => $statuses,
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$levels = array();
		foreach ( (array) $posts as $post ) {
			$level = self::toLevel( $post );
			if ( null === $level ) {
				continue;
			}
			if ( $level['rank'] < 1 ) {
				self::log(
					'level-rank:' . $level['id'],
					'el nivel ' . $level['id'] . ' no tiene un rango válido (entero ≥ 1); no se ofrece.'
				);
				continue;
			}
			$levels[] = $level;
		}

		usort(
			$levels,
			static function ( array $a, array $b ): int {
				return $a['rank'] <=> $b['rank'] ?: $a['id'] <=> $b['id'];
			}
		);

		return $levels;
	}

	/**
	 * Un nivel por id, publicado o no (para poder editarlo). `null` si no existe o no es un
	 * nivel de este módulo.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( int $levelId ): ?array {
		if ( $levelId < 1 || ! function_exists( 'get_post' ) ) {
			return null;
		}
		$post = get_post( $levelId );
		if ( ! is_object( $post ) ) {
			return null;
		}
		return self::toLevel( $post );
	}

	/**
	 * Nivel publicado con ese rango, o `null`. Los rangos solo son únicos entre publicados.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function byRank( int $rank ): ?array {
		if ( $rank < 1 ) {
			return null;
		}
		foreach ( self::all() as $level ) {
			if ( $level['rank'] === $rank ) {
				return $level;
			}
		}
		return null;
	}

	/** Rango del nivel; 0 si no existe (o si no tiene un rango válido). */
	public static function rankOf( int $levelId ): int {
		$level = self::get( $levelId );
		return null === $level ? 0 : (int) $level['rank'];
	}

	/** Un nivel sin publicar no se puede vender aunque tenga la marca. */
	public static function isPurchasable( int $levelId ): bool {
		$level = self::get( $levelId );
		return null !== $level && $level['published'] && $level['purchasable'];
	}

	/** Antigüedad mínima de la cuenta en días; 0 = sin requisito. */
	public static function minAccountAgeDays( int $levelId ): int {
		$level = self::get( $levelId );
		return null === $level ? 0 : max( 0, (int) $level['min_account_age_days'] );
	}

	/**
	 * Configuración de beneficios por clave de tipo, tal como está guardada. Aquí no se sanea:
	 * la Tarea 8 valida al guardar y las tareas de beneficios leen cada clave.
	 *
	 * @return array<string,mixed>
	 */
	public static function benefitsConfig( int $levelId ): array {
		$level = self::get( $levelId );
		return null === $level || ! is_array( $level['benefits'] ) ? array() : $level['benefits'];
	}

	/** ¿Hay socios con una concesión activa o programada de este nivel? */
	public static function hasActiveMembers( int $levelId ): bool {
		if ( $levelId < 1 || ! isset( $GLOBALS['wpdb'] ) ) {
			return false;
		}

		global $wpdb;
		$table = class_exists( 'HWE_Memberships_Schema' )
			? HWE_Memberships_Schema::grantsTable()
			: $wpdb->prefix . 'hwe_memberships_grants';

		$sql = $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE level_id = %d AND status IN ('active','scheduled') LIMIT 1",
			$levelId
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			return false;
		}

		$found = $wpdb->get_var( $sql );
		if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
			self::log( 'members-check:' . $levelId, 'no se pudo comprobar si el nivel tiene socios; se trata como ocupado.' );
			return true;
		}
		return null !== $found && false !== $found;
	}

	/** Se puede quitar el nivel si no queda ningún socio con él activo o programado. */
	public static function canRemove( int $levelId ): bool {
		return ! self::hasActiveMembers( $levelId );
	}

	/** ¿El rango ya lo usa otro nivel publicado? (El propio nivel se excluye.) */
	public static function isRankTaken( int $rank, int $excludeLevelId = 0 ): bool {
		if ( $rank < 1 || ! function_exists( 'get_posts' ) ) {
			return false;
		}

		$args = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => array( 'publish' ),
			'meta_key'    => self::RANK_META,
			'meta_value'  => $rank,
			'numberposts' => 1,
			'fields'      => 'ids',
		);
		if ( $excludeLevelId > 0 ) {
			$args['exclude'] = array( $excludeLevelId );
		}

		return array() !== (array) get_posts( $args );
	}

	/** Siguiente rango libre por encima del mayor publicado. */
	public static function nextRank(): int {
		$max = 0;
		foreach ( self::all() as $level ) {
			$max = max( $max, (int) $level['rank'] );
		}
		return $max + 1;
	}

	// ------------------------------------------------------------------------
	//  Guardado en wp-admin
	// ------------------------------------------------------------------------

	/** Caja de datos del nivel. */
	public static function addMetaBox(): void {
		add_meta_box(
			'hwe_membership_level',
			__( 'Datos del nivel', 'hwe' ),
			array( self::class, 'renderMetaBox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/** HTML de la caja: rango, icono, color, compra y antigüedad mínima. */
	public static function renderMetaBox( $post ): void {
		$postId = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$rank   = self::rankOf( $postId );
		if ( $rank < 1 ) {
			$rank = self::nextRank();
		}
		$icon        = (string) get_post_meta( $postId, self::ICON_META, true );
		$color       = (string) get_post_meta( $postId, self::COLOR_META, true );
		$purchasable = self::toBool( get_post_meta( $postId, self::PURCHASABLE_META, true ) );
		$minAge      = max( 0, (int) get_post_meta( $postId, self::MIN_AGE_META, true ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p><label for="hwe_membership_rank"><strong>' . esc_html__( 'Rango', 'hwe' ) . '</strong></label><br>';
		echo '<input type="number" min="1" step="1" id="hwe_membership_rank" name="hwe_membership_rank" value="' . esc_attr( (string) $rank ) . '" required>';
		echo '<span class="description"> ' . esc_html__( 'Entero ≥ 1; mayor rango = más acceso. Único entre los niveles publicados.', 'hwe' ) . '</span></p>';

		echo '<p><label for="hwe_membership_icon"><strong>' . esc_html__( 'Icono', 'hwe' ) . '</strong></label><br>';
		echo '<input type="text" id="hwe_membership_icon" name="hwe_membership_icon" style="width:100%" value="' . esc_attr( $icon ) . '" placeholder="https://…">';
		echo '<span class="description"> ' . esc_html__( 'URL de la imagen o id de la medioteca. Vacío = sin icono.', 'hwe' ) . '</span></p>';

		echo '<p><label for="hwe_membership_color"><strong>' . esc_html__( 'Color', 'hwe' ) . '</strong></label><br>';
		echo '<input type="text" id="hwe_membership_color" name="hwe_membership_color" value="' . esc_attr( $color ) . '" placeholder="#112233">';
		echo '<span class="description"> ' . esc_html__( 'Color de marca en hexadecimal. Vacío = sin color.', 'hwe' ) . '</span></p>';

		echo '<p><label><input type="checkbox" name="hwe_membership_purchasable" value="1"' . checked( $purchasable, true, false ) . '> ';
		echo esc_html__( 'Se puede comprar', 'hwe' ) . '</label></p>';

		echo '<p><label for="hwe_membership_min_age_days"><strong>' . esc_html__( 'Antigüedad mínima de la cuenta (días)', 'hwe' ) . '</strong></label><br>';
		echo '<input type="number" min="0" step="1" id="hwe_membership_min_age_days" name="hwe_membership_min_age_days" value="' . esc_attr( (string) $minAge ) . '">';
		echo '<span class="description"> ' . esc_html__( '0 = sin requisito.', 'hwe' ) . '</span></p>';
	}

	/**
	 * Guarda los campos del nivel. Un rango inválido o repetido no se escribe: se conserva el
	 * anterior (o se asigna el siguiente libre si el nivel es nuevo) y se deja un aviso.
	 *
	 * @param int   $postId
	 * @param mixed $post
	 */
	public static function save( int $postId, $post ): void {
		if ( ! is_object( $post ) || ( $post->post_type ?? '' ) !== self::POST_TYPE ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $postId ) ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( self::CAP_MANAGE ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( array_key_exists( 'hwe_membership_rank', $_POST ) ) {
			self::saveRank( $postId, wp_unslash( (string) $_POST['hwe_membership_rank'] ) );
		}
		if ( array_key_exists( 'hwe_membership_icon', $_POST ) ) {
			update_post_meta( $postId, self::ICON_META, sanitize_text_field( wp_unslash( (string) $_POST['hwe_membership_icon'] ) ) );
		}
		if ( array_key_exists( 'hwe_membership_color', $_POST ) ) {
			$color = sanitize_text_field( wp_unslash( (string) $_POST['hwe_membership_color'] ) );
			$color = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $color ) : self::normalizeColor( $color );
			update_post_meta( $postId, self::COLOR_META, (string) $color );
		}
		if ( array_key_exists( 'hwe_membership_purchasable', $_POST ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_POST['hwe_membership_purchasable'] ) );
			update_post_meta( $postId, self::PURCHASABLE_META, self::toBool( $raw ) ? '1' : '0' );
		}
		if ( array_key_exists( 'hwe_membership_min_age_days', $_POST ) ) {
			$raw = wp_unslash( (string) $_POST['hwe_membership_min_age_days'] );
			update_post_meta( $postId, self::MIN_AGE_META, max( 0, is_numeric( $raw ) ? (int) floor( (float) $raw ) : 0 ) );
		}
	}

	/** Aplica el rango con las reglas de unicidad y deja el aviso si no se pudo guardar. */
	private static function saveRank( int $postId, string $raw ): void {
		$previous = (int) get_post_meta( $postId, self::RANK_META, true );
		$rank     = is_numeric( $raw ) ? (int) floor( (float) $raw ) : 0;

		if ( $rank < 1 ) {
			if ( $previous >= 1 && ! self::isRankTaken( $previous, $postId ) ) {
				self::notice( 'El rango debe ser un entero mayor o igual que 1: se conserva el rango anterior.' );
				return;
			}
			$fallback = self::nextRank();
			update_post_meta( $postId, self::RANK_META, $fallback );
			self::notice( 'El rango debe ser un entero mayor o igual que 1: se asignó el ' . $fallback . '.' );
			return;
		}

		if ( self::isRankTaken( $rank, $postId ) ) {
			if ( $previous >= 1 && $previous !== $rank && ! self::isRankTaken( $previous, $postId ) ) {
				self::notice( 'El rango ' . $rank . ' ya lo usa otro nivel publicado: se conserva el rango anterior.' );
				return;
			}
			$fallback = self::nextRank();
			update_post_meta( $postId, self::RANK_META, $fallback );
			self::notice( 'El rango ' . $rank . ' ya lo usa otro nivel publicado: se guardó el ' . $fallback . '.' );
			return;
		}

		update_post_meta( $postId, self::RANK_META, $rank );
	}

	// ------------------------------------------------------------------------
	//  Protección de niveles con socios
	// ------------------------------------------------------------------------

	/**
	 * Corta el borrado (papelera o definitivo) de un nivel con socios. Devuelve `false` para que
	 * WordPress no lo borre y deja el aviso; cualquier otro valor sigue el flujo normal.
	 *
	 * @param mixed $check
	 * @param mixed $post
	 * @return mixed
	 */
	public static function preventDelete( $check, $post ) {
		if ( ! is_object( $post ) || ( $post->post_type ?? '' ) !== self::POST_TYPE ) {
			return $check;
		}
		$postId = (int) ( $post->ID ?? 0 );
		if ( ! self::hasActiveMembers( $postId ) ) {
			return $check;
		}

		self::notice( 'No se puede borrar «' . (string) ( $post->post_title ?? '' ) . '»: tiene socios con membresía activa o programada. Quita la membresía a esos socios antes de borrarlo.' );
		return false;
	}

	/**
	 * Impide despublicar un nivel con socios: lo deja publicado y avisa. El borrado va por
	 * `preventDelete()`; este filtro solo actúa sobre estados que ocultarían el nivel.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function guardUnpublish( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== self::POST_TYPE ) {
			return $data;
		}

		$postId = (int) ( $postarr['ID'] ?? 0 );
		$status = (string) ( $data['post_status'] ?? '' );
		if ( $postId < 1 || in_array( $status, array( 'publish', 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return $data;
		}

		$current = function_exists( 'get_post' ) ? get_post( $postId ) : null;
		if ( ! is_object( $current ) || 'publish' !== ( $current->post_status ?? '' ) ) {
			return $data;
		}
		if ( ! self::hasActiveMembers( $postId ) ) {
			return $data;
		}

		self::notice( 'No se puede despublicar «' . (string) ( $current->post_title ?? '' ) . '»: tiene socios con membresía activa o programada.' );
		$data['post_status'] = 'publish';
		return $data;
	}

	// ------------------------------------------------------------------------
	//  Avisos del panel
	// ------------------------------------------------------------------------

	/** Guarda un aviso para la siguiente carga del panel del usuario actual. */
	private static function notice( string $message ): void {
		if ( ! function_exists( 'set_transient' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $message, 120 );
	}

	/** Pinta y consume el aviso pendiente. */
	public static function renderNotices(): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_transient' ) ) {
			return;
		}
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	// ------------------------------------------------------------------------
	//  Construcción y utilidades
	// ------------------------------------------------------------------------

	/**
	 * Convierte un post en el array del contrato. `null` si no es un nivel.
	 *
	 * @param mixed $post
	 * @return array<string,mixed>|null
	 */
	private static function toLevel( $post ): ?array {
		if ( ! is_object( $post ) || ( $post->post_type ?? '' ) !== self::POST_TYPE ) {
			return null;
		}

		$id = (int) ( $post->ID ?? 0 );
		if ( $id < 1 ) {
			return null;
		}

		$meta = static function ( string $key ) use ( $id ) {
			return function_exists( 'get_post_meta' ) ? get_post_meta( $id, $key, true ) : '';
		};

		$benefits = $meta( self::BENEFITS_META );
		$slug     = (string) ( $post->post_name ?? '' );
		$locales  = self::locales();
		$level    = array(
			'id'                   => $id,
			'name'                 => (string) ( $post->post_title ?? '' ),
			'description'          => (string) ( $post->post_content ?? '' ),
			'slug'                 => $slug,
			'rank'                 => (int) $meta( self::RANK_META ),
			'icon'                 => trim( (string) $meta( self::ICON_META ) ),
			'color'                => self::normalizeColor( $meta( self::COLOR_META ) ),
			'purchasable'          => self::toBool( $meta( self::PURCHASABLE_META ) ),
			'min_account_age_days' => max( 0, (int) $meta( self::MIN_AGE_META ) ),
			'published'            => 'publish' === ( $post->post_status ?? '' ),
			'benefits'             => is_array( $benefits ) ? $benefits : array(),
			'translations'         => array(),
			'slugs'                => array(),
		);

		$level['translations'] = self::translationsOf( $id, $locales );
		foreach ( $locales as $locale ) {
			$translated                = $level['translations'][ $locale ]['slug'] ?? '';
			$level['slugs'][ $locale ] = '' !== $translated ? $translated : $slug;
		}

		return $level;
	}

	/**
	 * Traducciones presentes del nivel, por idioma. Solo incluye los campos con valor: vacío usa
	 * el contenido nativo.
	 *
	 * @param list<string> $locales
	 * @return array<string,array<string,string>>
	 */
	private static function translationsOf( int $levelId, array $locales ): array {
		$translations = array();
		foreach ( $locales as $locale ) {
			$entry = array();
			$name  = self::translated( $levelId, 'title', $locale );
			$desc  = self::translated( $levelId, 'content', $locale );
			$slug  = self::translated( $levelId, 'slug', $locale );

			if ( '' !== $name ) {
				$entry['name'] = $name;
			}
			if ( '' !== $desc ) {
				$entry['description'] = $desc;
			}
			if ( '' !== $slug ) {
				$entry['slug'] = $slug;
			}
			if ( array() !== $entry ) {
				$translations[ $locale ] = $entry;
			}
		}
		return $translations;
	}

	/** Idiomas secundarios configurados; sin `hwe-content-i18n` no hay traducciones. */
	private static function locales(): array {
		if ( ! function_exists( 'hwe_i18n_locales' ) ) {
			return array();
		}
		$locales = hwe_i18n_locales();
		if ( ! is_array( $locales ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$locales,
				static function ( $locale ): bool {
					return is_string( $locale ) && '' !== $locale;
				}
			)
		);
	}

	/** Valor traducido de un campo, con la API de `hwe-content-i18n` o su meta directa. */
	private static function translated( int $levelId, string $field, string $locale ): string {
		if ( function_exists( 'hwe_i18n_get' ) ) {
			$value = hwe_i18n_get( 'post', $levelId, $field, $locale );
			return is_string( $value ) ? $value : '';
		}
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$value = get_post_meta( $levelId, '_hwe_' . $field . '_' . $locale, true );
		return is_string( $value ) ? $value : '';
	}

	/** `#abc`/`#aabbcc` en minúsculas; cualquier otra cosa se descarta. */
	private static function normalizeColor( mixed $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$value = strtolower( trim( $raw ) );
		return 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value ) ? $value : '';
	}

	/** Boleano tolerante con lo que guarda un checkbox o un meta antiguo. */
	private static function toBool( mixed $raw ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return 0 !== (int) $raw;
		}
		$value = strtolower( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
		return in_array( $value, array( '1', 'true', 'on', 'yes', 'sí', 'si' ), true );
	}

	/** Registra un aviso una sola vez por petición. */
	private static function log( string $key, string $message ): void {
		static $seen = array();
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		error_log( '[hwe] memberships: ' . $message );
	}
}
