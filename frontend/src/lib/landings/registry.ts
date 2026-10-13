import { routing } from "@/i18n/routing";
import { instanceRegistry } from "@/instance/registry";
import { localeSuffix } from "@/lib/i18n/pick-localized";
import type { EditorialLandingConfig } from "@/lib/config/types";
import { AREA_SEGMENT, reservedRootSegments } from "@/lib/landings/reserved-segments";

// ============================================================================
//  Registro de landings editoriales: las estáticas (con diseño propio, de la
//  instancia) y las creadas desde el panel.
//
//  Estáticas: `instanceRegistry.landings.staticEntries` (solo DATOS). Deben
//  coincidir con `hwe_landings_static_entries` del backend (misma clave, `base`
//  y tipo): si divergen, una instancia sin config viva emitiría canónicas y
//  sitemap con la URL equivocada.
//
//  Del panel: `config.landings.registry` (texto `identificador | Nombre | tipo`).
//  Llevan el molde estándar: sin secciones únicas ni SEO de respaldo.
//
//  La clave del registro es un IDENTIFICADOR interno INMUTABLE: es donde cuelga
//  el contenido (`landings.editorial.<id>`) y la locación del banner del hero.
//  La URL pública (`base` y un tramo por idioma) se puede cambiar sin mover nada.
// ============================================================================

export type BaseSectionId =
  | "hero"
  | "lead"
  // Slots de contenido del CMS. Son el SLOT, no la posición: ver
  // `LandingContentSection.id` en types.ts.
  | "s1"
  | "s2"
  | "s3"
  | "s4"
  | "s5"
  | "quote"
  | "faq"
  | "products"
  | "closing";

export const BASE_SECTION_IDS: readonly BaseSectionId[] = [
  "hero",
  "lead",
  "s1",
  "s2",
  "s3",
  "s4",
  "s5",
  "quote",
  "faq",
  "products",
  "closing",
];

/** De dónde cuelga la URL pública de una landing editorial. */
export type EditorialUrlBase = "area" | "root";

export interface EditorialRegistryEntry {
  /** Identificador interno INMUTABLE (clave del contenido y de la locación del banner). */
  slug: string;
  /** Tramo de la URL pública en el idioma por defecto. */
  path: string;
  /**
   * Tramo de la URL en CADA idioma del sitio (el por defecto incluido). Un idioma sin tramo
   * propio usa el del idioma por defecto. `base` es uno solo para todos los idiomas.
   */
  paths: Record<string, string>;
  /** `area` → `/learn/<tramo>`; `root` → `/<tramo>` (sin página padre). */
  base: EditorialUrlBase;
  /** Etiqueta legible (respaldo del título del hero cuando no hay banner ni SEO). */
  label: string;
  jsonLd: "person" | "article";
  /** Secciones únicas por código, insertadas tras la sección base indicada. */
  uniqueSections: { key: string; after: BaseSectionId }[];
  /**
   * Secciones base que una sección única ABSORBE: se retiran del render, no del dato.
   * Sin declarar = molde estándar.
   */
  consumesBase: BaseSectionId[];
  /** Slot (`s1`…`s5`) junto al que se pinta la FAQ en vez de como banda propia. */
  faqAside: BaseSectionId | null;
  /** Claves i18n de la instancia con el SEO por defecto; `null` en las del panel. */
  seoFallback: { titleKey: string; descriptionKey: string } | null;
  /** Pistas de presentación opacas para las vistas de la instancia. */
  hints: Record<string, string>;
  /** ¿Creada desde el panel? Las estáticas llevan secciones de código. */
  dynamic: boolean;
}

const isBaseSection = (value: unknown): value is BaseSectionId =>
  typeof value === "string" && (BASE_SECTION_IDS as readonly string[]).includes(value);

/** Normaliza un tramo de URL venido del CMS (defensivo: PHP ya lo sanea). */
export function cleanPath(value: string | null | undefined): string {
  if (typeof value !== "string") return "";
  return value
    .trim()
    .toLowerCase()
    .replace(/^\/+|\/+$/g, "")
    .replace(/[^a-z0-9-]+/g, "")
    .replace(/^-+|-+$/g, "");
}

/** Tramo de la URL de `entry` en `locale`; sin el suyo, el del idioma por defecto. */
export function pathFor(entry: EditorialRegistryEntry, locale: string): string {
  return entry.paths[locale] ?? entry.path;
}

function allLocalesPaths(path: string, byLocale: Record<string, string> = {}) {
  return Object.fromEntries(
    routing.locales.map((locale) => [locale, cleanPath(byLocale[locale]) || path]),
  );
}

/**
 * Las landings con diseño propio de la instancia, normalizadas. Una `after` que no sea un slot
 * base se descarta (la sección única iría siempre al final, en silencio).
 */
