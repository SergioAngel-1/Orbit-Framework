import "server-only";
import { normalizePlacement } from "./normalize";
import type { BannerPlacement } from "./types";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { fetchWithRetry } from "@/lib/http/fetch-retry";
import { logReadFailure } from "@/lib/observability/read-failure";

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
    // Con reintentos: un hipo de WordPress durante el prerender horneaba la
    // home SIN hero (la lectura es fail-soft y el build salía en verde).
    const res = await fetchWithRetry(
      () =>
        wpFetch(path, {
          next: { revalidate: REVALIDATE_SECONDS, tags: ["banners"] },
        }),
      undefined,
      { label: "banners" },
    );
    if (!res.ok) {
      logReadFailure("banners.read_failed", `HTTP ${res.status}`, {
        context: { placement, locale },
        message: "WordPress no sirvió los banners: se pinta sin ellos",
      });
      return EMPTY;
    }
    return normalizePlacement(await res.json());
  } catch (error) {
    logReadFailure("banners.read_failed", error, {
      context: { placement, locale },
      message: "No se pudieron leer los banners: se pinta sin ellos",
    });
    return EMPTY;
  }
}
