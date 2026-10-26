// ============================================================================
//  Guards mínimos para validar payloads pequeños en el bundle del cliente.
//
//  Por qué existen: zod son ~34 kB gz. Los parsers de extensiones del carrito
//  (`lib/loyalty/cart.ts`, `lib/memberships/cart.ts`) los puede importar un
//  componente cliente —el cajón del carrito— y arrastrarían zod a TODAS las
//  páginas. Sus contratos son pequeños y fijos: cuatro guards bastan y se
//  auditan de un vistazo.
//
//  No es un sustituto general de zod: para formularios y cuerpos de API sigue
//  usándose `lib/validation/*`.
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
