import "server-only";
import { normalizePlacement } from "./normalize";
import type { BannerPlacement } from "./types";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export type { BannerSlide, BannerPlacement } from "./types";

const REVALIDATE_SECONDS = 300;
const EMPTY: BannerPlacement = { slides: [], intervalMs: 6000 };

/**
 * Lee un placement de banners del plugin HWE Banners.
 *
 * Con el módulo apagado (`enabled: false`, ver `isModuleEnabled(config,
 * "banners")`) devuelve el placement vacío sin llamar a WordPress: el módulo
 * no existe a efectos del frontend. Falla de forma suave ante cualquier error.
 */
export async function getBannerPlacement(
  placement: string,
  locale: string,
  opts?: { enabled?: boolean },
): Promise<BannerPlacement> {
  if (opts?.enabled === false) return EMPTY;
  const path = `/wp-json/hwe-banners/v1/banners/${encodeURIComponent(
    placement,
  )}?lang=${encodeURIComponent(locale)}`;

  try {
    const res = await wpFetch(path, {
      next: { revalidate: REVALIDATE_SECONDS, tags: ["banners"] },
    });
    if (!res.ok) return EMPTY;
    return normalizePlacement(await res.json());
  } catch {
    return EMPTY;
  }
}
