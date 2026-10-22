import type { SiteConfig, SiteConfigLocation } from "./types";
import { businessPhone } from "./contact";

export interface ConfiguredLocation {
  name: string;
  address: string;
  /** Ciudad/municipio (`addressLocality`); "" si el panel no la trae. */
  city: string;
  /** Departamento/región (`addressRegion`); "" si el panel no la trae. */
  region: string;
  /** Código postal (`postalCode`); "" si el panel no la trae. */
  postalCode: string;
  mapsUrl: string;
  phone: string;
  hours: string[];
  latitude: number | null;
  longitude: number | null;
}

const SLOT_KEYS = [
  "location_1",
  "location_2",
  "location_3",
  "location_4",
  "location_5",
] as const;

function isFilled(loc: SiteConfigLocation | undefined): loc is SiteConfigLocation {
  if (!loc) return false;
  return Boolean((loc.label || "").trim() && (loc.address || "").trim());
}

function parseHours(raw: string | undefined): string[] {
  return (raw ?? "")
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

/** `parseFloat` (no `Number`) porque el campo admite texto suelto en el admin. */
function parseCoordinate(
  raw: string | undefined,
  min: number,
  max: number,
): number | null {
  const value = Number.parseFloat((raw ?? "").trim());
  if (!Number.isFinite(value) || value < min || value > max) return null;
  return value;
}

/**
 * Sedes configuradas en Control Center (slots no vacíos: nombre + dirección).
 *
 * Una sede sin teléfono propio hereda el del negocio: si no, emitiría un
 * `LocalBusiness` SIN `telephone` — una ficha de negocio local a la que no se
 * puede llamar.
 */
export function getConfiguredLocations(config: SiteConfig): ConfiguredLocation[] {
  const locs = config.legal?.locations;
  const general = businessPhone(config);
  const fromConfig: ConfiguredLocation[] = [];

  for (const key of SLOT_KEYS) {
    const slot = locs?.[key];
    if (!isFilled(slot)) continue;
    fromConfig.push({
      name: slot.label.trim(),
      address: slot.address.trim(),
      city: (slot.city || "").trim(),
      region: (slot.region || "").trim(),
      postalCode: (slot.postal_code || "").trim(),
      mapsUrl: (slot.maps || "").trim(),
      phone: (slot.phone || "").trim() || general,
      hours: parseHours(slot.hours),
      latitude: parseCoordinate(slot.latitude, -90, 90),
      longitude: parseCoordinate(slot.longitude, -180, 180),
    });
  }

  return fromConfig;
}
