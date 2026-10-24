<?php
/**
 * Tarea diaria de `hwe-memberships` (spec §5.5–§5.6 y §12).
 *
 * Un ÚNICO evento diario de WP-Cron, `hwe_memberships_daily`, materializa por lotes las
 * transiciones que el resto del módulo calcula al leer:
 *  · `scheduled → active` cuando llega el inicio de una concesión programada;
 *  · `active → ended` cuando llega su fin;
 *  · el aviso `expiring` de las concesiones que terminan dentro de los días configurados;
 *  · la limpieza de los tokens del carné caducados hace más de una semana (cada petición de
 *    carné inserta una fila; sin ella la tabla crecería sin tope).
 *
 * Si una pasada deja trabajo pendiente, programa una continuación puntual
 * (`hwe_memberships_daily_continue`) a cinco minutos; no se espera al día siguiente.
 *
 * La lectura del nivel efectivo nunca escribe: se calcula por fechas (`Members::state`). Esta
 * tarea solo deja la base de datos en el mismo estado que ya se ve, escribe el historial,
 * actualiza la caché de `members`, revoca los tokens del carné cuando el socio se queda sin
 * nivel y dispara los hooks **después del COMMIT** de cada usuario.
 *
 * Decisiones:
 *  · **Sin recorrer todos los usuarios**: los candidatos salen de una consulta indexada por
 *    `status` + fechas (`Grants::dueUserIds`). Cada usuario se procesa en su propia
 *    transacción con su fila bloqueada, igual que el resto de escrituras del módulo.
 *  · **Idempotente**: la transición cambia el `status`; una segunda pasada ya no la ve. El
 *    aviso de caducidad usa `expiry_notified_at` como marca de «una vez por concesión».
 *  · **Congelados no caducan**: un socio con `frozen_at` se salta antes de tocar sus fechas.
 *  · **Tope por ejecución y continuación**: `$batch` socios procesados sin fallo como máximo
 *    (con transición o solo con aviso). Si queda trabajo, se registra con
 *    `[hwe] memberships: …` y se programa UN único evento `hwe_memberships_daily_continue` a
 *    cinco minutos (sin duplicarlo si ya existe). Al desactivar el plugin se retiran el diario
 *    y la continuación.
 *  · **Un fallo no bloquea al resto**: un socio cuyo `materialize` devuelve `-1` no consume el
 *    cupo; se sigue con el siguiente candidato, con un techo de exploración por ejecución para
 *    que un grupo de socios que fallan siempre no recorra la tabla sin límite.
 *  · **Módulo apagado no hace nada**: ni siquiera consulta (no es un error).
 *
 * En producción el evento no lo dispara una petición: lo ejecuta el contenedor `wp-cron`
 * (`DISABLE_WP_CRON`). Se programa al activar el plugin y se autorrepara en `init` si falta
 * (una migración o un `wp cron` vaciado dejarían el mantenimiento sin ejecutar para siempre).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Scheduler {

	/** Hook del evento diario y acción pública del módulo. */
	public const HOOK = 'hwe_memberships_daily';

	/** Hook del evento de continuación cuando una pasada deja trabajo pendiente. */
	public const HOOK_CONTINUE = 'hwe_memberships_daily_continue';

	/** Segundos hasta la continuación (5 minutos). */
	public const CONTINUE_DELAY = 300;

	/** Usuarios que se procesan como máximo en una ejecución (por defecto). */
	public const BATCH = 200;

	/**
	 * Techo de candidatos que una ejecución puede mirar para encontrar `$batch` socios que se
	 * puedan procesar sin fallo. Un socio que falla no consume el cupo, pero tampoco puede
	 * hacer que la tarea recorra sin límite a todos los socios con trabajo pendiente.
	 */
	private const MAX_SCAN = 5000;

	/** Mínimo del techo de candidatos cuando el lote es pequeño. */
	private const MIN_SCAN = 500;

	/** Evita inundar el log si `init` reintenta en cada petición y no se puede programar. */
	private static bool $scheduleFailureLogged = false;

	/** Engancha la tarea y autorrepara su programación. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::HOOK_CONTINUE, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Programa el evento diario si no existe. Idempotente: llamarlo dos veces deja un único
	 * evento. La primera pasada se deja para dentro de una hora, como `hwe-loyalty`.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );

		if (
			false === $scheduled
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $scheduled ) )
		) {
			if ( ! self::$scheduleFailureLogged ) {
				self::$scheduleFailureLogged = true;
				error_log( '[hwe] memberships: no se pudo programar la tarea diaria de membresías.' );
			}
		}
	}

	/** Retira TODAS las ocurrencias del evento diario y de su continuación. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
	}

	/**
	 * Programa una única continuación si no hay una ya pendiente. Se usa cuando una pasada
	 * deja trabajo sin materializar: sin esto, el resto esperaría al día siguiente.
	 */
	private static function scheduleContinuation(): void {
		if ( wp_next_scheduled( self::HOOK_CONTINUE ) ) {
			return;
		}

		$scheduled = wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::HOOK_CONTINUE );

		if (
			false === $scheduled
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $scheduled ) )
		) {
			error_log( '[hwe] memberships: no se pudo programar la continuación de la tarea diaria.' );
		}
	}

	/**
	 * Materializa las transiciones pendientes de hasta `$batch` socios procesados sin fallo.
	 * Devuelve cuántas transiciones (activaciones + caducidades) se materializaron; los avisos
	 * de caducidad no cuentan como transición.
	 *
	 * Un socio cuyo `materialize` devuelve `-1` (fallo registrado) no consume el cupo: se sigue
	 * con el siguiente candidato. Un socio que devuelve `0` (congelado, carrera con otra pasada
	 * o nada que materializar) sí cuenta como procesado. La búsqueda de candidatos tiene un
	 * techo (`MAX_SCAN`) para que un grupo de socios que fallan siempre no convierta la tarea
	 * en un recorrido sin límite. Si al terminar queda trabajo, se programa una ÚNICA
	 * continuación a cinco minutos; la siguiente pasada diaria recoge lo que quede si la
	 * continuación tampoco basta. Un fallo sin ningún progreso no programa continuación para
	 * no martillear la base de datos: vuelve a entrar en la pasada diaria.
	 *
	 * Es el callback del evento diario y del de continuación. WordPress ignora el valor de
	 * retorno; se devuelve para poder probarlo y para que una instancia pueda invocarlo a mano.
	 */
	public static function run( int $batch = self::BATCH ): int {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return 0;
		}

		$batch = max( 1, $batch );

		// Mantenimiento previo: los tokens del carné caducados hace más de una semana.
		if ( class_exists( 'HWE_Memberships_Card' ) ) {
			HWE_Memberships_Card::purgeExpired();
		}

		$settings   = HWE_Memberships_Settings::get();
		$noticeDays = max( 0, (int) ( $settings['expiring_notice_days'] ?? 0 ) );
		$now        = new DateTimeImmutable( '@' . time() );
		$maxScan    = max( $batch + 1, min( self::MAX_SCAN, max( $batch * 10, self::MIN_SCAN ) ) );

		/** @var array<int,true> $attempted */
		$attempted = array();
		$processed = 0;
		$total     = 0;
		$progress  = false;

		while ( $processed < $batch ) {
			// El primer socio no intentado está como mucho en la posición count($attempted)+1;
			// duplicar el margen evita una consulta por cada fallo.
			$probeLimit = min( $maxScan, max( $batch + 1, count( $attempted ) * 2 + 1 ) );
			$candidates = HWE_Memberships_Grants::dueUserIds( $probeLimit, $now, $noticeDays );

			if ( array() === $candidates ) {
				break;
			}

			$fresh = array();
			foreach ( $candidates as $candidate ) {
				$userId = (int) $candidate;
				if ( $userId > 0 && ! isset( $attempted[ $userId ] ) ) {
					$fresh[] = $userId;
				}
			}

			if ( array() === $fresh ) {
				break;
			}

			foreach ( $fresh as $userId ) {
				if ( $processed >= $batch ) {
					break;
				}

				$attempted[ $userId ] = true;

				try {
					$result = HWE_Memberships_Grants::materialize( $userId, $now, $noticeDays );
				} catch ( \Throwable $e ) {
					error_log( '[hwe] memberships: la tarea diaria falló para el usuario ' . $userId . '; se continúa con el siguiente.' );
					continue;
				}

				if ( $result < 0 ) {
					// El fallo ya quedó registrado por `materialize`; no consume el cupo.
					continue;
				}

				++$processed;
				$total   += $result;
				$progress = true;
			}

			if ( count( $attempted ) >= $maxScan ) {
				break;
			}
		}

		$hasPending = false;

		if ( $processed >= $batch ) {
			if ( count( $attempted ) >= $maxScan ) {
				$hasPending = true;
			} else {
				// Con un límite de count($attempted)+1 se ve el primer socio no intentado, si
				// existe: todos los que pueden ir delante ya están en $attempted.
				$probeLimit = min( $maxScan, count( $attempted ) + 1 );
				$probe      = HWE_Memberships_Grants::dueUserIds( $probeLimit, $now, $noticeDays );
				foreach ( $probe as $candidate ) {
					if ( ! isset( $attempted[ (int) $candidate ] ) ) {
						$hasPending = true;
						break;
					}
				}
			}
		} elseif ( $progress && count( $attempted ) >= $maxScan ) {
			// Se tocó el techo de exploración con progreso: puede quedar trabajo detrás.
			$hasPending = true;
		}

		if ( $hasPending ) {
			$limitMessage = $processed >= $batch
				? 'el tope de ' . $batch . ' socios'
				: 'el techo de exploración de ' . $maxScan . ' candidatos';
			error_log(
				'[hwe] memberships: la tarea diaria alcanzó '
				. $limitMessage
				. ' en una ejecución; queda trabajo y se programa una continuación.'
			);
			self::scheduleContinuation();
		}

		return $total;
	}
}
