import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Token de la Conversions API de Meta y código de prueba, leídos de HWE
//  Control Center → Integraciones por el canal interno
//  (`GET /wp-json/hwe/v1/integrations/meta-capi`, `IntegrationsInternalApi.php`,
//  solo con `X-HWE-Internal-Secret`). El id del píxel NO va aquí: es público y
//  sale de `getSiteConfig().integrations.meta_pixel_id`.
//
//  Caché EN MEMORIA del proceso, igual que `lib/payments/credentials.ts` y por
//  lo mismo: nunca en la Data Cache de Next, que vive en Redis. Dura `TTL_MS`;
//  `/api/revalidate` la vacía al guardar Control Center, así que quitar el
//  código de prueba surte efecto al momento. Si WordPress no responde se usa
//  la última lectura buena.
// ============================================================================

export interface MetaCapiCredentials {
  accessToken: string;
  /** Solo durante las pruebas con la agencia; vacío en producción. */
  testEventCode: string;
}

const TTL_MS = 60_000;
const FETCH_TIMEOUT_MS = 5_000;

let cached: { value: MetaCapiCredentials; at: number } | null = null;

function str(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

export function parseMetaCapiCredentials(raw: unknown): MetaCapiCredentials {
  const payload = (raw && typeof raw === "object" ? raw : {}) as Record<
    string,
    unknown
  >;
  return {
    accessToken: str(payload.access_token),
    testEventCode: str(payload.test_event_code),
  };
}

export async function getMetaCapiCredentials(): Promise<MetaCapiCredentials> {
  if (cached && Date.now() - cached.at < TTL_MS) return cached.value;
  const secret = process.env.HWE_REVALIDATION_SECRET;
  const empty = { accessToken: "", testEventCode: "" };
  if (!secret) return cached?.value ?? empty;
  try {
    const res = await wpFetch("/wp-json/hwe/v1/integrations/meta-capi", {
      cache: "no-store",
      signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const value = parseMetaCapiCredentials(await res.json());
    cached = { value, at: Date.now() };
    return value;
  } catch (error) {
    logger.warn(
      {
        event: "analytics.meta_capi.credentials_unavailable",
        err: error instanceof Error ? error.message : error,
        stale: cached !== null,
      },
      "No se pudo leer el token de la Conversions API; se usa la última lectura",
    );
    return cached?.value ?? empty;
  }
}

/** Olvida la lectura en caché (al guardar Control Center, y en tests). */
export function clearMetaCapiCredentialsCache(): void {
  cached = null;
}
