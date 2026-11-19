import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Pasarela activa y credenciales de cobro, leídas de HWE Control Center.
//
//  `GET /wp-json/hwe/v1/payments/credentials` (PaymentsInternalApi.php, solo con
//  el secreto interno) devuelve la pasarela elegida y las credenciales de cada
//  una, con los secretos descifrados.
//
//  Precedencia:
//   - Pasarela: `PAYMENT_PROVIDER` del entorno SOLO como anulación explícita
//     (desarrollo, CI, emergencias) → la del panel → `noop` fuera de
//     producción. En producción sin ninguna de las dos, `null`: el cobro falla
//     con un error claro en vez de caer a la pasarela de pruebas.
//   - Cada credencial: la del panel si tiene valor; si no, su variable de
//     entorno (`AVALPAY_*`, `WOMPI_*`).
//
//  Caché EN MEMORIA del proceso, nunca en la Data Cache de Next (vive en Redis:
//  ahí acabarían los secretos). Dura `TTL_MS`; pasado ese plazo se sirve la copia
//  caducada al instante y se refresca en segundo plano (una sola lectura en vuelo
//  por proceso, compartida por las peticiones simultáneas), pero solo hasta
//  `STALE_MAX_MS`: una copia más vieja ya no se sirve sin antes esperar a
//  WordPress. Si WordPress no responde (o responde algo que no es la config) se
//  sigue con la última lectura buena, reintentando como mucho cada
//  `RETRY_AFTER_FAILURE_MS`, y, sin ninguna, solo con el entorno.
//  `/api/revalidate` la vacía al guardar el Control Center, y una lectura que ya
//  iba en vuelo antes de vaciarla no la vuelve a llenar con datos viejos.
// ============================================================================

export interface PaymentSettings {
  /** Pasarela con la que se cobra, o `null` si no hay ninguna decidida. */
  provider: string | null;
  avalpay: { login: string; secretKey: string; baseUrl: string };
  wompi: { publicKey: string; integritySecret: string; eventsSecret: string };
}

interface CmsPayload {
  provider?: unknown;
  avalpay?: Record<string, unknown>;
  wompi?: Record<string, unknown>;
}

const TTL_MS = 60_000;
const FETCH_TIMEOUT_MS = 5_000;
/** Con copia caducada y WordPress fallando, no se reintenta antes de esto. */
const RETRY_AFTER_FAILURE_MS = 10_000;
/**
 * Antigüedad máxima de la copia que se sirve sin esperar: pasado esto, una
 * pasarela o unas llaves cambiadas sin pasar por `/api/revalidate` no pueden
 * seguir cobrando con las de antes; se espera a WordPress.
 */
const STALE_MAX_MS = 10 * 60_000;

let cached: { payload: CmsPayload; at: number } | null = null;
/** Lectura del panel en vuelo: la comparten todas las peticiones del proceso. */
let inFlight: Promise<CmsPayload | null> | null = null;
/** Sube al vaciar la caché: una lectura anterior no debe repoblarla. */
let generation = 0;
/** Último fallo de un refresco en segundo plano (para no reintentar en bucle). */
let lastFailureAt = 0;

