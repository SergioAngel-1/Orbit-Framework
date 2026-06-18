import "server-only";
import type { SiteConfig } from "./types";
import { CONFIG_DEFAULTS } from "./defaults";

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

/**
 * Fusión profunda: base proporciona los defaults, override los reemplaza.
 * Omite valores null/undefined del override.
 */
function deepMerge(base: unknown, override: unknown): unknown {
  if (!base || typeof base !== "object" || Array.isArray(base)) return base;
  if (!override || typeof override !== "object" || Array.isArray(override)) return base;

  const b = base as Record<string, unknown>;
  const o = override as Record<string, unknown>;
  const result: Record<string, unknown> = { ...b };

  for (const key of Object.keys(o)) {
    const ov = o[key];
    if (ov !== null && ov !== undefined) {
      result[key] = deepMerge(b[key], ov);
    }
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

  try {
    const res = await fetch(url, {
      next: { revalidate: REVALIDATE_SECONDS, tags: ["site-config"] },
    });

    if (!res.ok) {
      return CONFIG_DEFAULTS;
    }

    const data: unknown = await res.json();
    return deepMerge(CONFIG_DEFAULTS, data) as SiteConfig;
  } catch {
    return CONFIG_DEFAULTS;
  }
}
