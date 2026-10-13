import type { SiteConfig } from "@/lib/config/types";
import { brandDescription } from "@/lib/config/brand";
import { getConfiguredLocations } from "@/lib/config/locations";
import { collectSocialSameAs, dialableNumber } from "@/lib/config/social";
import type { ProductDetail } from "@/types/catalog";
import { routing } from "@/i18n/routing";
import { localizedPath } from "./urls";
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

const RETURN_METHOD_MAP: Record<string, string[]> = {
  mail: ["https://schema.org/ReturnByMail"],
  in_store: ["https://schema.org/ReturnInStore"],
  mail_and_store: [
    "https://schema.org/ReturnByMail",
    "https://schema.org/ReturnInStore",
  ],
};

/**
 * «El cliente paga el envío de la devolución» es `ReturnFeesCustomerResponsibility`
 * (el cliente asume su mensajería), NO `ReturnShippingFees`: ese significa que
 * la TIENDA cobra una tarifa de devolución, y Google exige entonces su importe
 * en `returnShippingFeesAmount`. Los términos de venta no fijan ninguna tarifa,
 * así que declararla sería inventarla.
 */
const RETURN_FEES_MAP: Record<string, string> = {
  free: "https://schema.org/FreeReturn",
  customer_pays: "https://schema.org/ReturnFeesCustomerResponsibility",
};

/**
 * Rango de días del panel («0-1», «1 - 5», «3») a `QuantitativeValue`. `null`
 * si no se entiende: un plazo mal escrito no se declara, no se adivina.
 */
