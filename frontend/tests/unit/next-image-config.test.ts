import { describe, expect, it } from "vitest";
// `hasLocalMatch` es la comprobación con la que el optimizador de Next 16 admite una URL local.
import { hasLocalMatch } from "next/dist/shared/lib/match-local-pattern";
import { cmsMediaSrc } from "@/lib/wordpress/media";

// ============================================================================
//  `CmsImage` pide al optimizador `/api/media/…?ver=N`. Next 16 rechaza con 400 cualquier imagen
//  local con cadena de consulta salvo que `images.localPatterns` la autorice: sin esto, los medios
//  con `?ver=` no cargarían (descubierto probando `/_next/image` contra un `next start`).
// ============================================================================
describe("next.config.mjs: images.localPatterns", () => {
  it("admite /api/media con ?ver= y conserva el defecto para el resto de rutas locales", async () => {
    const mod = (await import("../../next.config.mjs")) as {
      default: { images: { localPatterns: unknown[] } };
    };
    const patterns = mod.default.images.localPatterns as Parameters<
      typeof hasLocalMatch
    >[0];

    const media = cmsMediaSrc("https://tienda.example/wp-content/uploads/a.png?ver=3")!;
    expect(media).toBe("/api/media/a.png?ver=3");
    expect(hasLocalMatch(patterns, media)).toBe(true);
    expect(hasLocalMatch(patterns, "/api/media/2026/07/a.png")).toBe(true);

    expect(hasLocalMatch(patterns, "/img/hero.png")).toBe(true);
    expect(hasLocalMatch(patterns, "/img/hero.png?x=1")).toBe(false);
    expect(hasLocalMatch(patterns, "/api/csrf?x=1")).toBe(false);
  });
});
