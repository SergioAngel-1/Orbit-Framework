import "server-only";
import pino, { type Logger } from "pino";
import { currentRequestId } from "./request-context";

// ============================================================================
//  Logging estructurado (pino) — SOLO servidor.
//
//  Emite JSON por stdout (apto para agregadores: Loki, Datadog, CloudWatch…).
//  Nivel configurable con LOG_LEVEL (por defecto "info"; "debug" en desarrollo).
//  No registra secretos: pasa SIEMPRE objetos acotados como contexto.
// ============================================================================

const isProd = process.env.NODE_ENV === "production";

const globalForLogger = globalThis as unknown as { __hweLogger?: Logger };

export const logger: Logger =
  globalForLogger.__hweLogger ??
  pino({
    level: process.env.LOG_LEVEL ?? (isProd ? "info" : "debug"),
    base: { service: "hwe-frontend" },
    // En producción se emite JSON crudo; el formateo bonito (pino-pretty) se
    // deja a la herramienta de desarrollo para no acoplar dependencias al build.
    redact: {
      paths: [
        "*.authorization",
        "*.cookie",
        "*.password",
        "*.token",
        "req.headers.authorization",
        "req.headers.cookie",
      ],
      censor: "[redacted]",
    },
  });

if (!isProd) {
  globalForLogger.__hweLogger = logger;
}

/** Crea un logger hijo con contexto fijo (p. ej. el nombre de la ruta). */
export function childLogger(bindings: Record<string, unknown>): Logger {
  return logger.child(bindings);
}

/**
 * Logger hijo con el `requestId` del contexto actual (si lo hay), para
 * correlacionar todos los logs de una misma petición. Úsalo dentro de un
 * `runWithRequestId(...)`.
 */
export function requestLogger(extra: Record<string, unknown> = {}): Logger {
  const requestId = currentRequestId();
  return logger.child(requestId ? { requestId, ...extra } : extra);
}
