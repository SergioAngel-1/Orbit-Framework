<?php
/**
 * Pantalla de beneficios por nivel (spec §11.1 y §7.1) y su vista comparativa.
 *
 * La pantalla se pinta **genérica** a partir del registro de tipos: por cada tipo de
 * `HWE_Memberships_Benefits::all()` (núcleo o de instancia) dibuja su interruptor `enabled` y un
 * campo del tipo que declara `fields()`. Un tipo cuya dependencia está apagada
 * (`available() === false`) se pinta en gris, con el motivo, y **su configuración guardada se
 * conserva**: el guardado parte de lo almacenado y solo superpone los tipos disponibles que se
 * han pintado. Una clave de tipo que este despliegue no conoce también se conserva intacta.
 *
 * Guardar exige `hwe_memberships_manage` y nonce, pasa por
 * `HWE_Memberships_Benefits::sanitizeLevelConfig()` y revalida `memberships` una sola vez. Los
 * valores ajustados (recortados, descartados o normalizados) dejan un aviso en el panel.
 *
 * La comparativa es de solo lectura (`hwe_memberships_view`): una tabla de tipos × niveles con
 * un resumen de los valores configurados, sin frases de cliente (esas se componen en el BFF).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Benefits_Screen {

	/** Acción de `admin-post.php` que guarda los beneficios de un nivel. */
	public const ACTION = 'hwe_memberships_save_benefits';

	/** Campo del nonce. El nonce se ata al id del nivel. */
	public const NONCE_FIELD  = 'hwe_memberships_benefits_nonce';
	public const NONCE_ACTION = 'hwe_memberships_save_benefits';

	/** Lista de tipos pintados como editables (los demás no se tocan al guardar). */
	public const TYPES_FIELD = 'hwe_memberships_benefit_types';

	/** Mapa `tipo → campo → valor` del formulario. */
	public const VALUES_FIELD = 'hwe_memberships_benefits';

	/** Campo con el id del nivel. */
	public const LEVEL_FIELD = 'level_id';

	/** Parámetro GET del nivel elegido en la pantalla. */
	public const LEVEL_QUERY = 'level';

	/** Engancha el POST del formulario. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handleSave' ) );
	}

	// ------------------------------------------------------------------------
	//  Pantalla de edición
	// ------------------------------------------------------------------------

	/** Pinta el selector de nivel y los beneficios del nivel elegido. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver los beneficios de membresía.', 'hwe' ) );
			return;
		}

		$levels  = HWE_Memberships_Levels::all( true );
		$canEdit = HWE_Memberships_Admin_Capabilities::canManage();
		$level   = self::selectedLevel( $levels );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Beneficios de membresía', 'hwe' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		if ( null === $level ) {
			echo '<p><strong>' . esc_html__( 'Todavía no hay niveles.', 'hwe' ) . '</strong> ';
			if ( $canEdit ) {
				echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . HWE_Memberships_Levels::POST_TYPE ) ) . '">'
					. esc_html__( 'Crea uno para configurar sus beneficios', 'hwe' ) . '</a>.';
			}
			echo '</p></div>';
			return;
		}

		$levelId = (int) $level['id'];

		self::renderLevelPicker( $levels, $levelId );

		echo '<p>' . esc_html__( 'Activa un tipo de beneficio y configura sus valores para este nivel. Un tipo cuya dependencia esté apagada se muestra en gris, con el motivo, y conserva su configuración guardada.', 'hwe' ) . '</p>';

		if ( ! $canEdit ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'Solo tienes permiso de lectura: los campos se muestran deshabilitados.', 'hwe' )
				. '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::LEVEL_FIELD ) . '" value="' . esc_attr( (string) $levelId ) . '">';
		wp_nonce_field( self::nonceAction( $levelId ), self::NONCE_FIELD );

		foreach ( HWE_Memberships_Benefits::all() as $key => $benefit ) {
			self::renderBenefit( (string) $key, $benefit, $levelId, $levels, $canEdit );
		}

		if ( $canEdit ) {
			echo '<p class="submit"><button type="submit" class="button button-primary">'
				. esc_html__( 'Guardar beneficios', 'hwe' ) . '</button></p>';
		}

		echo '</form>';
		echo '<p><a href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::COMPARISON ) ) . '">'
			. esc_html__( 'Ver la comparativa de niveles y beneficios', 'hwe' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Un bloque por tipo de beneficio. El interruptor y los campos van en un `fieldset` que se
	 * deshabilita entero cuando el tipo no está disponible o el usuario no puede escribir.
	 *
	 * @param list<array<string,mixed>> $levels
	 */
	private static function renderBenefit(
		string $key,
		HWE_Memberships_Benefit $benefit,
		int $levelId,
		array $levels,
		bool $canEdit
	): void {
		$available = self::isAvailable( $benefit, $key );
		$config    = self::configOf( $benefit, $levelId, $key );
		if ( null === $config ) {
			$config = HWE_Memberships_Benefits::sanitizeConfig( $benefit, array() );
		}

		$disabled = ! $available || ! $canEdit;
		$class    = 'hwe-memberships-benefit' . ( $available ? '' : ' is-unavailable' );
		$style    = $available ? '' : ' style="opacity:0.65"';

		echo '<fieldset class="' . esc_attr( $class ) . '" data-benefit="' . esc_attr( $key ) . '"' . $style
			. ( $disabled ? ' disabled="disabled"' : '' ) . '>';
		echo '<legend><code>' . esc_html( $key ) . '</code></legend>';

		if ( ! $available ) {
			echo '<p class="description">' . esc_html( self::dependencyReason( self::dependencyOf( $benefit, $key ) ) ) . '</p>';
		}

		echo '<p><label><input type="checkbox" name="' . esc_attr( self::fieldName( $key, 'enabled' ) ) . '" value="1"'
			. self::checked( ! empty( $config['enabled'] ) ) . '> '
			. esc_html__( 'Activar este beneficio en el nivel', 'hwe' ) . '</label></p>';

		if ( $canEdit && $available ) {
			echo '<input type="hidden" name="' . esc_attr( self::TYPES_FIELD ) . '[]" value="' . esc_attr( $key ) . '">';
		}

		foreach ( self::fieldsOf( $benefit, $key ) as $name => $field ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $field ) ) {
				continue;
			}
			self::renderField( $key, $name, $field, $config[ $name ] ?? null, $levels, $disabled );
		}

		echo '</fieldset>';
	}

	/**
	 * Pinta un campo según el tipo declarado por el registro (los mismos tipos del Control
	 * Center más los compuestos del módulo). Un tipo desconocido se registra y se omite.
	 *
	 * @param array<string,mixed>       $field
	 * @param list<array<string,mixed>> $levels
	 */
	private static function renderField(
		string $key,
		string $name,
		array $field,
		mixed $value,
		array $levels,
		bool $disabled
	): void {
		$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
		$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : $name;
		$id    = 'hwe_memberships_' . $key . '_' . $name;
		$fieldName = self::fieldName( $key, $name );
		$isText    = HWE_Memberships_Benefit::FIELD_TEXT === $type || HWE_Memberships_Benefit::FIELD_TRANSLATABLE === $type;
		$attributes = self::fieldAttributes( $field, $isText );

		switch ( $type ) {
			case HWE_Memberships_Benefit::FIELD_NUMBER:
			case HWE_Memberships_Benefit::FIELD_PERCENTAGE:
			case HWE_Memberships_Benefit::FIELD_LEVEL:
				$value = is_scalar( $value ) ? (int) $value : 0;

				if ( HWE_Memberships_Benefit::FIELD_LEVEL === $type ) {
					echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $fieldName ) . '"' . self::disabled( $disabled ) . '>';
					echo '<option value="0"' . self::selected( 0 === $value ) . '>' . esc_html__( '— Ninguno —', 'hwe' ) . '</option>';
					foreach ( $levels as $level ) {
						$optionId = (int) $level['id'];
						echo '<option value="' . esc_attr( (string) $optionId ) . '"' . self::selected( $optionId === $value ) . '>'
							. esc_html( (string) $level['name'] ) . '</option>';
					}
					echo '</select></p>';
					return;
				}

				echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
				echo '<input type="number" step="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $fieldName ) . '"'
					. ' value="' . esc_attr( (string) $value ) . '"' . $attributes . self::disabled( $disabled ) . '>';
				if ( HWE_Memberships_Benefit::FIELD_PERCENTAGE === $type ) {
					echo ' <span class="description">%</span>';
				}
				echo '</p>';
				return;

			case HWE_Memberships_Benefit::FIELD_BOOLEAN:
				echo '<p><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="'
					. esc_attr( $fieldName ) . '" value="1"' . self::checked( self::toBool( $value ) ) . self::disabled( $disabled ) . '> '
					. esc_html( $label ) . '</label></p>';
				return;

			case HWE_Memberships_Benefit::FIELD_SELECT:
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$current = is_scalar( $value ) ? (string) $value : '';

				echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $fieldName ) . '"' . self::disabled( $disabled ) . '>';
				foreach ( $options as $optionValue => $optionLabel ) {
					if ( ! is_scalar( $optionValue ) ) {
						continue;
					}
					echo '<option value="' . esc_attr( (string) $optionValue ) . '"'
						. self::selected( (string) $optionValue === $current ) . '>'
						. esc_html( is_scalar( $optionLabel ) ? (string) $optionLabel : (string) $optionValue )
						. '</option>';
				}
				echo '</select></p>';
				return;

			case HWE_Memberships_Benefit::FIELD_CATEGORIES:
				$ids = self::idsOf( $value );

				echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $fieldName ) . '" value="'
					. esc_attr( implode( ', ', $ids ) ) . '"' . self::disabled( $disabled ) . '>';
				echo '<span class="description"> ' . esc_html__( 'Ids de categorías de producto separados por comas.', 'hwe' ) . '</span></p>';
				return;

			case HWE_Memberships_Benefit::FIELD_TEXT:
				echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $fieldName ) . '" value="'
					. esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' . $attributes . self::disabled( $disabled ) . '></p>';
				return;

			case HWE_Memberships_Benefit::FIELD_TRANSLATABLE:
				$texts = is_array( $value ) ? $value : array();
				echo '<div class="hwe-memberships-translatable"><p><strong>' . esc_html( $label ) . '</strong></p>';
				foreach ( HWE_Memberships_Benefits::locales() as $locale ) {
					$localeId = $id . '_' . $locale;

					echo '<p><label for="' . esc_attr( $localeId ) . '"><code>' . esc_html( $locale ) . '</code></label> ';
					echo '<input type="text" id="' . esc_attr( $localeId ) . '" name="'
						. esc_attr( $fieldName . '[' . $locale . ']' ) . '" value="'
						. esc_attr( isset( $texts[ $locale ] ) && is_scalar( $texts[ $locale ] ) ? (string) $texts[ $locale ] : '' )
						. '"' . $attributes . self::disabled( $disabled ) . '></p>';
				}
				echo '</div>';
				return;
		}

		error_log(
			'[hwe] memberships: el campo «' . $name . '» del tipo «' . $key
			. '» declara un tipo desconocido y no se pinta.'
		);
	}

	/** Selector de nivel como enlaces GET (no es una escritura). */
	private static function renderLevelPicker( array $levels, int $selectedId ): void {
		echo '<p class="hwe-memberships-level-picker"><strong>' . esc_html__( 'Nivel', 'hwe' ) . ':</strong> ';

		foreach ( $levels as $level ) {
			$levelId = (int) $level['id'];
			$class   = $levelId === $selectedId ? 'button button-primary' : 'button';

			echo '<a class="' . esc_attr( $class ) . '" href="'
				. esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BENEFITS, array( self::LEVEL_QUERY => $levelId ) ) )
				. '">' . esc_html( (string) $level['name'] ) . '</a> ';
		}

		echo '</p>';
	}

	/**
	 * Nivel elegido por `?level=` o el primero de la lista. `null` si no hay ninguno.
	 *
	 * @param list<array<string,mixed>> $levels
	 * @return array<string,mixed>|null
	 */
	private static function selectedLevel( array $levels ): ?array {
		$requested = isset( $_GET[ self::LEVEL_QUERY ] ) && is_scalar( $_GET[ self::LEVEL_QUERY ] )
			? absint( wp_unslash( (string) $_GET[ self::LEVEL_QUERY ] ) )
			: 0;

		foreach ( $levels as $level ) {
			if ( (int) $level['id'] === $requested ) {
				return $level;
			}
		}

		return $levels[0] ?? null;
	}

	// ------------------------------------------------------------------------
	//  Guardado
	// ------------------------------------------------------------------------

	/** POST de `admin-post.php`: capacidad + nonce, saneado y redirección con aviso. */
	public static function handleSave(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$levelId = isset( $_POST[ self::LEVEL_FIELD ] ) && is_scalar( $_POST[ self::LEVEL_FIELD ] )
			? absint( wp_unslash( (string) $_POST[ self::LEVEL_FIELD ] ) )
			: 0;
		$nonce   = isset( $_POST[ self::NONCE_FIELD ] ) && is_scalar( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::nonceAction( $levelId ) ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}

		$values = isset( $_POST[ self::VALUES_FIELD ] ) && is_array( $_POST[ self::VALUES_FIELD ] )
			? (array) wp_unslash( $_POST[ self::VALUES_FIELD ] )
			: array();
		$types  = isset( $_POST[ self::TYPES_FIELD ] ) && is_array( $_POST[ self::TYPES_FIELD ] )
			? (array) wp_unslash( $_POST[ self::TYPES_FIELD ] )
			: array();

		$result = self::saveForLevel( $levelId, $values, $types );

		if ( ! $result['ok'] ) {
			HWE_Memberships_Admin::flash( $result['code'] );
		} else {
			$code = array() === $result['adjusted'] ? 'saved' : 'saved_adjusted';
			HWE_Memberships_Admin::flash( $code, array( 'adjusted' => $result['adjusted'] ) );
		}

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect(
				'missing_level' === $result['code']
					? HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BENEFITS )
					: HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BENEFITS, array( self::LEVEL_QUERY => $levelId ) )
			);
		}
	}

	/**
	 * Sanea y guarda los beneficios de un nivel.
	 *
	 * Parte de la configuración guardada y solo superpone los tipos **disponibles** que el
	 * formulario declaró como editables: la configuración de un tipo con la dependencia apagada
	 * (que se pinta deshabilitado y no se envía) y la de una clave desconocida se conservan.
	 * Después pasa todo por `sanitizeLevelConfig()` y revalida `memberships` una sola vez.
	 *
	 * @param array<string,mixed> $posted Mapa tipo → campo → valor.
	 * @param list<mixed>         $types  Tipos pintados como editables.
	 * @return array{ok:bool,code:string,adjusted:list<string>}
	 */
	public static function saveForLevel( int $levelId, array $posted, array $types ): array {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			return array( 'ok' => false, 'code' => 'forbidden', 'adjusted' => array() );
		}
		if ( null === HWE_Memberships_Levels::get( $levelId ) ) {
			return array( 'ok' => false, 'code' => 'missing_level', 'adjusted' => array() );
		}

		$present = array();
		foreach ( $types as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$present[ $key ] = true;
			}
		}
		if ( array() === $present ) {
			foreach ( array_keys( $posted ) as $key ) {
				if ( is_string( $key ) ) {
					$present[ $key ] = true;
				}
			}
		}

		$raw      = HWE_Memberships_Levels::benefitsConfig( $levelId );
		$incoming = array();
		$benefits = HWE_Memberships_Benefits::all();

		foreach ( $benefits as $key => $benefit ) {
			if ( ! self::isAvailable( $benefit, (string) $key ) || ! isset( $present[ $key ] ) ) {
				continue;
			}

			$incoming[ $key ] = isset( $posted[ $key ] ) && is_array( $posted[ $key ] )
				? $posted[ $key ]
				: array();
			$raw[ $key ]      = $incoming[ $key ];
		}

		$clean    = HWE_Memberships_Benefits::sanitizeLevelConfig( $raw );
		$adjusted = array();

		foreach ( $benefits as $key => $benefit ) {
			if ( ! isset( $incoming[ $key ] ) ) {
				continue;
			}
			$cleanConfig = $clean[ $key ] ?? null;
			if ( is_array( $cleanConfig ) && self::wasAdjusted( $benefit, $incoming[ $key ], $cleanConfig ) ) {
				$adjusted[] = (string) $key;
			}
		}

		update_post_meta( $levelId, HWE_Memberships_Levels::BENEFITS_META, $clean );

		HWE_Memberships_Admin::revalidate();

		return array(
			'ok'       => true,
			'code'     => 'saved',
			'adjusted' => array_values( array_unique( $adjusted ) ),
		);
	}

	/**
	 * ¿El saneado cambió algo que el editor envió? Solo se comparan los campos declarados y
	 * presentes en el POST: el relleno de defaults no es un ajuste que haya que avisar.
	 *
	 * @param array<string,mixed> $incoming
	 * @param array<string,mixed> $clean
	 */
	private static function wasAdjusted( HWE_Memberships_Benefit $benefit, array $incoming, array $clean ): bool {
		if (
			array_key_exists( 'enabled', $incoming )
			&& self::toBool( $incoming['enabled'] ) !== ! empty( $clean['enabled'] )
		) {
			return true;
		}

		$fields = self::fieldsOf( $benefit, $benefit->key() );
		if ( ! is_array( $fields ) ) {
			return false;
		}

		foreach ( $fields as $name => $field ) {
			if ( ! is_string( $name ) || ! is_array( $field ) || ! array_key_exists( $name, $incoming ) ) {
				continue;
			}
			if ( self::fieldChanged( $field, $incoming[ $name ], $clean[ $name ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/** Compara el valor recibido con el saneado, normalizando según el tipo de campo. */
	private static function fieldChanged( array $field, mixed $raw, mixed $clean ): bool {
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';

		switch ( $type ) {
			case HWE_Memberships_Benefit::FIELD_BOOLEAN:
				return self::toBool( $raw ) !== self::toBool( $clean );

			case HWE_Memberships_Benefit::FIELD_NUMBER:
			case HWE_Memberships_Benefit::FIELD_PERCENTAGE:
			case HWE_Memberships_Benefit::FIELD_LEVEL:
				return self::integerOrNull( $raw ) !== (int) $clean;

			case HWE_Memberships_Benefit::FIELD_CATEGORIES:
				return self::idsOf( $raw ) !== self::idsOf( $clean );

			case HWE_Memberships_Benefit::FIELD_TRANSLATABLE:
				return self::textMap( $raw ) !== self::textMap( $clean );
		}

		return (string) ( is_scalar( $raw ) ? $raw : '' ) !== (string) ( is_scalar( $clean ) ? $clean : '' );
	}

	// ------------------------------------------------------------------------
	//  Comparativa (solo lectura)
	// ------------------------------------------------------------------------

	/** Tabla tipos × niveles con un resumen de los valores configurados. */
	public static function renderComparison(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver las membresías.', 'hwe' ) );
			return;
		}

		$levels   = HWE_Memberships_Levels::all( true );
		$benefits = HWE_Memberships_Benefits::all();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Comparativa de niveles y beneficios', 'hwe' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		if ( array() === $levels || array() === $benefits ) {
			echo '<p>' . esc_html__( 'No hay niveles o tipos de beneficio que comparar todavía.', 'hwe' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Beneficio', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Disponibilidad', 'hwe' ) . '</th>';
		foreach ( $levels as $level ) {
			echo '<th scope="col">' . esc_html( (string) $level['name'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $benefits as $key => $benefit ) {
			$available = self::isAvailable( $benefit, (string) $key );

			echo '<tr>';
			echo '<th scope="row"><code>' . esc_html( (string) $key ) . '</code></th>';
			echo '<td>' . esc_html(
				$available
					? __( 'Disponible', 'hwe' )
					: self::dependencyReason( self::dependencyOf( $benefit, (string) $key ) )
			) . '</td>';

			foreach ( $levels as $level ) {
				$config = self::configOf( $benefit, (int) $level['id'], (string) $key );
				$cell   = '—';

				if ( $available && is_array( $config ) && ! empty( $config['enabled'] ) ) {
					$summary = self::summary( $benefit, $config, (string) $key );
					$cell    = '' === $summary ? __( 'Activado', 'hwe' ) : $summary;
				}

				echo '<td>' . esc_html( $cell ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BENEFITS ) ) . '">'
			. esc_html__( 'Volver a los beneficios', 'hwe' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Resumen de los valores configurados de un tipo para una celda: solo datos, sin frases de
	 * cliente (esas se componen en el BFF).
	 *
	 * @param array<string,mixed> $config
	 */
	private static function summary( HWE_Memberships_Benefit $benefit, array $config, string $key ): string {
		$fields = self::fieldsOf( $benefit, $key );
		if ( array() === $fields ) {
			return '';
		}

		$parts   = array();
		$locales = HWE_Memberships_Benefits::locales();

		foreach ( $fields as $name => $field ) {
			if ( ! is_string( $name ) || ! is_array( $field ) || ! array_key_exists( $name, $config ) ) {
				continue;
			}

			$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
			$value = $config[ $name ];

			switch ( $type ) {
				case HWE_Memberships_Benefit::FIELD_BOOLEAN:
					if ( self::toBool( $value ) ) {
						$parts[] = isset( $field['label'] ) && is_scalar( $field['label'] )
							? (string) $field['label']
							: $name;
					}
					break;

				case HWE_Memberships_Benefit::FIELD_PERCENTAGE:
					$parts[] = (int) $value . ' %';
					break;

				case HWE_Memberships_Benefit::FIELD_NUMBER:
				case HWE_Memberships_Benefit::FIELD_LEVEL:
					if ( 0 !== (int) $value ) {
						$parts[] = (string) (int) $value;
					}
					break;

				case HWE_Memberships_Benefit::FIELD_CATEGORIES:
					$ids = self::idsOf( $value );
					if ( array() !== $ids ) {
						$parts[] = implode( ', ', $ids );
					}
					break;

				case HWE_Memberships_Benefit::FIELD_SELECT:
				case HWE_Memberships_Benefit::FIELD_TEXT:
					if ( is_scalar( $value ) && '' !== (string) $value ) {
						$parts[] = (string) $value;
					}
					break;

				case HWE_Memberships_Benefit::FIELD_TRANSLATABLE:
					foreach ( $locales as $locale ) {
						if ( isset( $value[ $locale ] ) && is_scalar( $value[ $locale ] ) && '' !== (string) $value[ $locale ] ) {
							$parts[] = (string) $value[ $locale ];
							break;
						}
					}
					break;
			}
		}

		return implode( ' · ', $parts );
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	/** Acción del nonce atada al nivel: un nonce de un nivel no vale en otro. */
	private static function nonceAction( int $levelId ): string {
		return self::NONCE_ACTION . ':' . $levelId;
	}

	private static function fieldName( string $key, string $field ): string {
		return self::VALUES_FIELD . '[' . $key . '][' . $field . ']';
	}

	/** ¿Está disponible la dependencia del tipo? Un error suyo se registra y queda no disponible. */
	private static function isAvailable( HWE_Memberships_Benefit $benefit, string $key ): bool {
		try {
			return $benefit->available();
		} catch ( \Throwable $e ) {
			error_log( '[hwe] memberships: el tipo «' . $key . '» falló al comprobar su disponibilidad; se trata como no disponible.' );
			return false;
		}
	}

	/**
	 * Campos declarados por el tipo. Un tipo de instancia roto no tumba el panel.
	 *
	 * @return array<string,mixed>
	 */
	private static function fieldsOf( HWE_Memberships_Benefit $benefit, string $key ): array {
		try {
			$fields = $benefit->fields();
		} catch ( \Throwable $e ) {
			error_log( '[hwe] memberships: el tipo «' . $key . '» falló al declarar sus campos; no se pinta.' );
			return array();
		}

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Configuración saneada del tipo para un nivel; `null` si no la declara o si falla.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function configOf( HWE_Memberships_Benefit $benefit, int $levelId, string $key ): ?array {
		try {
			$config = $benefit->configFor( $levelId );
		} catch ( \Throwable $e ) {
			error_log( '[hwe] memberships: el tipo «' . $key . '» falló al leer su configuración; se usa la vacía.' );
			return null;
		}

		return is_array( $config ) ? $config : null;
	}

	/** Dependencia declarada del tipo; `null` si falla al declararla. */
	private static function dependencyOf( HWE_Memberships_Benefit $benefit, string $key ): ?string {
		try {
			$dependency = $benefit->dependsOn();
		} catch ( \Throwable $e ) {
			error_log( '[hwe] memberships: el tipo «' . $key . '» falló al declarar su dependencia.' );
			return null;
		}

		return is_string( $dependency ) ? $dependency : null;
	}

	/**
	 * `min`/`max` de un campo numérico o `maxlength` de uno de texto, como atributos escapados.
	 *
	 * @param array<string,mixed> $field
	 */
	private static function fieldAttributes( array $field, bool $text = false ): string {
		if ( $text ) {
			if ( isset( $field['max'] ) && is_numeric( $field['max'] ) && (int) $field['max'] > 0 ) {
				return ' maxlength="' . esc_attr( (string) (int) $field['max'] ) . '"';
			}
			return '';
		}

		$attributes = '';

		if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
			$attributes .= ' min="' . esc_attr( (string) (int) $field['min'] ) . '"';
		}
		if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
			$attributes .= ' max="' . esc_attr( (string) (int) $field['max'] ) . '"';
		}

		return $attributes;
	}

	private static function checked( bool $value ): string {
		return $value ? ' checked="checked"' : '';
	}

	private static function selected( bool $value ): string {
		return $value ? ' selected="selected"' : '';
	}

	private static function disabled( bool $value ): string {
		return $value ? ' disabled="disabled"' : '';
	}

	/**
	 * Motivo legible de una dependencia, sin nombres de una instancia concreta: solo el id del
	 * módulo y, si la declara, la función que exige.
	 */
	private static function dependencyReason( ?string $dependency ): string {
		if ( null === $dependency || '' === trim( $dependency ) ) {
			return __( 'Este tipo no está disponible en este despliegue.', 'hwe' );
		}

		$parts   = explode( ':', $dependency, 2 );
		$module  = (string) $parts[0];
		$feature = isset( $parts[1] ) ? (string) $parts[1] : '';

		if ( '' !== $feature ) {
			/* translators: 1: id del módulo, 2: función del módulo. */
			return sprintf( __( 'Requiere el módulo «%1$s» (función «%2$s»).', 'hwe' ), $module, $feature );
		}

		/* translators: %s: id del módulo. */
		return sprintf( __( 'Requiere el módulo «%s».', 'hwe' ), $module );
	}

	/** @return list<int> */
	private static function idsOf( mixed $value ): array {
		if ( is_string( $value ) ) {
			$items = preg_split( '/[\s,|]+/', $value ) ?: array();
		} elseif ( is_array( $value ) ) {
			$items = $value;
		} else {
			$items = array();
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( is_scalar( $item ) && 1 === preg_match( '/^\d+$/', trim( (string) $item ) ) ) {
				$id = (int) $item;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		$ids = array_keys( $ids );
		sort( $ids );

		return $ids;
	}

	/** @return array<string,string> */
	private static function textMap( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $locale => $text ) {
			if ( is_string( $locale ) && is_scalar( $text ) ) {
				$text = trim( (string) $text );
				if ( '' !== $text ) {
					$out[ $locale ] = $text;
				}
			}
		}

		ksort( $out );

		return $out;
	}

	private static function integerOrNull( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^-?\d+$/', trim( (string) $value ) ) ) {
			return null;
		}

		return (int) $value;
	}

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
}
