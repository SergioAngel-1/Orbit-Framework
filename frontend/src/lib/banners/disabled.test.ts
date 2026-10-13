import { afterEach, describe, expect, it, vi } from "vitest";
import { getBanner, getBanners, getLandingHeroSlide, getPageHeroSlide } from "./index";
import { getSiteConfig } from "@/lib/config";

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const EMPTY = { slides: [], intervalMs: 6000 };

describe("banners con el módulo apagado", () => {
  it("getBanner y getBanners devuelven vacío sin llamar a WordPress", async () => {
    const fetchSpy = vi
      .spyOn(globalThis, "fetch")
      .mockRejectedValue(new Error("no debe llamarse"));

    expect(await getBanner("carousel", "home", "es", { enabled: false })).toEqual(
      EMPTY,
    );
    const all = await getBanners("es", "home", { enabled: false });

    expect(Object.keys(all)).toEqual([
      "carousel",
      "band",
      "allies",
      "page_hero",
      "landing",
    ]);
    expect(Object.values(all).every((p) => p.slides.length === 0)).toBe(true);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it.each([
    ["el módulo apagado", { banners: { enabled: true }, modules: {} }],
    [
      "el interruptor del Control Center apagado",
      { banners: { enabled: false }, modules: { banners: true } },
    ],
  ])(
    "los heros de página y de landing son null con %s, sin llamar a WordPress",
    async (_name, config) => {
      vi.mocked(getSiteConfig).mockResolvedValue(config as never);
      const fetchSpy = vi
        .spyOn(globalThis, "fetch")
        .mockRejectedValue(new Error("no debe llamarse"));

      expect(await getPageHeroSlide("tienda", "es")).toBeNull();
      expect(await getLandingHeroSlide("landing-x", "es")).toBeNull();
      expect(fetchSpy).not.toHaveBeenCalled();
    },
  );
});
