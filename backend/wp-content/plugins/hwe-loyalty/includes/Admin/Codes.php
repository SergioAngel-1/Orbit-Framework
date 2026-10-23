<?php
/**
 * Acción por lotes: asignar código de referido a quien todavía no tiene (spec §5.4, §12).
 *
 * El lote es acotado y **reanudable sin cursor persistente**: cada pasada busca a los
 * participantes sin fila de referidos en orden de id, asigna los primeros `$limit` y deja el
 * resto para la siguiente. Las filas ya creadas desaparecen de la consulta y
 * `HWE_Loyalty_Referrals` garantiza la unicidad del usuario y del código, así que repetir una
 * pasada (o cortarla a medias) no duplica nada: solo completa lo que faltaba.
 *
 * Solo entran los roles participantes de los ajustes (`allowed_roles`): asignar un código a una
 * cuenta que no puede usar el programa sería ruido, y el filtro de roles se aplica en SQL para
 * no cargar todos los usuarios.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Codes {

	public const ACTION = 'hwe_loyalty_codes';
	public const NONCE  = 'hwe_loyalty_codes';
	public const FIELD  = 'hwe_loyalty_codes_nonce';

	public const DEFAULT_BATCH = 25;
	public const MAX_BATCH     = 100;

	/** Engancha el POST del lote. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handleRequest' ) );
	}

	/**
	 * Asigna como mucho `$limit` códigos y devuelve cuántos quedan sin código.
	 *
	 * @return array{assigned:int,remaining:int}
	 */
	public static function assignMissing( int $limit = self::DEFAULT_BATCH ): array {
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );

		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			return array( 'assigned' => 0, 'remaining' => 0 );
		}

		$assigned = 0;
		foreach ( self::missingIds( $limit ) as $userId ) {
			if ( '' !== \HWE_Loyalty_Referrals::codeFor( $userId ) ) {
				$assigned++;
			}
		}

		return array( 'assigned' => $assigned, 'remaining' => self::remaining() );
	}

	/** POST de `admin-post.php`: capacidad + nonce, lote y redirección con el resultado. */
	public static function handleRequest(): void {
		if ( ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			wp_die( 'No autorizado.', 403 );
			return;
		}

		$nonce = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( 'Nonce no válido.', 403 );
			return;
		}

		$limit  = isset( $_POST['hwe_loyalty_batch'] ) ? absint( wp_unslash( $_POST['hwe_loyalty_batch'] ) ) : self::DEFAULT_BATCH;
		$result = self::assignMissing( $limit );

		HWE_Loyalty_Admin::flash(
			'batch_done',
			array(
				'count'     => $result['assigned'],
				'remaining' => $result['remaining'],
			)
		);

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::CODES ) );
		}
	}

	/** Cuántos participantes siguen sin código (para la pantalla). */
	public static function remaining(): int {
		global $wpdb;

		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			return 0;
		}

		[ $joins, $where, $args ] = self::candidateSql();
		if ( '' === $joins ) {
			return 0;
		}

		$sql = 'SELECT COUNT(DISTINCT u.ID) FROM ' . $wpdb->users . " u {$joins} WHERE {$where}";
		if ( array() !== $args ) {
			$sql = (string) $wpdb->prepare( $sql, $args );
		}

		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	/** @return list<int> */
	private static function missingIds( int $limit ): array {
		global $wpdb;

		[ $joins, $where, $args ] = self::candidateSql();
		if ( '' === $joins ) {
			return array();
		}

		$sql  = 'SELECT u.ID FROM ' . $wpdb->users . " u {$joins} WHERE {$where} ORDER BY u.ID ASC LIMIT %d";
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $args, array( $limit ) ) ) );

		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * FROM/JOIN y WHERE compartidos por el listado y el contador.
	 *
	 * @return array{0:string,1:string,2:list<mixed>}
	 */
	private static function candidateSql(): array {
		global $wpdb;

		$roles = HWE_Loyalty_Settings::allowedRoles();
		if ( array() === $roles ) {
			return array( '', '', array() );
		}

		$metaKey = $wpdb->prefix . 'capabilities';
		$patterns = array();
		foreach ( $roles as $role ) {
			// El meta de capacidades guarda `s:<longitud>:"<rol>";b:1;`; el comodín después
			// tolera el resto de la cadena serializada sin depender de su longitud exacta.
			$patterns[] = '%"' . $role . '";b:1%';
		}

		$like = implode( ' OR ', array_fill( 0, count( $patterns ), 'um.meta_value LIKE %s' ) );
		$joins = 'INNER JOIN ' . $wpdb->usermeta . ' um ON um.user_id = u.ID AND um.meta_key = %s'
			. ' LEFT JOIN ' . HWE_Loyalty_Schema::referralsTable() . ' r ON r.user_id = u.ID';
		$where = "(r.user_id IS NULL OR r.code = '') AND ({$like})";

		return array( $joins, $where, array_merge( array( $metaKey ), $patterns ) );
	}
}
