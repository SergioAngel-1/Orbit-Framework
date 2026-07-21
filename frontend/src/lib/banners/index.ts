import "server-only";
import { normalizePlacement } from "./normalize";
import type { BannerPlacement } from "./types";

export type { BannerSlide, BannerPlacement } from "./types";

const REVALIDATE_SECONDS = 300;
const EMPTY: BannerPlacement = { slides: [], intervalMs: 6000 };

function getWpBase(): string {
  const raw =
    process.env.WORDPRESS_INTERNAL_API_URL ??
    process.env.NEXT_PUBLIC_WORDPRESS_API_URL ??
    "http://wordpress:80";
  return raw.replace(/\/graphql$/, "").replace(/\/$/, "");
}

export async function getBannerPlacement(
  placement: string,
  locale: string,
): Promise<BannerPlacement> {
  const url = `${getWpBase()}/wp-json/hwe-banners/v1/banners/${encodeURIComponent(
    placement,
  )}?lang=${encodeURIComponent(locale)}`;

  try {
    const res = await fetch(url, {
      next: { revalidate: REVALIDATE_SECONDS, tags: ["banners"] },
    });
    if (!res.ok) return EMPTY;
    return normalizePlacement(await res.json());
  } catch {
    return EMPTY;
  }
}
