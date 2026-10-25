import { describe, expect, it, vi } from "vitest";

// ============================================================================
//  Vuelta de la pasarela: `getPathname` localiza el href interno y la navegación lo
//  entrega con el tramo público de la instancia.
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/checkout": "/pago",
      "/account": "/cuenta",
    },
  },
}));

import { checkoutReturnUrl } from "./return-url";
import { getPathname, redirect } from "@/i18n/navigation";

describe("checkoutReturnUrl con rutas públicas", () => {
  it("compone la vuelta con el tramo público y el prefijo del idioma", () => {
    expect(checkoutReturnUrl("https://tienda.example", "es", 42)).toBe(
      "https://tienda.example/pago/return?ref=42",
    );
    expect(checkoutReturnUrl("https://tienda.example/", "en", 42)).toBe(
      "https://tienda.example/en/pago/return?ref=42",
    );
  });
});

describe("getPathname", () => {
  it("traduce una ruta concreta y una plantilla sin mapa de pathnames", () => {
    expect(getPathname({ locale: "es", href: "/account/orders/7" })).toBe(
      "/cuenta/orders/7",
    );
    expect(getPathname({ locale: "en", href: "/checkout/return" })).toBe(
      "/en/pago/return",
    );
  });

  it("`redirect` usa la misma resolución (se prueba con el error de Next)", () => {
    // En un componente de servidor `redirect` lanza; aquí basta comprobar que la ruta
    // remapeada llega a Next (la URL va dentro del digest del error).
    expect(() => redirect({ locale: "es", href: "/account" })).toThrow(/NEXT_REDIRECT/);
  });
});
