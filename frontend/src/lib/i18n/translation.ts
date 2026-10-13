import { routing } from "@/i18n/routing";
import { localeSuffix } from "@/lib/i18n/pick-localized";

// ============================================================================
//  Traducciones de contenido (`hwe-content-i18n`) en el frontend. Módulo PURO:
//  lo usan servidor, cliente y tests.
//
//  WordPress expone `translation(locale: "<idioma>") { … }` en cada tipo
//  traducible (Post, Page, Product, ProductCategory, Category, Tag). Esas
//  selecciones no se escriben a mano en cada query: la query lleva un marcador
//
//      # @translation Post
//      # @translation Post: title excerpt slug      (solo lo que se pinta)
//
//  y `withTranslations` lo sustituye, según los idiomas que haga falta, por una
//  selección con alias (`tr_en: translation(locale: "en") { … }`). Con el idioma
//  por defecto no queda nada: la query no menciona `translation` y funciona
//  igual contra un WordPress de un solo idioma.
//
//  Tras la respuesta, `flattenTranslations` convierte `tr_<idioma>` en claves
//  `<campo>_<idioma>` (`seoTitle` → `seo_title_en`), que es lo que ya lee
//  `pickLocalized`: la capa de datos no necesita un segundo mecanismo.
// ============================================================================

/**
 * Campos de `translation` que se piden por tipo de GraphQL. Son los que declara
 * `hwe-content-i18n` en WordPress; una instancia que registre campos propios los
 * pide con la lista explícita del marcador.
 */
export const TRANSLATION_FIELDS = {
  Post: ["title", "excerpt", "content", "seoTitle", "seoDescription", "slug"],
  Page: ["title", "content", "seoTitle", "seoDescription"],
  Product: [
    "name",
    "shortDescription",
    "description",
    "seoTitle",
    "seoDescription",
    "slug",
  ],
  ProductCategory: ["name", "description", "seoTitle", "seoDescription", "slug"],
  Category: ["name", "description"],
  Tag: ["name"],
} as const satisfies Record<string, readonly string[]>;

/** Idiomas secundarios del sitio (los de `routing`, sin el idioma por defecto). */
export function secondaryLocales(): string[] {
  return routing.locales.filter((locale) => locale !== routing.defaultLocale);
}

/**
 * Traducciones que hay que pedir para renderizar una página en `locale`: ninguna
 * en el idioma por defecto (el contenido nativo), la suya en un secundario. Un
 * idioma que el sitio no declara no pide nada, así que un valor de la ruta nunca
 * acaba dentro de una query.
 */
export function translationLocales(locale: string): string[] {
  return secondaryLocales().includes(locale) ? [locale] : [];
}

const MARKER =
  /^[ \t]*#[ \t]*@translation[ \t]+(\w+)(?:[ \t]*:[ \t]*([\w \t]+?))?[ \t]*$/gm;

/**
 * Sustituye los marcadores `# @translation <Tipo>[: campos]` de un documento
 * GraphQL por la selección de `translation` de cada idioma de `locales`.
 *
 * Se ignoran los idiomas que el sitio no declara o que son el idioma por
 * defecto. Sin idiomas, el marcador desaparece.
 *
 * @throws Error si el marcador nombra un tipo sin campos declarados y no trae lista.
 */
export function withTranslations(document: string, locales: readonly string[]): string {
  const wanted = locales.filter((locale) => secondaryLocales().includes(locale));

  return document.replace(MARKER, (_match, type: string, list?: string) => {
    const fields =
      list !== undefined
        ? list.trim().split(/\s+/)
        : (TRANSLATION_FIELDS as Record<string, readonly string[]>)[type];
    if (!fields || fields.length === 0) {
      throw new Error(
        `i18n: «# @translation ${type}» no tiene campos declarados; añádelo a TRANSLATION_FIELDS o pasa la lista («# @translation ${type}: title slug»).`,
      );
    }
    return wanted
      .map(
        (locale) =>
          `tr_${localeSuffix(locale)}: translation(locale: "${locale}") { ${fields.join(" ")} }`,
      )
      .join("\n");
  });
}

