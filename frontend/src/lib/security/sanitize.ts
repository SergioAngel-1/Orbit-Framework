import "server-only";
import DOMPurify from "isomorphic-dompurify";

// ============================================================================
//  Sanitización de HTML proveniente del CMS (descripciones de producto, posts).
//
//  WordPress puede devolver HTML enriquecido. Antes de renderizarlo con
//  `dangerouslySetInnerHTML` hay que sanearlo para evitar XSS almacenado.
//  Úsalo SIEMPRE que vayas a inyectar HTML del CMS en el DOM (Fase 5).
// ============================================================================

// Un enlace que abre otra ventana conserva `window.opener` si no lleva
// `rel="noopener noreferrer"`: la página abierta podría redirigir la nuestra
// (reverse tabnabbing). Esto cubre `target="_blank"` en cualquier grafía y también
// los nombres de ventana (`target="x"`), que los navegadores NO protegen por
// defecto; solo `_self` navega en la misma pestaña.
//
// Se FUSIONA con el `rel` que traiga el CMS (p. ej. `nofollow`) en vez de
// sobrescribirlo, y se quita `opener`, que contradice a `noopener`.
//
// El hook se registra UNA vez al importar el módulo: registrarlo dentro de
// `sanitizeHtml` añadía uno más por cada llamada y los acumulaba durante toda la
// vida del proceso del servidor.
DOMPurify.addHook("afterSanitizeAttributes", (node) => {
  if (node.tagName !== "A") return;
  const target = node.getAttribute("target");
  if (!target || target.toLowerCase() === "_self") return;

  const tokens = new Set(
    (node.getAttribute("rel") ?? "").toLowerCase().split(/\s+/).filter(Boolean),
  );
  tokens.delete("opener");
  tokens.add("noopener");
  tokens.add("noreferrer");
  node.setAttribute("rel", Array.from(tokens).join(" "));
});

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
  "caption",
  "thead",
  "tbody",
  "tfoot",
  "tr",
  "th",
  "td",
  "span",
  "div",
];

const ALLOWED_ATTR = [
  "href",
  "src",
  "alt",
  "title",
  "target",
  "rel",
  "class",
  // Estructura y accesibilidad de las tablas del campo `richtext`.
  "scope",
  "colspan",
  "rowspan",
];

/** Devuelve una versión saneada del HTML, lista para inyectar en el DOM. */
export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    // `target` y `rel` se conservan; el hook de arriba fuerza el `rel` seguro.
    ADD_ATTR: ["target", "rel"],
    FORBID_TAGS: ["style", "script", "iframe", "form", "input"],
    FORBID_ATTR: ["onerror", "onload", "onclick", "style"],
  });
}
