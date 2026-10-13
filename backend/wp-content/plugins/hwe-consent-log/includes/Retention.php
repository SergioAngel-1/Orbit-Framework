<?php
/**
 * Retención: el borrado programado de decisiones caducadas según los años del Control Center.
 *
 * Es un trait de `HWE_Consent_Log` (hwe-consent-log.php): el plugin es una sola clase con
 * estado estático y se parte por responsabilidad para que cada archivo se lea de una vez.
 * Los métodos usan `self::` y las constantes de la clase que lo incluye.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait HWE_Consent_Log_Retention {
	public static function scheduleCron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Años de conservación de Control Center; 0 = no borrar nunca. */
	public static function retentionYears(): int {
		$value = null;
		if ( class_exists( '\HWE\ControlCenter\Storage' ) ) {
			$all   = \HWE\ControlCenter\Storage::getAll();
			$value = $all['integrations']['consent_retention_years'] ?? null;
		}
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return self::DEFAULT_YEARS;
		}
		return max( 0, (int) $value );
	}

	public static function purgeExpired(): void {
		$years = self::retentionYears();
		if ( 0 === $years ) {
			return;
		}
		global $wpdb;
		$log    = self::logTable();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$years} years" ) );

		// Por lotes y en dos pasos (leer comprobantes, luego borrar): un DELETE
		// que se lee a sí mismo en una subconsulta no lo admite MySQL/MariaDB.
		//
		// La agrupación la resuelve SOLO el índice `(receipt, created_at)`, sin
		// leer filas. Con el índice de `receipt` a secas (v1) tenía que ir a la
		// fila por cada `created_at`: medido sobre 200.000 filas, ~3 s por lote
		// frente a 2–45 ms. (Partir del rango de `created_at` con un
		// `NOT EXISTS` se probó y es peor: MariaDB materializa la subconsulta.)
		for ( $i = 0; $i < 200; $i++ ) {
			$receipts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT receipt FROM {$log} GROUP BY receipt HAVING MAX(created_at) < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					self::PURGE_BATCH
				)
			);
			if ( empty( $receipts ) ) {
				return;
			}
			$placeholders = implode( ',', array_fill( 0, count( $receipts ), '%s' ) );
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$log} WHERE receipt IN ({$placeholders})", $receipts ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}
	}
}
