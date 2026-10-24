// ============================================================================
//  Contrato del módulo `memberships` que consume el checkout del BFF.
//
//  La Store API rechaza la compra con códigos `hwe_membership_*` cuando el
//  nivel cambia con el carrito ya lleno (caduca, se congela, se retira) o
//  cuando la línea de membresía no se puede comprar. El BFF los traduce a un
//  409 con un `reason` estable —el código sin el prefijo `hwe_`— y los datos
//  que la UI necesita para explicar qué pasó. Los textos se componen aquí:
//  WordPress no devuelve frases para el cliente.
// ============================================================================

/** Motivos estables que el checkout devuelve con 409. */
export const MEMBERSHIP_CHECKOUT_REASONS = [
  // Requisito de acceso de una línea (el nivel cambió con el carrito lleno).
  "membership_required",
  "membership_early_access",
  // Envío gratis pedido que ya no se puede aplicar.
  "membership_free_delivery_unavailable",
  // Reglas de compra de una membresía.
  "membership_login_required",
  "membership_one_per_cart",
  "membership_not_purchasable",
  "membership_account_too_new",
  "membership_account_pending",
  "membership_already_indefinite",
] as const;

export type MembershipCheckoutErrorReason =
  (typeof MEMBERSHIP_CHECKOUT_REASONS)[number];

/**
 * Datos estructurados que explican el rechazo. Solo viaja esta lista blanca:
 * el resto de la respuesta de WordPress no se reenvía al navegador.
 */
export interface MembershipCheckoutErrorDetails {
  /** Días que faltan para cumplir la antigüedad mínima de la cuenta. */
  days_left?: number;
  /** Días mínimos de antigüedad que exige el nivel. */
  min_account_age_days?: number;
  /** Antigüedad actual de la cuenta, o `null` si WordPress no la pudo leer. */
  account_age_days?: number | null;
  /** Nivel comprado o nivel efectivo según el motivo. */
  level_id?: number;
  /** Nivel que cubre una compra indefinida ya existente. */
  current_level_id?: number;
  /** Rango mínimo del requisito de acceso. */
  min_rank?: number;
  level_slug?: string;
  level_name?: string;
  /** ISO 8601 UTC de apertura al público del producto. */
  opens_at?: string | null;
  /** Producto y línea que motivan el bloqueo. */
  product_id?: number;
  item_id?: number;
  key?: string;
  /** Número de líneas de membresía del carrito (una por compra). */
  lines?: number;
}

export interface MembershipCheckoutError {
  reason: MembershipCheckoutErrorReason;
  details: MembershipCheckoutErrorDetails;
}

const MESSAGES: Readonly<Record<MembershipCheckoutErrorReason, string>> = {
  membership_required:
    "Este producto requiere una membresía activa. Revisa tu membresía e inténtalo de nuevo.",
  membership_early_access:
    "Este producto todavía no está disponible para tu membresía. Revisa la fecha de apertura e inténtalo de nuevo.",
  membership_free_delivery_unavailable:
    "No queda ningún envío gratis disponible en tu membresía. Revisa el carrito e inténtalo de nuevo.",
  membership_login_required: "Inicia sesión para comprar una membresía.",
  membership_one_per_cart:
    "Solo puedes llevar una membresía por compra, con cantidad 1.",
  membership_not_purchasable: "Ese nivel de membresía no está disponible para comprar.",
  membership_account_too_new:
    "Tu cuenta todavía no cumple la antigüedad mínima para comprar este nivel.",
  membership_account_pending: "Tu cuenta está pendiente de aprobación.",
  membership_already_indefinite:
    "Ya tienes ese nivel de membresía, o uno superior, sin caducidad.",
};

const NUMBER_FIELDS = [
  "days_left",
  "min_account_age_days",
  "level_id",
  "current_level_id",
  "min_rank",
  "product_id",
  "item_id",
  "lines",
] as const;

const STRING_FIELDS = ["level_slug", "level_name", "key"] as const;

const PREFIX = "hwe_";

function isReason(value: string): value is MembershipCheckoutErrorReason {
  return (MEMBERSHIP_CHECKOUT_REASONS as readonly string[]).includes(value);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function nonNegativeInteger(value: unknown): number | undefined {
  return typeof value === "number" && Number.isInteger(value) && value >= 0
    ? value
    : undefined;
}

/** Copia solo los datos útiles y con el tipo esperado. */
function pickDetails(data: unknown): MembershipCheckoutErrorDetails {
  if (!isRecord(data)) return {};

  const details: MembershipCheckoutErrorDetails = {};

  for (const field of NUMBER_FIELDS) {
    const value = nonNegativeInteger(data[field]);
    if (value !== undefined) details[field] = value;
  }

  for (const field of STRING_FIELDS) {
    if (typeof data[field] === "string") details[field] = data[field] as string;
  }

  const age = data.account_age_days;
  if (typeof age === "number" && Number.isInteger(age) && age >= 0) {
    details.account_age_days = age;
  } else if (age === null) {
    details.account_age_days = null;
  }

  const opensAt = data.opens_at;
  if (typeof opensAt === "string" || opensAt === null) {
    details.opens_at = opensAt;
  }

  return details;
}

/**
 * Traduce un código de la Store API del módulo a su motivo estable y sus
 * datos. Un código ajeno devuelve `null` y sigue el camino de error normal.
 */
export function membershipCheckoutError(
  code: string | null | undefined,
  data?: unknown,
): MembershipCheckoutError | null {
  if (!code) return null;

  const reason = code.startsWith(PREFIX) ? code.slice(PREFIX.length) : code;
  if (!isReason(reason)) return null;

  return { reason, details: pickDetails(data) };
}

/** Texto del 409 para el cliente, a partir del motivo estable. */
export function membershipCheckoutMessage(
  reason: MembershipCheckoutErrorReason,
): string {
  return MESSAGES[reason];
}
