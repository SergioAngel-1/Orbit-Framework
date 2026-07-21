import type { BannerPlacement, BannerSlide } from "./types";

const DEFAULT_INTERVAL_MS = 6000;

function str(value: unknown): string {
  return typeof value === "string" ? value : "";
}

export function mapSlide(raw: unknown): BannerSlide | null {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const r = raw as Record<string, unknown>;

  const image = str(r.image);
  const title = str(r.title);
  if (!image || !title) return null;

  const subtitle = str(r.subtitle);
  const cta = str(r.cta);
  const ctaHref = str(r.ctaHref);
  const badge = str(r.badge);
  const imageMobile = str(r.imageMobile);
  const link = str(r.link);

  const slide: BannerSlide = {
    id: typeof r.id === "number" || typeof r.id === "string" ? r.id : image,
    image,
    title,
  };
  if (subtitle) slide.subtitle = subtitle;
  if (cta) slide.cta = cta;
  if (ctaHref) slide.ctaHref = ctaHref;
  if (badge) slide.badge = badge;
  if (imageMobile) slide.imageMobile = imageMobile;
  if (link) slide.link = link;
  if (typeof r.hideOverlay === "boolean") slide.hideOverlay = r.hideOverlay;
  if (typeof r.order === "number") slide.order = r.order;
  if (typeof r.placement === "string") slide.placement = r.placement;

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
