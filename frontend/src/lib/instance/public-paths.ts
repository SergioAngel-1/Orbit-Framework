import { instanceRegistry } from "@/instance/registry";
import type { PublicPathMap, PublicPathValue } from "./types";

// ============================================================================
//  Rutas PÚBLICAS de la instancia, resueltas en un solo sitio.
//
//  El núcleo escribe sus rutas en inglés (`/products`, `/account`, `/checkout/return`…)
//  porque son los nombres de las carpetas de `app/[locale]/`. Una instancia puede servir
//  esas mismas vistas con otros tramos (p. ej. en español) declarando SOLO datos en
//  `InstanceRegistry.publicPaths`; este módulo traduce en los dos sentidos:
//
//    - `toPublicPath("/products/vela", "es")`  → `"/productos/vela"`
//    - `toInternalPath("/productos/vela", "es")` → `"/products/vela"`
//
//  Reglas:
//  - La clave es la ruta INTERNA con el formato de `pathnames` de next-intl
//    (`/products/[slug]`), o un prefijo suyo (`/products`). Un hijo sin entrada propia
//    hereda el prefijo del padre; una entrada propia (la clave más larga) manda.
//  - El valor es el tramo público, igual para todos los idiomas o por idioma. Un idioma
//    sin entrada conserva el tramo interno.
//  - Sin mapa, las dos funciones son la identidad: el framework no cambia ninguna URL.
//
//  Es un módulo PURO a propósito (ni next-intl ni servidor): lo usan `localizedPath`,
//  la navegación, el proxy, robots, llms.txt, banners y el blog, también desde el
//  navegador. La consulta (`?…`) y el ancla (`#…`) se conservan tal cual.
// ============================================================================

/** Segmento dinámico (`[slug]`, `[...rest]`). */
const PARAM_SEGMENT = /^\[\[?\.\.\.[^\]]+\]\]?$|^\[[^\]]+\]$/;

interface Entry {
  internal: string;
  internalSegments: string[];
  value: PublicPathValue;
}

interface Mapping {
  from: string[];
  to: string[];
}