export function parseDayRange(raw?: string | null): Record<string, unknown> | null {
  const m = (raw ?? "").trim().match(/^(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?$/);
  if (!m) return null;
  const min = Number(m[1]);
  const max = m[2] === undefined ? min : Number(m[2]);
  if (max < min) return null;
  return {
    "@type": "QuantitativeValue",
    minValue: min,
    maxValue: max,
    unitCode: "DAY",
  };
}

/** Fecha de hoy (YYYY-MM-DD), para offers.validFrom. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
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

/**
 * Importe o rango de importes del campo de envío del panel. `parseFloat` (no
 * `Number`) porque el campo admite texto suelto: `Number("12 EUR")` da NaN.
 * Un rango se escribe con guion («5-12»); al revés se ordena.
 */
export function parseAmountRange(raw: string): { min: number; max: number } | null {
  const text = (raw || "").trim();
  if (!text) return null;
  const range = /^(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)/.exec(text);
  if (range) {
    const a = Number.parseFloat(range[1].replace(",", "."));
    const b = Number.parseFloat(range[2].replace(",", "."));
    return { min: Math.min(a, b), max: Math.max(a, b) };
  }
  const single = Number.parseFloat(text);
  return Number.isNaN(single) ? null : { min: single, max: single };
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
    // Mismo criterio que `priceValidUntil`: el precio publicado vale desde el
    // día en que se sirve la ficha (ISR la regenera cada pocos minutos).
    validFrom: today(),
    priceValidUntil: priceValidUntil(),
    itemCondition: "https://schema.org/NewCondition",
    availability: inStock
      ? "https://schema.org/InStock"
      : "https://schema.org/OutOfStock",
  };

  // shippingDetails (opcional, gobernado por el módulo SEO & GEO). El campo del
  // panel admite un importe («5», o con texto suelto: «5 EUR») o un RANGO
  // («5-12»): con tarifas por zona (módulo shipping), declarar un importe único
  // era afirmar un coste que solo paga una parte de los compradores.
  const shipping = parseAmountRange(config.seo.shipping_amount);
  if (shipping) {
    offer.shippingDetails = {
      "@type": "OfferShippingDetails",
      shippingRate: {
        "@type": "MonetaryAmount",
        ...(shipping.min === shipping.max
          ? { value: shipping.min }
          : { minValue: shipping.min, maxValue: shipping.max }),
        currency,
      },
      shippingDestination: {
        "@type": "DefinedRegion",
        addressCountry: country,
      },
    };
    // deliveryTime: solo con los DOS plazos del panel. Google lo pide completo
    // (preparación + tránsito); medio plazo sería un dato inventado.
    const handlingTime = parseDayRange(config.seo.handling_days);
    const transitTime = parseDayRange(config.seo.transit_days);
    if (handlingTime && transitTime) {
      (offer.shippingDetails as Record<string, unknown>).deliveryTime = {
        "@type": "ShippingDeliveryTime",
        handlingTime,
        transitTime,
      };
    }
  }

  // hasMerchantReturnPolicy (opcional).
  // finite → requiere días; unlimited/none se emiten sin merchantReturnDays.
  const returnCategory = config.seo.return_category || "finite";
  const hasReturnDays = config.seo.return_days !== "";
  const emitReturnPolicy =
    returnCategory === "unlimited" ||
    returnCategory === "none" ||
    (returnCategory === "finite" && hasReturnDays);
  if (emitReturnPolicy) {
    const category = RETURN_CATEGORY_MAP[returnCategory] ?? RETURN_CATEGORY_MAP.finite;
    const policy: Record<string, unknown> = {
      "@type": "MerchantReturnPolicy",
      applicableCountry: country,
      returnPolicyCountry: country,
      returnPolicyCategory: category,
    };
    if (returnCategory === "finite") {
      policy.merchantReturnDays = Number(config.seo.return_days) || 0;
    }
    // Cómo y a cargo de quién: hechos de los términos de venta, declarados en
    // el panel. Sin rellenar no se emiten (Google avisa, pero no se inventa).
    if (returnCategory !== "none") {
      const methods = RETURN_METHOD_MAP[config.seo.return_method];
      if (methods) policy.returnMethod = methods.length === 1 ? methods[0] : methods;
      const fees = RETURN_FEES_MAP[config.seo.return_fees];
      if (fees) policy.returnFees = fees;
    }
    offer.hasMerchantReturnPolicy = policy;
  }

  const jsonLd: Record<string, unknown> = {
    "@context": "https://schema.org/",
    "@type": "Product",
    "@id": `${url}#product`,
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
  // `reviewCount` sale de las reseñas que trajo `getProductBySlug` (tope de
  // 100): coincide con `averageRating` salvo en un producto con más de esas
  // 100 reseñas, límite técnico de WPGraphQL que no vale la pena paginar aquí.
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

/** Parsea una lista separada por líneas o comas (p. ej. knowsAbout). */
export function parseList(raw?: string | null): string[] {
  if (!raw) return [];
  return raw
    .split(/[\n,]/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

/**
 * `PostalAddress` con las partes que Google pide por separado
 * (`addressLocality`, `addressRegion`, `postalCode`): sin ellas los
 * resultados enriquecidos avisan «Falta addressLocality / postalCode». Salen
 * de campos propios del panel, no de trocear el texto libre de la dirección;
 * un campo vacío simplemente no se emite.
 */
export function postalAddress(
  config: SiteConfig,
  parts: { street: string; city?: string; region?: string; postalCode?: string },
): Record<string, unknown> {
  const address: Record<string, unknown> = {
    "@type": "PostalAddress",
    streetAddress: parts.street,
  };
  const city = (parts.city || "").trim();
  const region = (parts.region || "").trim();
  const postalCode = (parts.postalCode || "").trim();
  if (city) address.addressLocality = city;
  if (region) address.addressRegion = region;
  if (postalCode) address.postalCode = postalCode;
  if (config.ecommerce.country) address.addressCountry = config.ecommerce.country;
  return address;
}

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

/**
 * JSON-LD `Article` para landings educativas y posts de blog.
 *
 * `author` y `publisher` son requeridos por Google para que un `Article` sea
 * elegible: sin `author` explícito se atribuye a la `Organization` del sitio
 * (referencia por `@id` al nodo del `@graph` global). `datePublished` /
 * `dateModified` se emiten solo si el llamante los tiene.
 */
export function buildArticleJsonLd(input: {
  headline: string;
  description: string;
  config: SiteConfig;
  image?: string;
  url: string;
  datePublished?: string;
  dateModified?: string;
  /** `id`: el `@id` de una entidad del grafo (p. ej. el del fundador). */
  author?: { name: string; url?: string; id?: string; description?: string };
  about?: string;
  /** `es` / `en` — el idioma REAL del cuerpo servido, no el de la URL. */
  inLanguage?: string;
  /** `BlogPosting` para las entradas del blog; `Article` por defecto. */
  type?: "Article" | "BlogPosting";
  /** Selectores CSS de los bloques óptimos para asistentes de voz/IA. */
  speakable?: string[];
}): Record<string, unknown> {
  const base = cleanBase(input.config.brand.url);
  const orgRef = base ? { "@id": `${base}/#organization` } : undefined;

  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": input.type ?? "Article",
    headline: input.headline,
    description: input.description,
    mainEntityOfPage: input.url,
    url: input.url,
  };
  if (input.image) node.image = [input.image];
  if (input.inLanguage) node.inLanguage = input.inLanguage;
  if (input.datePublished) node.datePublished = input.datePublished;
  if (input.dateModified) node.dateModified = input.dateModified;

  node.author = input.author
    ? {
        "@type": "Person",
        ...(input.author.id ? { "@id": input.author.id } : {}),
        name: input.author.name,
        ...(input.author.url ? { url: input.author.url } : {}),
        ...(input.author.description ? { description: input.author.description } : {}),
      }
    : (orgRef ?? { "@type": "Organization", name: input.config.brand.name });
  if (orgRef) node.publisher = orgRef;

  if (input.about) node.about = { "@id": input.about };
  if (input.speakable && input.speakable.length > 0) {
    node.speakable = {
      "@type": "SpeakableSpecification",
      cssSelector: input.speakable,
    };
  }
  return node;
}

/**
 * `uploadDate` como Google lo acepta para un vídeo: fecha y hora CON zona
 * horaria. El CMS guarda solo el día (`AAAA-MM-DD`, ver `isoDateOrMonth`) y un
 * día a secas hacía que Search Console avisara «falta la zona horaria» y, en
 * algunos casos, «valor de fecha y hora no válido».
 *
 * La hora real no se sabe: se emite mediodía UTC, que cae en el mismo día en
 * cualquier zona de -12 a +11 — la fecha que el cliente escribió no se desplaza. Una fecha con hora y zona se respeta tal cual. Un mes
 * a secas (`AAAA-MM`) o cualquier otra cosa devuelve `undefined`: Google lo da
 * por inválido, y mejor ningún dato que uno que invalide el nodo.
 */
export function videoUploadDateTime(value: string | undefined): string | undefined {
  const raw = (value ?? "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return `${raw}T12:00:00Z`;
  if (
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/.test(raw) &&
    !Number.isNaN(Date.parse(raw))
  ) {
    return raw;
  }
  return undefined;
}

/**
 * JSON-LD `VideoObject` para un embed de YouTube. `uploadDate` es opcional
 * aquí, pero Google la EXIGE: sin ella Search Console marca el vídeo («Falta el
 * campo uploadDate») y no hay resultado enriquecido — el nodo sigue siendo
 * señal útil para motores generativos. `thumbnailUrl` se deriva del ID de
 * YouTube.
 */
export function buildVideoObjectJsonLd(input: {
  name: string;
  description: string;
  youtubeId: string;
  uploadDate?: string;
}): Record<string, unknown> {
  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "VideoObject",
    name: input.name,
    description: input.description,
    thumbnailUrl: [
      `https://i.ytimg.com/vi/${input.youtubeId}/hqdefault.jpg`,
      `https://i.ytimg.com/vi/${input.youtubeId}/maxresdefault.jpg`,
    ],
    embedUrl: `https://www.youtube-nocookie.com/embed/${input.youtubeId}`,
    contentUrl: `https://www.youtube.com/watch?v=${input.youtubeId}`,
  };
  const uploadDate = videoUploadDateTime(input.uploadDate);
  if (uploadDate) node.uploadDate = uploadDate;
  return node;
}

/**
 * JSON-LD `Service` con sus `Offer` — para una página que publica TARIFAS de
 * algo que se contrata.
 *
 * Los importes NO se extraen del cuerpo de la página: ahí son prosa dentro de
 * un richtext y adivinarlos sería frágil. Vienen de campos propios del CMS, que
 * es lo único que puede viajar a un `Offer`.
 *
 * `provider` referencia a la organización por `@id` en vez de repetir sus
 * datos: el nodo ya existe en el grafo del sitio (`buildSiteGraph`).
 */
export function buildServiceJsonLd(input: {
  name: string;
  description: string;
  url: string;
  currency: string;
  offers: { name: string; price: string }[];
  config: SiteConfig;
}): Record<string, unknown> | null {
  if (input.offers.length === 0) return null;
  const base = cleanBase(input.config.brand.url);
  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "Service",
    name: input.name,
    description: input.description,
    url: input.url,
    offers: input.offers.map((offer) => ({
      "@type": "Offer",
      name: offer.name,
      price: offer.price,
      priceCurrency: input.currency,
      availability: "https://schema.org/InStock",
      url: input.url,
    })),
  };
  if (base) node.provider = { "@id": `${base}/#organization` };
  return node;
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

/**
 * Tipos schema.org admitidos para una sede (`seo.business_type`). La lista
 * debe coincidir con las opciones de `Schema.php` (lo comprueba
 * `jsonld.test.ts`): un valor que no esté aquí vuelve a `LocalBusiness`, nunca
 * se publica un `@type` que el panel no ofreció.
 */
export const BUSINESS_TYPES = [
  "LocalBusiness",
  "Store",
  "ClothingStore",
  "ShoeStore",
  "JewelryStore",
  "BookStore",
  "ElectronicsStore",
  "FurnitureStore",
  "HardwareStore",
  "PetStore",
  "SportingGoodsStore",
  "GroceryStore",
  "Florist",
  "Pharmacy",
  "HealthAndBeautyBusiness",
  "BeautySalon",
  "Restaurant",
  "CafeOrCoffeeShop",
  "Bakery",
  "ProfessionalService",
  "HomeAndConstructionBusiness",
] as const;

export function businessType(config: SiteConfig): string {
  const configured = (config.seo.business_type || "").trim();
  return (BUSINESS_TYPES as readonly string[]).includes(configured)
    ? configured
    : "LocalBusiness";
}

/**
 * JSON-LD de negocio local por sede configurada en Control Center → Legal →
 * Sedes, con el tipo de `seo.business_type`. Se emite solo en `/contact`, donde
 * las sedes ya son contenido visible — no en el `@graph` global.
 */
export function buildLocalBusinessJsonLd(
  config: SiteConfig,
): Record<string, unknown>[] {
  const base = cleanBase(config.brand.url);
  const locations = getConfiguredLocations(config);
  const type = businessType(config);

  return locations.map((loc, i) => {
    const node: Record<string, unknown> = {
      "@context": "https://schema.org",
      "@type": type,
      ...(base ? { "@id": `${base}/#location-${i + 1}` } : {}),
      name: loc.name,
      address: postalAddress(config, {
        street: loc.address,
        city: loc.city,
        region: loc.region,
        postalCode: loc.postalCode,
      }),
    };
    if (base) node.parentOrganization = { "@id": `${base}/#organization` };
    // Un número que ya trae su «+» se publica como está escrito; el que lleva
    // el indicativo a la antigua («(057) 321…») sale en E.164, que es lo que
    // se puede marcar.
    const dialable = dialableNumber(loc.phone);
    const telephone =
      dialable?.startsWith("+") && !loc.phone.trim().startsWith("+")
        ? dialable
        : loc.phone;
    if (telephone) node.telephone = telephone;
    if (loc.mapsUrl) node.hasMap = loc.mapsUrl;
    if (loc.latitude !== null && loc.longitude !== null) {
      node.geo = {
        "@type": "GeoCoordinates",
        latitude: loc.latitude,
        longitude: loc.longitude,
      };
    }
    if (loc.hours.length > 0) node.openingHours = loc.hours;
    return node;
  });
}

/** Tope de ítems en cualquier ItemList de catálogo (tienda/categoría/colecciones). */
export const COLLECTION_ITEM_LIST_LIMIT = 24;

export interface CollectionPageItem {
  name: string;
  url: string;
  image?: string | null;
}

export interface CollectionPageJsonLdInput {
  name: string;
  description?: string;
  url: string;
  items: CollectionPageItem[];
  /** Total real cuando `items` viene acotado a `COLLECTION_ITEM_LIST_LIMIT`. */
  totalItems?: number;
}

/** JSON-LD `CollectionPage` + `ItemList` para listados de productos. */
export function buildCollectionPageJsonLd({
  name,
  description,
  url,
  items,
  totalItems,
}: CollectionPageJsonLdInput): Record<string, unknown> {
  return {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    name,
    ...(description ? { description } : {}),
    url,
    mainEntity: {
      "@type": "ItemList",
      numberOfItems: totalItems ?? items.length,
      itemListElement: items.map((item, i) => ({
        "@type": "ListItem",
        position: i + 1,
        name: item.name,
        url: item.url,
        ...(item.image ? { image: item.image } : {}),
      })),
    },
  };
}
