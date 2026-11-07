import type { SiteConfig } from "@/lib/config/types";
import { routing } from "@/i18n/routing";

// ============================================================================
//  /.well-known/security.txt (RFC 9116): a quién avisar de una vulnerabilidad.
//
//  Sale de la config del sitio: `Contact` es `legal.email` (el correo público de
//  la marca), así que sin él no hay nada que publicar y la ruta responde 404.
//  `Expires` es obligatorio y debe ser futuro (la RFC recomienda menos de un año);
//  como el fichero se genera en cada petición, se renueva solo a `EXPIRES_DAYS`.
//  Función pura.
// ============================================================================

const EXPIRES_DAYS = 180;
const DAY_MS = 24 * 60 * 60 * 1000;

/** `Contact` válido (RFC 9116 §2.5.3) a partir del correo configurado, o `null`. */
function contactFor(email: string): string | null {
  const value = (email || "").trim();
  if (!/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/.test(value)) return null;
  return `mailto:${value}`;
}

/** Cuerpo de `security.txt`, o `null` si la config no da un contacto publicable. */
export function buildSecurityTxt(config: SiteConfig, now: Date = new Date()): string | null {
  const contact = contactFor(config.legal.email);
  const base = (config.brand.url || "").replace(/\/$/, "");
  if (!contact || !base) return null;
  // Truncado al día: el valor no cambia entre peticiones del mismo día (cachea bien).
  const day = new Date(Math.floor(now.getTime() / DAY_MS) * DAY_MS);
  const expires = new Date(day.getTime() + EXPIRES_DAYS * DAY_MS);
  return (
    [
      `Contact: ${contact}`,
      `Expires: ${expires.toISOString().replace(/\.\d{3}Z$/, "Z")}`,
      `Preferred-Languages: ${routing.locales.join(", ")}`,
      `Canonical: ${base}/.well-known/security.txt`,
    ].join("\n") + "\n"
  );
}
