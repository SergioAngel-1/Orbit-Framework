import { describe, it, expect } from "vitest";
import { parseBanners } from "@/lib/config/banners";

describe("parseBanners", () => {
  it("parsea una línea completa", () => {
    expect(
      parseBanners(
        "https://x/img.jpg | Rebajas | Hasta -50% | Ver ofertas | /products | Nuevo",
      ),
    ).toEqual([
      {
        id: 0,
        image: "https://x/img.jpg",
        title: "Rebajas",
        subtitle: "Hasta -50%",
        cta: "Ver ofertas",
        ctaHref: "/products",
        badge: "Nuevo",
      },
    ]);
  });

  it("omite los campos opcionales vacíos", () => {
    expect(parseBanners("https://x/a.jpg | Solo título")).toEqual([
      { id: 0, image: "https://x/a.jpg", title: "Solo título" },
    ]);
    expect(parseBanners("https://x/a.jpg | Título |  | CTA | /go")).toEqual([
      { id: 0, image: "https://x/a.jpg", title: "Título", cta: "CTA", ctaHref: "/go" },
    ]);
  });

  it("descarta líneas sin imagen o sin título y líneas en blanco", () => {
    const raw = "\n | Sin imagen \nhttps://x/b.jpg |  \n\nhttps://x/c.jpg | Válido\n";
    // id = índice de la línea original ("https://x/c.jpg | Válido" es la línea 4, contando las inválidas)
    expect(parseBanners(raw)).toEqual([
      { id: 4, image: "https://x/c.jpg", title: "Válido" },
    ]);
  });

  it("devuelve [] para entrada vacía", () => {
    expect(parseBanners("")).toEqual([]);
  });
});
