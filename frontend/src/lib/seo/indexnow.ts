import "server-only";
import { routing } from "@/i18n/routing";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { logger } from "@/lib/observability/logger";
import { indexingBlockedByEnv } from "./indexing";
import { absoluteLocalized } from "./urls";

// ============================================================================
//  IndexNow: aviso inmediato a Bing, Yandex, Naver, Seznam… (Google no lo usa)
//  de que una URL ha cambiado, en vez de esperar a que la vuelvan a rastrear.
//
//  Se activa con `INDEXNOW_KEY` (variable de servidor; 8–128 caracteres
//  alfanuméricos o guiones). La clave se publica en `/indexnow.txt`, que es la
//  `keyLocation` que se declara al enviar (en la raíz: vale para todo el sitio).
//
//  El disparador es el webhook `product.*` de WooCommerce (`/api/revalidate`):
//  la ficha cambiada se anuncia en su URL del idioma por defecto. Las URLs se
//  juntan unos segundos antes de enviarlas, porque una importación masiva
//  dispara un webhook por producto y la API admite hasta 10.000 por envío.
//
//  Nunca en desarrollo, con la guarda `HWE_NOINDEX` ni con la config degradada
//  (anunciaría el dominio por defecto). Un fallo de la API solo se registra:
//  IndexNow es una mejora, el sitemap sigue siendo la fuente de verdad.
// ============================================================================

export const INDEXNOW_ENDPOINT = "https://api.indexnow.org/indexnow";
const BATCH_DELAY_MS = 10_000;
const MAX_URLS_PER_SUBMIT = 10_000;
const SUBMIT_TIMEOUT_MS = 5_000;

/** Clave válida del entorno, o `null` (IndexNow apagado). */
export function indexNowKey(
  env: Record<string, string | undefined> = process.env,
): string | null {
  const key = (env.INDEXNOW_KEY ?? "").trim();
  return /^[a-zA-Z0-9-]{8,128}$/.test(key) ? key : null;
}

/**
 * Ruta INTERNA de la ficha que cambió, a partir del cuerpo del webhook `product.*`
 * de WooCommerce. `null` si no trae un slug (p. ej. el ping de prueba al crear el
 * webhook). Un producto borrado o despublicado también se anuncia: así el buscador
 * descubre antes el 404.
 */
export function productPathFromWebhook(rawBody: string): string | null {
  try {
    const body = JSON.parse(rawBody) as { slug?: unknown };
    const slug = typeof body?.slug === "string" ? body.slug.trim() : "";
    return slug ? `/products/${encodeURIComponent(slug)}` : null;
  } catch {
    return null;
  }
}

export interface IndexNowSubmission {
  host: string;
  key: string;
  keyLocation: string;
  urlList: string[];
}

/** Envía un lote. Devuelve el estado HTTP (o 0 si no hubo respuesta). */
export async function submitIndexNow(
  submission: IndexNowSubmission,
  fetchImpl: typeof fetch = fetch,
): Promise<number> {
  try {
    const res = await fetchImpl(INDEXNOW_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify(submission),
      signal: AbortSignal.timeout(SUBMIT_TIMEOUT_MS),
    });
    // 200 = aceptado, 202 = aceptado pendiente de validar la clave.
    if (res.status === 200 || res.status === 202) {
      logger.info(
        {
          event: "indexnow.submitted",
          count: submission.urlList.length,
          status: res.status,
        },
        "IndexNow: URLs anunciadas",
      );
    } else {
      logger.warn(
        { event: "indexnow.rejected", status: res.status },
        "IndexNow rechazó el envío (¿clave o host incorrectos?)",
      );
    }
    return res.status;
  } catch (error) {
    logger.warn(
      { event: "indexnow.failed", err: error instanceof Error ? error.message : error },
      "IndexNow: no se pudo enviar",
    );
    return 0;
  }
}

// ── Cola en memoria (por proceso) ────────────────────────────────────────────

const pending = new Set<string>();
let timer: ReturnType<typeof setTimeout> | null = null;

async function flush(): Promise<void> {
  timer = null;
  const key = indexNowKey();
  const urls = [...pending].slice(0, MAX_URLS_PER_SUBMIT);
  pending.clear();
  if (!key || urls.length === 0) return;
  const config = await getSiteConfig();
  if (isFallbackSiteConfig(config)) return;
  const base = config.brand.url.replace(/\/$/, "");
  await submitIndexNow({
    host: new URL(base).host,
    key,
    keyLocation: `${base}/indexnow.txt`,
    urlList: urls,
  });
}

/**
 * Pone en cola la ficha del webhook. No espera al envío: se llama desde `after()`
 * en `/api/revalidate` y el lote sale `BATCH_DELAY_MS` después de la primera URL.
 */
export async function notifyProductChange(rawBody: string): Promise<void> {
  if (process.env.NODE_ENV !== "production" || indexingBlockedByEnv()) return;
  if (!indexNowKey()) return;
  const path = productPathFromWebhook(rawBody);
  if (!path) return;
  const config = await getSiteConfig();
  if (isFallbackSiteConfig(config)) return;
  pending.add(absoluteLocalized(config.brand.url, path, routing.defaultLocale));
  timer ??= setTimeout(() => void flush(), BATCH_DELAY_MS);
}

/** Solo para tests: envía ya lo que haya en cola. */
export async function flushIndexNowForTests(): Promise<void> {
  if (timer) clearTimeout(timer);
  await flush();
}
