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
