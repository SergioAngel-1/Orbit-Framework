import { ImageResponse } from "next/og";
import type { SiteConfig } from "@/lib/config";

/**
 * Generadores de imágenes de marca para SEO/GEO (Open Graph + iconos).
 *
 * Premisa de la plantilla: NO se versionan assets estáticos (favicons, og.jpg).
 * Todo se renderiza dinámicamente a partir de la configuración del Control
 * Center (nombre + colores de marca), de modo que cada despliegue white-label
 * obtiene sus propias imágenes sin tocar el repo.
 *
 * Consumido por:
 *   - app/api/og/route.tsx        → tarjeta Open Graph 1200×630
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
export function renderOgCard(config: SiteConfig): ImageResponse {
  const { brand, design } = config;
  const primary = design.colors.brand || "#2563eb";
  const dark = design.colors.brand_dark || "#1e40af";
  const name = brand.name || "HeadlessWP";
  const tagline = brand.tagline || brand.description || "";

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
