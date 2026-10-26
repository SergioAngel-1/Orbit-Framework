import { instanceRegistry } from "@/instance/registry";
import { logger } from "@/lib/observability/logger";
import type { InstanceSitemap, SitemapChangeFrequency } from "./types";

// ============================================================================
//  Sitemap de la instancia: páginas propias y exclusiones, declaradas SOLO con
//  datos en `InstanceRegistry.sitemap`.
//
//  - `pages`: rutas INTERNAS (formato de `pathnames`) que se añaden al segmento
//    `paginas` del sitemap, expandidas a todos los idiomas por `entries.ts` (la
//    URL pública sale de `localizedPath`, así que `publicPaths` sigue mandando).
//  - `exclude`: rutas INTERNAS que no se anuncian (sitemap y `llms.txt`), exactas
//    (`"/legal/privacy"`) o un prefijo con `/*` (`"/products/*"`).
//
//  Módulo PURO con el mapa inyectable (como `public-paths.ts`): los tests no
//  dependen del registro real. La validación es fail-soft —el contenido falla de
//  forma suave—: una entrada mal formada se descarta y queda un evento en el log,
//  nunca rompe la generación del sitemap.
// ============================================================================

/** Frecuencias válidas del protocolo de sitemaps. */
const CHANGE_FREQUENCIES: readonly SitemapChangeFrequency[] = [
  "always",
  "hourly",
  "daily",
  "weekly",
  "monthly",
  "yearly",
  "never",
];

const DEFAULT_CHANGE_FREQUENCY: SitemapChangeFrequency = "monthly";
const DEFAULT_PRIORITY = 0.5;

/** Una página propia ya normalizada. */
export interface InstanceSitemapPageEntry {
  /** Ruta interna, sin prefijo de idioma. */
  path: string;
  changeFrequency: SitemapChangeFrequency;
  priority: number;
}

/** Reglas resueltas del registro. */
export interface InstanceSitemapRules {
  pages: InstanceSitemapPageEntry[];
  /** Rutas exactas que no se anuncian. */
  exact: string[];
  /** Prefijos (sin `/*`): se excluye el prefijo y todo lo que cuelga de él. */
  prefixes: string[];
}

function warnInvalid(reason: string, entry: unknown): void {
  logger.warn(
    { event: "instance.sitemap.invalid_entry", reason, entry },
    "sitemap: entrada del registro de la instancia descartada",
  );
}

/**
 * Valida una ruta interna. Devuelve la ruta recortada o `null` (y ya deja el
 * evento). `allowWildcard` acepta el sufijo `/*` de las exclusiones de prefijo.
 */
function normalizeRoute(
  raw: unknown,
  label: string,
  allowWildcard: boolean,
): string | null {
  if (typeof raw !== "string") {
    warnInvalid(`${label}: no es texto`, raw);
    return null;
  }
  const value = raw.trim();
  const prefix = allowWildcard && value.endsWith("/*") ? value.slice(0, -2) : value;
  if (!prefix.startsWith("/")) {
    warnInvalid(`${label}: debe empezar por «/»`, raw);
    return null;
  }
  if (prefix === "/") {
    // La home se puede excluir; como página propia no tiene sentido (ya está).
    return allowWildcard ? "/" : null;
  }
  if (prefix.endsWith("/")) {
    warnInvalid(`${label}: no puede acabar en «/»`, raw);
    return null;
  }
  if (prefix.includes("//") || prefix.includes("?") || prefix.includes("#")) {
    warnInvalid(`${label}: no admite «//», consulta ni ancla`, raw);
    return null;
  }
  if (prefix.includes("*") || /[[\]]/.test(prefix)) {
    warnInvalid(`${label}: una ruta del sitemap no puede ser dinámica`, raw);
    return null;
  }
  return value;
}

function normalizePage(entry: unknown): InstanceSitemapPageEntry | null {
  if (typeof entry !== "object" || entry === null || Array.isArray(entry)) {
    warnInvalid("página: no es un objeto", entry);
    return null;
  }
  const raw = entry as {
    path?: unknown;
    changeFrequency?: unknown;
    priority?: unknown;
  };
  if (raw.path === "/") {
    warnInvalid("página: la home ya está en el núcleo", entry);
    return null;
  }
  const path = normalizeRoute(raw.path, "página", false);
  if (path === null) return null;

  let changeFrequency = DEFAULT_CHANGE_FREQUENCY;
  if (raw.changeFrequency !== undefined) {
    if (
      typeof raw.changeFrequency !== "string" ||
      !CHANGE_FREQUENCIES.includes(raw.changeFrequency as SitemapChangeFrequency)
    ) {
      warnInvalid("página: frecuencia de cambio desconocida", entry);
      return null;
    }
    changeFrequency = raw.changeFrequency as SitemapChangeFrequency;
  }

  let priority = DEFAULT_PRIORITY;
  if (raw.priority !== undefined) {
    if (
      typeof raw.priority !== "number" ||
      !Number.isFinite(raw.priority) ||
      raw.priority < 0 ||
      raw.priority > 1
    ) {
      warnInvalid("página: prioridad fuera del rango 0–1", entry);
      return null;
    }
    priority = raw.priority;
  }

  return { path, changeFrequency, priority };
}

/**
 * Resuelve el campo `sitemap` del registro (por defecto, el de la instancia).
 * Un valor que no sea un objeto se ignora con su evento.
 */
export function resolveInstanceSitemap(
  map: InstanceSitemap | undefined = instanceRegistry.sitemap,
): InstanceSitemapRules {
  const rules: InstanceSitemapRules = { pages: [], exact: [], prefixes: [] };
  if (map === undefined || map === null) return rules;
  if (typeof map !== "object" || Array.isArray(map)) {
    warnInvalid("sitemap: el campo debe ser un objeto", map);
    return rules;
  }

  // Un campo que no sea lista se ignora entero (con su evento) en vez de lanzar:
  // el registro es dato de instancia y la generación del sitemap nunca se rompe.
  const pages = Array.isArray(map.pages) ? map.pages : [];
  if (map.pages !== undefined && !Array.isArray(map.pages)) {
    warnInvalid("páginas: el campo debe ser una lista", map.pages);
  }
  for (const entry of pages) {
    const page = normalizePage(entry);
    if (page) rules.pages.push(page);
  }

  const exclude = Array.isArray(map.exclude) ? map.exclude : [];
  if (map.exclude !== undefined && !Array.isArray(map.exclude)) {
    warnInvalid("exclusiones: el campo debe ser una lista", map.exclude);
  }
  for (const entry of exclude) {
    const isPrefix = typeof entry === "string" && entry.trim().endsWith("/*");
    const route = normalizeRoute(entry, "exclusión", true);
    if (route === null) continue;
    if (isPrefix) {
      const base = route.slice(0, -2);
      if (base === "") {
        warnInvalid("exclusión: el prefijo no puede ser la raíz", entry);
        continue;
      }
      rules.prefixes.push(base);
    } else {
      rules.exact.push(route);
    }
  }

  return rules;
}

/**
 * ¿Esta ruta INTERNA no se anuncia? La comparación es contra la ruta interna (antes de
 * expandir por idioma): exacta o bajo un prefijo declarado con `/*`.
 */
export function isSitemapPathExcluded(
  path: string,
  rules: InstanceSitemapRules,
): boolean {
  if (rules.exact.includes(path)) return true;
  return rules.prefixes.some(
    (prefix) => path === prefix || path.startsWith(`${prefix}/`),
  );
}
