// ============================================================================
//  Instrumentación de Next.js (observabilidad).
//
//  - `register()` corre una vez al arrancar el servidor: punto de inicialización
//    para un SDK de observabilidad (p. ej. Sentry: `Sentry.init({ dsn })`).
//  - `onRequestError` captura errores no controlados de Server Components y
//    Route Handlers y los registra de forma estructurada (pino). Es el lugar
//    natural para reenviarlos a Sentry/Datadog.
//
//  Mantener este archivo sin dependencias pesadas: el logger es server-only y
//  el SDK de errores se cablea aquí cuando el cliente lo configure (SENTRY_DSN).
// ============================================================================

export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    // Guard de arranque: aborta en producción con secretos por defecto/cortos.
    const { assertSecrets } = await import("@/lib/security/secret-guard");
    assertSecrets();

    const { logger } = await import("@/lib/observability/logger");
    logger.info({ event: "server_start" }, "Frontend iniciado");
  }
}

export async function onRequestError(
  error: unknown,
  request: { path?: string; method?: string },
) {
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  const { logger } = await import("@/lib/observability/logger");
  logger.error(
    {
      event: "request_error",
      path: request?.path,
      method: request?.method,
      err: error instanceof Error ? { message: error.message, name: error.name } : error,
    },
    "Error no controlado en una petición",
  );
  if (process.env.SENTRY_DSN) {
    try {
      const Sentry = await import("@sentry/nextjs");
      Sentry.captureException(error, { extra: { path: request?.path, method: request?.method } });
    } catch {
      // Si Sentry falla, no bloquear la respuesta.
    }
  }
}
