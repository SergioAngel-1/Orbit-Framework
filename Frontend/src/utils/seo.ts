/**
 * Utilidades SEO dinámicas
 * Gestión dinámica de meta tags, Open Graph, Twitter Cards y Schema.org
 * 
 * Los textos se cargan desde los archivos de traducción (locales/es|en/common/seo.json)
 * para soportar internacionalización.
 * 
 * BASE_URL y SITE_NAME se leen dinámicamente desde el caché de SiteConfig (localStorage).
 */

import i18n from '../config/i18n';
import { buildLocalizedPath, getLangFromPath } from '../contexts/LanguageContext';

export interface SEOConfig {
  title: string;
  description: string;
  keywords?: string;
  image?: string;
  url?: string;
  canonicalUrl?: string; // URL canónica diferente a url (para páginas con parámetros)
  type?: 'website' | 'article' | 'product';
  noIndex?: boolean;
  noCanonical?: boolean; // No generar canonical (para páginas con parámetros dinámicos)
  schema?: Record<string, unknown>;
}

// ── Helpers para leer config dinámica desde caché localStorage ──
const SITE_CONFIG_CACHE_KEY = 'site_config_cache';

function getSiteConfig(): Record<string, any> | null {
  try {
    const raw = localStorage.getItem(SITE_CONFIG_CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed?.data ?? parsed;
  } catch { return null; }
}

export function getBaseUrl(): string {
  return getSiteConfig()?.urls?.frontend_url || window.location.origin;
}

function getSiteName(): string {
  return getSiteConfig()?.identity?.site_name || 'My Store';
}

function getCurrencyCode(): string {
  return getSiteConfig()?.currency?.currency_code || 'USD';
}

// Accesores que se evalúan cada vez (no constantes) para reflejar config dinámica
function getDefaultImage(): string {
  return `${getBaseUrl()}/assets/images/og/OG-Home.webp`;
}

// Imágenes OG específicas por página (evaluadas dinámicamente)
export function getOgImages() {
  const base = getBaseUrl();
  return {
    home: `${base}/assets/images/og/OG-Home.webp`,
    catalogo: `${base}/assets/images/og/OG-Catalogo.webp`,
    membresias: `${base}/assets/images/og/OG-Membresias.webp`,
    referidos: `${base}/assets/images/og/OG-Referidos.webp`,
    contacto: `${base}/assets/images/og/OG-Contacto.webp`,
    wallet: `${base}/assets/images/og/OG-Wallet.webp`,
  };
}
// Alias retrocompatible (evaluación lazy via Proxy)
export const OG_IMAGES = new Proxy({} as Record<string, string>, {
  get(_t, prop: string) { return (getOgImages() as any)[prop]; },
  ownKeys() { return Object.keys(getOgImages()); },
  getOwnPropertyDescriptor(_t, p: string) {
    const imgs = getOgImages();
    return p in imgs ? { configurable: true, enumerable: true, value: (imgs as any)[p] } : undefined;
  },
});

/**
 * Obtiene las configuraciones SEO predefinidas para cada página.
 * Se evalúa dinámicamente para respetar el idioma activo.
 */
export function getSEOConfigs(): Record<string, SEOConfig> {
  const BASE_URL = getBaseUrl();
  return {
    home: {
      title: i18n.t('seo:pages.home.title'),
      description: i18n.t('seo:pages.home.description'),
      keywords: i18n.t('seo:pages.home.keywords'),
      url: BASE_URL,
      type: 'website',
    },
    catalogo: {
      title: i18n.t('seo:pages.catalogo.title'),
      description: i18n.t('seo:pages.catalogo.description'),
      keywords: i18n.t('seo:pages.catalogo.keywords'),
      url: `${BASE_URL}/catalogo`,
      type: 'website',
    },
    membresias: {
      title: i18n.t('seo:pages.membresias.title'),
      description: i18n.t('seo:pages.membresias.description'),
      keywords: i18n.t('seo:pages.membresias.keywords'),
      url: `${BASE_URL}/membresias`,
      type: 'website',
    },
    referidos: {
      title: i18n.t('seo:pages.referidos.title'),
      description: i18n.t('seo:pages.referidos.description'),
      keywords: i18n.t('seo:pages.referidos.keywords'),
      url: `${BASE_URL}/invitados`,
      type: 'website',
    },
    contacto: {
      title: i18n.t('seo:pages.contacto.title'),
      description: i18n.t('seo:pages.contacto.description'),
      keywords: i18n.t('seo:pages.contacto.keywords'),
      url: `${BASE_URL}/contacto`,
      type: 'website',
    },
    privacidad: {
      title: i18n.t('seo:pages.privacidad.title'),
      description: i18n.t('seo:pages.privacidad.description'),
      url: `${BASE_URL}/privacidad`,
      type: 'website',
    },
    terminos: {
      title: i18n.t('seo:pages.terminos.title'),
      description: i18n.t('seo:pages.terminos.description'),
      url: `${BASE_URL}/terminos`,
      type: 'website',
    },
    'guia-requisa': {
      title: i18n.t('seo:pages.guia-requisa.title'),
      description: i18n.t('seo:pages.guia-requisa.description'),
      url: `${BASE_URL}/guia-requisa`,
      type: 'website',
    },
    'marco-legal': {
      title: i18n.t('seo:pages.marco-legal.title'),
      description: i18n.t('seo:pages.marco-legal.description'),
      url: `${BASE_URL}/marco-legal`,
      type: 'website',
    },
    'politica-invitados': {
      title: i18n.t('seo:pages.politica-invitados.title'),
      description: i18n.t('seo:pages.politica-invitados.description'),
      url: `${BASE_URL}/politica-invitados`,
      type: 'website',
    },
    toures: {
      title: i18n.t('seo:pages.toures.title'),
      description: i18n.t('seo:pages.toures.description'),
      keywords: i18n.t('seo:pages.toures.keywords'),
      url: `${BASE_URL}/toures`,
      type: 'website',
    },
    login: {
      title: i18n.t('seo:pages.login.title'),
      description: i18n.t('seo:pages.login.description'),
      url: `${BASE_URL}/iniciar-sesion`,
      type: 'website',
      noIndex: true,
    },
    register: {
      title: i18n.t('seo:pages.register.title'),
      description: i18n.t('seo:pages.register.description'),
      keywords: i18n.t('seo:pages.register.keywords'),
      url: `${BASE_URL}/registrarse`,
      canonicalUrl: `${BASE_URL}/registrarse`,
      type: 'website',
    },
    faq: {
      title: i18n.t('seo:pages.faq.title'),
      description: i18n.t('seo:pages.faq.description'),
      keywords: i18n.t('seo:pages.faq.keywords'),
      url: `${BASE_URL}/faq`,
      type: 'website',
    },
  };
}

// Tipo de las claves de configuración SEO por página
export type SEOPageKey = keyof ReturnType<typeof getSEOConfigs>;

// Getter dinámico para compatibilidad con imports existentes.
// NUNCA cachear el resultado: siempre llama a getSEOConfigs() para respetar el idioma activo.
export const SEO_CONFIGS: Record<string, SEOConfig> = new Proxy({} as Record<string, SEOConfig>, {
  get(_target, prop: string) {
    return getSEOConfigs()[prop];
  },
  ownKeys() {
    return Object.keys(getSEOConfigs());
  },
  getOwnPropertyDescriptor(_target, prop: string) {
    const configs = getSEOConfigs();
    if (prop in configs) {
      return { configurable: true, enumerable: true, value: configs[prop] };
    }
    return undefined;
  },
  has(_target, prop: string) {
    return prop in getSEOConfigs();
  },
});

/**
 * Actualiza los meta tags del documento
 */
function updateMetaTag(name: string, content: string, isProperty = false): void {
  const attribute = isProperty ? 'property' : 'name';
  let element = document.querySelector(`meta[${attribute}="${name}"]`);
  
  if (!element) {
    element = document.createElement('meta');
    element.setAttribute(attribute, name);
    document.head.appendChild(element);
  }
  
  element.setAttribute('content', content);
}

/**
 * Actualiza o crea el link canonical
 */
function updateCanonical(url: string): void {
  let link = document.querySelector('link[rel="canonical"]') as HTMLLinkElement;
  
  if (!link) {
    link = document.createElement('link');
    link.rel = 'canonical';
    document.head.appendChild(link);
  }
  
  link.href = url;
}

/**
 * Localiza recursivamente las URLs del sitio dentro de un objeto Schema.org.
 * Aplica localizeUrl() a cualquier string que comience con BASE_URL,
 * asegurando que el prefijo /en/ se agregue o quite según el idioma activo.
 */
function localizeSchemaUrls(obj: unknown): unknown {
  const baseUrl = getBaseUrl();
  if (typeof obj === 'string') {
    return obj.startsWith(baseUrl) ? localizeUrl(obj) : obj;
  }
  if (Array.isArray(obj)) {
    return obj.map(localizeSchemaUrls);
  }
  if (obj !== null && typeof obj === 'object') {
    const result: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(obj as Record<string, unknown>)) {
      result[key] = localizeSchemaUrls(value);
    }
    return result;
  }
  return obj;
}

