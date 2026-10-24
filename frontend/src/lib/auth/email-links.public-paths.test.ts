import { describe, expect, it, vi } from "vitest";

// ============================================================================
//  Los enlaces de los correos de auth salen con la ruta pública de la instancia.
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/reset-password": "/recuperar",
      "/verify-email": "/verificar",
    },
  },
}));

import { authEmailUrl } from "./email-links";

describe("authEmailUrl con rutas públicas", () => {
  it("usa el tramo público y el prefijo del idioma", () => {
    expect(authEmailUrl("/reset-password", "es")).toMatch(/\/recuperar$/);
    expect(authEmailUrl("/verify-email", "en")).toMatch(/\/en\/verificar$/);
  });
});
