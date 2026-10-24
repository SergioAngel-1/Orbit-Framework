import { describe, expect, it } from "vitest";
import { instanceRegistry } from "@/instance/registry";
import { resolveTimeZone } from "@/i18n/request";
import { toInternalPath, toPublicPath } from "@/lib/instance/public-paths";

// ============================================================================
//  Campos del registro del ejemplo que llegan con el contrato 2.2: una ruta pública de bajo
//  impacto y la zona horaria de la tienda. El job «contrato» superpone este registro al del
//  framework y corre la suite del núcleo: estos datos no pueden alterar sus tests.
// ============================================================================

describe("registro del ejemplo (contrato 2.2)", () => {
  it("sirve `/about` en `/nosotros` y deshace el camino", () => {
    expect(instanceRegistry.publicPaths).toMatchObject({ "/about": "/nosotros" });
    expect(toPublicPath("/about", "es")).toBe("/nosotros");
    expect(toInternalPath("/nosotros", "es")).toBe("/about");
  });

  it("declara la zona horaria de la tienda", () => {
    expect(instanceRegistry.timeZone).toBe("America/Bogota");
    expect(resolveTimeZone("es")).toBe("America/Bogota");
    // Un idioma sin entrada del mapa cae al valor de la instancia, no al de la plantilla.
    expect(resolveTimeZone("en")).toBe("America/Bogota");
  });
});
