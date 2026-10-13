import type { BannerSlide } from "@/lib/banners/types";
import type { BaseSectionId } from "@/lib/landings/registry";

// ============================================================================
//  Modelo de la landing de producto (módulo `product-landing`), ya resuelto al
//  idioma de la página y saneado. Lo construye `normalizeProductLanding`
//  (`product-landing.ts`) a partir de `Product.landing` de WPGraphQL.
// ============================================================================

/** Componente principal del producto (lista «Sobre el producto»). */
export interface LandingComponent {
  image: string | null;
  /** Clave del set de íconos (`hwe_product_landing_icons`): respaldo si no hay imagen. */
  icon: string | null;
  name: string;
  note: string | null;
}

/** Cualidad destacada. */
export interface LandingQuality {
  icon: string | null;
  /** Reemplaza al ícono si existe. */
  image: string | null;
  title: string;
  text: string | null;
}

/**
 * Consejo o pregunta. Con `heading` es una pregunta desplegable; sin él, un
 * ítem de la lista de consejos. `body` es texto plano (el plugin elimina el
 * HTML al guardar): se pinta como texto, nunca como HTML.
 */
export interface LandingAdvice {
  heading: string | null;
  body: string;
}

export interface ProductLanding {
  /**
   * Idioma real del contenido servido. En un idioma secundario sin traducción
   * de los campos de peso (`LANDING_PROBE_FIELDS`) la landing sirve el idioma
   * por defecto: entonces vale ese idioma y la vista marca `lang` sobre el
   * subárbol para que lectores de pantalla y buscadores no lo lean como el de
   * la página (WCAG 3.1.2).
   */
  contentLang: string;

  /**
   * Idiomas del sitio en los que la landing existe de verdad (el por defecto,
   * siempre). Sin traducción no se anuncia el idioma: ni hreflang ni sitemap.
   */
  locales: string[];

  seoTitle: string | null;
  seoDescription: string | null;
  seoOgImage: string | null;

  /**
   * Excepción de paleta de esta landing. Cada hex `#rrggbb` es opcional; `null`
   * = ese rol usa el color de la plantilla base. Se valida antes de inyectarse
   * como propiedad CSS.
   */
  accents: { primary: string | null; secondary: string | null };

  heroSubtitle: string | null;
  heroImage: string | null;

  edu: {
    enabled: boolean;
    kicker: string | null;
    title: string | null;
    /** HTML saneado. */
    body: string | null;
    image: string | null;
  };
  about: {
    title: string | null;
    /** HTML saneado. */
    body: string | null;
    components: LandingComponent[];
  };
  qualities: {
    enabled: boolean;
    title: string | null;
    items: LandingQuality[];
  };
  advice: {
    enabled: boolean;
    title: string | null;
    /** HTML saneado. */
    intro: string | null;
    items: LandingAdvice[];
  };
  cta: { title: string | null; text: string | null };
  video: {
    enabled: boolean;
    url: string | null;
    caption: string | null;
    /** `AAAA-MM-DD` o `AAAA-MM` ya validada → `uploadDate`, o `null`. */
    uploadDate: string | null;
    /** Portada propia de la mediateca. `null` = la que genera YouTube. */
    poster: string | null;
  };
  closing: {
    title: string | null;
    /** HTML saneado. */
    text: string | null;
    ctaLabel: string | null;
    ctaHref: string | null;
  };
}

// ============================================================================
//  Landing editorial (módulo `landings`): el contenido de una landing del panel o
//  con diseño propio, ya resuelto al idioma y saneado. Lo construye
//  `getEditorialLanding` (`editorial-landing.ts`) a partir de `config.landings`.
// ============================================================================

/**
 * Una sección de contenido del molde. `id` es el SLOT del CMS (`s1`…`s5`), no la posición en el
 * flujo: el orden de render lo fija `order`. Con ids posicionales una sección única no podía
 * leer «el cuerpo de s3» con seguridad: bastaba desactivar una sección anterior para que
 * apuntara a otra.
 */
export interface LandingContentSection {
  id: "s1" | "s2" | "s3" | "s4" | "s5";
  layout:
    | "image-left"
    | "image-right"
    | "full"
    | "text-only"
    | "prose-blocks"
    | "divider"
    | "mirror-pair";
  kicker: string | null;
  title: string | null;
  /** HTML saneado. */
  body: string | null;
  image: string | null;
  /**
   * Texto alternativo de `image`, escrito por el editor. `null` cuando no lo escribió: entonces
   * la imagen se pinta DECORATIVA (`alt=""`), no con el título de la sección — repetir el
   * titular no describe la fotografía.
   */
  imageAlt: string | null;
  /** Segunda imagen (cuarto plano de «Pareja en espejo») y su alternativo. */
  image2: string | null;
  image2Alt: string | null;
}

export interface LandingFaqEntry {
  question: string;
  /** HTML saneado. */
  answer: string;
}

/** Un producto de la banda de productos (lo que la vista necesita de la ficha). */
export interface LandingProductCard {
  databaseId: number;
  slug: string;
  urlSlug: string;
  name: string;
  price: string | null;
  image: { sourceUrl: string; altText: string } | null;
}

export interface EditorialLanding {
  /** Identificador interno de la landing. */
  slug: string;
  /**
   * Idioma real del contenido servido. En un idioma secundario sin traducción de los campos de
   * peso, la landing sirve el idioma por defecto: entonces vale ese idioma.
   */
  contentLang: string;
  /**
   * Idiomas del sitio en los que la landing existe de verdad (el por defecto, siempre). Sin
   * traducción no se anuncia el idioma: ni hreflang ni sitemap. Distinto de `contentLang`, que
   * dice qué idioma se sirve AHORA.
   */
  locales: string[];
  hero: BannerSlide | null;
  /** Color de apoyo `#rrggbb` ya validado, o `null`. */
  accent: string | null;
  /** HTML saneado. */
  lead: string | null;
  /** Ya ordenadas por `order` y solo las `enabled`. */
  sections: LandingContentSection[];
  quote: {
    text: string;
    author: string | null;
    photo: string | null;
    backgroundImage: string | null;
    /** Marca que firma la banda, centrada bajo el texto. */
    logo: string | null;
    logoAlt: string | null;
  } | null;
  faq: { title: string | null; items: LandingFaqEntry[] } | null;
  products: {
    title: string | null;
    items: LandingProductCard[];
    /** Slot tras el que se pinta (por defecto `faq`). */
    after: BaseSectionId;
    /** Slug de la categoría en modo «por categoría»; `null` en manual. */
    categorySlug: string | null;
  } | null;
  /** Producto asociado (slug nativo), o `null`. */
  product: string | null;
  productCtaLabel: string | null;
  closing: {
    title: string | null;
    /** HTML saneado. */
    text: string | null;
    ctaLabel: string | null;
    /** Enlace del CMS SIN validar: pasa por `classifyCmsHref` al pintarlo. */
    ctaHref: string | null;
    cta2Label: string | null;
    cta2Href: string | null;
    backgroundImage: string | null;
  } | null;
  seo: {
    title: string | null;
    description: string | null;
    ogImage: string | null;
    /** `AAAA-MM-DD` ya validada, o `null`. */
    datePublished: string | null;
    dateModified: string | null;
    /** Firma del artículo; `null` = la firma la organización. `landingSlug` es un IDENTIFICADOR. */
    author: { name: string; landingSlug: string | null } | null;
  };
}
