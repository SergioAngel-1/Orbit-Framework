import { describe, expect, it, vi } from "vitest";

vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

import { compileRedirects, matchRedirect } from "./redirects";

const rules = compileRedirects([
  { from: "/tienda/vela-roja/", to: "/products/vela-roja" },
  { from: "/producto/*", to: "/products/*" },
  { from: "/producto/especial/*", to: "/learn" },
  { from: "/oferta", to: "/products?category=ofertas" },
  { from: "/temporal", to: "/blog", permanent: false },
  { from: "/fuera", to: "https://otra.example/x" },
]);

describe("matchRedirect", () => {
  it("exacta, con o sin barra final, conserva la consulta", () => {
    expect(matchRedirect("/tienda/vela-roja", "?utm_source=x", rules)).toEqual({
      location: "/products/vela-roja?utm_source=x",
      status: 301,
    });
    expect(matchRedirect("/tienda/vela-roja/", "", rules)?.location).toBe(
      "/products/vela-roja",
    );
  });

  it("prefijo: copia el resto en el `*`; el más largo manda", () => {
    expect(matchRedirect("/producto/camiseta/", "", rules)?.location).toBe(
      "/products/camiseta",
    );
    expect(matchRedirect("/producto/especial/algo", "", rules)?.location).toBe(
      "/learn",
    );
    expect(matchRedirect("/producto", "", rules)?.location).toBe("/products");
    // Un texto que solo EMPIEZA igual no es el prefijo.
    expect(matchRedirect("/productos/x", "", rules)).toBeNull();
  });

  it("un destino con consulta propia no hereda la de la petición", () => {
    expect(matchRedirect("/oferta", "?a=1", rules)?.location).toBe(
      "/products?category=ofertas",
    );
  });

  it("302 si no es permanente; destino absoluto tal cual", () => {
    expect(matchRedirect("/temporal", "", rules)?.status).toBe(302);
    expect(matchRedirect("/fuera", "", rules)?.location).toBe("https://otra.example/x");
  });

  it("una ruta codificada se compara decodificada", () => {
    const encoded = compileRedirects([
      { from: "/categoría/velas", to: "/categories/velas" },
    ]);
    expect(matchRedirect("/categor%C3%ADa/velas", "", encoded)?.location).toBe(
      "/categories/velas",
    );
  });

  it("sin reglas, nada", () => {
    expect(matchRedirect("/x", "", compileRedirects(undefined))).toBeNull();
  });
});

describe("compileRedirects", () => {
  it.each([
    [{ from: "sin-barra", to: "/a" }],
    [{ from: "//evil.example", to: "/a" }],
    [{ from: "/a?x=1", to: "/b" }],
    [{ from: "/*", to: "/b" }],
    [{ from: "/api/x", to: "/b" }],
    [{ from: "/a", to: "javascript:alert(1)" }],
    [{ from: "/a", to: "//evil.example" }],
    [{ from: "/a/", to: "/a" }],
    [{ from: "/a/*", to: "/a/b/*" }],
  ])("descarta una regla inválida (%j) y en modo estricto lanza", (entry) => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const compiled = compileRedirects([entry]);
    expect(compiled.exact.size + compiled.prefixes.length).toBe(0);
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
    expect(() => compileRedirects([entry], { strict: true })).toThrow(/redirects:/);
  });

  it("un `from` repetido: manda la primera", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const compiled = compileRedirects([
      { from: "/a", to: "/b" },
      { from: "/a/", to: "/c" },
    ]);
    expect(matchRedirect("/a", "", compiled)?.location).toBe("/b");
    warn.mockRestore();
  });
});
