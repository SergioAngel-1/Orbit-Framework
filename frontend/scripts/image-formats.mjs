// ============================================================================
//  Formatos de `/_next/image`, por variable de entorno.
//
//  `images.formats` fija qué formato codifica Next según el `Accept` del
//  navegador: con AVIF primero, un navegador moderno recibe AVIF; si el
//  despliegue lo deja fuera, recibe WebP. AVIF pesa ~1/3 menos pero cuesta
//  varias veces la CPU de WebP en cada variante en frío (con sharp, 2 núcleos y
//  calidad 75: 0,3–1,1 s frente a 66–235 ms, ≈5x). En un servidor pequeño, que
//  comparte CPU con el resto de la pila, una ficha con varias imágenes nuevas a
//  la vez puede tardar segundos en servirse. Por eso el defecto sigue siendo
//  AVIF + WebP (ahorra ancho de banda y la caché amortiza el coste), pero un
//  despliegue pequeño puede elegir solo WebP.
//
//  Es una variable de BUILD: en la imagen `standalone` Next hornea la config al
//  construir y `next.config.mjs` no se vuelve a evaluar al arrancar.
// ============================================================================

/** Los únicos formatos que `images.formats` acepta (esquema de Next 16). */
const SUPPORTED = new Set(["image/avif", "image/webp"]);

/** Lo que Next sirve sin configurar nada más: AVIF si el navegador lo acepta, WebP si no. */
export const DEFAULT_IMAGE_FORMATS = ["image/avif", "image/webp"];

/**
 * `images.formats` derivado de `NEXT_IMAGE_FORMATS` (lista separada por comas:
 * `webp`, `avif` o sus tipos MIME; el orden es el de preferencia).
 *
 * Nunca devuelve un valor que Next rechace: los que no sirven se ignoran con un
 * aviso y, si no queda ninguno, se vuelve al defecto.
 *
 * @param {Record<string, string | undefined>} env
 * @param {(message: string) => void} warn
 * @returns {("image/avif" | "image/webp")[]}
 */
export function imageFormats(env = {}, warn = console.warn) {
  const raw = env.NEXT_IMAGE_FORMATS;
  if (raw === undefined || raw === null || String(raw).trim() === "") {
    return [...DEFAULT_IMAGE_FORMATS];
  }

  const formats = [];
  const ignored = [];
  for (const entry of String(raw).split(",")) {
    const token = entry.trim().toLowerCase();
    if (token === "") continue;
    const mime = token.includes("/") ? token : `image/${token}`;
    if (!SUPPORTED.has(mime)) ignored.push(token);
    else if (!formats.includes(mime)) formats.push(mime);
  }

  if (ignored.length > 0) {
    warn(
      `NEXT_IMAGE_FORMATS: se ignoran los valores no válidos (${ignored.join(", ")}); ` +
        "los admitidos son avif y webp.",
    );
  }
  if (formats.length === 0) {
    if (ignored.length === 0) {
      warn("NEXT_IMAGE_FORMATS no contiene ningún formato: se usa el defecto (avif, webp).");
    }
    return [...DEFAULT_IMAGE_FORMATS];
  }
  return formats;
}
