<?php
/**
 * Arranque de la administración del módulo y avisos de una sola vez.
 *
 * `HWE_Loyalty::boot()` llama a `register()`, que comprueba el módulo: con el plugin apagado no
 * se registra el menú, ni las cajas del pedido, ni las columnas, ni los manejadores de
 * acciones. Si el plugin está activo pero el interruptor «Puntos» está apagado, la
 * administración sigue disponible en modo de auditoría (leer movimientos y la red); las
 * escrituras manuales se permiten igualmente porque son una herramienta del equipo, no del
 * programa automático.
 *
 * El patrón de aviso es POST → redirect → GET con un transient de 60 s por usuario: el panel
 * nunca refleja un mensaje construido con texto de la petición.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Capabilities.php';
require_once __DIR__ . '/Users.php';
require_once __DIR__ . '/Movements.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Adjustments.php';
require_once __DIR__ . '/Codes.php';
require_once __DIR__ . '/Csv.php';
require_once __DIR__ . '/Pages.php';
require_once __DIR__ . '/Menu.php';
require_once __DIR__ . '/UserProfile.php';
require_once __DIR__ . '/UserColumns.php';
require_once __DIR__ . '/OrderBox.php';

final class HWE_Loyalty_Admin {

	private const FLASH_PREFIX = 'hwe_loyalty_admin_notice_';

	/**
	 * Registra todas las piezas. La puerta del módulo se comprueba aquí, no en cada hook: un
	 * plugin inactivo no llega a `boot()`, pero incluirlo desde otro sitio tampoco debe pintar
	 * el panel del módulo.
	 */
	public static function register(): void {
		if ( ! HWE_Loyalty_Settings::moduleActive() ) {
			return;
		}

		HWE_Loyalty_Admin_Capabilities::register();
		HWE_Loyalty_Admin_Menu::register();
		HWE_Loyalty_Admin_Adjustments::register();
		HWE_Loyalty_Admin_UserProfile::register();
		HWE_Loyalty_Admin_UserColumns::register();
		HWE_Loyalty_Admin_OrderBox::register();
		HWE_Loyalty_Admin_Csv::register();
	}

	/**
	 * Guarda el aviso del próximo render para el usuario actual.
	 *
	 * @param array<string,mixed> $extra Datos acotados que el texto necesita (puntos, ids…).
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
	 * Mensaje del aviso. Los textos son fijos y solo intercalan números ya saneados: nunca se
	 * pinta un valor libre de la petición.
	 *
	 * @param array<string,mixed> $flash
	 */
	public static function noticeMessage( array $flash ): string {
		$code   = isset( $flash['code'] ) ? (string) $flash['code'] : '';
		$points = isset( $flash['points'] ) ? (int) $flash['points'] : 0;
		$count  = isset( $flash['count'] ) ? (int) $flash['count'] : 0;
		$unit   = HWE_Loyalty_Settings::get()['currency_name_plural'];

		return match ( $code ) {
			'ok'            => sprintf( 'Ajuste aplicado: %d %s.', $points, $unit ),
			'duplicate'     => sprintf( 'Ese ajuste ya se había aplicado; no se ha movido ningún %s.', $unit ),
			'insufficient'  => 'El cargo supera el saldo disponible: no se ha movido nada.',
			'invalid_user'  => 'No existe ningún usuario con ese id, email o login.',
			'invalid_points' => 'Indica una cantidad entera mayor que cero.',
			'invalid_note'  => 'La nota es obligatoria para un ajuste manual.',
			'invalid_key'   => 'El formulario caducó. Recarga la pantalla e inténtalo de nuevo.',
			'failed'        => 'No se pudo aplicar el ajuste; revisa el registro del servidor.',
			'code_saved'    => 'Código de referido guardado.',
			'code_invalid'  => 'El código no es válido o ya pertenece a otra cuenta.',
			'referrer_saved' => 'Referidor actualizado.',
			'referrer_invalid' => 'No se cambió el referidor: no existe, es la misma cuenta o crearía un ciclo.',
			'batch_done'    => sprintf( 'Lote terminado: %d códigos asignados; quedan %d usuarios sin código.', $count, max( 0, (int) ( $flash['remaining'] ?? 0 ) ) ),
			default         => 'Operación completada.',
		};
	}

	/** @param array<string,mixed>|null $flash */
	public static function renderNotice( ?array $flash ): void {
		if ( null === $flash ) {
			return;
		}

		$class = in_array( (string) ( $flash['code'] ?? '' ), array( 'invalid_user', 'invalid_points', 'invalid_note', 'invalid_key', 'insufficient', 'failed', 'code_invalid', 'referrer_invalid' ), true )
			? 'notice notice-error'
			: 'notice notice-success';

		echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>'
			. esc_html( self::noticeMessage( $flash ) )
			. '</p></div>';
	}
}
