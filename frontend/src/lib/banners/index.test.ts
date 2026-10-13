import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logReadFailure } from "@/lib/observability/read-failure";
import { getSiteConfig } from "@/lib/config";
import { getBanner, getBanners, getLandingHeroSlide, getPageHeroSlide } from "./index";

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/http/fetch-retry", () => ({
  fetchWithRetry: async (request: () => Promise<Response>) => request(),
}));
vi.mock("./localize", () => ({ localizePlacement: vi.fn(async (p: unknown) => p) }));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });
const CELL = {
  intervalMs: 4000,
  slides: [{ id: "1-0", image: "a.jpg", title: "Hola" }],
};

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(logReadFailure).mockReset();
  vi.mocked(getSiteConfig).mockResolvedValue({
    banners: { enabled: true },
    modules: { banners: true },
  } as never);
});

describe("getBanner", () => {
  it("lee un tipo en una locación, en el idioma pedido, con el tag ISR `banners`", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(CELL));

    const placement = await getBanner("carousel", "home", "en");

    expect(placement.intervalMs).toBe(4000);
    expect(placement.slides[0].title).toBe("Hola");
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe-banners/v1/banners/carousel?lang=en&location=home",
      { next: { revalidate: 300, tags: ["banners"] } },
    );
  });

  it("codifica el tipo y la locación", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(CELL));

    await getBanner("a/b", "loc ación", "es");

    expect(vi.mocked(wpFetch).mock.calls[0][0]).toBe(
      "/wp-json/hwe-banners/v1/banners/a%2Fb?lang=es&location=loc%20aci%C3%B3n",
    );
  });

  it("un HTTP de error o una excepción dan el placement vacío y quedan registrados", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({}, 502));
    expect((await getBanner("carousel", "home", "es")).slides).toEqual([]);

    vi.mocked(wpFetch).mockRejectedValue(new Error("sin red"));
    expect((await getBanner("carousel", "home", "es")).slides).toEqual([]);

    expect(logReadFailure).toHaveBeenCalledTimes(2);
  });
});

describe("getBanners", () => {
  it("devuelve todos los tipos conocidos, vacíos los que el payload no trae", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({ carousel: CELL, band: { intervalMs: 6000, slides: [] } }),
    );

    const all = await getBanners("es", "home");

    expect(Object.keys(all)).toEqual([
      "carousel",
      "band",
      "allies",
      "page_hero",
      "landing",
    ]);
    expect(all.carousel.slides).toHaveLength(1);
    expect(all.allies.slides).toEqual([]);
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe-banners/v1/banners?lang=es&location=home",
      expect.anything(),
    );
  });

  it("la locación por defecto es `home`; un fallo da todos vacíos", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("x"));

    const all = await getBanners("es");

    expect(all.carousel.slides).toEqual([]);
    expect(String(vi.mocked(wpFetch).mock.calls[0][0])).toContain("location=home");
  });
});

describe("heros de página y de landing", () => {
  it("devuelven el primer slide de su tipo y locación", async () => {
    vi.mocked(wpFetch).mockImplementation(async () => json(CELL));

    expect((await getPageHeroSlide("tienda", "es"))?.title).toBe("Hola");
    expect(String(vi.mocked(wpFetch).mock.calls[0][0])).toContain(
      "/banners/page_hero?",
    );

    expect((await getLandingHeroSlide("landing-x", "es"))?.title).toBe("Hola");
    expect(String(vi.mocked(wpFetch).mock.calls[1][0])).toContain("/banners/landing?");
  });

  it("null si no hay banner", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ intervalMs: 6000, slides: [] }));

    expect(await getPageHeroSlide("tienda", "es")).toBeNull();
  });
});
