import type { SiteConfig } from "@/lib/config";
import type { ProductDetail } from "@/types/catalog";
import type { FaqItem } from "./faq";

/**
 * Constructores de JSON-LD para SEO/GEO. Funciones puras: reciben los datos ya
 * resueltos (producto, config, URLs) y devuelven el objeto schema.org listo
 * para serializar. Mantener el markup aquí evita duplicarlo en cada página.
 */

const RETURN_CATEGORY_MAP: Record<string, string> = {
  finite: "https://schema.org/MerchantReturnFiniteReturnWindow",
  unlimited: "https://schema.org/MerchantReturnUnlimitedWindow",
  none: "https://schema.org/MerchantReturnNotPermitted",
};

/** Normaliza un precio de WooGraphQL ("&euro;19,99") a decimal con punto. */
function parsePrice(raw?: string | null): string {
  return (raw ?? "0")
    .replace(/[^\d.,]/g, "")
    .replace(/\.(?=\d{3})/g, "")
    .replace(",", ".");
}

/** Fecha (YYYY-MM-DD) a +1 año, para offers.priceValidUntil. */
function priceValidUntil(): string {
  const d = new Date();
  d.setFullYear(d.getFullYear() + 1);
  return d.toISOString().slice(0, 10);
}

export interface ProductJsonLdInput {
  product: ProductDetail;
  config: SiteConfig;
  /** URL canónica absoluta de la ficha. */
  url: string;
  /** Descripción ya en texto plano. */
  description: string;
}

/** JSON-LD `Product` con grado merchant listing (brand, rating, envío, devoluciones). */
export function buildProductJsonLd({
  product,
  config,
  url,
  description,
}: ProductJsonLdInput) {
  const currency = config.ecommerce.currency || "EUR";
  const country = config.ecommerce.country || "ES";
  const brandName = config.seo.product_brand || config.brand.name;
  const inStock = product.stockStatus !== "OUT_OF_STOCK";

  const offer: Record<string, unknown> = {
    "@type": "Offer",
    url,
    priceCurrency: currency,
    price: parsePrice(product.price),
    priceValidUntil: priceValidUntil(),
    itemCondition: "https://schema.org/NewCondition",
    availability: inStock
      ? "https://schema.org/InStock"
      : "https://schema.org/OutOfStock",
  };

  // shippingDetails (opcional, gobernado por el módulo SEO & GEO).
  if (config.seo.shipping_amount !== "") {
    offer.shippingDetails = {
      "@type": "OfferShippingDetails",
      shippingRate: {
        "@type": "MonetaryAmount",
        value: config.seo.shipping_amount,
        currency,
      },
      shippingDestination: {
        "@type": "DefinedRegion",
        addressCountry: country,
      },
    };
  }

  // hasMerchantReturnPolicy (opcional).
  if (config.seo.return_days !== "") {
    const category =
      RETURN_CATEGORY_MAP[config.seo.return_category] ?? RETURN_CATEGORY_MAP.finite;
    const policy: Record<string, unknown> = {
      "@type": "MerchantReturnPolicy",
      applicableCountry: country,
      returnPolicyCountry: country,
      returnPolicyCategory: category,
    };
    if (config.seo.return_category === "finite") {
      policy.merchantReturnDays = Number(config.seo.return_days) || 0;
    }
    offer.hasMerchantReturnPolicy = policy;
  }

  const jsonLd: Record<string, unknown> = {
    "@context": "https://schema.org/",
    "@type": "Product",
    name: product.name,
    image: product.image ? [product.image.sourceUrl] : [],
    description,
    sku: String(product.databaseId),
    brand: { "@type": "Brand", name: brandName },
    // speakable: marca los bloques óptimos para asistentes de voz/IA.
    speakable: {
      "@type": "SpeakableSpecification",
      cssSelector: [".product-name", ".product-summary"],
    },
    offers: offer,
  };

  // aggregateRating + review (solo con reseñas reales — exigido por Google).
  const reviews = product.reviews?.items ?? [];
  const rated = reviews.filter((r) => r.rating > 0);
  if (rated.length > 0 && product.reviews.averageRating > 0) {
    jsonLd.aggregateRating = {
      "@type": "AggregateRating",
      ratingValue: product.reviews.averageRating,
      reviewCount: rated.length,
    };
    jsonLd.review = rated.slice(0, 5).map((r) => ({
      "@type": "Review",
      reviewRating: { "@type": "Rating", ratingValue: r.rating, bestRating: 5 },
      author: { "@type": "Person", name: r.authorName },
      ...(r.date ? { datePublished: r.date } : {}),
      ...(r.content ? { reviewBody: r.content } : {}),
    }));
  }

  return jsonLd;
}

/** Quita la barra final de una URL base. */
function cleanBase(base: string): string {
  return (base || "").replace(/\/$/, "");
}

type SocialKind =
  | "twitter"
  | "instagram"
  | "facebook"
  | "youtube"
  | "linkedin"
  | "wikipedia"
  | "wikidata";

/** Tipos que solo se aceptan como URL completa (sin construcción desde handle). */
const URL_ONLY_KINDS = new Set<SocialKind>(["linkedin", "wikipedia", "wikidata"]);

