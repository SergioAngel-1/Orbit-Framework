// ============================================================================
//  Guards mínimos para validar payloads pequeños en el bundle del cliente.
//
//  Por qué existen: zod son ~34 kB gz. Los parsers de extensiones del carrito
//  (`lib/loyalty/cart.ts`, `lib/memberships/cart.ts`) los puede importar un
//  componente cliente —el cajón del carrito— y arrastrarían zod a TODAS las
//  páginas, igual que `destinationFromAddress` en el checkout. Sus contratos son
//  pequeños y fijos: unos guards bastan y se auditan de un vistazo.
//
//  No es un sustituto general de zod: para formularios y cuerpos de API sigue
//  usándose `lib/validation/*`. Cuando una regla la comparten el cliente y el BFF,
//  el guard es la única fuente y el esquema de zod la envuelve (`locationSchema`).
// ============================================================================

/** Objeto plano (excluye arrays y `null`). */
export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function isBoolean(value: unknown): value is boolean {
  return typeof value === "boolean";
}

export function isString(value: unknown): value is string {
  return typeof value === "string";
}

/** Entero ≥ 0, igual que `z.number().int().nonnegative()`. */
export function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value >= 0;
}

/** Unidades menores: string de solo dígitos, igual que `z.string().regex(/^\d+$/)`. */
export function isMinorUnits(value: unknown): value is string {
  return typeof value === "string" && /^\d+$/.test(value);
}

/** Pin del cliente: coordenadas en grados decimales. Son números JSON, no cadenas. */
export interface GeoPoint {
  lat: number;
  lng: number;
}

/**
 * Pin del cliente válido, o `null` si no lo es. Es la ÚNICA fuente de la regla: números finitos,
 * latitud en ±90, longitud en ±180 y nunca `(0, 0)` (el valor por defecto de un mapa sin marcar,
 * que no es una ubicación real). `locationSchema` (`lib/validation/address.ts`) la envuelve para
 * el BFF, así que quien solo necesita descartar un pin mal formado —el checkout— no arrastra zod.
 */
export function parseGeoPoint(value: unknown): GeoPoint | null {
  if (!isRecord(value)) return null;
  const { lat, lng } = value;
  const latitude =
    typeof lat === "number" && Number.isFinite(lat) && lat >= -90 && lat <= 90;
  const longitude =
    typeof lng === "number" && Number.isFinite(lng) && lng >= -180 && lng <= 180;
  if (!latitude || !longitude) return null;
  if (lat === 0 && lng === 0) return null;
  return { lat, lng };
}
