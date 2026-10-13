<?php
/**
 * Datos personales (RGPD): desvincular las filas de una cuenta, exportarlas y suprimirlas desde las herramientas de privacidad de WordPress.
 *
 * Es un trait de `HWE_Consent_Log` (hwe-consent-log.php): el plugin es una sola clase con
 * estado estático y se parte por responsabilidad para que cada archivo se lea de una vez.
 * Los métodos usan `self::` y las constantes de la clase que lo incluye.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait HWE_Consent_Log_Privacy {
	/** Desvincula las filas de la cuenta sin borrarlas: la prueba se conserva. */
	public static function detachUser( int $userId ): int {
		global $wpdb;
		$log = self::logTable();
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$log} SET user_id = NULL WHERE user_id = %d", $userId ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function registerExporter( array $exporters ): array {
		$exporters['hwe-cookie-consent'] = array(
			'exporter_friendly_name' => 'Consentimientos de cookies',
			'callback'               => array( self::class, 'exportPersonalData' ),
		);
		return $exporters;
	}

	public static function registerEraser( array $erasers ): array {
		$erasers['hwe-cookie-consent'] = array(
			'eraser_friendly_name' => 'Consentimientos de cookies',
			'callback'             => array( self::class, 'erasePersonalData' ),
		);
		return $erasers;
	}

	public static function exportPersonalData( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items = array();
		foreach ( self::historyForUser( (int) $user->ID, 500 ) as $row ) {
			$items[] = array(
				'group_id'    => 'hwe-cookie-consent',
				'group_label' => 'Consentimientos de cookies',
				'item_id'     => 'hwe-cookie-consent-' . $row->id,
				'data'        => array(
					array( 'name' => 'Fecha (UTC)', 'value' => $row->created_at ),
					array( 'name' => 'Acción', 'value' => self::ACTION_LABELS[ $row->action ] ?? $row->action ),
					array( 'name' => 'Decisión', 'value' => self::decisionLabel( $row ) ),
					array( 'name' => 'Generación', 'value' => self::generationLabel( $row ) ),
					array( 'name' => 'Comprobante', 'value' => $row->receipt ),
					array( 'name' => 'IP (truncada)', 'value' => (string) $row->ip ),
					array( 'name' => 'Navegador', 'value' => (string) $row->user_agent ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erasePersonalData( string $email, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email );
		$detached = $user ? self::detachUser( (int) $user->ID ) : 0;
		return array(
			'items_removed'  => $detached > 0,
			'items_retained' => $detached > 0,
			'messages'       => $detached > 0
				? array( 'Los consentimientos de cookies se conservan como prueba legal, desvinculados de la cuenta.' )
				: array(),
			'done'           => true,
		);
	}
}
