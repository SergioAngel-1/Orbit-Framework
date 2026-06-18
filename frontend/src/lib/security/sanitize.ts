import "server-only";
import DOMPurify from "isomorphic-dompurify";

// ============================================================================
//  Sanitización de HTML proveniente del CMS (descripciones de producto, posts).
//
//  WordPress puede devolver HTML enriquecido. Antes de renderizarlo con
//  `dangerouslySetInnerHTML` hay que sanearlo para evitar XSS almacenado.
//  Úsalo SIEMPRE que vayas a inyectar HTML del CMS en el DOM (Fase 5).
// ============================================================================

const ALLOWED_TAGS = [
  "p",
  "br",
  "strong",
  "em",
  "b",
  "i",
  "u",
  "s",
  "ul",
  "ol",
  "li",
  "blockquote",
  "code",
  "pre",
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "a",
  "img",
  "figure",
  "figcaption",
  "table",
  "thead",
  "tbody",
  "tr",
  "th",
  "td",
  "span",
  "div",
];

const ALLOWED_ATTR = ["href", "src", "alt", "title", "target", "rel", "class"];

/** Devuelve una versión saneada del HTML, lista para inyectar en el DOM. */
export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    // Fuerza enlaces externos seguros.
    ADD_ATTR: ["target", "rel"],
    FORBID_TAGS: ["style", "script", "iframe", "form", "input"],
    FORBID_ATTR: ["onerror", "onload", "onclick", "style"],
  });
}

/** Convierte HTML en texto plano (para excerpts/meta descriptions). */
export function htmlToText(html: string): string {
  const clean = DOMPurify.sanitize(html, { ALLOWED_TAGS: [], ALLOWED_ATTR: [] });
  return clean.replace(/\s+/g, " ").trim();
}
