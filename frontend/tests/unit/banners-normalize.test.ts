import { describe, it, expect } from "vitest";
import { mapSlide, normalizePlacement } from "@/lib/banners/normalize";

describe("mapSlide", () => {
  it("mapea un slide completo omitiendo opcionales vacíos", () => {
    expect(
      mapSlide({
        id: "12-0",
        placement: "hero",
        order: 2,
        image: "https://x/a.jpg",
        imageMobile: "https://x/a-m.jpg",
        title: "Rebajas",
        subtitle: "",
        cta: "Ver",
        ctaHref: "/products",
        badge: "",
        link: "",
        hideOverlay: false,
      }),
    ).toEqual({
      id: "12-0",
      placement: "hero",
      order: 2,
      image: "https://x/a.jpg",
      imageMobile: "https://x/a-m.jpg",
      title: "Rebajas",
      cta: "Ver",
      ctaHref: "/products",
      hideOverlay: false,
    });
  });

  it("descarta slides sin imagen o sin título", () => {
    expect(mapSlide({ id: 1, title: "Sin imagen" })).toBeNull();
    expect(mapSlide({ id: 1, image: "https://x/a.jpg" })).toBeNull();
    expect(mapSlide("no-objeto")).toBeNull();
    expect(mapSlide(null)).toBeNull();
  });
});

describe("normalizePlacement", () => {
  it("parsea intervalMs y filtra slides inválidos", () => {
    const result = normalizePlacement({
      intervalMs: 4000,
      slides: [
        { id: 1, image: "https://x/a.jpg", title: "Ok" },
        { id: 2, title: "Descartado" },
      ],
    });
    expect(result.intervalMs).toBe(4000);
    expect(result.slides).toEqual([
      { id: 1, image: "https://x/a.jpg", title: "Ok" },
    ]);
  });

  it("usa defaults seguros ante entrada inválida", () => {
    expect(normalizePlacement(null)).toEqual({ intervalMs: 6000, slides: [] });
    expect(normalizePlacement({ slides: "no-array" })).toEqual({
      intervalMs: 6000,
      slides: [],
    });
  });
});
