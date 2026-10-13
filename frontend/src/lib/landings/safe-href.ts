// ============================================================================
//  Enlaces que escribe un editor (botón de cierre de la landing).
//
//  Es TEXTO LIBRE del CMS y acaba en un `href`: sin validar, `javascript:` o un
//  `//host` (protocolo relativo, que sale del sitio) llegan a la página. Solo se
//  admiten rutas internas (`/…`) y direcciones `http(s)://`; lo demás se
//  descarta (el botón vuelve a la ficha del producto).
//
//  Módulo PURO.
// ============================================================================

export type LandingLink =
  { kind: "internal"; href: string } | { kind: "external"; href: string };

export function resolveLandingHref(raw: string | null | undefined): LandingLink | null {
  const value = (raw ?? "").trim();
  if (value === "") return null;

  // Caracteres de control o barras invertidas: los navegadores los normalizan a
  // algo distinto de lo que se ve (`/\evil.example` → `//evil.example`).
  if (/[\u0000-\u001f\u007f\\]/.test(value)) return null;

  if (value.startsWith("/")) {
    return value.startsWith("//") ? null : { kind: "internal", href: value };
  }

  try {
    const url = new URL(value);
    if (url.protocol === "https:" || url.protocol === "http:") {
      return { kind: "external", href: url.toString() };
    }
  } catch {
    // No es una URL absoluta: se descarta.
  }
  return null;
}
