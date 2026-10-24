<?php
/**
 * Arranque de la administración del módulo y avisos de una sola vez.
 *
 * `HWE_Memberships::boot()` llama a `register()`, que comprueba el módulo: con el plugin apagado
 * no se registra el menú, ni la autorreparación de capacidades, ni los manejadores de guardado.
 *
 * El patrón de aviso es POST → redirect → GET con un transient de 60 s por usuario: el panel
 * nunca refleja un mensaje construido con texto libre de la petición. El mensaje solo intercala
 * claves de tipo declaradas por el registro de beneficios.
 *
 * Guardar un nivel (por la caja del CPT) o sus beneficios (por la pantalla del módulo) invalida
 * el tag ISR `memberships`, que registra `HWE_Memberships_Access::tags()` en
 * `hwe_revalidation_tags`; aquí no se vuelve a declarar el tag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Capabilities.php';
require_once __DIR__ . '/Menu.php';
require_once __DIR__ . '/LevelsScreen.php';
require_once __DIR__ . '/BenefitsScreen.php';

final class HWE_Memberships_Admin {

	/** Prefijo del transient con el aviso pendiente del usuario actual. */
	private const FLASH_PREFIX = 'hwe_memberships_admin_notice_';

	/**
	 * Registra todas las piezas. La puerta del módulo se comprueba aquí, no en cada hook: un
	 * plugin inactivo no llega a `boot()`, pero incluirlo desde otro sitio tampoco debe pintar
	 * el panel del módulo.
	 */
	public static function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return;
		}

		HWE_Memberships_Admin_Capabilities::register();
		HWE_Memberships_Admin_Menu::register();
		HWE_Memberships_Admin_Benefits_Screen::register();

		// Prioridad 20: detrás de `HWE_Memberships_Levels::save()` (10), que es quien escribe.
		add_action(
			'save_post_' . HWE_Memberships_Levels::POST_TYPE,
			array( self::class, 'onLevelSaved' ),
			20,
			3
		);
	}

	/**
	 * Revalida el tag `memberships` cuando se guarda un nivel desde wp-admin. No es una escritura
	 * (la hace `HWE_Memberships_Levels::save()` con su capacidad y su nonce): aquí solo se evita
	 * revalidar en autoguardados, revisiones y peticiones sin permiso de gestión.
	 *
	 * @param int   $postId
	 * @param mixed $post
	 * @param bool  $update
	 */
	public static function onLevelSaved( int $postId, $post = null, bool $update = false ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if (
			function_exists( 'wp_is_post_revision' )
			&& wp_is_post_revision( $postId )
		) {
			return;
		}
		if (
			! function_exists( 'current_user_can' )
			|| ! current_user_can( HWE_Memberships_Admin_Capabilities::CAP_MANAGE )
		) {
			return;
		}

		self::revalidate();
	}

	/** Invalida el tag ISR del módulo. Un fallo del Control Center se registra, no se traga. */
	public static function revalidate(): void {
		if ( ! class_exists( '\\HWE\\ControlCenter\\Revalidation' ) ) {
			error_log( '[hwe] memberships: no se pudo revalidar «memberships»: falta el Control Center.' );
			return;
		}

		\HWE\ControlCenter\Revalidation::trigger( 'memberships' );
	}

	// ------------------------------------------------------------------------
	//  Avisos del panel
	// ------------------------------------------------------------------------

	/**
	 * Guarda el aviso del próximo render para el usuario actual.
	 *
	 * @param array<string,mixed> $extra Datos acotados que el texto necesita (claves de tipo ya saneadas).
	 */
	public static function flash( string $code, array $extra = array() ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$userId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		set_transient(
			self::FLASH_PREFIX . $userId,
			array_merge( array( 'code' => $code ), $extra ),
			60
		);
	}

	/** @return array<string,mixed>|null Aviso pendiente del usuario actual, ya consumido. */
	public static function pullFlash(): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$userId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$value  = get_transient( self::FLASH_PREFIX . $userId );
		if ( false === $value ) {
			return null;
		}

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::FLASH_PREFIX . $userId );
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Mensaje del aviso. Los textos son fijos y solo intercalan claves de tipo declaradas por el
	 * registro (nunca datos libres de la petición).
	 *
	 * @param array<string,mixed> $flash
	 */
	public static function noticeMessage( array $flash ): string {
		$code = isset( $flash['code'] ) ? (string) $flash['code'] : '';

		return match ( $code ) {
			'saved'          => __( 'Beneficios guardados.', 'hwe' ),
			'saved_adjusted' => sprintf(
				/* translators: %s: claves de tipo de beneficio cuyos valores se ajustaron. */
				__( 'Beneficios guardados. Se ajustaron valores fuera de rango o no válidos en: %s.', 'hwe' ),
				implode( ', ', array_map( 'strval', (array) ( $flash['adjusted'] ?? array() ) ) )
			),
			'forbidden'      => __( 'No tienes permiso para guardar los beneficios de este nivel.', 'hwe' ),
			'invalid'        => __( 'El formulario caducó. Recarga la pantalla e inténtalo de nuevo.', 'hwe' ),
			'missing_level'  => __( 'El nivel ya no existe.', 'hwe' ),
			default          => __( 'Operación completada.', 'hwe' ),
		};
	}

	/** @param array<string,mixed>|null $flash */
	public static function renderNotice( ?array $flash ): void {
		if ( null === $flash ) {
			return;
		}

		$code = (string) ( $flash['code'] ?? '' );
		$class = in_array( $code, array( 'forbidden', 'invalid', 'missing_level' ), true )
			? 'notice notice-error'
			: ( 'saved_adjusted' === $code ? 'notice notice-warning' : 'notice notice-success' );

		echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>'
			. esc_html( self::noticeMessage( $flash ) )
			. '</p></div>';
	}
}
