import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Lecturas del CMS que fallan «de forma suave».
//
//  El contenido cae a un valor por defecto (lista vacía, sin banner, sin
//  recomendaciones) en vez de romper la página, pero el fallo NUNCA es silencioso
//  (spec §2): queda en el log con un evento estructurado. `error` para lo que
//  vacía algo estructural (listas de URLs para el sitemap y el prerender,
//  categorías, banners); `warn` para un adorno opcional (destacado, franjas de
//  productos).
// ============================================================================

export function logReadFailure(
  event: string,
  error: unknown,
  options: {
    level?: "warn" | "error";
    message?: string;
    context?: Record<string, unknown>;
  } = {},
): void {
  const {
    level = "error",
    message = "Lectura del CMS fallida; se sirve el valor por defecto",
    context,
  } = options;
  logger[level](
    {
      event,
      ...context,
      err: error instanceof Error ? error.message : String(error),
    },
    message,
  );
}
