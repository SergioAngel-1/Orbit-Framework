<?php
/**
 * Resumen de administración (spec §11.1): socios activos por nivel, congelados, caducidades
 * próximas y movimientos del periodo.
 *
 * Todo son agregados en SQL: `COUNT(*)` y `COUNT(DISTINCT user_id)` con los índices existentes
 * (`members.level_id`, `events.type_created`) y `LIMIT` no aplica porque no devuelve filas. La
 * foto de socios activos sale de `members` (la caché que mantiene `HWE_Memberships_Grants` en
 * cada escritura); el resto sale del historial, que es la fuente de lo que ya ocurrió.
 *
 * «Altas» son los eventos `activated` y las concesiones de administración que partían de cero
 * (`level_from = 0`); «renovaciones», los eventos `renewed`. Las ampliaciones manuales quedan en
 * el historial, pero no inflan estos contadores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Stats {

	/** Periodo por defecto del resumen. */
	public const DEFAULT_DAYS = 30;

	/** Periodos admitidos por el selector. */
	public const WINDOWS = array( 30, 90, 365 );

	/** Ventana corta de caducidad. */
	public const EXPIRING_SOON = 7;

	/** Ventana larga de caducidad. */
	public const EXPIRING_LATER = 30;

	/**
	 * Cifras del periodo.
	 *
	 * @return array{
	 *   days:int,
	 *   active:int,
	 *   active_by_level:list<array{level_id:int,total:int}>,
	 *   frozen:int,
	 *   expiring_7:int,
	 *   expiring_30:int,
	 *   expired:int,
	 *   activations:int,
	 *   renewals:int
	 * }
	 */
	public static function summary( int $days = self::DEFAULT_DAYS ): array {
		global $wpdb;

		$days = in_array( $days, self::WINDOWS, true ) ? $days : self::DEFAULT_DAYS;
		$now  = time();
		$nowSql = gmdate( 'Y-m-d H:i:s', $now );
		$since  = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );

		$members = HWE_Memberships_Schema::membersTable();
		$events  = HWE_Memberships_Schema::eventsTable();

		$byLevel = $wpdb->get_results(
			"SELECT level_id, COUNT(*) AS total FROM {$members}"
			. ' WHERE frozen_at IS NULL AND level_id IS NOT NULL AND level_id > 0'
			. " AND (covers_until IS NULL OR covers_until > '{$nowSql}')"
			. ' GROUP BY level_id ORDER BY total DESC, level_id ASC'
		);

		$levels = array();
		foreach ( is_array( $byLevel ) ? $byLevel : array() as $row ) {
			$levels[] = array(
				'level_id' => (int) ( $row->level_id ?? 0 ),
				'total'    => max( 0, (int) ( $row->total ?? 0 ) ),
			);
		}

		$soon = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT"
				. ' COALESCE(SUM(CASE WHEN covers_until > %s AND covers_until <= %s THEN 1 ELSE 0 END), 0) AS next7,'
				. ' COALESCE(SUM(CASE WHEN covers_until > %s AND covers_until <= %s THEN 1 ELSE 0 END), 0) AS next30'
				. " FROM {$members}"
				. ' WHERE frozen_at IS NULL AND level_id IS NOT NULL AND level_id > 0'
				. ' AND covers_until IS NOT NULL AND covers_until > %s',
				$nowSql,
				gmdate( 'Y-m-d H:i:s', $now + self::EXPIRING_SOON * DAY_IN_SECONDS ),
				$nowSql,
				gmdate( 'Y-m-d H:i:s', $now + self::EXPIRING_LATER * DAY_IN_SECONDS ),
				$nowSql
			)
		);

		$frozen = $wpdb->get_var( "SELECT COUNT(*) FROM {$members} WHERE frozen_at IS NOT NULL" );

		$expired = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$events} WHERE type = '"
				. HWE_Memberships_Events::EXPIRED . "' AND created_at >= %s",
				$since
			)
		);

		$activations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE level_from = 0 AND type IN ('"
				. HWE_Memberships_Events::ACTIVATED . "','"
				. HWE_Memberships_Events::GRANTED_BY_ADMIN . "') AND created_at >= %s",
				$since
			)
		);

		$renewals = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE type = '"
				. HWE_Memberships_Events::RENEWED . "' AND created_at >= %s",
				$since
			)
		);

		$active = 0;
		foreach ( $levels as $level ) {
			$active += $level['total'];
		}

		return array(
			'days'            => $days,
			'active'          => $active,
			'active_by_level' => $levels,
			'frozen'          => max( 0, (int) $frozen ),
			'expiring_7'      => max( 0, (int) ( $soon?->next7 ?? 0 ) ),
			'expiring_30'     => max( 0, (int) ( $soon?->next30 ?? 0 ) ),
			'expired'         => max( 0, (int) $expired ),
			'activations'     => max( 0, (int) $activations ),
			'renewals'        => max( 0, (int) $renewals ),
		);
	}

	/** Pantalla del resumen. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver el resumen de membresías.', 'hwe' ) );
			return;
		}

		$days = isset( $_GET['days'] ) && is_scalar( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : self::DEFAULT_DAYS; // phpcs:ignore WordPress.Security.NonceVerification
		$days = in_array( $days, self::WINDOWS, true ) ? $days : self::DEFAULT_DAYS;
		$data = self::summary( $days );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Resumen', 'hwe' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		echo '<p>';
		foreach ( self::WINDOWS as $window ) {
			printf(
				'<a class="button%s" href="%s">%s</a> ',
				$window === $days ? ' button-primary' : '',
				esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::SUMMARY, array( 'days' => $window ) ) ),
				esc_html( sprintf(
					/* translators: %d: días del periodo. */
					_n( 'Últimos %d día', 'Últimos %d días', $window, 'hwe' ),
					$window
				) )
			);
		}
		echo '</p>';

		echo '<div class="notice notice-info inline"><p>'
			. esc_html( sprintf(
				/* translators: %d: número de socios activos. */
				__( '%d socios activos.', 'hwe' ),
				(int) $data['active']
			) )
			. '</p></div>';

		echo '<table class="wp-list-table widefat fixed striped" style="max-width:640px"><tbody>';
		self::renderRow( __( 'Congelados', 'hwe' ), (int) $data['frozen'] );
		self::renderRow(
			sprintf(
				/* translators: %d: días de la ventana corta. */
				__( 'Caducan en %d días', 'hwe' ),
				self::EXPIRING_SOON
			),
			(int) $data['expiring_7']
		);
		self::renderRow(
			sprintf(
				/* translators: %d: días de la ventana larga. */
				__( 'Caducan en %d días', 'hwe' ),
				self::EXPIRING_LATER
			),
			(int) $data['expiring_30']
		);
		self::renderRow(
			sprintf(
				/* translators: %d: días del periodo. */
				__( 'Caducados en los últimos %d días', 'hwe' ),
				$days
			),
			(int) $data['expired']
		);
		self::renderRow( __( 'Altas en el periodo', 'hwe' ), (int) $data['activations'] );
		self::renderRow( __( 'Renovaciones en el periodo', 'hwe' ), (int) $data['renewals'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Socios activos por nivel', 'hwe' ) . '</h2>';
		if ( array() === $data['active_by_level'] ) {
			echo '<p><em>' . esc_html__( 'Todavía no hay socios activos.', 'hwe' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Socios activos', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $data['active_by_level'] as $level ) {
			$row = HWE_Memberships_Levels::get( (int) $level['level_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( null === $row ? '#' . (int) $level['level_id'] : (string) $row['name'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $level['total'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function renderRow( string $label, int $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><strong>'
			. esc_html( number_format_i18n( $value ) ) . '</strong></td></tr>';
	}
}
