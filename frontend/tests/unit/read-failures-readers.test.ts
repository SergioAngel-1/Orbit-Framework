import { beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  Los lectores del CMS que degradan a un valor por defecto (lista vacía, sin
//  banner, sin destacado) no lo hacen en silencio: dejan un evento estructurado
//  (spec §2). Antes `getCategories`, `getPostSlugs` y compañía tragaban el error.
// ============================================================================

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));
const wpFetch = vi.fn();
vi.mock("@/lib/wordpress/internal-fetch", () => ({
  wpFetch: (...a: unknown[]) => wpFetch(...a),
}));
const logger = vi.hoisted(() => ({ warn: vi.fn(), error: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import { getCategories } from "@/lib/catalog/products";
import { getFeaturedPostOverride, getPostSlugs } from "@/lib/blog/posts";
import { getBannerPlacement } from "@/lib/banners";

beforeEach(() => {
  fetchGraphQL.mockReset();
  wpFetch.mockReset();
  logger.warn.mockReset();
  logger.error.mockReset();
});

describe("lectores del CMS que degradan sin romper", () => {
  it("getCategories: lista vacía y error en el log", async () => {
    fetchGraphQL.mockRejectedValue(new Error("WordPress caído"));

    expect(await getCategories()).toEqual([]);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "catalog.categories_failed",
        err: "WordPress caído",
      }),
      expect.any(String),
    );
  });

  it("getPostSlugs: lista vacía y error en el log (el prerender y el sitemap lo verán)", async () => {
    fetchGraphQL.mockRejectedValue(new Error("WordPress caído"));

    expect(await getPostSlugs()).toEqual([]);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "blog.post_slugs_failed" }),
      expect.any(String),
    );
  });

  it("getFeaturedPostOverride: sin destacado y aviso en el log", async () => {
    fetchGraphQL.mockRejectedValue(new Error("WordPress caído"));

    expect(await getFeaturedPostOverride("es")).toBeNull();
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "blog.featured_post_failed" }),
      expect.any(String),
    );
  });

  it("getBannerPlacement: sin banners y error en el log, con una excepción o con un HTTP 5xx", async () => {
    wpFetch.mockRejectedValue(new Error("sin red"));
    expect((await getBannerPlacement("hero", "es")).slides).toEqual([]);

    wpFetch.mockResolvedValue({ ok: false, status: 502 });
    expect((await getBannerPlacement("hero", "es")).slides).toEqual([]);

    const events = logger.error.mock.calls
      .map(([entry]) => (entry as { event: string }).event)
      .filter((event) => event === "banners.read_failed");
    expect(events.length).toBeGreaterThanOrEqual(2);
  });
});
