import "server-only";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Fetch con reintentos para las lecturas del CMS.
//
//  Durante `next build` se prerenderizan cientos de páginas en paralelo y
//  WordPress se satura: algunas peticiones fallan por red. Como las lecturas de
//  contenido son fail-soft (devuelven vacío en vez de romper), ese fallo se
//  hornearía en el HTML estático sin dejar rastro — la home saldría publicada
//  sin hero y el build en verde.
//
//  Reintenta solo lo que puede ser transitorio: errores de red y 5xx. Un 4xx o
//  una respuesta correcta se devuelven tal cual (reintentarlos solo alarga el
//  build). Solo para LECTURAS: una escritura no es idempotente.
// ============================================================================

export interface FetchRetryOptions {
  /** Intentos totales, incluido el primero. */
  attempts?: number;
  /** Espera base; crece exponencialmente (250, 500, 1000…). */
  baseDelayMs?: number;
  /** Etiqueta para los logs (p. ej. "banners"). */
  label?: string;
}

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * `request` es la petición a repetir: una URL con su `init`, o una función que
 * la hace (p. ej. `() => wpFetch(path, init)`, que añade el secreto interno).
 * Lanza el último error si se agotan los intentos.
 */
export async function fetchWithRetry(
  request: string | (() => Promise<Response>),
  init?: RequestInit,
  options: FetchRetryOptions = {},
): Promise<Response> {
  const attempts = Math.max(1, options.attempts ?? 3);
  const baseDelayMs = options.baseDelayMs ?? 250;
  const label = options.label ?? "fetch";
  const send = typeof request === "string" ? () => fetch(request, init) : request;

  let lastError: unknown = null;

  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const res = await send();
      if (res.status < 500) return res;
      lastError = new Error(`HTTP ${res.status}`);
    } catch (error) {
      lastError = error;
    }

    if (attempt < attempts) {
      logger.warn(
        {
          event: "fetch.retry",
          label,
          attempt,
          attempts,
          err: lastError instanceof Error ? lastError.message : lastError,
        },
        "Lectura del CMS fallida; reintentando",
      );
      await sleep(baseDelayMs * 2 ** (attempt - 1));
    }
  }

  logger.error(
    {
      event: "fetch.retry.exhausted",
      label,
      attempts,
      err: lastError instanceof Error ? lastError.message : lastError,
    },
    "Lectura del CMS agotó los reintentos: el contenido saldrá vacío",
  );
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}
