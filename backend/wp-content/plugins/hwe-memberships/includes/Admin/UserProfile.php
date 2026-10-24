<?php
/**
 * Sección de membresía en el perfil de usuario (spec §11.2).
 *
 * Muestra el estado del socio —nivel efectivo, estado, fin, cola programada por nivel, cupo de
 * envíos consumido y reservado, número y antigüedad— y sus últimos eventos. La lectura es la de
 * `HWE_Memberships_Members::state()`, que calcula por fechas y nunca escribe.
 *
 * **No hay un campo de «nivel» que se sobrescriba.** Las acciones son explícitas y cada una
 * delega en `HWE_Memberships_Grants`, con capacidad y nonce propios:
 *  · conceder un nivel (con días o indefinido),
 *  · extender la cobertura vigente (una concesión nueva del mismo nivel; la línea de tiempo la
 *    encadena detrás de la actual),
 *  · quitar todas las concesiones vivas,
 *  · congelar o descongelar.
 * La nota viaja en la concesión y en el historial. El formulario lleva una clave de idempotencia
 * por render: un doble envío del mismo POST no concede dos veces.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_User_Profile {

	/** Acción de `admin-post.php`. */
	public const ACTION = 'hwe_memberships_profile_action';

	/** Campo del nonce, atado al usuario. */
	public const NONCE_FIELD  = 'hwe_memberships_profile_nonce';
	public const NONCE_PREFIX = 'hwe_memberships_profile';

	/** Eventos que se muestran en el perfil. */
	public const HISTORY = 10;

	/** Longitud máxima de la nota. */
	private const NOTE_MAX = 500;

	/** Rango razonable de una concesión: de 1 día a 10 años. */
	private const DAYS_MAX = 3650;

	/** Engancha la sección del perfil. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handleAction' ) );
	}

	/** Nonce de una acción sobre un usuario concreto. */
	public static function nonceAction( int $userId ): string {
		return self::NONCE_PREFIX . ':' . $userId;
	}

	// ------------------------------------------------------------------------
	//  Sección del perfil
	// ------------------------------------------------------------------------

	/**
	 * Pinta la sección. Sin capacidad de lectura no se muestra nada.
	 *
	 * @param mixed $user `WP_User` de la pantalla de perfil.
	 */
	public static function render( $user ): void {
		if (
			! HWE_Memberships_Admin_Capabilities::canView()
			|| ! is_object( $user )
			|| empty( $user->ID )
		) {
			return;
		}

		$userId = (int) $user->ID;
		$state  = HWE_Memberships_Members::state( $userId );
		$levels = HWE_Memberships_Levels::all( true );
		$canEdit = HWE_Memberships_Admin_Capabilities::canManage();
		$frozen = HWE_Memberships_Members::STATUS_FROZEN === (string) $state['status'];

		echo '<h2>' . esc_html__( 'Membresía', 'hwe' ) . '</h2>';
		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Nivel efectivo', 'hwe' ) . '</th><td><strong>'
			. esc_html( self::levelName( (int) $state['level_id'] ) )
			. '</strong></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Estado', 'hwe' ) . '</th><td>'
			. esc_html( HWE_Memberships_Admin_Members_Screen::statusLabel( self::statusFor( $state ) ) )
			. '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Fin de la cobertura', 'hwe' ) . '</th><td>'
			. esc_html( self::formatDate( $state['ends_at'] ) )
			. '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Número de socio', 'hwe' ) . '</th><td>'
			. esc_html( '' !== (string) $state['member_number'] ? (string) $state['member_number'] : '—' )
			. '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Socio desde', 'hwe' ) . '</th><td>'
			. esc_html( self::formatDate( $state['member_since'] ) )
			. '</td></tr>';

		$quota = self::quota( $userId );
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Cupo de envíos', 'hwe' ),
			esc_html( sprintf(
				/* translators: 1: envíos usados, 2: envíos reservados. */
				__( '%1$d usados, %2$d reservados', 'hwe' ),
				$quota['used'],
				$quota['reserved']
			) )
		);

		echo '</tbody></table>';

		self::renderQueue( $state['queue'] );

		if ( $canEdit ) {
			self::renderActions( $userId, $state, $levels, $frozen );
		}

		self::renderHistory( $userId );
	}

	/** @param list<array<string,mixed>> $queue */
	private static function renderQueue( array $queue ): void {
		echo '<h3>' . esc_html__( 'Cola programada', 'hwe' ) . '</h3>';
		if ( array() === $queue ) {
			echo '<p><em>' . esc_html__( 'Sin niveles en cola.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '<th>' . esc_html__( 'Desde', 'hwe' ) . '</th>';
		echo '<th>' . esc_html__( 'Hasta', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $queue as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$infinite = ! empty( $entry['infinite'] );
			$undated  = ! empty( $entry['undated'] );
			echo '<tr>';
			echo '<td>' . esc_html( self::levelName( (int) ( $entry['level_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( $undated ? __( 'Sin fecha', 'hwe' ) : self::formatDate( $entry['starts_at'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( $infinite ? __( 'Indefinida', 'hwe' ) : self::formatDate( $entry['ends_at'] ?? null ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Acciones explícitas. El nivel se elige para conceder; para extender se usa el efectivo.
	 *
	 * @param list<array<string,mixed>> $levels
	 * @param array<string,mixed>       $state
	 */
	private static function renderActions( int $userId, array $state, array $levels, bool $frozen ): void {
		echo '<h3>' . esc_html__( 'Acciones', 'hwe' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Cada acción deja su rastro en el historial con tu usuario. La nota es opcional y se guarda con la concesión.', 'hwe' ) . '</p>';

		// Conceder.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em">';
		self::formHidden( $userId );
		echo '<select name="hwe_memberships_grant_level">';
		foreach ( $levels as $level ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( (string) (int) $level['id'] ),
				esc_html( (string) $level['name'] )
			);
		}
		echo '</select> ';
		printf(
			'<input type="number" min="0" max="%d" step="1" name="hwe_memberships_grant_days" value="30" class="small-text" /> ',
			self::DAYS_MAX
		);
		printf(
			'<input type="text" name="hwe_memberships_note" value="" maxlength="%d" class="regular-text" placeholder="%s" /> ',
			self::NOTE_MAX,
			esc_attr__( 'Nota (opcional)', 'hwe' )
		);
		printf( '<input type="hidden" name="hwe_memberships_key" value="%s" />', esc_attr( self::newKey() ) );
		echo '<button type="submit" class="button button-primary" name="hwe_memberships_action" value="grant">'
			. esc_html__( 'Conceder nivel', 'hwe' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Días 0 = indefinida.', 'hwe' ) . '</p>';
		echo '</form>';

		// Extender.
		if ( (int) $state['level_id'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em">';
			self::formHidden( $userId );
			printf(
				'<input type="number" min="1" max="%d" step="1" name="hwe_memberships_extend_days" value="30" class="small-text" /> ',
				self::DAYS_MAX
			);
			printf(
				'<input type="text" name="hwe_memberships_note" value="" maxlength="%d" class="regular-text" placeholder="%s" /> ',
				self::NOTE_MAX,
				esc_attr__( 'Nota (opcional)', 'hwe' )
			);
			printf( '<input type="hidden" name="hwe_memberships_key" value="%s" />', esc_attr( self::newKey() ) );
			echo '<button type="submit" class="button" name="hwe_memberships_action" value="extend">'
				. esc_html( sprintf(
					/* translators: %s: nombre del nivel efectivo. */
					__( 'Extender %s', 'hwe' ),
					self::levelName( (int) $state['level_id'] )
				) ) . '</button>';
			echo '</form>';
		}

		// Congelar / descongelar. La congelación no guarda nota: su hecho queda en el historial
		// con la fecha y el administrador.
		if ( $frozen || (int) $state['level_id'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em">';
			self::formHidden( $userId );
			printf(
				'<button type="submit" class="button" name="hwe_memberships_action" value="%s">%s</button>',
				esc_attr( $frozen ? 'unfreeze' : 'freeze' ),
				esc_html( $frozen ? __( 'Descongelar', 'hwe' ) : __( 'Congelar', 'hwe' ) )
			);
			echo '</form>';
		}

		// Quitar.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::formHidden( $userId );
		printf(
			'<input type="text" name="hwe_memberships_note" value="" maxlength="%d" class="regular-text" placeholder="%s" /> ',
			self::NOTE_MAX,
			esc_attr__( 'Motivo de la retirada (opcional)', 'hwe' )
		);
		echo '<button type="submit" class="button" name="hwe_memberships_action" value="remove">'
			. esc_html__( 'Quitar membresía', 'hwe' ) . '</button>';
		echo '</form>';
	}

	private static function formHidden( int $userId ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $userId ) . '">';
		wp_nonce_field( self::nonceAction( $userId ), self::NONCE_FIELD );
	}

	private static function renderHistory( int $userId ): void {
		$events = HWE_Memberships_Admin_History_Screen::items(
			array(
				'user_id' => $userId,
				'type'    => '',
				'level'   => 0,
				'from'    => '',
				'to'      => '',
				'paged'   => 1,
			),
			self::HISTORY,
			0
		);

		echo '<h3>' . esc_html__( 'Últimos eventos', 'hwe' ) . '</h3>';
		if ( array() === $events ) {
			echo '<p><em>' . esc_html__( 'Sin eventos.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Fecha', 'hwe' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'hwe' ) . '</th>';
		echo '<th>' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::formatMysql( (string) ( $event->created_at ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( HWE_Memberships_Admin_History_Screen::typeLabel( (string) ( $event->type ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( HWE_Memberships_Admin_History_Screen::levelName( (int) ( $event->level_to ?? 0 ) ) ?: '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------------
	//  Acciones
	// ------------------------------------------------------------------------

	/** POST de `admin-post.php`: capacidad, nonce, acción, aviso y vuelta al perfil. */
	public static function handleAction(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$userId = isset( $_POST['user_id'] ) && is_scalar( $_POST['user_id'] )
			? absint( wp_unslash( $_POST['user_id'] ) )
			: 0;
		$nonce  = isset( $_POST[ self::NONCE_FIELD ] ) && is_scalar( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::nonceAction( $userId ) ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}
		if ( function_exists( 'current_user_can' ) && $userId > 0 && ! current_user_can( 'edit_user', $userId ) ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$action = isset( $_POST['hwe_memberships_action'] ) && is_scalar( $_POST['hwe_memberships_action'] )
			? sanitize_key( wp_unslash( (string) $_POST['hwe_memberships_action'] ) )
			: '';
		$result = self::applyAction( $userId, $action, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		HWE_Memberships_Admin::flash( $result['code'] );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect(
				$userId > 0
					? admin_url( 'user-edit.php?user_id=' . $userId )
					: HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::MEMBERS )
			);
		}
	}

	/**
	 * Ejecuta una acción del perfil delegando en `HWE_Memberships_Grants`. No pinta nada.
	 *
	 * Códigos: `granted`, `extended`, `removed`, `member_frozen`, `member_unfrozen`,
	 * `invalid_user`, `invalid_level`, `invalid_days`, `invalid_key`, `invalid_action`,
	 * `no_membership`, `not_granted` y `not_possible`.
	 *
	 * @param array<string,mixed> $input Normalmente `$_POST`.
	 * @return array{ok:bool,code:string}
	 */
	public static function applyAction( int $userId, string $action, array $input ): array {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			return array( 'ok' => false, 'code' => 'forbidden' );
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( $userId <= 0 || ! is_object( $user ) ) {
			return array( 'ok' => false, 'code' => 'invalid_user' );
		}

		$actorId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$get     = static fn ( string $key ): string => isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
			? (string) $input[ $key ]
			: '';
		$note    = self::cleanNote( $get( 'hwe_memberships_note' ) );
		$data    = array(
			'note'   => $note,
			'source' => 'wp-admin',
		);

		switch ( $action ) {
			case 'grant':
				$levelId = absint( $get( 'hwe_memberships_grant_level' ) );
				if ( $levelId <= 0 || null === HWE_Memberships_Levels::get( $levelId ) ) {
					return array( 'ok' => false, 'code' => 'invalid_level' );
				}
				$days = absint( $get( 'hwe_memberships_grant_days' ) );
				if ( $days > self::DAYS_MAX ) {
					return array( 'ok' => false, 'code' => 'invalid_days' );
				}
				$key = self::cleanKey( $get( 'hwe_memberships_key' ) );
				if ( '' === $key ) {
					return array( 'ok' => false, 'code' => 'invalid_key' );
				}

				$grantId = HWE_Memberships_Grants::grant(
					$userId,
					$levelId,
					$days > 0 ? $days : null,
					HWE_Memberships_Grants::ORIGIN_ADMIN,
					self::grantKey( $userId, $key ),
					array(),
					$actorId,
					$data
				);

				return null !== $grantId
					? array( 'ok' => true, 'code' => 'granted' )
					: array( 'ok' => false, 'code' => 'not_granted' );

			case 'extend':
				$state = HWE_Memberships_Members::state( $userId );
				if ( (int) $state['level_id'] <= 0 ) {
					return array( 'ok' => false, 'code' => 'no_membership' );
				}

				$days = absint( $get( 'hwe_memberships_extend_days' ) );
				if ( $days < 1 || $days > self::DAYS_MAX ) {
					return array( 'ok' => false, 'code' => 'invalid_days' );
				}
				$key = self::cleanKey( $get( 'hwe_memberships_key' ) );
				if ( '' === $key ) {
					return array( 'ok' => false, 'code' => 'invalid_key' );
				}

				$grantId = HWE_Memberships_Grants::grant(
					$userId,
					(int) $state['level_id'],
					$days,
					HWE_Memberships_Grants::ORIGIN_ADMIN,
					self::grantKey( $userId, $key ),
					array(),
					$actorId,
					$data + array( 'extend' => true )
				);

				return null !== $grantId
					? array( 'ok' => true, 'code' => 'extended' )
					: array( 'ok' => false, 'code' => 'not_granted' );

			case 'freeze':
				$ok = HWE_Memberships_Grants::freeze( $userId, $actorId );
				return array( 'ok' => $ok, 'code' => $ok ? 'member_frozen' : 'not_possible' );

			case 'unfreeze':
				$ok = HWE_Memberships_Grants::unfreeze( $userId, $actorId );
				return array( 'ok' => $ok, 'code' => $ok ? 'member_unfrozen' : 'not_possible' );

			case 'remove':
				$ok = HWE_Memberships_Grants::removeAll(
					$userId,
					$actorId,
					'' !== $note ? $note : 'admin_removed'
				);
				return array( 'ok' => $ok, 'code' => $ok ? 'removed' : 'not_possible' );
		}

		return array( 'ok' => false, 'code' => 'invalid_action' );
	}

	/**
	 * Cupo de envíos consumido y reservado por las concesiones vivas. Solo lectura.
	 *
	 * @return array{used:int,reserved:int}
	 */
	public static function quota( int $userId ): array {
		global $wpdb;

		if ( $userId <= 0 ) {
			return array( 'used' => 0, 'reserved' => 0 );
		}

		$grants = HWE_Memberships_Schema::grantsTable();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(shipping_quota_used), 0) AS used,"
				. " COALESCE(SUM(shipping_quota_reserved), 0) AS reserved"
				. " FROM {$grants} WHERE user_id = %d AND status IN ('active','scheduled')",
				$userId
			)
		);

		return array(
			'used'     => max( 0, (int) ( $row?->used ?? 0 ) ),
			'reserved' => max( 0, (int) ( $row?->reserved ?? 0 ) ),
		);
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	/** Estado de la UI a partir del estado del socio. */
	private static function statusFor( array $state ): string {
		return match ( (string) $state['status'] ) {
			HWE_Memberships_Members::STATUS_FROZEN => HWE_Memberships_Admin_Members_Screen::STATUS_FROZEN,
			HWE_Memberships_Members::STATUS_ACTIVE => HWE_Memberships_Admin_Members_Screen::STATUS_ACTIVE,
			default                                => HWE_Memberships_Admin_Members_Screen::STATUS_EXPIRED,
		};
	}

	private static function levelName( int $levelId ): string {
		$name = HWE_Memberships_Admin_History_Screen::levelName( $levelId );

		return '' !== $name ? $name : '—';
	}

	/** Nota limpia y acotada; se guarda solo si el administrador la escribió. */
	private static function cleanNote( string $note ): string {
		$note = trim( sanitize_text_field( $note ) );
		if ( '' === $note ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::NOTE_MAX ) : substr( $note, 0, self::NOTE_MAX );
	}

	/** Clave del formulario: saneada y con longitud acotada. Un UUID cumple el mínimo. */
	private static function cleanKey( string $key ): string {
		$key = sanitize_key( $key );
		if ( strlen( $key ) < 8 || strlen( $key ) > 64 ) {
			return '';
		}

		return $key;
	}

	/** Clave de idempotencia de una concesión de administración. */
	private static function grantKey( int $userId, string $key ): string {
		return substr( 'admin:' . $userId . ':' . $key, 0, 191 );
	}

	/** Clave nueva por render del formulario; un doble clic reenvía la misma. */
	private static function newKey(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return uniqid( 'membership', true );
		}
	}

	/** @param mixed $value */
	private static function formatDate( mixed $value ): string {
		if ( $value instanceof \DateTimeInterface ) {
			return wp_date( 'Y-m-d H:i', $value->getTimestamp() );
		}

		return '—';
	}

	private static function formatMysql( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : wp_date( 'Y-m-d H:i', $timestamp );
	}
}
