import type { SiteConfig } from "@/lib/config/types";
import { brandDescription } from "@/lib/config/brand";
import { collectSocialSameAs } from "@/lib/config/social";
import { routing } from "@/i18n/routing";
import { localizedPath } from "../urls";
import { cleanBase, parseList, postalAddress } from "./shared";

// ============================================================================
//  Grafo del sitio (`Organization` + `WebSite` + fundador) y personas. Los `@id`
//  son estables: el resto de páginas los referencian por cruce. Funciones puras.
// ============================================================================

/** Nodo `Organization` (sin @context) para reutilizar en un @graph. */
function organizationNode(config: SiteConfig, locale: string): Record<string, unknown> {
  const base = cleanBase(config.brand.url);
  const logo = config.seo.organization_logo || `${base}/api/icon?size=512`;
  const sameAs = collectSocialSameAs(config.social);

  const org: Record<string, unknown> = {
    "@type": "Organization",
    ...(base ? { "@id": `${base}/#organization` } : {}),
    name: config.brand.name,
    url: base || undefined,
    logo,
  };
  const description = brandDescription(config, locale);
  if (description) org.description = description;
  // La marca puede tener más de un nombre en circulación (el del sitio
  // anterior, la razón social): declararlos es lo que deja a un buscador
  // unirlos en UNA entidad en vez de tratarlos como negocios distintos.
  const alternateNames = parseList(config.seo.alternate_names).filter(
    (name) => name !== config.brand.name,
  );
  if (alternateNames.length > 0) org.alternateName = alternateNames;
  const legalName = (config.legal.company || "").trim();
  if (legalName && legalName !== config.brand.name) org.legalName = legalName;
  if (config.seo.founding_date) org.foundingDate = config.seo.founding_date;
  if (config.legal.address) {
    org.address = postalAddress(config, { street: config.legal.address });
  }
  if (config.legal.email) {
    org.contactPoint = {
      "@type": "ContactPoint",
      contactType: "customer support",
      email: config.legal.email,
    };
  }
  const knowsAbout = parseList(config.seo.knows_about);
  if (knowsAbout.length > 0) org.knowsAbout = knowsAbout;
  if (sameAs.length > 0) org.sameAs = sameAs;
  // El fundador es una ENTIDAD del grafo, no un texto: aquí solo se declara la
  // arista por `@id`. El mismo `@graph` lleva un `Person` de referencia
  // (`founderNode`) para que la arista resuelva en cualquier documento, y el
  // nodo completo lo emite la landing del fundador con el mismo `@id`.
  if (base && (config.seo.founder_name || "").trim()) {
    org.founder = { "@id": `${base}/#founder` };
  }
  return org;
}

/** Nodo `WebSite` (sin @context) con `SearchAction` si la búsqueda está activa. */
function websiteNode(config: SiteConfig, locale: string): Record<string, unknown> {
  const base = cleanBase(config.brand.url);
  const site: Record<string, unknown> = {
    "@type": "WebSite",
    ...(base
      ? { "@id": `${base}/#website`, publisher: { "@id": `${base}/#organization` } }
      : {}),
    name: config.brand.name,
    url: base || undefined,
  };
  const description = brandDescription(config, locale);
  if (description) site.description = description;
  if (config.ecommerce.search_enabled && base) {
    // URL pública del catálogo en el locale canónico.
    const storePath = localizedPath("/products", routing.defaultLocale);
    site.potentialAction = {
      "@type": "SearchAction",
      target: {
        "@type": "EntryPoint",
        urlTemplate: `${base}${storePath}?search={search_term_string}`,
      },
      "query-input": "required name=search_term_string",
    };
  }
  return site;
}

/**
 * Grafo unificado del sitio (Organization + WebSite + fundador) en un solo bloque @graph.
 * Se emite en el layout; el resto de páginas referencian sus @id por cruce.
 */
export function buildSiteGraph(
  config: SiteConfig,
  locale: string,
  options: { founderPageUrl?: string | null } = {},
) {
  // El @graph acompaña a una página declarada en `locale`: su `description`
  // tiene que estar en ese idioma. Los `@id` no cambian (misma entidad).
  const founder = founderNode(config, options.founderPageUrl);
  return {
    "@context": "https://schema.org",
    "@graph": [
      organizationNode(config, locale),
      websiteNode(config, locale),
      ...(founder ? [founder] : []),
    ],
  };
}

/**
 * `@id` del fundador: el mismo en el `@graph` de todas las páginas, en el
 * `Person` completo de su landing y en la firma de los artículos que firma.
 * `null` sin nombre configurado o sin URL de sitio.
 */
export function founderId(config: SiteConfig): string | null {
  const base = cleanBase(config.brand.url);
  if (!base || !(config.seo.founder_name || "").trim()) return null;
  return `${base}/#founder`;
}

/**
 * Nodo `Person` (sin @context) del responsable/fundador, señal E-E-A-T.
 * Va en el `@graph` del layout para que la arista `founder` de la
 * Organization resuelva en CUALQUIER documento, pero es solo la referencia:
 * el nodo completo (foto, biografía, `mainEntityOfPage`) lo emite su landing
 * con el mismo `@id`, y `pageUrl` —la URL de esa landing— es su `url`. Sin
 * landing, la `url` cae al perfil externo. Devuelve null si no hay nombre.
 */
export function founderNode(
  config: SiteConfig,
  pageUrl?: string | null,
): Record<string, unknown> | null {
  const name = (config.seo.founder_name || "").trim();
  if (!name) return null;
  const base = cleanBase(config.brand.url);
  const id = founderId(config);
  const person: Record<string, unknown> = {
    "@type": "Person",
    // `@id` estable: es el otro extremo de la arista `founder` que declara la
    // Organization. Sin él son dos personas distintas para un consumidor del
    // grafo.
    ...(id ? { "@id": id } : {}),
    name,
  };
  if (config.seo.founder_role) person.jobTitle = config.seo.founder_role;
  const url = pageUrl || config.seo.founder_url;
  if (url) person.url = url;
  if (config.seo.founder_url) person.sameAs = [config.seo.founder_url];
  if (base) person.worksFor = { "@id": `${base}/#organization` };
  return person;
}

/**
 * Documento JSON-LD del responsable/fundador para su página («Sobre nosotros»):
 * el MISMO `@id` que el nodo de referencia del `@graph` del layout, pero con
 * `@context` propio y `mainEntityOfPage`. `null` sin nombre configurado.
 */
export function buildFounderPageJsonLd(
  config: SiteConfig,
  pageUrl: string,
): Record<string, unknown> | null {
  const node = founderNode(config, pageUrl);
  if (!node) return null;
  return { "@context": "https://schema.org", ...node, mainEntityOfPage: pageUrl };
}

/**
 * JSON-LD `Person` para una persona explícita (p. ej. el autor de una landing).
 * Retorna siempre un objeto, incluso sin todos los campos opcionales.
 */
export function buildPersonJsonLdFor(
  person: {
    name: string;
    jobTitle?: string;
    image?: string;
    url?: string;
    sameAs?: string[];
  },
  config: SiteConfig,
): Record<string, unknown> {
  const base = cleanBase(config.brand.url);
  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "Person",
    name: person.name,
  };
  if (person.jobTitle) node.jobTitle = person.jobTitle;
  if (person.image) node.image = person.image;
  if (person.url) node.url = person.url;
  if (person.sameAs && person.sameAs.length) node.sameAs = person.sameAs;
  if (base) node.worksFor = { "@id": `${base}/#organization` };
  return node;
}
