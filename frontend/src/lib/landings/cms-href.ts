// ============================================================================
//  Allow-list de esquema para un enlace escrito en el CMS.
//
//  Los campos de enlace del CMS (`closing_cta_href` de la landing de producto,
//  `cta_href` / `cta2_href` del molde editorial) son TEXTO LIBRE en PHP a
//  propósito: aceptan rutas internas (`/products`), anclas y el prefijo propio
//  `editorial:<identificador>`, que `esc_url_raw` destrozaría. La validación vive
//  aquí, en el único punto por el que pasan antes de llegar a un `<a href>`.
//
//  Es una ALLOW-LIST, no una lista negra: lo que no se reconoce se rechaza. Una
//  lista negra de `javascript:` se rodea con entidades HTML, mayúsculas
//  intercaladas o caracteres de control, que es exactamente lo que el navegador
//  ignora al resolver el esquema.
//
//  Devuelve `null` —y no cadena vacía— porque los consumidores omiten el botón
//  cuando el enlace es nulo: uno inválido se comporta como uno no escrito.
//
//  Se despoja TAB/LF/CR de TODA la cadena (no solo de los extremos) porque el
//  parser WHATWG los elimina antes de tokenizar, y trata `\` como `/`. Eso evita
//  evasiones como `/\evil.example` (`\` se resuelve como `/`) y `/<TAB>/evil.example`.
//
//  Módulo PURO.
// ============================================================================

/** Esquemas con `:` que se sirven. `editorial:` lo resuelve el llamador. */
const ALLOWED_SCHEMES = ["http:", "https:", "mailto:", "tel:", "editorial:"];

export function resolveCmsHref(raw: string | null | undefined): string | null {
  if (typeof raw !== "string") return null;

  const href = raw.replace(/[\t\n\r]/g, "").trim();
  if (href === "") return null;

  // Protocolo-relativa: `//host` sale del sitio (y `\` cuenta como `/`).
  if ((href[0] === "/" || href[0] === "\\") && (href[1] === "/" || href[1] === "\\")) {
    return null;
  }

  // Ruta interna o ancla: no hay esquema que validar.
  if (href[0] === "/" || href[0] === "#") return href;

  const colon = href.indexOf(":");
  if (colon === -1) return null;
  const scheme = href.slice(0, colon + 1).replace(/\s/g, "");
  if (scheme === ":") return null;
  return ALLOWED_SCHEMES.includes(scheme.toLowerCase()) ? href : null;
}

/** Cómo se pinta un enlace ya validado. */
export type CmsLink =
  /** Ruta interna: `Link` de `@/i18n/navigation` (conserva el idioma). */
  | { kind: "internal"; href: string }
  /** Ancla, `mailto:`, `tel:` o `http(s)://`: un `<a>` plano. */
  | { kind: "plain"; href: string; external: boolean }
  /** `editorial:<identificador>`: el llamador lo resuelve con `getEditorialHref`. */
  | { kind: "editorial"; slug: string };

/** Valida y clasifica un enlace del CMS; `null` si no pasa la allow-list. */
export function classifyCmsHref(raw: string | null | undefined): CmsLink | null {
  const href = resolveCmsHref(raw);
  if (href === null) return null;
  if (href[0] === "/") return { kind: "internal", href };
  if (href[0] === "#") return { kind: "plain", href, external: false };
  if (/^editorial:/i.test(href)) {
    const slug = href.slice("editorial:".length).trim();
    return slug === "" ? null : { kind: "editorial", slug };
  }
  return { kind: "plain", href, external: /^https?:/i.test(href) };
}
