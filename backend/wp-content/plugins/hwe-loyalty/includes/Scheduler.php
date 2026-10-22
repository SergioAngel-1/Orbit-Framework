<?php
/**
 * Tareas programadas de `hwe-loyalty` (spec §5.3 y §6.3).
 *
 * Un ÚNICO evento diario de WP-Cron, `hwe_loyalty_daily`, del que cuelgan las tareas de
 * mantenimiento del módulo: la caducidad de abonos (`runExpiry`) y el cumpleaños
 * (`HWE_Loyalty_Events::runBirthdays`), que se engancha aparte como OTRO callback del mismo
 * hook. Por eso la caducidad es un callback propio y no el cuerpo del evento: así nadie tiene
 * que editar esta clase para colgar trabajo nuevo.
 *
 * En producción el evento no lo dispara una petición: lo ejecuta el contenedor `wp-cron`
 * (`DISABLE_WP_CRON`). Se programa en la activación y, además, se autorrepara en `init` si
 * falta (un `wp cron` vaciado, una migración o un despliegue que copió la base sin la opción
 * `cron` dejarían el mantenimiento sin ejecutar para siempre). `schedule()` es idempotente,
 * así que pasar por `init` en cada petición no duplica nada.
 *
 * Decisiones de esta tarea:
 *  · **Un solo evento** (`wp_next_scheduled` antes de programar) y `unschedule()` retira
 *    TODAS las ocurrencias (`wp_clear_scheduled_hook`), no solo la primera.
 *  · **Primera pasada dentro de una hora**, no en el instante de la activación: la petición
 *    que activa el plugin no es el sitio para caducar abonos, y el cron diario ya reparte el
 *    resto. Después, cada día.
 *  · **Caducidad por lotes**: `BATCH` abonos por llamada al libro y como máximo `MAX_BATCHES`
 *    lotes por ejecución (5.000 abonos). Cada abono va en su propia transacción dentro del
 *    libro, así que un tope evita que una tarea diaria se eternice; si se alcanza, se registra
 *    con `error_log('[hwe] loyalty: …')` para que se vea que queda trabajo y la siguiente
 *    pasada lo continúe. Sin PII: solo el número de abonos.
 *  · **Con «Puntos» apagado no hace nada**: ni siquiera consulta el libro (falla cerrado y no
 *    deja rastro de error, porque no lo es).
 *
 * Nada de bucles sobre todos los usuarios: el trabajo se descubre por la fecha de caducidad de
 * los abonos vencidos, que es una consulta indexada del libro.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Scheduler {

	/**
	 * Hook del evento diario y, a la vez, la acción pública del módulo: cualquier clase se
	 * cuelga con `add_action( HWE_Loyalty_Scheduler::HOOK, $callback )`.
	 */
	public const HOOK = 'hwe_loyalty_daily';

	/** Abonos vencidos que se piden al libro en cada lote. */
	public const BATCH = 200;

	/**
	 * Lotes por ejecución. Con `BATCH` = 200, una pasada procesa como mucho 5.000 abonos
	 * vencidos; lo que quede se retoma en la pasada siguiente.
	 */
	public const MAX_BATCHES = 25;

	/**
	 * Evita inundar el log cuando `init` reintenta en cada petición y WordPress sigue sin
	 * poder programar el evento (opción `cron` no escribible, por ejemplo).
	 */
	private static bool $scheduleFailureLogged = false;

	/** Engancha la caducidad al evento diario y autorrepara su programación. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'runExpiry' ) );
		// Autorreparación (patrón `hwe-consent-log`): si el evento desapareció de la opción
		// `cron`, la siguiente petición lo vuelve a programar. `schedule()` es idempotente.
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Programa el evento diario si no existe. Idempotente: llamarlo dos veces deja un único
	 * evento. Lo invoca el hook de activación del plugin.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );

		// Un fallo al programar no puede pasar desapercibido: sin evento, los abonos vencidos
		// no se caducarían hasta la siguiente activación. Se registra una vez por proceso
		// porque `init` vuelve a intentarlo en cada petición.
		if (
			false === $scheduled
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $scheduled ) )
		) {
			if ( ! self::$scheduleFailureLogged ) {
				self::$scheduleFailureLogged = true;
				error_log( '[hwe] loyalty: no se pudo programar el evento diario de caducidad.' );
			}
		}
	}

	/** Retira TODAS las ocurrencias del evento. Lo invoca el hook de desactivación. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Caducidad de los abonos vencidos, por lotes, hasta agotarlos o hasta el tope por
	 * ejecución. Devuelve cuántos abonos procesó.
	 *
	 * Es el callback del evento diario (WordPress ignora el valor de retorno; se devuelve para
	 * poder probarlo y para que una instancia pueda invocarlo a mano si lo necesita).
	 */
	public static function runExpiry(): int {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return 0;
		}

		$total = 0;
		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$processed = HWE_Loyalty_Ledger::expireDue( self::BATCH );
			$total    += $processed;

			// Un lote incompleto significa que ya no queda nada vencido en este momento.
			if ( $processed < self::BATCH ) {
				return $total;
			}
		}

		error_log(
			'[hwe] loyalty: la caducidad diaria alcanzó el tope de '
			. ( self::BATCH * self::MAX_BATCHES )
			. ' abonos en una ejecución; queda trabajo para la siguiente pasada.'
		);

		return $total;
	}
}
