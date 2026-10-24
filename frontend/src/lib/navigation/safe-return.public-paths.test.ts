import { describe, expect, it, vi } from "vitest";

// ============================================================================
//  `?return=` acepta la forma pública y la interna (anti open-redirect), y el fallback
//  `/account` sigue siendo el interno (la navegación lo traduce a `/cuenta`).
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/account": "/cuenta",
      "/checkout": "/pago",
      "/login": "/entrar",
    },
  },
}));

import { loginHref, safeInternalPath, withReturn } from "./safe-return";

describe("safeInternalPath con rutas públicas", () => {
  it("acepta la ruta pública y devuelve la interna", () => {
    expect(safeInternalPath("/cuenta/orders")).toBe("/account/orders");
    expect(safeInternalPath("/pago")).toBe("/checkout");
    expect(safeInternalPath("/products/x")).toBe("/products/x");
  });

  it("un valor inseguro sigue cayendo al fallback interno", () => {
    expect(safeInternalPath("https://evil.example")).toBe("/account");
    expect(safeInternalPath("//evil.example")).toBe("/account");
    expect(safeInternalPath("/api/csrf")).toBe("/account");
  });

  it("el destino del login vuelve a la ruta interna", () => {
    // Volver a la cuenta es el destino por defecto: no viaja en la URL.
    expect(loginHref("/cuenta")).toBe("/login");
    expect(loginHref("/account")).toBe("/login");
    expect(loginHref("/pago")).toEqual({
      pathname: "/login",
      query: { return: "/checkout" },
    });
    expect(withReturn("/register", "/pago")).toEqual({
      pathname: "/register",
      query: { return: "/checkout" },
    });
  });
});