export function staticEntries(
  declared:
    | NonNullable<typeof instanceRegistry.landings>["staticEntries"]
    | undefined = instanceRegistry.landings?.staticEntries,
): Record<string, EditorialRegistryEntry> {
  const out: Record<string, EditorialRegistryEntry> = {};
  for (const [slug, entry] of Object.entries(declared ?? {})) {
    const path = cleanPath(entry.path) || slug;
    out[slug] = {
      slug,
      path,
      paths: allLocalesPaths(path, entry.paths),
      base: entry.base === "root" ? "root" : "area",
      label: entry.label,
      jsonLd: entry.jsonLd === "person" ? "person" : "article",
      uniqueSections: (entry.uniqueSections ?? []).flatMap((section) =>
        isBaseSection(section.after)
          ? [{ key: section.key, after: section.after }]
          : [],
      ),
      consumesBase: (entry.consumesBase ?? []).filter(isBaseSection),
      faqAside: isBaseSection(entry.faqAside) ? entry.faqAside : null,
      seoFallback: entry.seoFallback ?? null,
      hints: entry.hints ?? {},
      dynamic: false,
    };
  }
  return out;
}

/** Una fila del registro editable (`config.landings.registry`). */
export interface EditorialRegistryItem {
  slug: string;
  label: string;
  jsonLd: "article" | "person";
}

/**
 * Parsea el texto canónico `identificador | Nombre | article|person` (una landing por línea) que
 * persiste el campo `landing_registry`. Descarta líneas sin identificador y los que colisionen
 * con una estática o con uno anterior.
 */
export function parseEditorialRegistry(
  raw: string | null | undefined,
  taken: Iterable<string> = Object.keys(staticEntries()),
): EditorialRegistryItem[] {
  if (typeof raw !== "string" || raw.trim() === "") return [];
  const out: EditorialRegistryItem[] = [];
  const seen = new Set<string>(taken);
  for (const line of raw.split(/\r\n|\r|\n/)) {
    const trimmed = line.trim();
    if (trimmed === "") continue;
    const parts = trimmed.split("|").map((part) => part.trim());
    // El identificador ya viene saneado por `sanitize_title` en PHP; aquí solo se descartan restos
    // inválidos (defensivo).
    const slug = cleanPath(parts[0]);
    if (slug === "" || seen.has(slug)) continue;
    seen.add(slug);
    out.push({
      slug,
      label: parts[1] ? parts[1] : slug,
      jsonLd: parts[2] === "person" ? "person" : "article",
    });
  }
  return out;
}

/**
 * Aplica a una entrada la URL configurada en el CMS (`landings.editorial.<id>.url`). Sin config,
 * la entrada conserva su `base` y sus tramos por defecto.
 */
function withConfiguredUrl(
  entry: EditorialRegistryEntry,
  editorial: Record<string, EditorialLandingConfig | undefined> | undefined,
  reserved: ReadonlySet<string>,
): EditorialRegistryEntry {
  const url = editorial?.[entry.slug]?.url;
  if (!url) return entry;

  const configuredPath = cleanPath(url.path);
  const path = configuredPath || entry.path;
  const base: EditorialUrlBase = url.base === "root" ? "root" : "area";

  const paths: Record<string, string> = {};
  for (const locale of routing.locales) {
    if (locale === routing.defaultLocale) {
      paths[locale] = path;
      continue;
    }
    // Sin tramo propio en el CMS: el del idioma por defecto si ESE sí se configuró; si no, el
    // de la entrada (código) para ese idioma.
    const own = cleanPath(url[`path_${localeSuffix(locale)}`]);
    paths[locale] = own || (configuredPath ? path : (entry.paths[locale] ?? path));
  }

  if (base === "root") {
    // Un tramo reservado en la raíz daría una landing inalcanzable (gana la ruta estática de
    // Next) y sin señal de error: vuelve al área, donde sí responde.
    if (reserved.has(path)) return { ...entry, path, paths, base: "area" };
    // Si solo el tramo de OTRO idioma está reservado, ese idioma usa el del idioma por defecto:
    // mover los dos por un fallo de uno castigaría a la URL que sí era válida.
    for (const locale of routing.locales) {
      if (reserved.has(paths[locale]!)) paths[locale] = path;
    }
  }
  return { ...entry, path, paths, base };
}

/**
 * Reubica una entrada cuya URL ya está ocupada por otra landing. `findEditorialByPath` resuelve
 * por orden de declaración: la primera conserva su URL y la SEGUNDA se mueve a su URL por defecto
 * (`/learn/<identificador>`), que es única salvo que otra la haya reclamado explícitamente.
 *
 * Se comprueba POR IDIOMA y, si choca cualquiera, se reubica la landing entera: no se deja una
 * mitad en la URL pedida y la otra en la de respaldo.
 */
function withoutUrlCollision(
  entry: EditorialRegistryEntry,
  taken: Set<string>,
): EditorialRegistryEntry {
  const keys = (candidate: EditorialRegistryEntry) =>
    routing.locales.map(
      (locale) => `${candidate.base}:${locale}:${pathFor(candidate, locale)}`,
    );
  const resolved = keys(entry).some((key) => taken.has(key))
    ? {
        ...entry,
        base: "area" as const,
        path: entry.slug,
        paths: allLocalesPaths(entry.slug),
      }
    : entry;
  for (const key of keys(resolved)) taken.add(key);
  return resolved;
}

