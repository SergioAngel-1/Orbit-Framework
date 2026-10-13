import "server-only";
import { cache } from "react";
import { getLocale } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { classifyCmsHref, type CmsLink } from "@/lib/landings/cms-href";
import { resolveProductLearnHref } from "@/lib/landings/editorial-product";
import { buildEditorialRegistry, editorialLandingHref } from "@/lib/landings/registry";
import { AREA_SEGMENT } from "@/lib/landings/reserved-segments";

// ============================================================================
//  Resolvedor de la URL pública de una landing editorial.
//
//  Es el ÚNICO sitio desde el que se enlaza a una landing editorial. La URL no es
//  deducible del identificador: el editor elige en el Control Center si cuelga
//  del área o de la raíz, y con qué tramo final (por idioma). Un
//  `href="/learn/<id>"` escrito a mano deja de resolver en cuanto la página se
//  mueve.
//
//  Todos los consumidores son Server Components, así que el helper es `async` y
//  lee la config viva. `cache()` lo hace gratis dentro de una petición.
//
//  El tramo depende del IDIOMA: quien tenga el locale a mano lo pasa; sin él se
//  usa el de la petición (`getLocale()`, seguro en cualquier render bajo
//  `[locale]` con `setRequestLocale`). No lo llames desde `generateStaticParams`
//  ni desde el sitemap: ahí no hay petición, y para eso está
//  `editorialLandingPaths`.
// ============================================================================

async function localeOr(locale: string | undefined): Promise<string> {
  return locale ?? (await getLocale());
}

/** Slug del producto asociado a la landing, o `null`. Solo mira el DATO, no el registro. */
function productOf(
  config: Awaited<ReturnType<typeof getSiteConfig>>,
  slug: string,
): string | null {
  const raw = config.landings.editorial?.[slug]?.product;
  return typeof raw === "string" && raw.trim() !== "" ? raw.trim() : null;
}

/**
 * Href interno de la landing editorial `id`, listo para `Link`, `localizedPath`,
 * `alternatesFor` y `absoluteLocalized`.
 *
 * Un identificador que no esté en el registro cae a `/learn/<id>`: sin config viva (respaldo
 * de `getSiteConfig`) es preferible un enlace al molde por defecto que un `null` que obligue a
 * cada llamante a decidir qué pintar.
 */
export const getEditorialHref = cache(
  async (slug: string, locale?: string): Promise<string> => {
    const [config, lang] = await Promise.all([getSiteConfig(), localeOr(locale)]);
    // Landing asociada a un producto: su URL es la de ese producto, no una URL libre. Sin esta
    // rama caería al `/learn/<id>`, que para una landing de este tipo es un 404 GARANTIZADO
    // (el registro de URL libre la excluye).
    const productSlug = productOf(config, slug);
    if (productSlug) {
      const href = await resolveProductLearnHref(productSlug, lang);
      if (href) return href;
    }
    const entry = buildEditorialRegistry(
      config.landings.registry,
      config.landings.editorial,
    )[slug];
    return entry ? editorialLandingHref(entry, lang) : `/${AREA_SEGMENT}/${slug}`;
  },
);

/**
 * Como `getEditorialHref`, pero `null` si el identificador NO está en el registro (o el módulo
 * está apagado), en vez de caer al molde por defecto.
 *
 * Es la variante para cuando la URL no la va a pulsar nadie sino que viaja a un dato —el
 * `author.url` del JSON-LD—: ahí un enlace de respaldo que puede ser un 404 es peor que no
 * declarar nada, porque una URL rota en datos estructurados invalida la señal.
 */
export const getEditorialHrefStrict = cache(
  async (slug: string, locale?: string): Promise<string | null> => {
    const [config, lang] = await Promise.all([getSiteConfig(), localeOr(locale)]);
    if (!isModuleEnabled(config, "landings")) return null;
    const productSlug = productOf(config, slug);
    if (productSlug) return await resolveProductLearnHref(productSlug, lang);
    const entry = buildEditorialRegistry(
      config.landings.registry,
      config.landings.editorial,
    )[slug];
    return entry ? editorialLandingHref(entry, lang) : null;
  },
);

/** Un enlace del CMS ya listo para pintar. */
export type ResolvedCmsLink =
  | { kind: "internal"; href: string }
  | { kind: "plain"; href: string; external: boolean };

/**
 * Valida un enlace escrito en el CMS (allow-list de `cms-href`) y resuelve el prefijo
 * `editorial:<id>` a la URL real de esa landing. `null` si no pasa la allow-list o la landing
 * enlazada no existe (un botón roto es peor que no pintarlo).
 */
export async function resolveCmsLink(
  raw: string | null | undefined,
  locale?: string,
): Promise<ResolvedCmsLink | null> {
  const link: CmsLink | null = classifyCmsHref(raw);
  if (!link) return null;
  if (link.kind !== "editorial") return link;
  const href = await getEditorialHrefStrict(link.slug, locale);
  return href ? { kind: "internal", href } : null;
}
