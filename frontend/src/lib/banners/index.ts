import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { fetchWithRetry } from "@/lib/http/fetch-retry";
import { logReadFailure } from "@/lib/observability/read-failure";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { localizePlacement } from "./localize";
import { normalizeBanners, normalizePlacement } from "./normalize";
import type { BannerPlacement, BannerSlide, BannerTypeKey, SiteBanners } from "./types";

export type { BannerSlide, BannerPlacement, BannerTypeKey, SiteBanners } from "./types";

const REVALIDATE_SECONDS = 300;
const TYPES: readonly BannerTypeKey[] = [
  "carousel",
  "band",
  "allies",
  "page_hero",
  "landing",
];

const EMPTY: BannerPlacement = { slides: [], intervalMs: 6000 };
const EMPTY_ALL = Object.fromEntries(TYPES.map((type) => [type, EMPTY])) as SiteBanners;

/**
 * Opciones de lectura. Con `enabled: false` (el módulo `banners` apagado, ver
 * `isModuleEnabled(config, "banners")`) los lectores devuelven vacío sin llamar a
 * WordPress: el módulo no existe a efectos del frontend.
 */
export interface BannerReadOptions {
  enabled?: boolean;
}

async function fetchBanners(path: string, context: Record<string, unknown>) {
  // Con reintentos: un hipo de WordPress durante el prerender horneaba la home SIN
  // banners (la lectura es fail-soft y el build salía en verde).
  const res = await fetchWithRetry(
    () =>
      wpFetch(path, { next: { revalidate: REVALIDATE_SECONDS, tags: ["banners"] } }),
    undefined,
    { label: "banners" },
  );
  if (!res.ok) {
    logReadFailure("banners.read_failed", `HTTP ${res.status}`, {
      context,
      message: "WordPress no sirvió los banners: se pinta sin ellos",
    });
    return null;
  }
  return res.json();
}

/** Un tipo de banner en una locación concreta. */
export async function getBanner(
  type: BannerTypeKey | string,
  location: string,
  locale: string,
  opts: BannerReadOptions = {},
): Promise<BannerPlacement> {
  if (opts.enabled === false) return EMPTY;
  const path =
    `/wp-json/hwe-banners/v1/banners/${encodeURIComponent(type)}` +
    `?lang=${encodeURIComponent(locale)}&location=${encodeURIComponent(location)}`;
  try {
    const raw = await fetchBanners(path, { type, location, locale });
    if (raw === null) return EMPTY;
    return await localizePlacement(normalizePlacement(raw), locale);
  } catch (error) {
    logReadFailure("banners.read_failed", error, {
      context: { type, location, locale },
      message: "No se pudieron leer los banners: se pinta sin ellos",
    });
    return EMPTY;
  }
}

/** Todos los tipos de banner para una locación (ISR, tag `banners`). */
export async function getBanners(
  locale: string,
  location = "home",
  opts: BannerReadOptions = {},
): Promise<SiteBanners> {
  if (opts.enabled === false) return EMPTY_ALL;
  const path =
    `/wp-json/hwe-banners/v1/banners` +
    `?lang=${encodeURIComponent(locale)}&location=${encodeURIComponent(location)}`;
  try {
    const raw = await fetchBanners(path, { location, locale });
    if (raw === null) return EMPTY_ALL;
    const all = normalizeBanners(raw);
    const entries = await Promise.all(
      TYPES.map(async (type) => [
        type,
        all[type] ? await localizePlacement(all[type], locale) : EMPTY,
      ]),
    );
    return Object.fromEntries(entries) as SiteBanners;
  } catch (error) {
    logReadFailure("banners.read_failed", error, {
      context: { location, locale },
      message: "No se pudieron leer los banners: se pinta sin ellos",
    });
    return EMPTY_ALL;
  }
}

/** ¿Se pintan banners? Requiere el módulo `banners` y el interruptor del Control Center. */
async function bannersOn(): Promise<boolean> {
  const config = await getSiteConfig();
  return config.banners.enabled && isModuleEnabled(config, "banners");
}

/**
 * Slide del hero de una página interna (`page_hero` + locación), o `null` si los
 * banners están apagados o no hay banner. Solo aporta imagen (el copy lo pone la vista).
 */
export async function getPageHeroSlide(
  location: string,
  locale: string,
): Promise<BannerSlide | null> {
  if (!(await bannersOn())) return null;
  const placement = await getBanner("page_hero", location, locale);
  return placement.slides[0] ?? null;
}

/**
 * Slide del banner de landing (`landing` + locación), o `null` si los banners están
 * apagados o no hay banner. Copy e imagen (y `showCopy`) vienen del CMS.
 */
export async function getLandingHeroSlide(
  location: string,
  locale: string,
): Promise<BannerSlide | null> {
  if (!(await bannersOn())) return null;
  const placement = await getBanner("landing", location, locale);
  return placement.slides[0] ?? null;
}