/** Separa `ruta?consulta#ancla` en la ruta y el sufijo literal. */
function splitSuffix(path: string): { path: string; suffix: string } {
  const cut = path.search(/[?#]/);
  if (cut === -1) return { path, suffix: "" };
  return { path: path.slice(0, cut), suffix: path.slice(cut) };
}

function segmentsOf(path: string): string[] {
  return path === "/" || path === "" ? [] : path.split("/").slice(1);
}

function normalizeRoute(raw: string, label: string): string {
  const value = raw.trim();
  if (!value.startsWith("/")) {
    throw new Error(`[hwe] rutas públicas: ${label} debe empezar por «/» («${raw}»).`);
  }
  if (value === "/") {
    throw new Error(
      `[hwe] rutas públicas: ${label} no puede ser la home; remapea rutas hijas.`,
    );
  }
  if (value.endsWith("/")) {
    throw new Error(
      `[hwe] rutas públicas: ${label} no puede acabar en «/» («${raw}»).`,
    );
  }
  if (value.includes("//") || value.includes("?") || value.includes("#")) {
    throw new Error(
      `[hwe] rutas públicas: ${label} no admite «//», consulta ni ancla («${raw}»).`,
    );
  }
  return value;
}

/** Entradas del mapa, ya normalizadas. Falla con un mensaje claro si una ruta no vale. */
export function publicPathEntries(map: PublicPathMap = {}): Entry[] {
  const entries: Entry[] = [];
  for (const [rawKey, value] of Object.entries(map)) {
    const internal = normalizeRoute(rawKey, `la ruta interna «${rawKey}»`);
    if (typeof value === "string") {
      normalizeRoute(value, `la ruta pública de «${rawKey}»`);
    } else if (value && typeof value === "object") {
      for (const [locale, localized] of Object.entries(value)) {
        if (typeof localized !== "string") {
          throw new Error(
            `[hwe] rutas públicas: «${rawKey}» tiene un valor no textual para «${locale}».`,
          );
        }
        normalizeRoute(localized, `la ruta pública de «${rawKey}» en «${locale}»`);
      }
    } else {
      throw new Error(
        `[hwe] rutas públicas: «${rawKey}» debe ser una ruta o un mapa por idioma.`,
      );
    }
    entries.push({
      internal,
      internalSegments: segmentsOf(internal),
      value,
    });
  }
  return entries;
}

/** Valores aplicables de una entrada (uno por idioma o el compartido). */
function valuesFor(value: PublicPathValue, locale?: string): string[] {
  if (typeof value === "string") return [value];
  if (locale !== undefined) {
    const localized = value[locale];
    return typeof localized === "string" ? [localized] : [];
  }
  return [...new Set(Object.values(value))];
}

function mappingsFor(
  entries: readonly Entry[],
  direction: "public" | "internal",
  locale?: string,
): Mapping[] {
  const mappings: Mapping[] = [];
  for (const entry of entries) {
    for (const value of valuesFor(entry.value, locale)) {
      const publicSegments = segmentsOf(value);
      mappings.push(
        direction === "public"
          ? { from: entry.internalSegments, to: publicSegments }
          : { from: publicSegments, to: entry.internalSegments },
      );
    }
  }
  return mappings;
}

/**
 * Longitud del prefijo (en segmentos) que `from` casa con `segments`, o `null`. Un segmento
 * `[...rest]` solo puede ser el último de la plantilla y casa con todo lo que quede.
 */
function matchPrefix(
  from: readonly string[],
  segments: readonly string[],
): number | null {
  if (from.length > segments.length) return null;
  for (let index = 0; index < from.length; index += 1) {
    const expected = from[index]!;
    if (PARAM_SEGMENT.test(expected)) {
      if (expected.includes("...") && index === from.length - 1) return from.length;
      if (!segments[index]) return null;
    } else if (segments[index] !== expected) {
      return null;
    }
  }
  return from.length;
}

function transformDirection(path: string, mappings: readonly Mapping[]): string {
  const { path: clean, suffix } = splitSuffix(path);
  if (!clean.startsWith("/") || clean.startsWith("//")) return path;
  const segments = segmentsOf(clean);

  let best: { mapping: Mapping; length: number; literals: number } | null = null;
  for (const mapping of mappings) {
    const length = matchPrefix(mapping.from, segments);
    if (length === null) continue;
    const literals = mapping.from.filter(
      (segment) => !PARAM_SEGMENT.test(segment),
    ).length;
    if (
      !best ||
      length > best.length ||
      (length === best.length && literals > best.literals)
    ) {
      best = { mapping, length, literals };
    }
  }
  if (!best) return path;

  const matched = segments.slice(0, best.length);
  const translated = best.mapping.to.map((target, index) => {
    const source = best!.mapping.from[index];
    // Un parámetro del destino toma el valor concreto del origen (`[slug]` → `vela`).
    if (source && PARAM_SEGMENT.test(source) && PARAM_SEGMENT.test(target)) {
      return matched[index] ?? target;
    }
    return target;
  });
  const rest = segments.slice(best.length);
  const result = [...translated, ...rest].join("/");
  return `/${result}${suffix}`;
}

/**
 * Ruta pública de una ruta interna, sin prefijo de idioma.
 *
 * @param path Ruta interna (`/products/vela`, con `?…`/`#…` si los hay).
 * @param locale Idioma de la ruta (para un mapa por idioma); sin él se usa el valor compartido.
 * @param map Mapa de la instancia (por defecto, el del registro).
 */
export function toPublicPath(
  path: string,
  locale?: string,
  map: PublicPathMap = instanceRegistry.publicPaths ?? {},
): string {
  if (Object.keys(map).length === 0) return path;
  return transformDirection(
    path,
    mappingsFor(publicPathEntries(map), "public", locale),
  );
}

/**
 * Ruta interna de una ruta pública, sin prefijo de idioma. Sin `locale` vale cualquier idioma
 * declarado (es lo que necesita un `?return=` que llega de otra pestaña).
 */
export function toInternalPath(
  path: string,
  locale?: string,
  map: PublicPathMap = instanceRegistry.publicPaths ?? {},
): string {
  if (Object.keys(map).length === 0) return path;
  return transformDirection(
    path,
    mappingsFor(publicPathEntries(map), "internal", locale),
  );
}

/**
 * Tramos públicos (primer segmento) que sirven la misma vista que `internalSegment`
 * (`"products"`), en cualquier idioma. Lo usan los lectores que reconocen enlaces del CMS
 * (banners, cuerpo del blog) además de la forma interna.
 */
export function publicAliases(
  internalSegment: string,
  map: PublicPathMap = instanceRegistry.publicPaths ?? {},
): string[] {
  const aliases = new Set<string>();
  for (const entry of publicPathEntries(map)) {
    if (entry.internalSegments[0] !== internalSegment) continue;
    for (const value of valuesFor(entry.value)) {
      const first = segmentsOf(value)[0];
      if (first) aliases.add(first);
    }
  }
  return [...aliases];
}

/** Tramos públicos de la raíz que ocupa el mapa (primer segmento de cada valor). */
export function publicRootSegments(
  map: PublicPathMap = instanceRegistry.publicPaths ?? {},
): string[] {
  const segments = new Set<string>();
  for (const entry of publicPathEntries(map)) {
    for (const value of valuesFor(entry.value)) {
      const first = segmentsOf(value)[0];
      if (first) segments.add(first);
    }
  }
  return [...segments];
}

/** Opciones de la validación (los idiomas del sitio y el principal, sin importar routing). */
export interface ValidatePublicPathsOptions {
  locales?: readonly string[];
  defaultLocale?: string;
  /** Tramos internos de la raíz que ya ocupa el núcleo (`products`, `checkout`…). */
  internalRoots?: readonly string[];
}

/**
 * Valida el mapa y lanza un error claro si una entrada es ambigua o pisa una ruta del
 * núcleo. Se llama al cargar `i18n/routing.ts` (el arranque falla en vez de servir URLs
 * rotas en silencio). Sin mapa no hace nada.
 */
export function validatePublicPaths(
  map: PublicPathMap | undefined,
  options: ValidatePublicPathsOptions = {},
): void {
  if (!map || Object.keys(map).length === 0) return;
  const entries = publicPathEntries(map);
  const { locales = [], defaultLocale, internalRoots = [] } = options;
  const localeSet = new Set(locales);

  const publicsByLocale = new Map<string, Map<string, string>>();
  const publicKey = (locale: string) => locale || "*";

  const register = (locale: string, resolved: string, internal: string) => {
    const key = publicKey(locale);
    let bucket = publicsByLocale.get(key);
    if (!bucket) {
      bucket = new Map();
      publicsByLocale.set(key, bucket);
    }
    const previous = bucket.get(resolved);
    if (previous !== undefined && previous !== internal) {
      throw new Error(
        `[hwe] rutas públicas: «${previous}» y «${internal}» acaban en la misma URL «${resolved}»` +
          (locale ? ` en «${locale}»` : "") +
          ".",
      );
    }
    bucket.set(resolved, internal);
  };

  for (const entry of entries) {
    const values =
      typeof entry.value === "string"
        ? locales.length > 0
          ? locales.map((locale) => [locale, entry.value as string] as const)
          : [["", entry.value] as const]
        : Object.entries(entry.value);

    for (const [locale, value] of values) {
      if (locale && locales.length > 0 && !localeSet.has(locale)) {
        throw new Error(
          `[hwe] rutas públicas: «${entry.internal}» declara el idioma «${locale}», que no está en routing.locales.`,
        );
      }
      const first = segmentsOf(value)[0] ?? "";
      if (first === "api") {
        throw new Error(
          `[hwe] rutas públicas: «${entry.internal}» no puede ocupar «/api» (es el BFF).`,
        );
      }
      if (localeSet.has(first)) {
        throw new Error(
          `[hwe] rutas públicas: «${entry.internal}» no puede ocupar «/${first}» (es un idioma).`,
        );
      }
      if (internalRoots.includes(first) && first !== entry.internalSegments[0]) {
        throw new Error(
          `[hwe] rutas públicas: «${entry.internal}» no puede servirse en «/${first}»` +
            (locale ? ` («${locale}»)` : "") +
            ": es la ruta interna de otra vista del núcleo.",
        );
      }
      register(locale, value, entry.internal);
    }
  }

  // Una ruta pública no puede coincidir con la INTERNA de otra entrada: el proxy no sabría
  // si reescribir o redirigir.
  for (const entry of entries) {
    for (const [locale, value] of typeof entry.value === "string"
      ? [["", entry.value] as const]
      : Object.entries(entry.value)) {
      if (value === entry.internal) continue;
      const clash = entries.find(
        (other) => other.internal === value && other.internal !== entry.internal,
      );
      if (clash) {
        throw new Error(
          `[hwe] rutas públicas: «${entry.internal}» no puede servirse en «${value}»` +
            (locale ? ` («${locale}»)` : "") +
            `: es la ruta interna de otra vista.`,
        );
      }
    }
  }

  // El idioma principal debe estar entre los declarados (si se pasaron).
  if (defaultLocale && locales.length > 0 && !localeSet.has(defaultLocale)) {
    throw new Error(
      `[hwe] rutas públicas: defaultLocale «${defaultLocale}» no está en locales.`,
    );
  }
}
