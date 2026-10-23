<?php
/**
 * Cifras del resumen de administración (spec §12).
 *
 * Periodo por defecto: últimos 30 días. «Emitidos» son los abonos del libro con signo positivo;
 * «canjeados», los cargos de tipo `redeem`; «caducados», los cargos `expire`. Los referidos
 * confirmados y pendientes se leen de su tabla. Todo son agregados: ni emails ni nombres.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Stats {

	public const DEFAULT_DAYS = 30;
	public const TOP_REFERRERS = 5;

	/**
	 * @return array{
	 *   days:int,
	 *   circulation:int,
	 *   issued:int,
	 *   redeemed:int,
	 *   expired:int,
	 *   referrals_confirmed:int,
	 *   referrals_pending:int,
	 *   top_referrers:list<object>
	 * }
	 */
	public static function summary( int $days = self::DEFAULT_DAYS ): array {
		global $wpdb;

		$days = in_array( $days, array( 30, 90, 365 ), true ) ? $days : self::DEFAULT_DAYS;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ledger = HWE_Loyalty_Schema::ledgerTable();
		$referrals = HWE_Loyalty_Schema::referralsTable();

		$circulation = $wpdb->get_var( 'SELECT COALESCE(SUM(balance), 0) FROM ' . HWE_Loyalty_Schema::balancesTable() );
		$issued = $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points), 0) FROM {$ledger} WHERE points > 0 AND created_at >= %s", $since )
		);
		$redeemed = $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(-points), 0) FROM {$ledger} WHERE points < 0 AND type = 'redeem' AND created_at >= %s", $since )
		);
		$expired = $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(-points), 0) FROM {$ledger} WHERE points < 0 AND type = 'expire' AND created_at >= %s", $since )
		);

		$confirmed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$referrals} WHERE status = %s AND referrer_id IS NOT NULL",
				\HWE_Loyalty_Referrals::STATUS_CONFIRMED
			)
		);
		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$referrals} WHERE status = %s AND referrer_id IS NOT NULL",
				\HWE_Loyalty_Referrals::STATUS_PENDING
			)
		);

		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_id, COUNT(*) AS total FROM {$referrals}"
				. ' WHERE referrer_id IS NOT NULL AND status = %s'
				. ' GROUP BY referrer_id ORDER BY total DESC, referrer_id ASC LIMIT %d',
				\HWE_Loyalty_Referrals::STATUS_CONFIRMED,
				self::TOP_REFERRERS
			)
		);

		return array(
			'days'                => $days,
			'circulation'         => max( 0, (int) $circulation ),
			'issued'              => max( 0, (int) $issued ),
			'redeemed'            => max( 0, (int) $redeemed ),
			'expired'             => max( 0, (int) $expired ),
			'referrals_confirmed' => max( 0, (int) $confirmed ),
			'referrals_pending'   => max( 0, (int) $pending ),
			'top_referrers'       => is_array( $top ) ? $top : array(),
		);
	}
}
