import { getTranslations } from "next-intl/server";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { brandDescription, brandTagline } from "@/lib/config/brand";
import { categoryHref } from "@/lib/catalog/product-href";
import { getCategories } from "@/lib/catalog/products";
import { publishedEditorialLandings } from "@/lib/landings/editorial-list";
import { absoluteLocalized } from "@/lib/seo/urls";
import { metaDescription } from "@/lib/seo/meta-description";
import { isPathAnnounced } from "@/lib/seo/announce";
import { isSitemapPathExcluded, resolveInstanceSitemap } from "@/lib/instance/sitemap";

// ============================================================================
//  /llms.txt — superficie machine-readable para agentes de IA.
//
//  Se genera por LOCALE, no una vez: un agente que llega por `/en` recibe el
//  mapa del sitio en inglés. El estándar no define i18n, así que se sigue la
//  convención de facto — un fichero por idioma, el canónico en la raíz
//  (`/llms.txt`) y los demás bajo su prefijo (`/en/llms.txt`).
//
//  El copy vive en i18n (`llms.*`); lo que sale del CMS es el catálogo y los
//  datos de negocio. Se puede desactivar entero desde Control Center → GEO →
//  «Generar /llms.txt».
//
//  Vive en `_llms/` y no en la ruta: un route handler de Next solo admite
//  exportar métodos HTTP y config.
// ============================================================================

/** Rutas INTERNAS de las páginas principales, en el orden en que se listan. */
const MAIN_PAGES = [
  ["home", "/"],
  ["store", "/products"],
  ["blog", "/blog"],
  ["about", "/about"],
  ["contact", "/contact"],
] as const;

/** Slugs legales: la misma lista que sirve `/legal/[slug]`. */
const LEGAL_SLUGS = ["privacy", "cookies", "terms", "returns"] as const;

/**
 * Cuerpo del `/llms.txt` del locale, o `null` si el cliente lo ha desactivado
 * en el Control Center o la config del sitio está degradada (el llamante
 * responde 404: con `brand.url` por defecto las URLs absolutas serían falsas).
 */
export async function buildLlmsTxt(locale: string): Promise<string | null> {
  const config = await getSiteConfig();
  if (!config.geo.llms_txt_enabled) return null;
  if (isFallbackSiteConfig(config)) return null;

  const t = await getTranslations({ locale, namespace: "llms" });
  const base = (config.brand.url || "").replace(/\/$/, "");
  const name = config.brand.name;
  const abs = (path: string) => absoluteLocalized(base, path, locale);
  // Las exclusiones del registro de la instancia se aplican a las páginas principales, a
  // los documentos legales y a las guías, comparando contra la ruta INTERNA. No añade
  // secciones ni textos: el copy de `llms.txt` vive en i18n.
  const rules = resolveInstanceSitemap();
  const lines: string[] = [];

  // Encabezado: rótulo, lema y descripción larga, ya resueltos al idioma.
  lines.push(`# ${name}`);
  const tagline = brandTagline(config, locale);
  const description = brandDescription(config, locale);
  if (tagline) lines.push("", `> ${tagline}`);
  if (description) lines.push("", description);

  lines.push("", `## ${t("sections.pages")}`);
  for (const [key, path] of MAIN_PAGES) {
    if (isSitemapPathExcluded(path, rules)) continue;
    lines.push(
      `- [${t(`pages.${key}.label`)}](${abs(path)}): ${t(`pages.${key}.text`, { brand: name })}`,
    );
  }

  try {
    const categories = await getCategories(locale);
    const announced = categories.filter((cat) =>
      isPathAnnounced(`/categories/${cat.slug}`, cat, rules),
    );
    if (announced.length > 0) {
      lines.push("", `## ${t("sections.categories")}`);
      for (const cat of announced) {
        const text = metaDescription(cat.description, 120);
        lines.push(
          `- [${cat.name}](${abs(categoryHref(cat))}): ${
            text || t("categoryFallback", { name: cat.name })
          }`,
        );
      }
    }
  } catch {
    /* sin categorías disponibles */
  }

  // Guías (módulo `landings`): las landings editoriales publicadas en este idioma.
  const guides = publishedEditorialLandings(config, locale);
  if (guides.length > 0) {
    lines.push("", `## ${t("sections.guides")}`);
    for (const guide of guides) {
      if (isSitemapPathExcluded(guide.href, rules)) continue;
      const text = metaDescription(guide.description, 120);
      lines.push(`- [${guide.title}](${abs(guide.href)})${text ? `: ${text}` : ""}`);
    }
  }

  lines.push("", `## ${t("sections.legal")}`);
  for (const slug of LEGAL_SLUGS) {
    if (isSitemapPathExcluded(`/legal/${slug}`, rules)) continue;
    lines.push(
      `- [${t(`legal.${slug}.label`)}](${abs(`/legal/${slug}`)}): ${t(`legal.${slug}.text`)}`,
    );
  }

  // Datos de referencia que la IA consulta con frecuencia.
  const facts: string[] = [];
  if (config.legal.company)
    facts.push(`- ${t("facts.company")}: ${config.legal.company}`);
  // Solo el NOMBRE: `founder_role` no tiene traducción en el esquema, y un
  // cargo en el idioma equivocado es peor que no darlo. El `jobTitle` viaja
  // donde le corresponde, en el `Person` del JSON-LD.
  if (config.seo.founder_name) {
    facts.push(`- ${t("facts.founder")}: ${config.seo.founder_name}`);
  }
  if (config.seo.founding_date)
    facts.push(`- ${t("facts.founded")}: ${config.seo.founding_date}`);
  if (config.ecommerce.country)
    facts.push(`- ${t("facts.country")}: ${config.ecommerce.country}`);
  if (config.ecommerce.currency)
    facts.push(`- ${t("facts.currency")}: ${config.ecommerce.currency}`);
  if (config.legal.email) facts.push(`- ${t("facts.contact")}: ${config.legal.email}`);
  if (facts.length > 0) lines.push("", `## ${t("sections.facts")}`, ...facts);

  if (config.legal.email) {
    lines.push(
      "",
      `## ${t("sections.contact")}`,
      `- ${t("email")}: ${config.legal.email}`,
    );
  }

  lines.push(
    "",
    `## ${t("sections.resources")}`,
    `- [${t("sitemap")}](${base}/sitemap.xml)`,
  );
  lines.push("");
  return lines.join("\n");
}

/** Respuesta de texto plano con la misma política de caché que el sitemap. */
export function llmsResponse(body: string): Response {
  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=0, s-maxage=300, stale-while-revalidate=86400",
    },
  });
}
