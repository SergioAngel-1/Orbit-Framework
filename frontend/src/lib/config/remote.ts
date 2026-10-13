import "server-only";
import type { SiteConfig } from "./types";
import { CONFIG_DEFAULTS } from "./defaults";
import { logger } from "@/lib/observability/logger";
import { wpBaseUrl, wpFetch } from "@/lib/wordpress/internal-fetch";

// ============================================================================
//  Carga remota de configuración desde WordPress.
//  ISR con tag `site-config` → se invalida cuando el plugin guarda cambios
//  (WordPress envía POST a /api/revalidate con X-HWE-Signature).
//
//  Esta es la lectura MÁS crítica de la web: de ella salen la marca, la moneda,
//  los flags de tienda y —sobre todo— `brand.url`, que alimenta canónicas,
//  hreflang, JSON-LD, sitemap y la línea `Sitemap:` de robots.txt. Por eso aquí
//  no basta con «degradar y seguir»:
//
//   1. TIMEOUT por intento: un WordPress que acepta y no responde colgaba el
//      render.
//   2. REINTENTOS ante fallos transitorios: un solo hipo de WordPress bastaba
//      para caer al fallback y hornear una ventana de ISR de páginas con
//      `brand.url` apuntando al valor por defecto del código.
//   3. El fallback se LOGUEA y se MARCA (`isFallbackSiteConfig`) para que quien
//      publica URLs absolutas pueda callarse en vez de publicar una falsa.
// ============================================================================

const REVALIDATE_SECONDS = 300;
const MAX_ATTEMPTS = 3;

/**
 * Timeout del fetch de config. 5 s es lo correcto SIRVIENDO: si WordPress no
 * contesta en ese plazo, más vale degradar que dejar colgada la petición de un
 * visitante.
 *
 * CONSTRUYENDO es otra historia, y por eso se puede subir con
 * `CONFIG_TIMEOUT_MS`. Una petición abortada por timeout NO entra en el Data
 * Cache de Next: si WordPress tarda más que el plazo no falla una página, fallan
 * TODAS, y cada una vuelve a pagar los 3 intentos antes de caer al fallback sin
 * guardar nada para la siguiente. Con un plazo que sí alcance, la primera página
 * paga la espera, el resultado se cachea y las demás pagan cero. Además, cada
 * página generada durante una ventana de fallback hornea la marca y la paleta de
 * respaldo del CÓDIGO, no las del CMS.
 */
const DEFAULT_TIMEOUT_MS = 5_000;

/**
 * Exportada para poder fijarla en tests: `AbortSignal.timeout()` es nativo y no
 * pasa por `setTimeout`, así que los temporizadores falsos de Vitest no lo ven.
 */
export function configTimeoutMs(): number {
  const raw = Number(process.env.CONFIG_TIMEOUT_MS);
  return Number.isFinite(raw) && raw > 0 ? raw : DEFAULT_TIMEOUT_MS;
}

/**
 * Marca que distingue «config real de WordPress» de «fallback en código».
 * `Symbol.for` (registro global) para que sobreviva a los límites de módulo que
 * Next puede duplicar entre runtimes.
 */
const FALLBACK_MARK = Symbol.for("hwe.site-config.fallback");

/**
 * ¿Esta config es el fallback de código porque WordPress no respondió?
 *
 * Úsalo antes de PUBLICAR una URL absoluta derivada de `config.brand.url`
 * (sitemap, `Sitemap:` de robots, `llms.txt`): con el fallback esa URL es el
 * valor por defecto del código, y servirla es peor que no servir nada — un
 * sitemap con el dominio equivocado envenena la indexación durante toda la
 * ventana ISR.
 */
export function isFallbackSiteConfig(config: SiteConfig): boolean {
  return (config as unknown as Record<PropertyKey, unknown>)[FALLBACK_MARK] === true;
}

/** Copia de los defaults marcada como fallback (nunca muta `CONFIG_DEFAULTS`). */
function fallbackConfig(): SiteConfig {
  return Object.defineProperty({ ...CONFIG_DEFAULTS }, FALLBACK_MARK, {
    value: true,
    enumerable: false,
  }) as SiteConfig;
}

type Kind = "object" | "array" | "scalar";