function str(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

/** Valor del panel si lo tiene; si no, el del entorno. */
function pick(cms: unknown, envName: string): string {
  return str(cms) || str(process.env[envName]);
}

/**
 * Combina lo que dice el panel con el entorno. Pura (salvo `process.env`),
 * para poder probar la precedencia sin red.
 */
export function resolvePaymentSettings(payload: CmsPayload | null): PaymentSettings {
  const override = str(process.env.PAYMENT_PROVIDER);
  const fromCms = str(payload?.provider);
  const provider =
    override || fromCms || (process.env.NODE_ENV === "production" ? null : "noop");

  return {
    provider,
    avalpay: {
      login: pick(payload?.avalpay?.login, "AVALPAY_LOGIN"),
      secretKey: pick(payload?.avalpay?.secret_key, "AVALPAY_SECRET_KEY"),
      baseUrl: pick(payload?.avalpay?.base_url, "AVALPAY_BASE_URL").replace(/\/+$/, ""),
    },
    wompi: {
      publicKey: pick(payload?.wompi?.public_key, "WOMPI_PUBLIC_KEY"),
      integritySecret: pick(payload?.wompi?.integrity_secret, "WOMPI_INTEGRITY_SECRET"),
      eventsSecret: pick(payload?.wompi?.events_secret, "WOMPI_EVENTS_SECRET"),
    },
  };
}

async function fetchCmsPayload(): Promise<CmsPayload | null> {
  if (!process.env.HWE_REVALIDATION_SECRET) return null;
  const res = await wpFetch("/wp-json/hwe/v1/payments/credentials", {
    cache: "no-store",
    signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data: unknown = await res.json();
  return data && typeof data === "object" ? (data as CmsPayload) : null;
}

/**
 * Lee el panel una sola vez aunque la pidan varias peticiones a la vez. El
 * resultado solo entra en la caché si nadie la vació mientras tanto. Rechaza si
 * WordPress falla (el llamador decide qué servir).
 */
function readCmsPayloadOnce(): Promise<CmsPayload | null> {
  if (inFlight) return inFlight;
  const startedAt = generation;
  const read = fetchCmsPayload()
    .then((payload) => {
      if (payload && generation === startedAt) {
        cached = { payload, at: Date.now() };
      }
      return payload;
    })
    .finally(() => {
      if (inFlight === read) inFlight = null;
    });
  inFlight = read;
  return read;
}

function logUnavailable(error: unknown): void {
  logger.warn(
    {
      event: "payments.credentials.unavailable",
      err: error instanceof Error ? error.message : String(error),
      stale: cached !== null,
    },
    "No se pudo leer la pasarela del CMS; se usa la última lectura o el entorno",
  );
}

/**
 * Refresca la copia. Un fallo —lanzar, o devolver algo que no es la config— se
 * anota para espaciar el siguiente intento. Nunca rechaza.
 */
async function refreshCopy(): Promise<void> {
  try {
    const payload = await readCmsPayloadOnce();
    if (!payload) {
      lastFailureAt = Date.now();
      logUnavailable(new Error("respuesta sin config de pagos"));
    }
  } catch (error) {
    lastFailureAt = Date.now();
    logUnavailable(error);
  }
}

/** Pasarela activa y credenciales vigentes (ver precedencia en la cabecera). */
export async function getPaymentSettings(): Promise<PaymentSettings> {
  if (cached) {
    const copy = cached;
    const now = Date.now();
    const age = now - cached.at;
    const mayRetry = now - lastFailureAt >= RETRY_AFTER_FAILURE_MS;
    if (age >= STALE_MAX_MS && (inFlight || mayRetry)) {
      // Demasiado vieja para servirla sin preguntar: se espera a WordPress. Si falla,
      // la última lectura buena sigue valiendo (y el reintento se espacia).
      await refreshCopy();
    } else if (age >= TTL_MS && !inFlight && mayRetry) {
      // Copia caducada: se sirve ya y se refresca detrás, sin hacer esperar el cobro.
      void refreshCopy();
    }
    return resolvePaymentSettings((cached ?? copy).payload);
  }
  // Sin copia en memoria: hay que esperar a WordPress (compartiendo la lectura).
  // Si falla, solo queda el entorno.
  try {
    return resolvePaymentSettings(await readCmsPayloadOnce());
  } catch (error) {
    logUnavailable(error);
    return resolvePaymentSettings(null);
  }
}

/** Olvida la lectura en caché (al guardar el Control Center, y en tests). */
export function clearPaymentSettingsCache(): void {
  cached = null;
  inFlight = null;
  lastFailureAt = 0;
  generation += 1;
}
