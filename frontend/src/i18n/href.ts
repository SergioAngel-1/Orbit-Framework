import { toPublicPath } from "@/lib/instance/public-paths";

// ============================================================================
//  Hrefs internos → hrefs públicos.
//
//  Las vistas escriben rutas INTERNAS (`/products`, `/account/orders`): son las carpetas
//  de `app/[locale]/` y lo que el núcleo entiende. Antes de entregar el href a next-intl
//  (que solo antepone el prefijo de idioma), se traduce al tramo PÚBLICO de la instancia
//  con `toPublicPath`. Sin mapa de rutas públicas es la identidad.
//
//  Se conservan la consulta (`?…`) y el ancla (`#…`), y se resuelven los parámetros de una
//  plantilla (`/products/[slug]` + `{ slug }`), porque los `pathnames` de next-intl no se
//  usan: una ruta concreta (`/products/vela-roja`) no es una clave suya y saldría sin
//  traducir. El módulo es puro y no depende del idioma actual: lo recibe quien lo llama.
// ============================================================================

/** Valor de un parámetro o de la consulta de un href. */
export type HrefParam = string | number | readonly (string | number)[];

/** Href con la misma forma que acepta la navegación de next-intl (sin `pathnames`). */
export interface NavigationHrefObject {
  pathname: string;
  params?: Readonly<Record<string, HrefParam>>;
  query?: Readonly<Record<string, HrefParam>>;
  hash?: string;
}

export type NavigationHref = string | NavigationHrefObject;

/** ¿Es una ruta interna de la app (y no externa, ancla o relativa)? */
function isLocalPath(path: string): boolean {
  return path.startsWith("/") && !path.startsWith("//");
}

/** Separa `ruta?consulta#ancla` en la ruta y el sufijo literal. */
function splitSuffix(path: string): { path: string; suffix: string } {
  const cut = path.search(/[?#]/);
  if (cut === -1) return { path, suffix: "" };
  return { path: path.slice(0, cut), suffix: path.slice(cut) };
}

function serializeQuery(query: NavigationHrefObject["query"]): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (Array.isArray(value)) {
      for (const item of value) params.append(key, String(item));
    } else if (value !== undefined) {
      params.set(key, String(value));
    }
  }
  const serialized = params.toString();
  return serialized ? `?${serialized}` : "";
}

/** Rellena `/products/[slug]` (o `[...rest]`) con sus parámetros, o `null` si falta alguno. */
function fillParams(
  template: string,
  params: NavigationHrefObject["params"],
): string | null {
  if (!params) return template.includes("[") ? null : template;
  let out = template;
  for (const [key, value] of Object.entries(params)) {
    const values = Array.isArray(value) ? value : [value];
    const joined = values.map((item) => encodeURIComponent(String(item))).join("/");
    out = out.split(`[...${key}]`).join(joined);
    out = out.split(`[${key}]`).join(joined);
  }
  return out.includes("[") ? null : out;
}

/**
 * Href listo para next-intl: ruta pública SIN prefijo de idioma (lo pone next-intl), con
 * su consulta y su ancla. Lo que no es una ruta interna se devuelve intacto.
 */
export function toPublicHref(href: NavigationHref, locale: string): string {
  if (typeof href === "string") {
    const { path, suffix } = splitSuffix(href);
    if (!isLocalPath(path)) return href;
    return `${toPublicPath(path, locale)}${suffix}`;
  }

  const filled = fillParams(href.pathname, href.params);
  // Una plantilla a la que le faltan parámetros se deja como está: next-intl (o Next)
  // dará el error que corresponda en vez de inventar una URL.
  const path = filled ?? href.pathname;
  const hash = href.hash
    ? href.hash.startsWith("#")
      ? href.hash
      : `#${href.hash}`
    : "";
  const suffix = `${serializeQuery(href.query)}${hash}`;
  if (!isLocalPath(path)) return `${path}${suffix}`;
  return `${toPublicPath(path, locale)}${suffix}`;
}
