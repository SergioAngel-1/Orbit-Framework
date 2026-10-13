import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Registro de los fallos de Redis que se degradan a propósito.
//
//  Rate-limit, anti-replay, locks, revocación y cachés siguen funcionando (con
//  menos garantías) cuando Redis no responde, pero un fallo así NUNCA es
//  silencioso: con Redis caído caerían una petición tras otra, así que se
//  registra como mucho una vez cada `THROTTLE_MS` por (ámbito, operación), con
//  el número de fallos que se omitieron desde el último aviso.
// ============================================================================

const THROTTLE_MS = 30_000;

const lastLogged = new Map<string, { at: number; suppressed: number }>();

export interface RedisFailureOptions {
  /** `error` para un control de seguridad que falla abierto; `warn` para una caché. */
  level?: "warn" | "error";
  /** Qué se pierde mientras tanto, para quien lee el log. */
  effect?: string;
}

export function logRedisFailure(
  scope: string,
  op: string,
  error: unknown,
  { level = "warn", effect }: RedisFailureOptions = {},
): void {
  const key = `${scope}:${op}`;
  const now = Date.now();
  const previous = lastLogged.get(key);
  if (previous && now - previous.at < THROTTLE_MS) {
    previous.suppressed += 1;
    return;
  }
  lastLogged.set(key, { at: now, suppressed: 0 });
  logger[level](
    {
      event: `${scope}.redis_error`,
      op,
      err: error instanceof Error ? error.message : String(error),
      ...(previous && previous.suppressed > 0
        ? { suppressed: previous.suppressed }
        : {}),
    },
    `Fallo de Redis en ${scope}${effect ? `: ${effect}` : ""}`,
  );
}

/** Solo para los tests: olvida los avisos ya emitidos. */
export function resetRedisFailureLog(): void {
  lastLogged.clear();
}