/** `seoTitle` → `seo_title`: el nombre del campo en WordPress y en `pickLocalized`. */
function snakeCase(camel: string): string {
  return camel.replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`);
}

/**
 * Aplana las selecciones `tr_<idioma>` de una respuesta: `tr_en: { seoTitle: "X" }`
 * pasa a `seo_title_en: "X"` en el mismo objeto. Omite lo nulo y lo vacío, de modo
 * que «sin traducción» es «sin clave». Recorre listas y objetos anidados y no
 * muta la entrada.
 */
export function flattenTranslations<T>(value: T): T {
  if (Array.isArray(value)) {
    return value.map((item) => flattenTranslations(item)) as unknown as T;
  }
  if (value === null || typeof value !== "object") return value;

  const out: Record<string, unknown> = {};
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    const translation = /^tr_([a-z0-9_]+)$/.exec(key);
    if (!translation) {
      out[key] = flattenTranslations(child);
      continue;
    }
    if (child === null || typeof child !== "object") continue;
    for (const [field, text] of Object.entries(child as Record<string, unknown>)) {
      if (typeof text === "string" && text.trim() !== "") {
        out[`${snakeCase(field)}_${translation[1]}`] = text;
      }
    }
  }
  return out as T;
}

/**
 * Un campo de la respuesta en `locale`, con el nombre de GraphQL (`seoTitle`):
 * su variante aplanada (`seo_title_en`) si existe y tiene texto; si no, el texto
 * nativo (`entity.seoTitle`). Nunca el de otro idioma secundario. Sin valor, null.
 */
export function pickTranslated(
  entity: object | null | undefined,
  field: string,
  locale: string,
): string | null {
  if (!entity) return null;
  const record = entity as Record<string, unknown>;
  const native = typeof record[field] === "string" ? (record[field] as string) : null;
  if (locale === routing.defaultLocale) return native;
  const translated = record[`${snakeCase(field)}_${localeSuffix(locale)}`];
  return typeof translated === "string" && translated.trim() !== ""
    ? translated
    : native;
}

/**
 * Quita de una respuesta las claves `<campo>_<idioma>` que dejó
 * `flattenTranslations` una vez resueltas con `pickLocalized`: no tiene sentido
 * mandar al cliente el texto de los demás idiomas. Recorre listas y objetos
 * anidados y no muta la entrada.
 */
export function stripTranslations<T>(value: T): T {
  const suffixes = secondaryLocales().map(localeSuffix);
  const isTranslationKey = (key: string) =>
    suffixes.some((suffix) => key.endsWith(`_${suffix}`));

  const walk = (node: unknown): unknown => {
    if (Array.isArray(node)) return node.map(walk);
    if (node === null || typeof node !== "object") return node;
    return Object.fromEntries(
      Object.entries(node as Record<string, unknown>)
        .filter(([key]) => !isTranslationKey(key))
        .map(([key, child]) => [key, walk(child)]),
    );
  };
  return walk(value) as T;
}

/**
 * ¿Existe la entidad en `locale`? El idioma por defecto, siempre; un secundario,
 * solo si su campo principal (`title`, `name`…) tiene texto: sin traducción no se
 * anuncia el idioma (ni hreflang ni sitemap).
 */
export function hasTranslation(entity: object, locale: string, field: string): boolean {
  if (locale === routing.defaultLocale) return true;
  const raw = (entity as Record<string, unknown>)[`${field}_${localeSuffix(locale)}`];
  return typeof raw === "string" && raw.trim() !== "";
}

/**
 * Idiomas del sitio en los que existe la entidad, con el idioma por defecto
 * primero. Es el `locales` de `AlternatesOptions` y de las filas del sitemap.
 */
export function translatedLocales(entity: object, field: string): string[] {
  return routing.locales.filter((locale) => hasTranslation(entity, locale, field));
}
