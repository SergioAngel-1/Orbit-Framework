import { ImageResponse } from "next/og";
import type { SiteConfig } from "@/lib/config";
import { brandDescription, brandTagline } from "@/lib/config/brand";
import {
  getWordPressInternalOrigin,
  getWordPressOrigin,
  wpMediaInternalUrl,
} from "@/lib/wordpress/media";
import { logReadFailure } from "@/lib/observability/read-failure";

/**
 * Generadores de imágenes de marca para SEO/GEO (Open Graph + iconos).
 *
 * Premisa de la plantilla: NO se versionan assets estáticos (favicons, og.jpg).
 * Todo se renderiza dinámicamente a partir de la configuración del Control
 * Center (nombre + colores de marca), de modo que cada despliegue white-label
 * obtiene sus propias imágenes sin tocar el repo.
 *
 * Consumido por:
 *   - app/api/og/route.tsx        → tarjeta Open Graph 1200×630 (el `locale` llega por
 *     query: la tarjeta de `/en` lleva su propio lema)
 *   - app/api/icon/route.tsx      → iconos PWA (manifest, 192/512)
 *   - app/icon.tsx                → favicon
 *   - app/apple-icon.tsx          → apple-touch-icon
 */

/** Primera letra significativa del nombre de marca (para los iconos). */
function brandInitial(name: string): string {
  const trimmed = (name || "H").trim();
  return (trimmed.charAt(0) || "H").toUpperCase();
}

/** Tarjeta social Open Graph / Twitter (1200×630), generada desde la marca. */
export function renderOgCard(config: SiteConfig, locale: string): ImageResponse {
  const { brand, design } = config;
  const primary = design.colors.brand || "#2563eb";
  const dark = design.colors.brand_dark || "#1e40af";
  const name = brand.name || "HeadlessWP";
  const tagline =
    brandTagline(config, locale) || brandDescription(config, locale) || "";

  return new ImageResponse(
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        flexDirection: "column",
        justifyContent: "center",
        padding: "80px",
        backgroundColor: primary,
        backgroundImage: `linear-gradient(135deg, ${primary} 0%, ${dark} 100%)`,
        color: "#ffffff",
        fontFamily: "sans-serif",
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          width: "96px",
          height: "96px",
          borderRadius: "24px",
          backgroundColor: "rgba(255,255,255,0.15)",
          fontSize: "56px",
          fontWeight: 800,
          marginBottom: "40px",
        }}
      >
        {brandInitial(name)}
      </div>
      <div
        style={{ display: "flex", fontSize: "72px", fontWeight: 800, lineHeight: 1.1 }}
      >
        {name}
      </div>
      {tagline ? (
        <div
          style={{
            display: "flex",
            fontSize: "34px",
            fontWeight: 400,
            marginTop: "24px",
            opacity: 0.92,
            maxWidth: "900px",
          }}
        >
          {tagline}
        </div>
      ) : null}
    </div>,
    { width: 1200, height: 630 },
  );
}

/** Icono cuadrado (favicon / PWA / apple-touch), generado desde la marca. */
export function renderBrandIcon(config: SiteConfig, size: number): ImageResponse {
  const { brand, design } = config;
  const primary = design.colors.brand || "#2563eb";
  const dark = design.colors.brand_dark || "#1e40af";
  const initial = brandInitial(brand.name || "HeadlessWP");

  // Radio proporcional: iconos grandes (PWA) con esquinas suaves; favicon casi cuadrado.
  const radius = Math.round(size * 0.18);

  return new ImageResponse(
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        backgroundColor: primary,
        backgroundImage: `linear-gradient(135deg, ${primary} 0%, ${dark} 100%)`,
        borderRadius: `${radius}px`,
        color: "#ffffff",
        fontSize: `${Math.round(size * 0.6)}px`,
        fontWeight: 800,
        fontFamily: "sans-serif",
      }}
    >
      {initial}
    </div>,
    { width: size, height: size },
  );
}

const ICON_CACHE_CONTROL = "public, max-age=300, s-maxage=300";
const ICON_FETCH_TIMEOUT_MS = 4_000;
const ICON_MAX_BYTES = 1_000_000;
const ICON_CONTENT_TYPES = /^image\/(png|jpeg|webp|gif|x-icon|vnd\.microsoft\.icon)$/i;

/**
 * El icono del sitio de WordPress como `Response`, o `null` si no se puede
 * servir (sin icono, host ajeno, tipo no admitido, demasiado grande o WordPress
 * inalcanzable). Quien llama cae entonces al icono generado desde la marca.
 *
 * Solo se pide una URL del propio WordPress (origen público o interno): aunque
 * el dato viene de nuestra config, el servidor no hace de proxy a un host
 * arbitrario. Un SVG se descarta a propósito (puede llevar scripts y el
 * navegador lo interpretaría al abrir la URL del icono).
 */
export async function wordpressSiteIconResponse(
  config: SiteConfig,
): Promise<Response | null> {
  const iconUrl = config.brand.icon_url;
  if (!iconUrl) return null;

  // Fetch en el servidor: dentro de Docker el origen público no resuelve; hace
  // falta el interno. En producción coinciden.
  const target = wpMediaInternalUrl(iconUrl);
  let origin: string;
  try {
    origin = new URL(target).origin;
  } catch {
    return null;
  }
  const allowed = [getWordPressOrigin(), getWordPressInternalOrigin()];
  if (!allowed.includes(origin)) return null;

  try {
    const upstream = await fetch(target, {
      signal: AbortSignal.timeout(ICON_FETCH_TIMEOUT_MS),
      redirect: "error",
    });
    if (!upstream.ok) return null;
    const type = upstream.headers.get("content-type")?.split(";")[0]?.trim() ?? "";
    if (!ICON_CONTENT_TYPES.test(type)) return null;
    const bytes = await upstream.arrayBuffer();
    if (bytes.byteLength === 0 || bytes.byteLength > ICON_MAX_BYTES) return null;
    return new Response(bytes, {
      headers: { "Content-Type": type, "Cache-Control": ICON_CACHE_CONTROL },
    });
  } catch (error) {
    logReadFailure("seo.site_icon_failed", error, {
      level: "warn",
      message: "No se pudo leer el icono del sitio de WordPress: se usa el de la marca",
    });
    return null;
  }
}

/**
 * Icono servido en favicon / apple-touch-icon / manifest PWA: el icono del sitio
 * subido en WordPress si lo hay y se puede servir, o el generado desde la marca.
 */
export async function resolveIconResponse(
  config: SiteConfig,
  size: number,
): Promise<Response> {
  return (await wordpressSiteIconResponse(config)) ?? renderBrandIcon(config, size);
}