function kindOf(value: unknown): Kind {
  if (Array.isArray(value)) return "array";
  return value !== null && typeof value === "object" ? "object" : "scalar";
}

/**
 * Fusión profunda: base proporciona los defaults, override los reemplaza.
 *
 *  - Las hojas (escalares y arrays) las gana el override. Antes se devolvía
 *    `base` ante cualquier hoja escalar, lo que descartaba TODA la config remota
 *    sin ningún error: marca, paleta, moneda y flags nunca llegaban al sitio.
 *  - `null`/`undefined` en el override conservan el default.
 *  - Los objetos se fusionan en profundidad. Una clave que los defaults no
 *    conocen se adopta tal cual.
 *  - Si la FORMA no coincide (objeto frente a array/escalar), manda el default y
 *    se registra: el contenido falla de forma suave. Caso real: PHP serializa un
 *    array vacío como `[]` y no como `{}`, así que un campo tipo mapa que llega
 *    vacío no debe destruir el objeto por defecto.
 *
 * Exportada solo para poder probarla directamente.
 */
export function deepMerge(base: unknown, override: unknown, path = ""): unknown {
  if (override === null || override === undefined) return base;
  if (base === undefined) return override;

  const kind = kindOf(override);
  if (kindOf(base) !== kind) {
    logger.warn(
      {
        event: "site_config.shape_mismatch",
        path,
        expected: kindOf(base),
        received: kind,
      },
      "La config remota no tiene la forma esperada; se conserva el valor por defecto",
    );
    return base;
  }
  // Escalar o array: el remoto reemplaza al default.
  if (kind !== "object") return override;

  const b = base as Record<string, unknown>;
  const o = override as Record<string, unknown>;
  const result: Record<string, unknown> = { ...b };

  for (const key of Object.keys(o)) {
    result[key] = deepMerge(b[key], o[key], path ? `${path}.${key}` : key);
  }
  return result;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

type Attempt =
  { ok: true; data: unknown } | { ok: false; reason: string; retryable: boolean };

async function attempt(path: string): Promise<Attempt> {
  try {
    const res = await wpFetch(path, {
      next: { revalidate: REVALIDATE_SECONDS, tags: ["site-config"] },
      signal: AbortSignal.timeout(configTimeoutMs()),
    });

    if (!res.ok) {
      // 5xx = WordPress con problemas (reintentable). 4xx = plugin desactivado
      // o ruta ausente: determinista, reintentarlo solo alarga el render.
      return { ok: false, reason: `HTTP ${res.status}`, retryable: res.status >= 500 };
    }

    return { ok: true, data: (await res.json()) as unknown };
  } catch (error) {
    return {
      ok: false,
      reason: error instanceof Error ? `${error.name}: ${error.message}` : "error",
      retryable: true,
    };
  }
}

/**
 * Obtiene la configuración efectiva del sitio.
 * Fuentes (en orden de prioridad):
 *   1. API REST de WordPress (/wp-json/hwe/v1/config) — con ISR
 *   2. CONFIG_DEFAULTS (código) — fallback marcado cuando WP no es alcanzable
 *
 * Solo debe llamarse desde el servidor (Server Components, Route Handlers).
 */
export async function fetchRemoteConfig(): Promise<SiteConfig> {
  const path = "/wp-json/hwe/v1/config";
  let lastReason = "desconocido";

  for (let i = 0; i < MAX_ATTEMPTS; i++) {
    const result = await attempt(path);

    if (result.ok) {
      if (i > 0) {
        logger.warn(
          { event: "site_config.recovered", attempts: i + 1 },
          "Config del sitio recuperada tras reintentar",
        );
      }
      return deepMerge(CONFIG_DEFAULTS, result.data) as SiteConfig;
    }

    lastReason = result.reason;
    if (!result.retryable || i === MAX_ATTEMPTS - 1) break;
    await sleep(250 * 2 ** i); // 250 ms, 500 ms
  }

  // El fallback mantiene la web en pie, pero es un incidente: marca, URL y
  // moneda degradan a los defaults de código. No se traga en silencio.
  logger.error(
    {
      event: "site_config.fallback",
      url: `${wpBaseUrl()}${path}`,
      reason: lastReason,
    },
    "No se pudo leer la config del sitio; sirviendo defaults de código (marca/URL/moneda degradadas)",
  );
  return fallbackConfig();
}
