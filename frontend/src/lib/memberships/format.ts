import es from "@/i18n/messages/es.json";
import en from "@/i18n/messages/en.json";

// ============================================================================
//  Helpers puros de formato del módulo `memberships`.
//
//  Sin `import "server-only"`: los puede usar también un Client Component para
//  pintar lo que el BFF ya devolvió. No leen configuración ni hacen fetch.
// ============================================================================

type Catalog = typeof es;

const CATALOGS: Record<"es" | "en", Catalog> = { es, en };

function catalogFor(locale: string): Catalog["memberships"]["durations"] {
  return (locale.toLowerCase().startsWith("en") ? CATALOGS.en : CATALOGS.es).memberships
    .durations;
}

function interpolate(
  template: string,
  values: Record<string, string | number>,
): string {
  return template.replace(/\{(\w+)\}/g, (placeholder, key: string) => {
    const value = values[key];
    return value === undefined || value === "" ? placeholder : String(value);
  });
}

/**
 * Duración legible de una membresía.
 *
 * `null` = indefinida; `undated` gana a los días (una entrada de la cola que
 * aún no tiene fechas) y `infinite` a todo (cola sin fin).
 */
export function formatMembershipDuration(
  days: number | null,
  locale: string,
  options: { infinite?: boolean; undated?: boolean } = {},
): string {
  const durations = catalogFor(locale);

  if (options.infinite) return durations.infinite;
  if (options.undated) return durations.undated;
  if (days === null || !Number.isFinite(days)) return durations.infinite;
  if (days === 1) return durations.one_day;
  if (days <= 0) return durations.infinite;

  return interpolate(durations.days, { days });
}

/**
 * Número de socio visible: el prefijo configurable delante del número propio
 * del módulo (nunca el id de usuario). Sin número no inventa uno.
 */
export function formatMemberNumber(number: string, prefix: string): string {
  const trimmed = number.trim();
  if (trimmed === "") return "";
  return `${prefix}${trimmed}`;
}

/**
 * Importe de la Store API (unidades menores, string) → decimal de la tienda.
 * Se hace por texto para no arrastrar errores de coma flotante.
 */
export function fromMinorUnits(
  value: string | number | null | undefined,
  minorUnit: number,
): string {
  const decimals = Number.isInteger(minorUnit)
    ? Math.min(6, Math.max(0, minorUnit))
    : 2;
  const raw =
    typeof value === "number" && Number.isFinite(value)
      ? String(Math.trunc(value))
      : typeof value === "string"
        ? value.trim()
        : "";
  const zero = decimals === 0 ? "0" : `0.${"0".repeat(decimals)}`;

  if (!/^\d+$/.test(raw)) return zero;

  const padded = raw.padStart(decimals + 1, "0");
  return decimals === 0
    ? padded
    : `${padded.slice(0, -decimals)}.${padded.slice(-decimals)}`;
}

/**
 * URL absoluta del QR del carné. Se compone con `config.brand.url` (el dominio
 * del frontend), NUNCA con `home_url()` de WordPress, que en headless apunta al
 * backend. Devuelve `null` si la base no es una URL http(s) o si no hay ruta:
 * publicar un QR a un dominio inventado es peor que fallar tipado.
 */
export function buildVerifyUrl(
  baseUrl: string,
  verifyPath: string,
  token: string,
): string | null {
  const base = baseUrl.trim().replace(/\/+$/, "");
  const path = verifyPath.trim().replace(/^\/+|\/+$/g, "");
  if (base === "" || path === "") return null;

  let parsed: URL;
  try {
    parsed = new URL(base);
  } catch {
    return null;
  }
  if (parsed.protocol !== "https:" && parsed.protocol !== "http:") return null;

  return `${base}/${path}/${encodeURIComponent(token)}`;
}
