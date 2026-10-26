import { describe, expect, it } from "vitest";
import { config } from "@/proxy";

// ============================================================================
//  El `matcher` del proxy (Next lo exige como literal en `config.matcher`).
//
//  next-intl reescribe las páginas sin prefijo de idioma; si además recibe
//  `/icon` o `/apple-icon` (las rutas de metadatos dinámicas de Next), no las
//  trata como archivos y responde con una página 404 en vez de la imagen. Se
//  excluyen esas dos rutas EXACTAS, sin tocar `/icon-guide` ni `/api/*`.
// ============================================================================

/**
 * Semántica de Next para un matcher en forma de grupo (`/((?!…).*)`): el
 * fuente se ancla con `^…$` y se prueba contra el pathname completo. Los
 * sufijos de transporte (`.rsc`, `.json`…) no cambian qué páginas entran.
 */
function matches(pathname: string): boolean {
  return config.matcher.some((matcher) => new RegExp(`^${matcher}$`).test(pathname));
}

describe("matcher del proxy", () => {
  it("no entra en las rutas de metadatos dinámicas de Next", () => {
    expect(matches("/icon")).toBe(false);
    expect(matches("/apple-icon")).toBe(false);
    // `generateImageMetadata` sirve las variantes en `/icon/<id>`.
    expect(matches("/icon/1")).toBe(false);
    expect(matches("/apple-icon/1")).toBe(false);
  });

  it("sí entra en las páginas (con y sin prefijo de idioma)", () => {
    expect(matches("/")).toBe(true);
    expect(matches("/products")).toBe(true);
    expect(matches("/en/products")).toBe(true);
  });

  it("sí entra en la API: la barrera de Origin sigue aplicándose", () => {
    expect(matches("/api/csrf")).toBe(true);
  });

  it("no excluye rutas que solo empiezan como un icono", () => {
    expect(matches("/icon-guide")).toBe(true);
    expect(matches("/apple-icon-guide")).toBe(true);
    expect(matches("/en/icon-guide")).toBe(true);
  });
});
