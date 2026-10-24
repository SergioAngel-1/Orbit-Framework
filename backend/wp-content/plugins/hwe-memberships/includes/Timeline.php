<?php
/**
 * Línea de tiempo de las concesiones de membresía (spec §5.3 y §5.4).
 *
 * Es lógica **pura**: no toca la base de datos ni llama a WordPress. Solo recibe concesiones en
 * memoria y fechas `DateTimeImmutable` en UTC, y devuelve concesiones recalculadas. La capa que
 * persiste (Tarea 5) convierte las filas de `grants` a este modelo y guarda el resultado en una
 * transacción.
 *
 * Modelo de concesión (array con estas claves; se conservan las claves extra que traiga cada
 * concesión existente, para que la capa de datos no tenga que recomponerlas):
 *  · `key`            string              Clave de idempotencia. Si `place()` recibe una clave que
 *                                         ya está en la lista, no duplica la concesión.
 *  · `level_id`       int                 Id del nivel.
 *  · `rank`           int                 Rango del nivel (mayor = más acceso).
 *  · `days`           int                 Días pagados; `0` = indefinida.
 *  · `starts_at`      ?DateTimeImmutable  Inicio en UTC. `null` = en cola sin fecha, porque la
 *                                         bloquea una concesión indefinida de rango ≥.
 *  · `ends_at`        ?DateTimeImmutable  Fin en UTC. `null` = indefinida o en cola sin fecha.
 *  · `status`         string              `scheduled`, `active`, `ended` o `revoked`.
 *  · `queued_seconds` ?int                (Opcional) segundos exactos que espera una concesión
 *                                         sin fecha. Se usa al aplazar una concesión ya empezada
 *                                         para no perder la fracción de día; en las fechadas es
 *                                         `null`.
 *
 * Al pasar una fila de `hwe_memberships_grants` a este modelo: `idempotency_key` → `key` y
 * `duration_days` → `days`; el resto de claves coincide. `queued_seconds` también coincide con
 * su columna.
 *
 * Reglas que aplica:
 *  · Una concesión nueva empieza en `now` salvo que haya cobertura de rango ≥ al suyo; entonces
 *    empieza al terminar esa cobertura (renovación del mismo nivel o nivel inferior).
 *  · El tiempo restante de las concesiones de rango inferior se aplaza al final de la nueva,
 *    conservando segundos y en orden de rango descendente. Una nueva indefinida deja la cola
 *    inferior programada sin fecha.
 *  · Una lectura del nivel efectivo se calcula por fechas: el inicio cuenta y el fin no.
 *  · Revocar no recorre las concesiones fechadas; solo libera la cola sin fecha cuando deja de
 *    estar bloqueada.
 *  · Congelar se resuelve con `shift()` (desplazamiento uniforme) o con `shiftSince()`, que
 *    devuelve a cada concesión su propio tiempo congelado y reancla la cadena programada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Timeline {

	private const DAY_SECONDS = 86400;

	/**
	 * Coloca una concesión nueva en la línea de tiempo y devuelve la lista resultante.
	 *
	 * `$new` solo necesita `key`, `level_id`, `rank` y `days`; los campos de fechas, estado y
	 * cola se recalculan. La concesión se añade al final de la lista. Si su clave ya existe, no
	 * se duplica (idempotencia) y la lista se devuelve tal cual.
	 *
	 * @param list<array<string,mixed>> $grants
	 * @param array<string,mixed>       $new
	 * @return list<array<string,mixed>>
	 */
	public static function place( array $grants, array $new, DateTimeImmutable $now ): array {
		$fresh = self::normalizeNew( $new );
		$key   = $fresh['key'];

		if ( '' !== $key ) {
			foreach ( $grants as $grant ) {
				if ( (string) ( $grant['key'] ?? '' ) === $key ) {
					return array_values( $grants );
				}
			}
		}

		$rank = (int) $fresh['rank'];
		$days = (int) $fresh['days'];

		// ¿Hasta cuándo hay cobertura de rango igual o superior? Una indefinida bloquea.
		$blocked_end = $now;
		$blocked     = false;
		foreach ( $grants as $grant ) {
			if ( ! self::isLive( $grant, $now ) ) {
				continue;
			}
			if ( (int) ( $grant['rank'] ?? 0 ) < $rank ) {
				continue;
			}
			$start = $grant['starts_at'] ?? null;
			if ( ! $start instanceof DateTimeImmutable ) {
				continue;
			}
			$end = $grant['ends_at'] ?? null;
			if ( ! $end instanceof DateTimeImmutable ) {
				$blocked = true;
				break;
			}
			if ( $end > $blocked_end ) {
				$blocked_end = $end;
			}
		}

		if ( $blocked ) {
			$fresh['starts_at']      = null;
			$fresh['ends_at']        = null;
			$fresh['status']         = 'scheduled';
			$fresh['queued_seconds'] = $days > 0 ? $days * self::DAY_SECONDS : null;
			$grants[]                = $fresh;
			return array_values( $grants );
		}

		$start = $blocked_end;
		$end   = $days > 0 ? $start->modify( '+' . $days . ' days' ) : null;

		$fresh['starts_at']      = $start;
		$fresh['ends_at']        = $end;
		$fresh['status']         = $start <= $now ? 'active' : 'scheduled';
		$fresh['queued_seconds'] = null;
		$grants[]                = $fresh;
		$new_index               = array_key_last( $grants );

		if ( 0 === $days ) {
			// La indefinida ocupa el nivel para siempre: el tiempo inferior espera sin fecha.
			foreach ( $grants as $index => $grant ) {
				if ( $index === $new_index || ! self::isLive( $grant, $now ) ) {
					continue;
				}
				if ( (int) ( $grant['rank'] ?? 0 ) >= $rank ) {
					continue;
				}
				$grant_start = $grant['starts_at'] ?? null;
				$grant_end   = $grant['ends_at'] ?? null;
				if ( ! $grant_start instanceof DateTimeImmutable || ! $grant_end instanceof DateTimeImmutable ) {
					continue;
				}
				$remaining = self::remainingSeconds( $grant, $now );
				if ( $remaining <= 0 ) {
					continue;
				}
				$grants[ $index ]['starts_at']      = null;
				$grants[ $index ]['ends_at']        = null;
				$grants[ $index ]['status']         = 'scheduled';
				$grants[ $index ]['queued_seconds'] = $remaining;
			}

			return array_values( $grants );
		}

		// La nueva es finita: reordena tras ella todo el tiempo inferior que quede.
		$lower = array();
		foreach ( $grants as $index => $grant ) {
			if ( $index === $new_index || ! self::isLive( $grant, $now ) ) {
				continue;
			}
			if ( (int) ( $grant['rank'] ?? 0 ) >= $rank ) {
				continue;
			}
			$grant_start = $grant['starts_at'] ?? null;
			$grant_end   = $grant['ends_at'] ?? null;
			if ( ! $grant_start instanceof DateTimeImmutable || ! $grant_end instanceof DateTimeImmutable ) {
				continue;
			}
			$remaining = self::remainingSeconds( $grant, $now );
			if ( $remaining <= 0 ) {
				continue;
			}
			$lower[] = array(
				'index'     => $index,
				'rank'      => (int) ( $grant['rank'] ?? 0 ),
				'remaining' => $remaining,
			);
		}

		usort(
			$lower,
			static function ( array $a, array $b ): int {
				return $b['rank'] <=> $a['rank'] ?: $a['index'] <=> $b['index'];
			}
		);

		$cursor = $end;
		foreach ( $lower as $item ) {
			$next_start = $cursor;
			$next_end   = $next_start->modify( '+' . $item['remaining'] . ' seconds' );

			$grants[ $item['index'] ]['starts_at']      = $next_start;
			$grants[ $item['index'] ]['ends_at']        = $next_end;
			$grants[ $item['index'] ]['status']         = $next_start <= $now ? 'active' : 'scheduled';
			$grants[ $item['index'] ]['queued_seconds'] = null;
			$cursor                                     = $next_end;
		}

		return array_values( $grants );
	}

	/**
	 * Concesión que da el nivel efectivo en `$at`: la de mayor rango que cubre ese instante.
	 * Cubrir es `starts_at <= $at` y (`ends_at` nulo o `$at < ends_at`). Las terminadas y
	 * revocadas no cuentan. En empate de rango gana la que empezó después.
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return array<string,mixed>|null
	 */
	public static function effective( array $grants, DateTimeImmutable $at ): ?array {
		$best = null;

		foreach ( $grants as $grant ) {
			$status = (string) ( $grant['status'] ?? '' );
			if ( in_array( $status, array( 'ended', 'revoked' ), true ) ) {
				continue;
			}

			$start = $grant['starts_at'] ?? null;
			if ( ! $start instanceof DateTimeImmutable || $at < $start ) {
				continue;
			}
			$end = $grant['ends_at'] ?? null;
			if ( $end instanceof DateTimeImmutable && $at >= $end ) {
				continue;
			}

			if ( null === $best ) {
				$best = $grant;
				continue;
			}

			$rank      = (int) ( $grant['rank'] ?? 0 );
			$best_rank = (int) ( $best['rank'] ?? 0 );
			if ( $rank > $best_rank ) {
				$best = $grant;
				continue;
			}
			if ( $rank < $best_rank ) {
				continue;
			}

			$best_start = $best['starts_at'];
			if ( $start > $best_start ) {
				$best = $grant;
				continue;
			}
			if ( $start < $best_start ) {
				continue;
			}

			if ( self::isLaterEnd( $end, $best['ends_at'] ?? null ) ) {
				$best = $grant;
			}
		}

		return $best;
	}

	/**
	 * Revoca una concesión por su clave. Una programada desaparece; una activa termina ahora.
	 * No recorre ni acorta las demás concesiones fechadas: solo vuelve a fechar la cola que
	 * estaba esperando sin fecha, si al quitar la indefinida deja de estar bloqueada.
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return list<array<string,mixed>>
	 */
	public static function revoke( array $grants, string $key, DateTimeImmutable $now ): array {
		$key = (string) $key;
		if ( '' === $key ) {
			return array_values( $grants );
		}

		$found = null;
		foreach ( $grants as $index => $grant ) {
			if ( (string) ( $grant['key'] ?? '' ) === $key ) {
				$found = $index;
				break;
			}
		}
		if ( null === $found ) {
			return array_values( $grants );
		}

		$status = (string) ( $grants[ $found ]['status'] ?? '' );
		if ( 'scheduled' === $status ) {
			unset( $grants[ $found ] );
			$grants = array_values( $grants );
		} elseif ( 'active' === $status ) {
			$grants[ $found ]['status']         = 'ended';
			$grants[ $found ]['ends_at']        = $now;
			$grants[ $found ]['queued_seconds'] = null;
		} else {
			return array_values( $grants );
		}

		return self::dateUndated( array_values( $grants ), $now );
	}

	/**
	 * Desplaza lo que aún no ha terminado: el fin de las activas y programadas y el inicio de
	 * las programadas. Las terminadas y revocadas no se tocan; las que esperan sin fecha
	 * siguen sin fecha (su tiempo pagado no corre mientras están bloqueadas).
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return list<array<string,mixed>>
	 */
	public static function shift( array $grants, int $seconds ): array {
		if ( 0 === $seconds ) {
			return array_values( $grants );
		}

		foreach ( $grants as $index => $grant ) {
			$status = (string) ( $grant['status'] ?? '' );
			if ( ! in_array( $status, array( 'active', 'scheduled' ), true ) ) {
				continue;
			}

			$start = $grant['starts_at'] ?? null;
			$end   = $grant['ends_at'] ?? null;

			if ( 'scheduled' === $status && $start instanceof DateTimeImmutable ) {
				$grants[ $index ]['starts_at'] = self::addSeconds( $start, $seconds );
			}
			if ( $end instanceof DateTimeImmutable ) {
				$grants[ $index ]['ends_at'] = self::addSeconds( $end, $seconds );
			}
		}

		return array_values( $grants );
	}

	/**
	 * Desplaza cada concesión por **su propio** tiempo congelado, en lugar de aplicar la misma
	 * duración a todas. `$frozenSince` mapea la clave de cada concesión al instante desde el que
	 * estuvo congelada: para una concesión que ya existía al congelar es `frozen_at`; para una
	 * creada durante la congelación es su `created_at`.
	 *
	 * Reglas:
	 *  · Una concesión que ya había empezado conserva su inicio y su fin se alarga sus segundos.
	 *  · Una programada con fecha futura se **reancla** al fin desplazado de la cobertura que la
	 *    bloqueaba (rango ≥), conservando su duración y el hueco que tuviera; si no tenía
	 *    cobertura delante, se desplaza por sus propios segundos. Así la cadena de renovaciones
	 *    sigue contigua y sin solapes aunque la concesión se creara a mitad de la congelación.
	 *  · Las que esperan sin fecha siguen sin fecha (su reloj no corre) y las terminadas o
	 *    revocadas no se tocan.
	 *
	 * @param list<array<string,mixed>>       $grants
	 * @param array<string,DateTimeImmutable> $frozenSince clave de la concesión => inicio de su congelación
	 * @return list<array<string,mixed>>
	 */
	public static function shiftSince( array $grants, array $frozenSince, DateTimeImmutable $until ): array {
		$grants    = array_values( $grants );
		$originals = $grants;
		$seconds   = array();

		foreach ( $grants as $index => $grant ) {
			$status = (string) ( $grant['status'] ?? '' );
			if ( ! in_array( $status, array( 'active', 'scheduled' ), true ) ) {
				continue;
			}

			$key   = (string) ( $grant['key'] ?? '' );
			$since = $frozenSince[ $key ] ?? null;
			$seconds[ $index ] = $since instanceof DateTimeImmutable
				? max( 0, $until->getTimestamp() - $since->getTimestamp() )
				: 0;

			$start = $grant['starts_at'] ?? null;
			$end   = $grant['ends_at'] ?? null;
			if ( $start instanceof DateTimeImmutable && $start <= $until && $end instanceof DateTimeImmutable ) {
				$grants[ $index ]['ends_at'] = self::addSeconds( $end, $seconds[ $index ] );
			}
		}

		$pending = array();
		foreach ( $grants as $index => $grant ) {
			$start = $grant['starts_at'] ?? null;
			$end   = $grant['ends_at'] ?? null;
			if ( ! in_array( (string) ( $grant['status'] ?? '' ), array( 'active', 'scheduled' ), true ) ) {
				continue;
			}
			if ( ! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable || $start <= $until ) {
				continue;
			}
			$pending[] = $index;
		}

		// Primero las que terminan antes: su fin desplazado es el ancla de las siguientes.
		usort(
			$pending,
			static function ( int $a, int $b ) use ( $originals ): int {
				$end_a = $originals[ $a ]['ends_at']->getTimestamp();
				$end_b = $originals[ $b ]['ends_at']->getTimestamp();
				if ( $end_a !== $end_b ) {
					return $end_a <=> $end_b;
				}
				return (int) ( $originals[ $b ]['rank'] ?? 0 ) <=> (int) ( $originals[ $a ]['rank'] ?? 0 ) ?: $a <=> $b;
			}
		);

		foreach ( $pending as $index ) {
			$original = $originals[ $index ];
			$start    = $original['starts_at'];
			$end      = $original['ends_at'];
			$duration = max( 0, $end->getTimestamp() - $start->getTimestamp() );
			$rank     = (int) ( $original['rank'] ?? 0 );

			$blocker     = null;
			$blocker_end = null;
			foreach ( $originals as $other => $candidate ) {
				if ( $other === $index ) {
					continue;
				}
				if ( ! in_array( (string) ( $candidate['status'] ?? '' ), array( 'active', 'scheduled' ), true ) ) {
					continue;
				}
				if ( (int) ( $candidate['rank'] ?? 0 ) < $rank ) {
					continue;
				}
				$other_start = $candidate['starts_at'] ?? null;
				$other_end   = $candidate['ends_at'] ?? null;
				if ( ! $other_start instanceof DateTimeImmutable || ! $other_end instanceof DateTimeImmutable ) {
					continue;
				}
				// Solo bloquea lo que termina en o antes de su inicio: un hermano posterior con
				// el mismo rango no es su antecesor en la cadena.
				if ( $other_end > $start ) {
					continue;
				}
				if ( null === $blocker_end || $other_end > $blocker_end ) {
					$blocker     = $other;
					$blocker_end = $other_end;
				}
			}

			if ( null === $blocker || null === $blocker_end ) {
				$new_start = self::addSeconds( $start, $seconds[ $index ] ?? 0 );
			} else {
				$shifted_end = $grants[ $blocker ]['ends_at'] ?? null;
				if ( ! $shifted_end instanceof DateTimeImmutable ) {
					continue;
				}
				$delta     = $shifted_end->getTimestamp() - $blocker_end->getTimestamp();
				$new_start = self::addSeconds( $start, $delta );
			}

			$grants[ $index ]['starts_at'] = $new_start;
			$grants[ $index ]['ends_at']   = self::addSeconds( $new_start, $duration );
		}

		return array_values( $grants );
	}

	/**
	 * Tiempo programado por nivel: solo las concesiones `scheduled` que aún no han empezado
	 * (o que esperan sin fecha), agrupadas por nivel y ordenadas por rango descendente.
	 *
	 * Cada entrada: `level_id` (int), `rank` (int), `seconds` (int, tiempo finito sumado),
	 * `days` (float, `seconds / 86400`), `starts_at` (?DateTimeImmutable, el inicio más
	 * temprano con fecha), `ends_at` (?DateTimeImmutable, el fin más tardío; `null` si hay
	 * alguna indefinida o sin fecha), `undated` (bool) e `infinite` (bool).
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return list<array<string,mixed>>
	 */
	public static function queue( array $grants, DateTimeImmutable $now ): array {
		$by_level = array();

		foreach ( $grants as $grant ) {
			if ( 'scheduled' !== (string) ( $grant['status'] ?? '' ) ) {
				continue;
			}

			$start = $grant['starts_at'] ?? null;
			if ( $start instanceof DateTimeImmutable && $start <= $now ) {
				// Su inicio ya llegó: por fechas no es cola, aunque el estado no se haya
				// materializado todavía (la Tarea 7 lo hará).
				continue;
			}

			$level_id = (int) ( $grant['level_id'] ?? 0 );
			if ( ! isset( $by_level[ $level_id ] ) ) {
				$by_level[ $level_id ] = array(
					'level_id' => $level_id,
					'rank'     => (int) ( $grant['rank'] ?? 0 ),
					'seconds'  => 0,
					'days'     => 0.0,
					'starts_at' => null,
					'ends_at'   => null,
					'undated'   => false,
					'infinite'  => false,
				);
			} else {
				$by_level[ $level_id ]['rank'] = max(
					(int) $by_level[ $level_id ]['rank'],
					(int) ( $grant['rank'] ?? 0 )
				);
			}

			$days = (int) ( $grant['days'] ?? 0 );
			$end  = $grant['ends_at'] ?? null;

			if ( ! $start instanceof DateTimeImmutable ) {
				$by_level[ $level_id ]['undated'] = true;
				if ( $days > 0 ) {
					$by_level[ $level_id ]['seconds'] += self::remainingSeconds( $grant, $now );
				} else {
					$by_level[ $level_id ]['infinite'] = true;
				}
				continue;
			}

			if ( null === $by_level[ $level_id ]['starts_at'] || $start < $by_level[ $level_id ]['starts_at'] ) {
				$by_level[ $level_id ]['starts_at'] = $start;
			}

			if ( $days <= 0 || ! $end instanceof DateTimeImmutable ) {
				$by_level[ $level_id ]['infinite'] = true;
				continue;
			}

			$by_level[ $level_id ]['seconds'] += max( 0, $end->getTimestamp() - $start->getTimestamp() );
			if ( null === $by_level[ $level_id ]['ends_at'] || $end > $by_level[ $level_id ]['ends_at'] ) {
				$by_level[ $level_id ]['ends_at'] = $end;
			}
		}

		$queue = array_values( $by_level );
		foreach ( $queue as $index => $entry ) {
			if ( $entry['undated'] || $entry['infinite'] ) {
				$queue[ $index ]['ends_at'] = null;
			}
			$queue[ $index ]['days'] = (float) ( $entry['seconds'] / self::DAY_SECONDS );
		}

		usort(
			$queue,
			static function ( array $a, array $b ): int {
				return $b['rank'] <=> $a['rank'] ?: $a['level_id'] <=> $b['level_id'];
			}
		);

		return $queue;
	}

	// ------------------------------------------------------------------------
	//  Utilidades internas
	// ------------------------------------------------------------------------

	/**
	 * Normaliza la concesión nueva y descarta las fechas/estado/cola que traiga: los calcula
	 * `place()`.
	 *
	 * @param array<string,mixed> $new
	 * @return array<string,mixed>
	 */
	private static function normalizeNew( array $new ): array {
		$grant = $new;

		$grant['key']            = is_scalar( $new['key'] ?? null ) ? (string) $new['key'] : '';
		$grant['level_id']       = (int) ( $new['level_id'] ?? 0 );
		$grant['rank']           = (int) ( $new['rank'] ?? 0 );
		$grant['days']           = max( 0, (int) ( $new['days'] ?? 0 ) );
		$grant['starts_at']      = null;
		$grant['ends_at']        = null;
		$grant['status']         = 'scheduled';
		$grant['queued_seconds'] = null;

		return $grant;
	}

	/** Una concesión cuenta para la línea de tiempo si no está terminada ni vencida por fechas. */
	private static function isLive( array $grant, DateTimeImmutable $now ): bool {
		$status = (string) ( $grant['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'scheduled' ), true ) ) {
			return false;
		}
		$end = $grant['ends_at'] ?? null;
		return ! $end instanceof DateTimeImmutable || $end > $now;
	}

	/**
	 * Segundos que le quedan a una concesión desde `$now` (o desde su inicio si aún no empezó).
	 * Para las que esperan sin fecha usa `queued_seconds`; si no está, los días pagados.
	 */
	private static function remainingSeconds( array $grant, DateTimeImmutable $now ): int {
		$start = $grant['starts_at'] ?? null;
		$end   = $grant['ends_at'] ?? null;

		if ( $start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable ) {
			$reference = $start > $now ? $start : $now;
			return max( 0, $end->getTimestamp() - $reference->getTimestamp() );
		}

		$queued = $grant['queued_seconds'] ?? null;
		if ( is_numeric( $queued ) && (int) $queued > 0 ) {
			return (int) $queued;
		}

		$days = max( 0, (int) ( $grant['days'] ?? 0 ) );
		return $days * self::DAY_SECONDS;
	}

	/**
	 * Vuelve a fechar la cola que estaba esperando sin fecha, en orden de rango descendente.
	 * Una concesión sigue esperando mientras haya una indefinida de rango ≥; en cuanto deja de
	 * estarlo, empieza en `$now` o al terminar la cobertura superior.
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return list<array<string,mixed>>
	 */
	private static function dateUndated( array $grants, DateTimeImmutable $now ): array {
		$pending = array();
		foreach ( $grants as $index => $grant ) {
			if ( 'scheduled' !== (string) ( $grant['status'] ?? '' ) ) {
				continue;
			}
			if ( ! ( $grant['starts_at'] ?? null ) instanceof DateTimeImmutable ) {
				$pending[] = $index;
			}
		}
		if ( array() === $pending ) {
			return array_values( $grants );
		}

		usort(
			$pending,
			static function ( int $a, int $b ) use ( $grants ): int {
				return (int) ( $grants[ $b ]['rank'] ?? 0 ) <=> (int) ( $grants[ $a ]['rank'] ?? 0 ) ?: $a <=> $b;
			}
		);

		foreach ( $pending as $index ) {
			$grant = $grants[ $index ];
			$rank  = (int) ( $grant['rank'] ?? 0 );

			$blocked_end = $now;
			$blocked     = false;
			foreach ( $grants as $other_index => $other ) {
				if ( $other_index === $index || ! self::isLive( $other, $now ) ) {
					continue;
				}
				if ( (int) ( $other['rank'] ?? 0 ) < $rank ) {
					continue;
				}
				if ( ! ( $other['starts_at'] ?? null ) instanceof DateTimeImmutable ) {
					continue;
				}
				$other_end = $other['ends_at'] ?? null;
				if ( ! $other_end instanceof DateTimeImmutable ) {
					$blocked = true;
					break;
				}
				if ( $other_end > $blocked_end ) {
					$blocked_end = $other_end;
				}
			}

			if ( $blocked ) {
				continue;
			}

			$days    = max( 0, (int) ( $grant['days'] ?? 0 ) );
			$seconds = self::remainingSeconds( $grant, $now );
			$start   = $blocked_end;

			$grants[ $index ]['starts_at']      = $start;
			$grants[ $index ]['ends_at']        = $days > 0 ? self::addSeconds( $start, $seconds ) : null;
			$grants[ $index ]['status']         = $start <= $now ? 'active' : 'scheduled';
			$grants[ $index ]['queued_seconds'] = null;
		}

		return array_values( $grants );
	}

	/** Suma (o resta) segundos conservando la zona UTC del objeto. */
	private static function addSeconds( DateTimeImmutable $at, int $seconds ): DateTimeImmutable {
		$sign = $seconds < 0 ? '-' : '+';
		return $at->modify( $sign . abs( $seconds ) . ' seconds' );
	}

	/** ¿`$a` termina después de `$b`? `null` es un fin indefinido (siempre después). */
	private static function isLaterEnd( ?DateTimeImmutable $a, ?DateTimeImmutable $b ): bool {
		if ( null === $a ) {
			return null !== $b;
		}
		if ( null === $b ) {
			return false;
		}
		return $a > $b;
	}
}
