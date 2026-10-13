// ============================================================================
//  Medios de WordPress (`wp-content/uploads`) — SOLO servidor.
//
//  Dos orígenes distintos:
//   · PÚBLICO (`WORDPRESS_PUBLIC_URL`): el que lleva la URL de una imagen en la
//     base de datos y el que ve el navegador. Es el mismo que autoriza
//     `next.config.mjs` en `remotePatterns` y en la CSP.
//   · INTERNO (`WORDPRESS_INTERNAL_API_URL`): el nombre del servicio en la red
//     de Docker. Es por donde el optimizador de `next/image` (que hace el fetch
//     en el SERVIDOR) llega a WordPress: dentro de un contenedor, `localhost`
//     es el propio contenedor.
//
//  Se leen variables de servidor (sin `NEXT_PUBLIC_`): estos helpers no tienen
//  efecto en un componente de cliente (el origen interno no llega al bundle),
//  donde devuelven la URL tal cual. Úsalos desde Server Components o pasa al
//  cliente la URL ya resuelta.
// ============================================================================

/** Origen público de WordPress (medios), de `WORDPRESS_PUBLIC_URL`. */
export function getWordPressOrigin(): string {
  const raw = process.env.WORDPRESS_PUBLIC_URL ?? "http://localhost:8080";
  try {
    return new URL(raw).origin;
  } catch {
    return "http://localhost:8080";
  }
}

/**
 * URL absoluta de un archivo en la medioteca (`wp-content/uploads/...`).
 *
 * Acepta `2026/07/foto.png`, `/wp-content/uploads/2026/07/foto.png` y
 * `wp-content/uploads/2026/07/foto.png`.
 */
export function wpUploadsUrl(path: string): string {
  const trimmed = path.trim().replace(/^\/+/, "");
  const relative = trimmed.replace(/^wp-content\/uploads\//, "");
  return `${getWordPressOrigin()}/wp-content/uploads/${relative}`;
}

/**
 * Origen interno de WordPress (hostname del servicio en la red Docker), o null
 * si no hay uno configurado.
 *
 * El host interno NO es un secreto, pero acaba visible en el `url` de
 * `/_next/image` que el navegador envía al optimizador: es lo que se acepta a
 * cambio de que las imágenes carguen dentro de Docker.
 */
export function getWordPressInternalOrigin(): string | null {
  const raw = process.env.WORDPRESS_INTERNAL_API_URL;
  if (!raw) return null;
  try {
    return new URL(raw).origin;
  } catch {
    return null;
  }
}

/**
 * ¿Es un SVG? El optimizador de `next/image` no los sirve (haría falta
 * `dangerouslyAllowSVG`, y respondería 400), así que un asset de la medioteca
 * que pueda ser vectorial o raster se decide por aquí: SVG → `<img>` tal cual;
 * raster → `CmsImage`, que lo reescala. Un PNG de 2560 px pintado a 71 px pesa
 * 316 KB servido en crudo.
 */
export function isSvgAsset(url: string): boolean {
  const path = url.trim().split(/[?#]/)[0] ?? "";
  return path.toLowerCase().endsWith(".svg");
}

/**
 * Resuelve una URL de medioteca que puede ser absoluta o relativa
 * (`/wp-content/uploads/...`) a absoluta pública. Los campos `type: image` del
 * Control Center persisten relativos para no atar la base de datos a un host.
 */
export function resolveCmsAssetUrl(url: string): string {
  const trimmed = url.trim();
  if (!trimmed || /^https?:\/\//i.test(trimmed) || trimmed.startsWith("data:")) {
    return trimmed;
  }
  const idx = trimmed.indexOf("wp-content/uploads/");
  if (idx === -1) return trimmed;
  return `${getWordPressOrigin()}/${trimmed.slice(idx)}`;
}

/**
 * Reescribe una URL de medioteca de WordPress a su equivalente por el ORIGEN
 * INTERNO (p. ej. `http://localhost:8080/...` → `http://wordpress/...`).
 *
 * Para fuentes de `next/image`: el navegador nunca resuelve ese host, solo el
 * optimizador hace el fetch en el servidor, y dentro de Docker la URL pública no
 * es resoluble. En modo híbrido o en producción (donde el público resuelve) el
 * origen interno coincide o no existe: no-op. Solo toca rutas de
 * `/wp-content/uploads/`; cualquier otra URL (otro host, otra ruta) se devuelve
 * sin cambios, así esto nunca convierte un host ajeno en una petición interna.
 */
export function wpMediaInternalUrl(url: string): string {
  let trimmed = url.trim();
  if (!trimmed || trimmed.startsWith("data:")) return trimmed;
  // Relativa a uploads → hacerla absoluta pública primero.
  if (!/^https?:\/\//i.test(trimmed) && trimmed.includes("wp-content/uploads/")) {
    trimmed = resolveCmsAssetUrl(trimmed);
  }
  if (!/^https?:\/\//i.test(trimmed)) return trimmed;
  let parsed: URL;
  try {
    parsed = new URL(trimmed);
  } catch {
    return trimmed;
  }
  if (!parsed.pathname.startsWith("/wp-content/uploads/")) return trimmed;
  // Solo se reescribe un medio de NUESTRO WordPress: el de otro host con la
  // misma ruta no se desvía a la red interna (SSRF por URL de contenido).
  if (parsed.origin !== getWordPressOrigin()) return trimmed;
  const internal = getWordPressInternalOrigin();
  if (!internal || parsed.origin === internal) return trimmed;
  return new URL(parsed.pathname + parsed.search, internal).toString();
}