/**
 * Registro completo: las estáticas y las del panel. Las dinámicas llevan el molde estándar.
 *
 * `editorial` es opcional para quien solo necesita saber QUÉ landings existen; sin él, todas
 * quedan con su URL por defecto. Una landing con `product` relleno no se resuelve por URL
 * libre: su única URL válida es la de su producto (ver `editorial-product.ts`), así que se
 * excluye cuando se pasa `editorial`.
 */
export function buildEditorialRegistry(
  raw: string | null | undefined,
  editorial?: Record<string, EditorialLandingConfig | undefined>,
  statics: Record<string, EditorialRegistryEntry> = staticEntries(),
  reserved: ReadonlySet<string> = reservedRootSegments(),
): Record<string, EditorialRegistryEntry> {
  const out: Record<string, EditorialRegistryEntry> = {};
  // URLs ya reclamadas, en orden de declaración: las estáticas primero.
  const taken = new Set<string>();
  for (const [slug, entry] of Object.entries(statics)) {
    if (editorial?.[slug]?.product) continue;
    out[slug] = withoutUrlCollision(
      withConfiguredUrl(entry, editorial, reserved),
      taken,
    );
  }
  for (const item of parseEditorialRegistry(raw, Object.keys(statics))) {
    if (editorial?.[item.slug]?.product) continue;
    out[item.slug] = withoutUrlCollision(
      withConfiguredUrl(
        {
          slug: item.slug,
          path: item.slug,
          paths: allLocalesPaths(item.slug),
          base: "area",
          label: item.label,
          jsonLd: item.jsonLd,
          uniqueSections: [],
          consumesBase: [],
          faqAside: null,
          seoFallback: null,
          hints: {},
          dynamic: true,
        },
        editorial,
        reserved,
      ),
      taken,
    );
  }
  return out;
}

/**
 * Href interno de la landing en `locale`, listo para `Link`, `localizedPath`, `alternatesFor` y
 * `absoluteLocalized`. Sin locale, el del idioma por defecto (el canónico).
 */
export function editorialLandingHref(
  entry: EditorialRegistryEntry,
  locale: string = routing.defaultLocale,
): string {
  const path = pathFor(entry, locale);
  return entry.base === "root" ? `/${path}` : `/${AREA_SEGMENT}/${path}`;
}

/**
 * El href de la landing en cada idioma del sitio: lo que esperan `alternatesFor` y
 * `absoluteLocalized` para que la canónica y los `hreflang` lleven el tramo de cada idioma.
 */
export function editorialLandingPaths(
  entry: EditorialRegistryEntry,
): Record<string, string> {
  return Object.fromEntries(
    routing.locales.map((locale) => [locale, editorialLandingHref(entry, locale)]),
  );
}

/**
 * Busca la landing servida por un tramo de URL en `locale`. `base` distingue las dos rutas que
 * pueden atender una landing: una configurada en la raíz NO responde también bajo `/learn/`
 * (contenido duplicado). Solo casa el tramo DE ESE IDIOMA; el de otro lo resuelve
 * `findEditorialByAnyPath`.
 */
export function findEditorialByPath(
  registry: Record<string, EditorialRegistryEntry>,
  base: EditorialUrlBase,
  path: string,
  locale: string = routing.defaultLocale,
): EditorialRegistryEntry | null {
  for (const entry of Object.values(registry)) {
    if (entry.base === base && pathFor(entry, locale) === path) return entry;
  }
  return null;
}

/**
 * Como `findEditorialByPath`, pero acepta también el tramo de OTRO idioma y dice si el que llegó
 * es el canónico de `locale`. Mismo contrato que las fichas de producto y de entrada: el
 * selector de idioma conserva los params de la ruta y los menús llevan el tramo del idioma por
 * defecto, así que `/en/learn/<tramo-es>` tiene que llegar a la landing, con un 308 a su URL.
 *
 * Gana siempre la coincidencia exacta en el idioma de la página.
 */
export function findEditorialByAnyPath(
  registry: Record<string, EditorialRegistryEntry>,
  base: EditorialUrlBase,
  path: string,
  locale: string,
): { entry: EditorialRegistryEntry; canonical: boolean } | null {
  const exact = findEditorialByPath(registry, base, path, locale);
  if (exact) return { entry: exact, canonical: true };
  // El idioma por defecto primero (los enlaces viejos y los menús llevan su tramo), después el resto.
  const others = [
    routing.defaultLocale,
    ...routing.locales.filter((code) => code !== routing.defaultLocale),
  ].filter((code) => code !== locale);
  for (const other of others) {
    const alias = findEditorialByPath(registry, base, path, other);
    if (alias) return { entry: alias, canonical: false };
  }
  return null;
}