/**
 * Actualiza o crea el script de Schema.org
 * Las URLs del sitio dentro del schema se localizan automáticamente según el idioma activo.
 */
function updateSchema(schema: Record<string, unknown>): void {
  const existingScript = document.querySelector('script[data-seo-schema]');
  if (existingScript) {
    existingScript.remove();
  }
  
  const localizedSchema = localizeSchemaUrls(schema) as Record<string, unknown>;
  
  const script = document.createElement('script');
  script.type = 'application/ld+json';
  script.setAttribute('data-seo-schema', 'true');
  script.textContent = JSON.stringify(localizedSchema);
  document.head.appendChild(script);
}

/**
 * Ajusta una URL según el idioma activo de i18n.
 * Traduce los slugs de las rutas estáticas (ej. /catalogo → /catalog para EN).
 * Esto permite que las páginas pasen URLs base en ES y el sistema las ajuste automáticamente.
 */
function localizeUrl(url: string): string {
  const lang = (i18n.language || 'es') as 'es' | 'en';
  try {
    const parsed = new URL(url);
    // Obtener el path canónico en ES (sin prefijo de idioma)
    const { pathWithoutLang: esPath } = getLangFromPath(parsed.pathname);
    // Construir el path localizado con slugs traducidos
    parsed.pathname = buildLocalizedPath(esPath, lang);
    return parsed.toString().replace(/\/$/, '') || parsed.origin;
  } catch {
    return url;
  }
}

