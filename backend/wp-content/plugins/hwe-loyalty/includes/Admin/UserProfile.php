<?php
/**
 * Sección de fidelización en el perfil de usuario (spec §12).
 *
 * Muestra saldo, código de referido editable, referidor editable y los últimos movimientos. El
 * saldo es **informativo**: no hay campo para escribir un número nuevo. Un ajuste se expresa
 * como abono o cargo explícito con su nota, y pasa por la misma clase que el ajuste manual
 * (`HWE_Loyalty_Admin_Adjustments`), con capacidad, nonce y clave de idempotencia.
 *
 * Los cambios de código y referidor delegan en `HWE_Loyalty_Referrals`: aquí no se duplican las
 * reglas de formato, unicidad, autorreferido ni ciclos. Un valor rechazado deja un aviso y no
 * modifica nada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_UserProfile {

	public const NONCE = 'hwe_loyalty_profile';
	private const HISTORY = 10;

	/** Engancha el perfil. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
	}

	/** Pinta la sección. Sin capacidad de lectura no se muestra nada. */
	public static function render( $user ): void {
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() || ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}

		$userId  = (int) $user->ID;
		$balance = HWE_Loyalty_Ledger::balance( $userId );
		$settings = HWE_Loyalty_Settings::get();
		$unit     = $settings['currency_name_plural'];
		$canManage = HWE_Loyalty_Admin_Capabilities::canManage();

		echo '<h2>' . esc_html__( 'Fidelización', 'hwe' ) . '</h2>';
		HWE_Loyalty_Admin::renderNotice( HWE_Loyalty_Admin::pullFlash() );
		if ( $canManage ) {
			wp_nonce_field( self::NONCE . '_' . $userId, 'hwe_loyalty_profile_nonce' );
		}
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Saldo', 'hwe' ) . '</th><td><strong>'
			. esc_html( number_format_i18n( (int) $balance['balance'] ) ) . ' ' . esc_html( $unit )
			. '</strong><p class="description">'
			. esc_html__( 'El saldo se cambia con un abono o un cargo explícito, nunca escribiendo un número nuevo.', 'hwe' )
			. '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Total ganado / usado', 'hwe' ) . '</th><td>'
			. esc_html( number_format_i18n( (int) $balance['earned'] ) ) . ' / '
			. esc_html( number_format_i18n( (int) $balance['used'] ) )
			. '</td></tr>';

		if ( HWE_Loyalty_Settings::referralsActive() ) {
			$code = self::codeOf( $userId );
			echo '<tr><th scope="row"><label for="hwe_loyalty_code">' . esc_html__( 'Código de referido', 'hwe' ) . '</label></th><td>';
			printf(
				'<input type="text" class="regular-text" id="hwe_loyalty_code" name="hwe_loyalty_code" value="%s" maxlength="32" autocomplete="off" %s />',
				esc_attr( $code ),
				$canManage ? '' : 'readonly'
			);
			echo '<p class="description">' . esc_html__( 'Vacío = no se cambia. El código debe ser único y no se deriva del login ni del email.', 'hwe' ) . '</p></td></tr>';

			$referrerId = \HWE_Loyalty_Referrals::referrerOf( $userId, false );
			echo '<tr><th scope="row"><label for="hwe_loyalty_referrer">' . esc_html__( 'Referidor (ID de usuario)', 'hwe' ) . '</label></th><td>';
			printf(
				'<input type="number" min="0" step="1" class="small-text" id="hwe_loyalty_referrer" name="hwe_loyalty_referrer" value="%s" %s />',
				esc_attr( null === $referrerId ? '' : (string) $referrerId ),
				$canManage ? '' : 'readonly'
			);
			echo ' <span>';
			echo null === $referrerId
				? esc_html__( 'Sin referidor', 'hwe' )
				: esc_html( sprintf( '%s (#%d)', HWE_Loyalty_Admin_Users::label( $referrerId ), $referrerId ) );
			echo '</span>';
			echo '<p class="description">' . esc_html__( 'Vacío = quitar el referidor. Se rechazan la misma cuenta y los ciclos; el saldo ya abonado no se mueve.', 'hwe' ) . '</p></td></tr>';
		}

		if ( $canManage ) {
			echo '<tr><th scope="row">' . esc_html__( 'Ajuste manual', 'hwe' ) . '</th><td>';
			echo '<select name="hwe_loyalty_direction">'
				. '<option value="credit">' . esc_html__( 'Abonar', 'hwe' ) . '</option>'
				. '<option value="debit">' . esc_html__( 'Cargar', 'hwe' ) . '</option>'
				. '</select> ';
			echo '<input type="number" min="0" step="1" class="small-text" name="hwe_loyalty_points" value="0" /> ';
			printf(
				'<input type="text" class="regular-text" name="hwe_loyalty_note" value="" placeholder="%s" maxlength="500" />',
				esc_attr__( 'Motivo del ajuste (obligatorio)', 'hwe' )
			);
			printf(
				'<input type="hidden" name="hwe_loyalty_key" value="%s" />',
				esc_attr( self::newKey() )
			);
			echo '<p class="description">' . esc_html__( 'Cantidad 0 = no se hace ningún ajuste al guardar el perfil. El cargo nunca deja el saldo en negativo.', 'hwe' ) . '</p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		self::renderHistory( $userId, $unit );
	}

	/**
	 * Guarda los campos del módulo en el perfil. La capacidad y el nonce se comprueban aquí
	 * aunque WordPress ya haya validado su propio formulario: este guardado también puede
	 * llegar desde una petición fabricada.
	 */
	public static function save( int $userId ): void {
		if ( $userId <= 0 || ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_user', $userId ) ) {
			return;
		}

		$nonce = isset( $_POST['hwe_loyalty_profile_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_profile_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE . '_' . $userId ) ) {
			return;
		}

		if ( ! HWE_Loyalty_Settings::moduleActive() ) {
			return;
		}

		if ( HWE_Loyalty_Settings::referralsActive() ) {
			self::saveCode( $userId );
			self::saveReferrer( $userId );
		}

		self::saveAdjustment( $userId );
	}

	private static function saveCode( int $userId ): void {
		if ( ! array_key_exists( 'hwe_loyalty_code', $_POST ) ) {
			return;
		}

		$code = trim( sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_code'] ) ) );
		$code = strtoupper( $code );
		if ( '' === $code || $code === self::codeOf( $userId ) ) {
			return;
		}

		if ( \HWE_Loyalty_Referrals::setCode( $userId, $code ) ) {
			HWE_Loyalty_Admin::flash( 'code_saved' );
		} else {
			HWE_Loyalty_Admin::flash( 'code_invalid' );
		}
	}

	private static function saveReferrer( int $userId ): void {
		if ( ! array_key_exists( 'hwe_loyalty_referrer', $_POST ) ) {
			return;
		}

		$raw = trim( sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_referrer'] ) ) );
		if ( '' !== $raw && ! ctype_digit( $raw ) ) {
			HWE_Loyalty_Admin::flash( 'referrer_invalid' );
			return;
		}

		$new = '' === $raw || '0' === $raw ? null : (int) $raw;
		$row = \HWE_Loyalty_Referrals::attributionOf( $userId );
		$current = null === $row || empty( $row['referrer_id'] ) ? null : (int) $row['referrer_id'];

		if ( $new === $current ) {
			return;
		}

		if ( \HWE_Loyalty_Referrals::setReferrer( $userId, $new ) ) {
			HWE_Loyalty_Admin::flash( 'referrer_saved' );
		} else {
			HWE_Loyalty_Admin::flash( 'referrer_invalid' );
		}
	}

	private static function saveAdjustment( int $userId ): void {
		$points = isset( $_POST['hwe_loyalty_points'] ) ? absint( wp_unslash( $_POST['hwe_loyalty_points'] ) ) : 0;
		if ( $points <= 0 ) {
			return;
		}

		$direction = isset( $_POST['hwe_loyalty_direction'] ) ? sanitize_key( wp_unslash( $_POST['hwe_loyalty_direction'] ) ) : '';
		$note      = isset( $_POST['hwe_loyalty_note'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_note'] ) ) : '';
		$key       = isset( $_POST['hwe_loyalty_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_key'] ) ) : '';

		$result = HWE_Loyalty_Admin_Adjustments::apply( $userId, $direction, $points, $note, $key );
		HWE_Loyalty_Admin::flash( $result['code'], array( 'points' => $points ) );
	}

	/** Código actual sin crearlo: mirar el perfil no debe asignar códigos en masa. */
	private static function codeOf( int $userId ): string {
		global $wpdb;

		$code = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT code FROM ' . HWE_Loyalty_Schema::referralsTable() . ' WHERE user_id = %d',
				$userId
			)
		);

		return is_string( $code ) ? $code : '';
	}

	private static function renderHistory( int $userId, string $unit ): void {
		$history = HWE_Loyalty_Ledger::history( $userId, 1, self::HISTORY );

		echo '<h3>' . esc_html__( 'Últimos movimientos', 'hwe' ) . '</h3>';
		if ( empty( $history['items'] ) ) {
			echo '<p><em>' . esc_html__( 'Sin movimientos.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Fecha', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Tipo', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Cantidad', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Nota', 'hwe' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $history['items'] as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::formatDate( (string) ( $item['created_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( HWE_Loyalty_Admin_Movements::typeLabel( (string) ( $item['type'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $item['points'] ?? 0 ) ) . ' ' . $unit ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['note'] ?? '—' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function formatDate( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : wp_date( 'Y-m-d H:i', $timestamp );
	}

	/** Clave de idempotencia nueva por render del formulario; un doble clic reenvía la misma. */
	private static function newKey(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return uniqid( 'loyalty', true );
		}
	}
}