/**
 * Convierte un valor social (handle "@x", slug o URL completa) en URL absoluta.
 * LinkedIn/Wikipedia/Wikidata solo se incluyen si ya son URL (formatos variables).
 */
function socialUrl(value: string, kind: SocialKind): string | null {
  const v = (value || "").trim();
  if (!v) return null;
  if (/^https?:\/\//i.test(v)) return v;
  if (URL_ONLY_KINDS.has(kind)) return null;
  const handle = v.replace(/^@/, "").trim();
  if (!handle) return null;
  const hosts: Partial<Record<SocialKind, string>> = {
    twitter: "https://twitter.com/",
    instagram: "https://instagram.com/",
    facebook: "https://facebook.com/",
    youtube: "https://youtube.com/@",
  };
  const host = hosts[kind];
  return host ? `${host}${handle}` : null;
}

/** Reúne los perfiles no vacíos como `sameAs` (ancla de entidad para IA). */
function collectSameAs(social: SiteConfig["social"]): string[] {
  return [
    socialUrl(social.twitter, "twitter"),
    socialUrl(social.instagram, "instagram"),
    socialUrl(social.facebook, "facebook"),
    socialUrl(social.youtube, "youtube"),
    socialUrl(social.linkedin, "linkedin"),
    socialUrl(social.wikipedia, "wikipedia"),
    socialUrl(social.wikidata, "wikidata"),
  ].filter((u): u is string => Boolean(u));
}

/** Parsea una lista separada por líneas o comas (p. ej. knowsAbout). */
function parseList(raw?: string | null): string[] {
  if (!raw) return [];
  return raw
    .split(/[\n,]/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

/** Nodo `Organization` (sin @context) para reutilizar en un @graph. */
function organizationNode(config: SiteConfig): Record<string, unknown> {
  const base = cleanBase(config.brand.url);
  const logo = config.seo.organization_logo || `${base}/api/icon?size=512`;
  const sameAs = collectSameAs(config.social);

  const org: Record<string, unknown> = {
    "@type": "Organization",
    ...(base ? { "@id": `${base}/#organization` } : {}),
    name: config.brand.name,
    url: base || undefined,
    logo,
  };
  if (config.brand.description) org.description = config.brand.description;
  if (config.seo.founding_date) org.foundingDate = config.seo.founding_date;
  if (config.legal.address) {
    org.address = {
      "@type": "PostalAddress",
      streetAddress: config.legal.address,
      addressCountry: config.ecommerce.country || undefined,
    };
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
  return org;
}

/** Nodo `WebSite` (sin @context) con `SearchAction` si la búsqueda está activa. */
function websiteNode(config: SiteConfig): Record<string, unknown> {
  const base = cleanBase(config.brand.url);
  const site: Record<string, unknown> = {
    "@type": "WebSite",
    ...(base
      ? { "@id": `${base}/#website`, publisher: { "@id": `${base}/#organization` } }
      : {}),
    name: config.brand.name,
    url: base || undefined,
  };
  if (config.brand.description) site.description = config.brand.description;
  if (config.ecommerce.search_enabled && base) {
    site.potentialAction = {
      "@type": "SearchAction",
      target: {
        "@type": "EntryPoint",
        urlTemplate: `${base}/products?search={search_term_string}`,
      },
      "query-input": "required name=search_term_string",
    };
  }
  return site;
}

/** JSON-LD `Organization` independiente (con @context). */
export function buildOrganizationJsonLd(config: SiteConfig) {
  return { "@context": "https://schema.org", ...organizationNode(config) };
}

/** JSON-LD `WebSite` independiente (con @context). */
export function buildWebsiteJsonLd(config: SiteConfig) {
  return { "@context": "https://schema.org", ...websiteNode(config) };
}

/**
 * Grafo unificado del sitio (Organization + WebSite) en un solo bloque @graph.
 * Se emite en el layout; el resto de páginas referencian sus @id por cruce.
 */
export function buildSiteGraph(config: SiteConfig) {
  return {
    "@context": "https://schema.org",
    "@graph": [organizationNode(config), websiteNode(config)],
  };
}

/**
 * JSON-LD `Person` para el responsable/fundador (señal E-E-A-T en /about).
 * Devuelve null si no hay nombre configurado.
 */
export function buildPersonJsonLd(config: SiteConfig): Record<string, unknown> | null {
  const name = (config.seo.founder_name || "").trim();
  if (!name) return null;
  const base = cleanBase(config.brand.url);
  const person: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "Person",
    name,
  };
  if (config.seo.founder_role) person.jobTitle = config.seo.founder_role;
  if (config.seo.founder_url) {
    person.url = config.seo.founder_url;
    person.sameAs = [config.seo.founder_url];
  }
  if (base) person.worksFor = { "@id": `${base}/#organization` };
  return person;
}

/** JSON-LD `FAQPage` (formato muy citado por motores generativos). */
export function buildFaqJsonLd(items: FaqItem[]) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };
}

export interface BreadcrumbItem {
  name: string;
  url: string;
}

/** JSON-LD `BreadcrumbList` a partir de una lista ordenada de migas. */
export function buildBreadcrumbJsonLd(items: BreadcrumbItem[]) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((item, i) => ({
      "@type": "ListItem",
      position: i + 1,
      name: item.name,
      item: item.url,
    })),
  };
}
