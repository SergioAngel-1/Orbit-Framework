import { describe, expect, it } from "vitest";
import { instanceRegistry } from "@/instance/registry";
import { resolveTimeZone } from "@/i18n/request";
import { toInternalPath, toPublicPath } from "@/lib/instance/public-paths";
import {
  isSitemapPathExcluded,
  resolveInstanceSitemap,
} from "@/lib/instance/sitemap";

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

  it("remapea también una vista de auth, para que el contrato cace tests no aislados", () => {
    expect(instanceRegistry.publicPaths).toMatchObject({
      "/reset-password": "/example-access",
    });
    expect(toPublicPath("/reset-password", "es")).toBe("/example-access");
    expect(toInternalPath("/example-access", "es")).toBe("/reset-password");
  });

  it("declara una página propia y una exclusión del sitemap (contrato 2.3)", () => {
    const rules = resolveInstanceSitemap(instanceRegistry.sitemap);
    expect(rules.pages).toEqual([
      { path: "/example", changeFrequency: "yearly", priority: 0.3 },
    ]);
    expect(isSitemapPathExcluded("/legal/cookies", rules)).toBe(true);
    expect(isSitemapPathExcluded("/legal/privacy", rules)).toBe(false);
  });

  it("declara la zona horaria de la tienda", () => {
    expect(instanceRegistry.timeZone).toBe("America/Bogota");
    expect(resolveTimeZone("es")).toBe("America/Bogota");
    // Un idioma sin entrada del mapa cae al valor de la instancia, no al de la plantilla.
    expect(resolveTimeZone("en")).toBe("America/Bogota");
  });
});
