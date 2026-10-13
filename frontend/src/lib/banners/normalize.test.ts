import { describe, it, expect } from "vitest";
import { mapSlide, normalizeBanners } from "./normalize";

describe("mapSlide — campo type", () => {
  it("copia el type cuando es string", () => {
    const slide = mapSlide({ image: "a.jpg", title: "T", type: "editorial" });
    expect(slide?.type).toBe("editorial");
  });

  it("omite type cuando no es string", () => {
    const slide = mapSlide({ image: "a.jpg", title: "T", type: 3 });
    expect(slide?.type).toBeUndefined();
  });

  it("omite type cuando no viene", () => {
    const slide = mapSlide({ image: "a.jpg", title: "T" });
    expect(slide?.type).toBeUndefined();
  });
});

describe("mapSlide — contrato mínimo (algo que pintar)", () => {
  it("acepta un slide con solo imagen; title queda vacío", () => {
    const slide = mapSlide({ image: "a.jpg" });
    expect(slide).not.toBeNull();
    expect(slide?.title).toBe("");
  });

  it("descarta slides sin imagen, sin decor y sin copy", () => {
    expect(mapSlide({})).toBeNull();
    expect(mapSlide({ link: "/store" })).toBeNull();
  });

  it("conserva un slide con copy aunque no tenga fotografía", () => {
    // El arco de la portada se sirve sin arte hasta que el cliente lo entregue:
    // la plantilla pinta su hueco, pero el titular y los botones ya escritos en
    // el CMS tienen que seguir viéndose.
    const slide = mapSlide({
      title: "Una nueva colección para tu hogar",
      badge: "Novedades",
      cta: "Explorar la tienda",
      ctaHref: "/store",
    });
    expect(slide).not.toBeNull();
    expect(slide?.image).toBe("");
    expect(slide?.title).toBe("Una nueva colección para tu hogar");
    expect(slide?.cta).toBe("Explorar la tienda");
  });

  it("descarta la cáscara de un page_hero sin foto (su copy vive en i18n)", () => {
    // Un `page_hero` se siembra con todos los campos de copy vacíos: sin
    // fotografía no aporta nada y la vista decide qué enseñar en su lugar.
    expect(mapSlide({ type: "page_hero", title: "", subtitle: "" })).toBeNull();
  });

  it("acepta decor default sin imagen", () => {
    const slide = mapSlide({ title: "T", decorMode: "default" });
    expect(slide).not.toBeNull();
    expect(slide?.image).toBe("");
    expect(slide?.decorMode).toBe("default");
  });

  it("copia la clave del decor de plantilla en modo default", () => {
    const slide = mapSlide({
      title: "T",
      decorMode: "default",
      decorPreset: "linea-color",
    });
    expect(slide?.decorPreset).toBe("linea-color");
  });

  it("ignora la clave en los modos que no la usan", () => {
    expect(
      mapSlide({ title: "T", decorMode: "none", decorPreset: "linea-color" })
        ?.decorPreset,
    ).toBeUndefined();
    expect(
      mapSlide({
        title: "T",
        decorMode: "custom",
        decorSvg: "https://example.com/m.svg",
        decorPreset: "linea-color",
      })?.decorPreset,
    ).toBeUndefined();
  });
});

describe("mapSlide — CTA secundario (cta2/cta2Href)", () => {
  it("copia cta2 y cta2Href cuando vienen como string no vacío", () => {
    const slide = mapSlide({
      image: "a.jpg",
      title: "T",
      cta2: "Más información",
      cta2Href: "/products",
    });
    expect(slide?.cta2).toBe("Más información");
    expect(slide?.cta2Href).toBe("/products");
  });

  it("omite cta2/cta2Href cuando faltan o son vacíos", () => {
    const slide = mapSlide({ image: "a.jpg", title: "T", cta2: "", cta2Href: 7 });
    expect(slide?.cta2).toBeUndefined();
    expect(slide?.cta2Href).toBeUndefined();
  });
});

describe("normalizeBanners — payload completo de /banners", () => {
  it("normaliza cada tipo del objeto (carousel/band/allies)", () => {
    const banners = normalizeBanners({
      carousel: { intervalMs: 5000, slides: [{ image: "a.jpg", title: "A" }] },
      band: { slides: [{ image: "b.jpg" }] },
      allies: "no válido",
    });
    expect(banners.carousel.intervalMs).toBe(5000);
    expect(banners.carousel.slides).toHaveLength(1);
    expect(banners.band.intervalMs).toBe(6000);
    expect(banners.band.slides[0].title).toBe("");
    expect(banners.allies.slides).toEqual([]);
  });

  it("copia bannerType y location del slide", () => {
    const slide = mapSlide({
      image: "a.jpg",
      title: "T",
      bannerType: "carousel",
      location: "home",
    });
    expect(slide?.bannerType).toBe("carousel");
    expect(slide?.location).toBe("home");
  });

  it("copia decorMode/decorSvg y vacía la imagen (XOR)", () => {
    const slide = mapSlide({
      image: "a.jpg",
      title: "T",
      decorMode: "custom",
      decorSvg: "https://example.com/m.svg",
    });
    expect(slide?.decorMode).toBe("custom");
    expect(slide?.decorSvg).toBe("https://example.com/m.svg");
    expect(slide?.image).toBe("");
  });

  it("descarta custom sin SVG", () => {
    expect(mapSlide({ title: "T", decorMode: "custom", decorSvg: "" })).toBeNull();
  });

  it("copia showCopy del landing", () => {
    const on = mapSlide({ image: "a.jpg", title: "T", showCopy: true });
    const off = mapSlide({ image: "a.jpg", title: "T", showCopy: false });
    expect(on?.showCopy).toBe(true);
    expect(off?.showCopy).toBe(false);
  });

  it("devuelve {} si el payload no es un objeto", () => {
    expect(normalizeBanners(null)).toEqual({});
    expect(normalizeBanners([1, 2])).toEqual({});
  });
});

describe("mapSlide — texto alternativo", () => {
  it("copia imageAlt cuando hay imagen", () => {
    const slide = mapSlide({ image: "a.jpg", imageAlt: " Frase del mes " });
    expect(slide?.imageAlt).toBe("Frase del mes");
  });

  it("lo descarta sin imagen: un decor no tiene fotografía que describir", () => {
    const slide = mapSlide({ decorMode: "default", title: "T", imageAlt: "Algo" });
    expect(slide?.imageAlt).toBeUndefined();
  });
});
