import { describe, expect, it, vi } from "vitest";

// ============================================================================
//  Hrefs internos → públicos antes de entregarlos a next-intl.
//  El mapa imita al de una instancia en español, con una plantilla.
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/products": "/productos",
      "/products/[slug]": "/producto/[slug]",
      "/cart": "/carrito",
      "/checkout": "/pago",
      "/account": "/cuenta",
      "/login": "/entrar",
    },
  },
}));

import { toPublicHref } from "./href";

describe("toPublicHref", () => {
  it("traduce una ruta interna concreta y conserva la consulta y el ancla", () => {
    // La plantilla propia del mapa (`/products/[slug]` → `/producto/[slug]`) manda sobre
    // la herencia de `/products`.
    expect(toPublicHref("/products/vela", "es")).toBe("/producto/vela");
    expect(toPublicHref("/cart?step=2#resumen", "es")).toBe("/carrito?step=2#resumen");
  });

  it("usa la plantilla cuando el mapa la declara", () => {
    expect(toPublicHref("/products/vela-roja", "es")).toBe("/producto/vela-roja");
  });

  it("un href con `pathname`, `params`, `query` y `hash` sale como URL", () => {
    expect(
      toPublicHref(
        {
          pathname: "/products/[slug]",
          params: { slug: "vela roja" },
          query: { color: "rojo", size: ["s", "m"] },
          hash: "ficha",
        },
        "es",
      ),
    ).toBe("/producto/vela%20roja?color=rojo&size=s&size=m#ficha");
  });

  it("sin mapa o con una ruta no remapeada devuelve el href tal cual", () => {
    expect(toPublicHref("/about", "es")).toBe("/about");
    expect(toPublicHref("/blog?author=ana", "es")).toBe("/blog?author=ana");
    expect(toPublicHref("https://otra.example/products/x", "es")).toBe(
      "https://otra.example/products/x",
    );
  });

  it("conserva la ruta de la home y las anclas locales", () => {
    expect(toPublicHref("/", "es")).toBe("/");
    expect(toPublicHref("#main-content", "es")).toBe("#main-content");
  });
});
