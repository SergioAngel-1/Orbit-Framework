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
//  ahí acabarían los secretos). Dura `TTL_MS`; si WordPress no responde se usa
//  la última lectura buena y, sin ninguna, solo el entorno. `/api/revalidate`
//  la vacía al guardar el Control Center.
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

let cached: { payload: CmsPayload; at: number } | null = null;

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

/** Pasarela activa y credenciales vigentes (ver precedencia en la cabecera). */
export async function getPaymentSettings(): Promise<PaymentSettings> {
  if (cached && Date.now() - cached.at < TTL_MS) {
    return resolvePaymentSettings(cached.payload);
  }
  try {
    const payload = await fetchCmsPayload();
    if (payload) cached = { payload, at: Date.now() };
    return resolvePaymentSettings(payload ?? cached?.payload ?? null);
  } catch (error) {
    logger.warn(
      {
        event: "payments.credentials.unavailable",
        err: error instanceof Error ? error.message : String(error),
        stale: cached !== null,
      },
      "No se pudo leer la pasarela del CMS; se usa la última lectura o el entorno",
    );
    return resolvePaymentSettings(cached?.payload ?? null);
  }
}

/** Olvida la lectura en caché (al guardar el Control Center, y en tests). */
export function clearPaymentSettingsCache(): void {
  cached = null;
}