/**
 * Actualiza o crea los link hreflang para idiomas alternos
 * ES = URL sin prefijo (default), EN = URL con /en/ prefijo y slugs traducidos
 */
function updateHreflang(url: string): void {
  const parsedUrl = new URL(url);
  // Obtener el path canónico en ES
  const { pathWithoutLang: esCanonicalPath } = getLangFromPath(parsedUrl.pathname);
  // Construir ambas versiones con slugs traducidos
  const esPath = buildLocalizedPath(esCanonicalPath, 'es');
  const enPath = buildLocalizedPath(esCanonicalPath, 'en');

  const esUrl = `${parsedUrl.origin}${esPath}`;
  const enUrl = `${parsedUrl.origin}${enPath}`;

  const hreflangConfigs = [
    { hreflang: 'es', href: esUrl },
    { hreflang: 'en', href: enUrl },
    { hreflang: 'x-default', href: esUrl },
  ];

  for (const { hreflang, href } of hreflangConfigs) {
    let link = document.querySelector(`link[rel="alternate"][hreflang="${hreflang}"]`) as HTMLLinkElement;
    if (!link) {
      link = document.createElement('link');
      link.rel = 'alternate';
      link.setAttribute('hreflang', hreflang);
      document.head.appendChild(link);
    }
    link.href = href;
  }
}

/**
 * Aplica configuración SEO completa a la página actual
 */
