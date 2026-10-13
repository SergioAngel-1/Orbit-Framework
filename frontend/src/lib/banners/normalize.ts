import type { BannerPlacement, BannerSlide } from "./types";

const DEFAULT_INTERVAL_MS = 6000;

function str(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function decorModeOf(value: unknown): BannerSlide["decorMode"] | undefined {
  if (value === "none" || value === "default" || value === "custom") {
    return value;
  }
  return undefined;
}

export function mapSlide(raw: unknown): BannerSlide | null {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const r = raw as Record<string, unknown>;

  const decorMode = decorModeOf(r.decorMode);
  const decorSvg = str(r.decorSvg);
  const isDecor =
    decorMode === "default" || (decorMode === "custom" && decorSvg !== "");

  const image = isDecor ? "" : str(r.image);
  if (decorMode === "custom" && !decorSvg) return null;

  const title = str(r.title);
  const subtitle = str(r.subtitle);
  const cta = str(r.cta);
  const ctaHref = str(r.ctaHref);
  const cta2 = str(r.cta2);
  const cta2Href = str(r.cta2Href);
  const badge = str(r.badge);
  const imageMobile = isDecor ? "" : str(r.imageMobile);
  const imageAlt = image ? str(r.imageAlt).trim() : "";
  const link = str(r.link);

  /* Un slide se descarta solo cuando no tiene NADA que pintar: ni fotografía,
     ni visual decorativo, ni una línea de copy.

     Antes bastaba con no tener imagen para caer, y eso hacía desaparecer el
     slide ENTERO —titular, entradilla y botones ya escritos en el CMS— mientras
     el cliente no entregase el arte. Las plantillas saben pintar su hueco (la
     zebra de «falta la foto»), así que un slide con copy es perfectamente
     servible sin fotografía: es la diferencia entre enseñar que falta una
     imagen y borrar en silencio el mensaje de la portada.

     Las cáscaras cuyo único contenido es la imagen —los `page_hero`, que llevan
     el copy en i18n— siguen cayendo aquí: sin foto no aportan nada, y la vista
     que las consume es quien decide qué enseñar en su lugar. */
  if (!isDecor && !image && !title && !subtitle && !badge && !cta) return null;

  const slide: BannerSlide = {
    id:
      typeof r.id === "number" || typeof r.id === "string"
        ? r.id
        : image || decorSvg || "slide",
    image,
    title,
  };
  if (subtitle) slide.subtitle = subtitle;
  if (cta) slide.cta = cta;
  if (ctaHref) slide.ctaHref = ctaHref;
  if (cta2) slide.cta2 = cta2;
  if (cta2Href) slide.cta2Href = cta2Href;
  if (badge) slide.badge = badge;
  if (imageMobile) slide.imageMobile = imageMobile;
  if (imageAlt) slide.imageAlt = imageAlt;
  if (link) slide.link = link;
  if (typeof r.hideOverlay === "boolean") slide.hideOverlay = r.hideOverlay;
  if (typeof r.order === "number") slide.order = r.order;
  if (typeof r.bannerType === "string") slide.bannerType = r.bannerType;
  if (typeof r.location === "string") slide.location = r.location;
  if (typeof r.type === "string") slide.type = r.type;
  if (decorMode) slide.decorMode = decorMode;
  if (decorMode === "custom" && decorSvg) slide.decorSvg = decorSvg;
  // Solo en modo `default`: en `custom` manda el SVG subido y en `none` no hay
  // visual decorativo. La clave se deja pasar tal cual —resolverla es cosa de
  // `decor-presets.ts`, que cae al visual por defecto si no la conoce—.
  if (decorMode === "default") {
    const decorPreset = str(r.decorPreset);
    if (decorPreset) slide.decorPreset = decorPreset;
  }
  if (typeof r.showCopy === "boolean") slide.showCopy = r.showCopy;

  return slide;
}

export function normalizePlacement(raw: unknown): BannerPlacement {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
    return { intervalMs: DEFAULT_INTERVAL_MS, slides: [] };
  }
  const r = raw as Record<string, unknown>;
  const intervalMs =
    typeof r.intervalMs === "number" && r.intervalMs > 0
      ? r.intervalMs
      : DEFAULT_INTERVAL_MS;
  const slides = Array.isArray(r.slides)
    ? r.slides.map(mapSlide).filter((s): s is BannerSlide => s !== null)
    : [];
  return { intervalMs, slides };
}

/** Normaliza el payload de GET /banners?location=: objeto type→placement. */
export function normalizeBanners(raw: unknown): Record<string, BannerPlacement> {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return {};
  const out: Record<string, BannerPlacement> = {};
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    out[key] = normalizePlacement(value);
  }
  return out;
}
