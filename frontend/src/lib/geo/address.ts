import { isE164, normalizePhoneE164 } from "@/lib/auth/phone";
import type { GeoDivision, GeoPack } from "./types";

// ============================================================================
//  Dirección según el paquete de país: funciones puras, válidas en servidor y
//  en cliente (los formularios las usan para ofrecer opciones; el BFF, para
//  validar y normalizar lo que recibe).
// ============================================================================

/** Texto comparable: sin tildes, en minúsculas y con una sola clase de separador. */
export function fold(value: string): string {
  return value
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

/** País (ISO) al que aplica el paquete, o `""` si es el de reserva sin país conocido. */
export function packCountry(pack: GeoPack): string {
  if (pack.country !== "generic") return pack.country;
  return /^[A-Z]{2}$/.test(pack.requested) ? pack.requested : "";
}

/** ¿La división es una lista cerrada que el paquete sabe comprobar? */
function hasClosedDivisions(pack: GeoPack): boolean {
  return (
    pack.divisions.length > 0 &&
    (pack.address.division === "required" || pack.address.division === "optional")
  );
}

/**
 * División por código, por nombre (sin tildes ni mayúsculas) o por el sufijo del
 * código (`ANT` → `CO-ANT`).
 */
export function findDivision(pack: GeoPack, raw: string): GeoDivision | undefined {
  const value = raw.trim();
  if (!value) return undefined;
  const upper = value.toUpperCase();
  const folded = fold(value);
  const compact = folded.replace(/\s+/g, "");
  return (
    pack.divisions.find((d) => d.code.toUpperCase() === upper) ??
    pack.divisions.find((d) => fold(d.name) === folded) ??
    pack.divisions.find((d) => fold(d.name).replace(/\s+/g, "") === compact) ??
    pack.divisions.find((d) => d.code.split("-").pop()?.toUpperCase() === upper)
  );
}

/** Código de división de una ciudad del paquete, o `undefined` si no es una ciudad conocida. */
export function divisionForCity(pack: GeoPack, city: string): string | undefined {
  if (!pack.address.cityDivision) return undefined;
  const folded = fold(city);
  if (!folded) return undefined;
  const found = pack.cities.find((c) => fold(c.name) === folded);
  return found && found.division ? found.division : undefined;
}

/**
 * Divisiones que tiene sentido ofrecer para una ciudad: con una ciudad conocida,
 * solo la suya (Bogotá + Antioquia sería una dirección imposible); con otra
 * ciudad, todas.
 */
export function divisionOptionsForCity(pack: GeoPack, city: string): GeoDivision[] {
  const known = divisionForCity(pack, city);
  return known ? pack.divisions.filter((d) => d.code === known) : pack.divisions;
}

/**
 * Teléfono con el prefijo del paquete: `3001234567` → `+573001234567`. Si ya
 * trae prefijo, o no forma un número E.164 válido, se deja como lo escribió el
 * cliente (nunca se inventa un número).
 */
export function normalizePhoneForPack(pack: GeoPack, raw: string): string {
  const phone = raw.trim();
  if (!phone) return "";
  const compact = phone.replace(/[\s().-]/g, "");
  if (/^(\+|00)\d{8,15}$/.test(compact)) {
    const e164 = `+${compact.replace(/^(\+|00)/, "")}`;
    return isE164(e164) ? e164 : phone;
  }
  if (pack.dialCode && /^\d{6,14}$/.test(compact)) {
    return normalizePhoneE164(pack.dialCode, compact) ?? phone;
  }
  return phone;
}

export interface AddressFields {
  city?: string;
  state?: string;
  postcode?: string;
  country?: string;
  phone?: string;
}

export interface AddressIssue {
  field: "state" | "postcode";
  message: string;
}

export interface CheckedAddress {
  /** Estado listo para WooCommerce (el código de la división en listas cerradas). */
  state: string;
  phone: string;
  issues: AddressIssue[];
}

/**
 * Comprueba y normaliza una dirección contra el paquete. Solo se pronuncia sobre
 * direcciones del país del paquete: una dirección de otro país es texto libre.
 *
 * Nunca rellena una división «por defecto»: si no se puede resolver, es un
 * problema (`issues`), no un valor inventado.
 */
export function checkAddress(pack: GeoPack, address: AddressFields): CheckedAddress {
  const state = (address.state ?? "").trim();
  const city = (address.city ?? "").trim();
  const postcode = (address.postcode ?? "").trim();
  const phone = normalizePhoneForPack(pack, address.phone ?? "");
  const country = (address.country ?? "").trim().toUpperCase();
  const own = packCountry(pack);

  // Otro país (o paquete de reserva sin país): nada que comprobar.
  if (!own || (country && country !== own)) return { state, phone, issues: [] };

  const issues: AddressIssue[] = [];
  let resolved = state;

  if (pack.address.division === "none") {
    resolved = "";
  } else if (hasClosedDivisions(pack)) {
    const given = state ? findDivision(pack, state) : undefined;
    const fromCity = divisionForCity(pack, city);
    if (state && !given) {
      issues.push({ field: "state", message: `${pack.divisionLabel} no válido` });
    } else if (given && fromCity && given.code !== fromCity) {
      issues.push({
        field: "state",
        message: `${pack.divisionLabel} no corresponde a la ciudad`,
      });
    } else if (given) {
      resolved = given.code;
    } else if (fromCity) {
      resolved = fromCity;
    } else if (pack.address.division === "required") {
      issues.push({ field: "state", message: `${pack.divisionLabel} requerido` });
    }
  }

  if (pack.address.postcode === "required" && !postcode) {
    issues.push({ field: "postcode", message: "Código postal requerido" });
  }

  return { state: resolved, phone, issues };
}

/**
 * Comprueba varias direcciones con nombre (`billing_address`, `shipping`…) y las
 * devuelve normalizadas. `details` lleva los problemas por campo
 * (`billing_address.state`), con la forma de `flatten().fieldErrors` de Zod.
 */
export function applyGeoPack<K extends string, A extends AddressFields>(
  pack: GeoPack,
  addresses: Partial<Record<K, A>>,
): { addresses: Partial<Record<K, A>>; details: Record<string, string[]> } {
  const result: Partial<Record<K, A>> = {};
  const details: Record<string, string[]> = {};
  for (const key of Object.keys(addresses) as K[]) {
    const address = addresses[key];
    if (!address) continue;
    const checked = checkAddress(pack, address);
    for (const issue of checked.issues) {
      (details[`${key}.${issue.field}`] ??= []).push(issue.message);
    }
    result[key] = {
      ...address,
      ...(address.state !== undefined || checked.state ? { state: checked.state } : {}),
      ...(address.phone !== undefined ? { phone: checked.phone } : {}),
    } as A;
  }
  return { addresses: result, details };
}
