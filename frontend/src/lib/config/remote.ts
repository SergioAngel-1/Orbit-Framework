import "server-only";
import type { SiteConfig } from "./types";
import { CONFIG_DEFAULTS } from "./defaults";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Carga remota de configuración desde WordPress.
//  ISR con tag `site-config` → se invalida cuando el plugin guarda cambios
//  (WordPress envía POST a /api/revalidate con X-HWE-Signature).
// ============================================================================

const REVALIDATE_SECONDS = 300;

/** Deriva la URL base de WordPress sin el path /graphql. */
function getWpBase(): string {
  const raw =
    process.env.WORDPRESS_INTERNAL_API_URL ??
    process.env.NEXT_PUBLIC_WORDPRESS_API_URL ??
    "http://wordpress:80";
  return raw.replace(/\/graphql$/, "").replace(/\/$/, "");
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

/**
 * Obtiene la configuración efectiva del sitio.
 * Fuentes (en orden de prioridad):
 *   1. API REST de WordPress (/wp-json/hwe/v1/config) — con ISR 5 min
 *   2. CONFIG_DEFAULTS (código) — fallback cuando WP no es alcanzable
 *
 * Solo debe llamarse desde el servidor (Server Components, Route Handlers).
 */
export async function fetchRemoteConfig(): Promise<SiteConfig> {
  const url = `${getWpBase()}/wp-json/hwe/v1/config`;

  // El fallback mantiene la web en pie, pero es un incidente: marca, URL y
  // moneda degradan a los defaults de código. No se traga en silencio.
  const fallback = (reason: string): SiteConfig => {
    logger.error(
      { event: "site_config.fallback", url, reason },
      "No se pudo leer la config del sitio; sirviendo defaults de código",
    );
    return CONFIG_DEFAULTS;
  };

  try {
    const res = await fetch(url, {
      next: { revalidate: REVALIDATE_SECONDS, tags: ["site-config"] },
    });

    if (!res.ok) return fallback(`HTTP ${res.status}`);

    const data: unknown = await res.json();
    return deepMerge(CONFIG_DEFAULTS, data) as SiteConfig;
  } catch (error) {
    return fallback(
      error instanceof Error ? `${error.name}: ${error.message}` : "error",
    );
  }
}
