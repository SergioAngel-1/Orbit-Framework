export interface BannerSlide {
  id: string | number;
  /** Vacío cuando el visual es decorativo (`decorMode` default|custom). */
  image: string;
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  /** CTA secundario (p. ej. enlace subrayado junto al botón principal). */
  cta2?: string;
  cta2Href?: string;
  badge?: string;
  imageMobile?: string;
  /**
   * Texto alternativo de la fotografía (campo propio del slide, traducible).
   * Ausente = el componente cae al `title`. Un slide de solo imagen con enlace lo
   * necesita: es el único nombre accesible de ese enlace.
   */
  imageAlt?: string;
  link?: string;
  hideOverlay?: boolean;
  order?: number;
  /** Tipo de banner (carousel | band | allies | …). */
  bannerType?: string;
  /** Locación donde se publica (home | …). */
  location?: string;
  /** Plantilla de slide (`default` y las que añada la instancia). */
  type?: string;
  /**
   * Visual lateral: `none` = foto estática; `default` | `custom` = SVG decorativo
   * (sin imagen).
   */
  decorMode?: "none" | "default" | "custom";
  /** URL del SVG propio cuando `decorMode === "custom"`. */
  decorSvg?: string;
  /**
   * Cuál de los visuales decorativos de plantilla, cuando `decorMode === "default"`.
   * Es una CLAVE, no una URL: la resuelve `lib/banners/decor-presets.ts`, que es donde
   * viven los assets (los aporta `InstanceRegistry.decorPresets`). Ausente → el primero.
   */
  decorPreset?: string;
  /** Landing: si es `false`, solo imagen a sangrado (sin panel de copy). Ausente = `true`. */
  showCopy?: boolean;
}

export interface BannerPlacement {
  slides: BannerSlide[];
  intervalMs: number;
}

/** Tipos de banner que trae el plugin; una instancia puede declarar más (`string`). */
export type BannerTypeKey = "carousel" | "band" | "allies" | "page_hero" | "landing";

export type SiteBanners = Record<BannerTypeKey, BannerPlacement>;