export function applySEO(config: SEOConfig): void {
  const {
    title,
    description,
    keywords,
    image = getDefaultImage(),
    url = getBaseUrl(),
    canonicalUrl,
    type = 'website',
    noIndex = false,
    noCanonical = false,
    schema,
  } = config;

  // HTML lang dinámico según idioma activo
  document.documentElement.lang = i18n.language || 'es';

  // Title
  document.title = title;
  
  // Meta básicos
  updateMetaTag('description', description);
  if (keywords) {
    updateMetaTag('keywords', keywords);
  } else {
    // Limpiar keywords de la página anterior si esta no define
    const existingKeywords = document.querySelector('meta[name="keywords"]');
    if (existingKeywords) existingKeywords.remove();
  }
  
  // Robots
  updateMetaTag('robots', noIndex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
  
  // Localizar URL según idioma activo (agrega /en/ si estamos en inglés)
  const localizedUrl = localizeUrl(url);
  const localizedCanonical = canonicalUrl ? localizeUrl(canonicalUrl) : localizedUrl;

  // Canonical - usar canonicalUrl localizada si existe, o url localizada
  if (!noCanonical) {
    updateCanonical(localizedCanonical);
  } else {
    // Remover canonical si existe y noCanonical es true
    const existingCanonical = document.querySelector('link[rel="canonical"]');
    if (existingCanonical) {
      existingCanonical.remove();
    }
  }
  
  // Open Graph
  updateMetaTag('og:title', title, true);
  updateMetaTag('og:description', description, true);
  updateMetaTag('og:image', image, true);
  updateMetaTag('og:url', localizedUrl, true);
  updateMetaTag('og:type', type, true);
  updateMetaTag('og:site_name', getSiteName(), true);
  updateMetaTag('og:locale', i18n.t('seo:ogLocale'), true);
  // og:locale:alternate para el idioma alterno
  const currentLang = i18n.language || 'es';
  updateMetaTag('og:locale:alternate', currentLang === 'es' ? 'en_US' : 'es_CO', true);
  
  // Hreflang tags para SEO multiidioma
  if (!noIndex) {
    updateHreflang(url);
  } else {
    // Remover hreflang tags si la página es noIndex
    document.querySelectorAll('link[rel="alternate"][hreflang]').forEach(el => el.remove());
  }

  // Twitter Card
  updateMetaTag('twitter:card', 'summary_large_image');
  updateMetaTag('twitter:title', title);
  updateMetaTag('twitter:description', description);
  updateMetaTag('twitter:image', image);
  updateMetaTag('twitter:url', localizedUrl);
  
  // Schema.org — limpiar schema anterior si esta página no define uno
  if (schema) {
    updateSchema(schema);
  } else {
    const existingSchema = document.querySelector('script[data-seo-schema]');
    if (existingSchema) existingSchema.remove();
  }
}

/**
 * Aplica SEO usando una configuración predefinida por nombre de página
 */
export function applySEOByPage(pageName: string): void {
  const configs = getSEOConfigs();
  const config = configs[pageName];
  if (config) {
    applySEO(config);
  }
}

/**
 * Construye Schema.org Product enriquecido para WooCommerce.
 * Fuente de verdad única: usada por useSEOProduct y ProductDetailPage.
 * @returns undefined si name está vacío (producto aún no cargado)
 */
export function buildProductSchema(params: {
  name: string;
  description: string;
  url: string;
  image?: string;
  sku?: string;
  price?: number;
  stockStatus?: string;
  averageRating?: string;
  ratingCount?: number;
}): Record<string, unknown> | undefined {
  if (!params.name) return undefined;

  const availabilityMap: Record<string, string> = {
    instock: 'https://schema.org/InStock',
    outofstock: 'https://schema.org/OutOfStock',
    onbackorder: 'https://schema.org/PreOrder',
  };
  const availability = availabilityMap[params.stockStatus || 'instock'] || 'https://schema.org/InStock';

  const schema: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: params.name,
    description: params.description,
    image: params.image,
    url: params.url,
    sku: params.sku || undefined,
    brand: { '@type': 'Organization', name: getSiteName() },
    offers: params.price ? {
      '@type': 'Offer',
      price: params.price,
      priceCurrency: getCurrencyCode(),
      availability,
      url: params.url,
      seller: { '@type': 'Organization', name: getSiteName() },
    } : undefined,
  };

  const rating = parseFloat(params.averageRating || '0');
  if (rating > 0 && params.ratingCount && params.ratingCount > 0) {
    schema.aggregateRating = {
      '@type': 'AggregateRating',
      ratingValue: rating,
      reviewCount: params.ratingCount,
    };
  }

  return schema;
}

/**
 * Genera Schema.org para una página de FAQ
 */
export function generateFAQSchema(faqs: Array<{ question: string; answer: string }>): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map(faq => ({
      '@type': 'Question',
      name: faq.question,
      acceptedAnswer: {
        '@type': 'Answer',
        text: faq.answer,
      },
    })),
  };
}

/**
 * Genera Schema.org para BreadcrumbList
 */
export function generateBreadcrumbSchema(items: Array<{ name: string; url: string }>): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.url,
    })),
  };
}

export default {
  applySEO,
  applySEOByPage,
  buildProductSchema,
  generateFAQSchema,
  generateBreadcrumbSchema,
  getSEOConfigs,
};
