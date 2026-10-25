import { publicAliases } from "@/lib/instance/public-paths";

const HREF_RE = /<a\s[^>]*?\bhref\s*=\s*(["'])(.*?)\1/gi;

// ============================================================================
//  Productos enlazados desde el cuerpo de una entrada.
//
//  La franja «Productos mencionados» y la reescritura de enlaces reconocen los
//  MISMOS enlaces, así que cuerpo y franja no pueden discrepar. Un enlace es de
//  producto si su ruta es la ficha del sitio (`/products/<slug>`, con o sin
//  prefijo de idioma) o una ruta de WooCommerce (`/product/<slug>`,
//  `/producto/<slug>`), y —cuando es absoluto— su host es el del sitio o el de
//  WordPress (`siteOrigins`). Un enlace a otra web con la misma forma de ruta
//  NO es nuestro producto.
// ============================================================================

function normalizeHost(host: string): string {
  return host.replace(/^www\./i, "").toLowerCase();
}

/** Quita el prefijo de idioma (`/en/…`) de los segmentos de una ruta. */
function segmentsOf(pathname: string, locales: readonly string[]): string[] {
  const parts = pathname.split("/").filter(Boolean);
  if (parts[0] && locales.includes(parts[0])) parts.shift();
  return parts;
}

const DEFAULT_LOCALES = ["es", "en"] as const;

/**
 * De un pathname (`/products/camiseta`, `/en/products/camiseta`,
 * `/product/camiseta`) extrae el slug de producto, o null si no es una ficha.
 * Reconoce la forma interna (`/products`) y la pública de la instancia
 * (`/productos`, si `InstanceRegistry.publicPaths` la declara).
 */
export function productSlugFromPathname(
  pathname: string,
  locales: readonly string[] = DEFAULT_LOCALES,
): string | null {
  const parts = segmentsOf(pathname, locales);
  const productSegments = new Set(["products", ...publicAliases("products")]);
  // Exactamente `<ruta>/<slug>`: una ruta que cuelga de la ficha no es la ficha.
  if (
    parts.length === 2 &&
    (productSegments.has(parts[0]!) ||
      parts[0] === "product" ||
      parts[0] === "producto")
  ) {
    try {
      return decodeURIComponent(parts[1]!);
    } catch {
      return null;
    }
  }
  return null;
}

function isWooProductPath(pathname: string, locales: readonly string[]): boolean {
  const first = segmentsOf(pathname, locales)[0];
  return first === "product" || first === "producto";
}

function isInternalHost(host: string, siteOrigins: readonly string[]): boolean {
  const wanted = normalizeHost(host);
  return siteOrigins.some((origin) => {
    try {
      return normalizeHost(new URL(origin).host) === wanted;
    } catch {
      return false;
    }
  });
}

/**
 * Resuelve un href (relativo o absoluto) a slug de producto interno. Los hosts
 * de `siteOrigins` cuentan como internos. Las rutas WooCommerce
 * `/product`|`/producto` se aceptan en cualquier host: son las que dejó un sitio
 * anterior migrado.
 */
export function productSlugFromHref(
  href: string,
  siteOrigins: readonly string[] = [],
  locales: readonly string[] = DEFAULT_LOCALES,
): string | null {
  const trimmed = href.trim();
  if (
    !trimmed ||
    trimmed.startsWith("#") ||
    trimmed.startsWith("mailto:") ||
    trimmed.startsWith("tel:") ||
    trimmed.toLowerCase().startsWith("javascript:")
  ) {
    return null;
  }

  try {
    if (!/^https?:\/\//i.test(trimmed)) {
      const path = (trimmed.startsWith("/") ? trimmed : `/${trimmed}`).split(
        /[?#]/,
      )[0]!;
      return productSlugFromPathname(path, locales);
    }

    const url = new URL(trimmed);
    const slug = productSlugFromPathname(url.pathname, locales);
    if (!slug) return null;
    if (isWooProductPath(url.pathname, locales)) return slug;
    return isInternalHost(url.host, siteOrigins) ? slug : null;
  } catch {
    return null;
  }
}

/** Extrae slugs de producto únicos (orden de aparición) desde HTML del CMS. */
export function extractProductSlugsFromHtml(
  html: string,
  opts?: { siteOrigins?: readonly string[]; locales?: readonly string[] },
): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  const re = new RegExp(HREF_RE.source, "gi");
  let m: RegExpExecArray | null;
  while ((m = re.exec(html)) !== null) {
    const slug = productSlugFromHref(m[2] ?? "", opts?.siteOrigins, opts?.locales);
    if (slug && !seen.has(slug)) {
      seen.add(slug);
      out.push(slug);
    }
  }
  return out;
}

/**
 * Reescribe los enlaces a ficha del HTML del CMS con la URL que devuelva
 * `resolve(slug)` —la canónica y localizada, calculada por quien llama—.
 *
 * Las entradas migradas enlazan con la forma de la web antigua
 * (`/producto/camiseta/`), que en este sitio es un 404. Si `resolve` devuelve
 * null (el producto ya no existe en el catálogo) el enlace se deja como está.
 * Conserva el `#fragmento`; descarta la query, que en los enlaces migrados es
 * rastreo (`?srsltid=…`).
 */
export function rewriteProductLinks(
  html: string,
  resolve: (slug: string) => string | null,
  opts?: { siteOrigins?: readonly string[]; locales?: readonly string[] },
): string {
  const re = new RegExp(HREF_RE.source, "gi");
  return html.replace(re, (full, quote: string, href: string) => {
    const slug = productSlugFromHref(href, opts?.siteOrigins, opts?.locales);
    if (!slug) return full;
    let hash = "";
    try {
      hash = new URL(href.trim(), "http://local.invalid").hash;
    } catch {
      return full;
    }
    const target = resolve(slug);
    if (!target) return full;
    // El match termina en `href` + comilla de cierre: se sustituye solo el valor.
    const head = full.slice(0, full.length - href.length - quote.length);
    return `${head}${target}${hash}${quote}`;
  });
}
