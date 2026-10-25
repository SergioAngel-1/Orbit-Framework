import { describe, expect, it, vi } from "vitest";

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: { "/products": "/productos" },
  },
}));

import {
  extractProductSlugsFromHtml,
  productSlugFromHref,
  productSlugFromPathname,
  rewriteProductLinks,
} from "./extract-product-links";

describe("productSlugFromPathname", () => {
  it("reconoce /products con y sin prefijo de idioma", () => {
    expect(productSlugFromPathname("/products/camiseta")).toBe("camiseta");
    expect(productSlugFromPathname("/products/camiseta/")).toBe("camiseta");
    expect(productSlugFromPathname("/en/products/camiseta")).toBe("camiseta");
  });

  it("reconoce la ruta pública de la instancia", () => {
    expect(productSlugFromPathname("/productos/camiseta")).toBe("camiseta");
    expect(productSlugFromPathname("/en/productos/camiseta")).toBe("camiseta");
    expect(productSlugFromPathname("/productos")).toBeNull();
  });

  it("reconoce /product y /producto de WooCommerce", () => {
    expect(productSlugFromPathname("/product/f-valor")).toBe("f-valor");
    expect(productSlugFromPathname("/producto/f-calma")).toBe("f-calma");
  });

  it("ignora listados, categorías y rutas que cuelgan de la ficha", () => {
    expect(productSlugFromPathname("/products")).toBeNull();
    expect(productSlugFromPathname("/categories/ropa")).toBeNull();
    expect(productSlugFromPathname("/blog/un-post")).toBeNull();
    expect(productSlugFromPathname("/products/camiseta/opiniones")).toBeNull();
  });

  it("un slug mal codificado no rompe", () => {
    expect(productSlugFromPathname("/products/%E0%A4%A")).toBeNull();
  });
});

describe("productSlugFromHref", () => {
  it("acepta relativos y absolutos del sitio", () => {
    expect(productSlugFromHref("/products/camiseta")).toBe("camiseta");
    expect(
      productSlugFromHref("https://mitienda.example/products/camiseta", [
        "https://mitienda.example",
      ]),
    ).toBe("camiseta");
  });

  it("el host del sitio cuenta con o sin www", () => {
    expect(
      productSlugFromHref("https://www.mitienda.example/products/camiseta", [
        "https://mitienda.example",
      ]),
    ).toBe("camiseta");
  });

  it("rechaza absolutos de otro host con ruta de ficha del sitio", () => {
    expect(
      productSlugFromHref("https://evil.test/products/camiseta", [
        "https://mitienda.example",
      ]),
    ).toBeNull();
  });

  it("acepta /product|/producto de Woo en cualquier host (sitio migrado)", () => {
    expect(
      productSlugFromHref("https://antigua.example/producto/camiseta", [
        "https://mitienda.example",
      ]),
    ).toBe("camiseta");
  });

  it("no toma esquemas peligrosos ni anclas", () => {
    for (const href of ["#x", "mailto:a@b.c", "tel:123", "javascript:alert(1)", ""]) {
      expect(productSlugFromHref(href)).toBeNull();
    }
  });
});

describe("extractProductSlugsFromHtml", () => {
  it("dedupe por orden de aparición", () => {
    const html = `
      <p><a href="/products/camiseta">Camiseta</a></p>
      <p><a href="https://mitienda.example/en/products/gorra">Gorra</a></p>
      <p><a href="https://otra.web/products/ajena">ajena</a></p>
      <p><a href="/products/camiseta">otra vez</a></p>
      <p><a href="/blog/foo">blog</a></p>
    `;
    expect(
      extractProductSlugsFromHtml(html, { siteOrigins: ["https://mitienda.example"] }),
    ).toEqual(["camiseta", "gorra"]);
  });
});

describe("rewriteProductLinks", () => {
  const canonical: Record<string, string> = { camiseta: "/products/camiseta" };
  const resolve = (slug: string) => canonical[slug] ?? null;

  it("lleva el enlace heredado de Woo a la ficha canónica", () => {
    const html =
      '<p>Mira <a href="https://antigua.example/producto/camiseta/" target="_blank">la camiseta</a></p>';
    expect(rewriteProductLinks(html, resolve)).toBe(
      '<p>Mira <a href="/products/camiseta" target="_blank">la camiseta</a></p>',
    );
  });

  it("conserva el fragmento y descarta la query de rastreo", () => {
    expect(
      rewriteProductLinks(
        '<a href="https://antigua.example/producto/camiseta/?srsltid=abc#uso">x</a>',
        resolve,
      ),
    ).toBe('<a href="/products/camiseta#uso">x</a>');
  });

  it("deja intacto lo que no resuelve o no es una ficha", () => {
    const html = [
      '<a href="/producto/ya-no-existe/">a</a>',
      '<a href="/products/camiseta/opiniones">b</a>',
      '<a href="/categories/ropa/">c</a>',
      '<a href="https://otra.web/products/camiseta">d</a>',
      '<a href="#seccion">e</a>',
    ].join("");
    expect(rewriteProductLinks(html, resolve)).toBe(html);
  });

  it("respeta las comillas simples", () => {
    expect(rewriteProductLinks("<a href='/producto/camiseta'>x</a>", resolve)).toBe(
      "<a href='/products/camiseta'>x</a>",
    );
  });
});
