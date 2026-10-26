import type { LoyaltyCartExtension } from "./types";
import type { LoyaltyConfig } from "@/lib/config/types";
import type { StoreCart } from "@/types/woocommerce";
import {
  isBoolean,
  isMinorUnits,
  isNonNegativeInteger,
  isRecord,
  isString,
} from "@/lib/validation/light";

// ============================================================================
//  Lectura del canje y cálculo del máximo canjeable para MOSTRAR.
//
//  El descuento de verdad lo calcula WordPress (`HWE_Loyalty_Redemption::quote`)
//  y viaja en la extensión `hwe-loyalty` del carrito. La réplica de abajo sirve
//  para pintar un control (un slider, un «usar el máximo»…) sin depender de que
//  la extensión esté presente; nunca decide un importe ni se envía al servidor.
// ============================================================================

/** Nombre de la extensión que publica el plugin en la Store API. */
export const LOYALTY_CART_EXTENSION = "hwe-loyalty";

export interface MaxRedeemableInput {
  /** Total de las líneas de producto, con impuestos, en unidades mayores. */
  itemsTotal: number;
  /** Envío con impuestos, en unidades mayores. */
  shippingTotal: number;
  /** Otros fees ajenos al canje (pueden ser negativos), en unidades mayores. */
  feesTotal?: number;
  /** Saldo vivo del usuario. */
  balance: number;
}

const EPSILON = 1e-9;

function nonNegative(value: number | undefined): number {
  return Number.isFinite(value) && value !== undefined ? Math.max(0, value) : 0;
}

function integerConfig(value: string | undefined): number {
  const parsed = Number(String(value ?? "").trim());
  return Number.isFinite(parsed) ? Math.max(0, Math.trunc(parsed)) : 0;
}

/**
 * Máximo de unidades canjeables en este carrito, con los mismos topes que
 * `HWE_Loyalty_Redemption::quote()`:
 *
 *  - el saldo,
 *  - `max_redeem_per_order` (0 = sin tope),
 *  - `max_redeem_percent` (0 = sin tope): porcentaje de la base sin envío
 *    (productos + fees ajenos), convertido a unidades hacia abajo,
 *  - el tope del carrito: con pago completo apagado no cubre el envío ni deja
 *    el total por debajo de `min_cash_due`; con pago completo encendido puede
 *    cubrir el total (envío incluido).
 *
 * Devuelve 0 cuando el canje no puede aplicarse (valor de la unidad ≤ 0,
 * carrito por debajo del mínimo en dinero o máximo por debajo de `min_redeem`).
 * La versión de WordPress conserva el tope bruto en `max_redeemable` aunque
 * marque `available: false`; para un control de UI, 0 es lo que se puede usar
 * de verdad.
 */
export function maxRedeemable(
  input: MaxRedeemableInput,
  config: Pick<
    LoyaltyConfig,
    | "point_value"
    | "min_redeem"
    | "max_redeem_per_order"
    | "max_redeem_percent"
    | "full_payment_enabled"
    | "min_cash_due"
  >,
): number {
  const value = Number(
    String(config.point_value ?? "")
      .trim()
      .replace(",", "."),
  );
  if (!Number.isFinite(value) || value <= 0) return 0;

  const items = nonNegative(input.itemsTotal);
  const shipping = nonNegative(input.shippingTotal);
  const fees = Number.isFinite(input.feesTotal) ? (input.feesTotal ?? 0) : 0;
  const balance = Math.max(0, Math.trunc(input.balance) || 0);
  const minRedeem = integerConfig(config.min_redeem);
  const maxPerOrder = integerConfig(config.max_redeem_per_order);
  const maxPercent = Math.min(100, integerConfig(config.max_redeem_percent));
  const minCash = nonNegative(Number(config.min_cash_due));
  const full = config.full_payment_enabled === true;

  const total = Math.max(0, items + shipping + fees);

  let maxByCash: number;
  if (full) {
    maxByCash = Math.floor(total / value + EPSILON);
  } else {
    // Por debajo del mínimo a pagar en dinero, el carrito no admite canje.
    if (total < minCash) return 0;

    // El descuento no puede cubrir el envío: solo productos y fees ajenos.
    const nonShipping = Math.max(0, items + fees);
    const maxDiscount = Math.min(nonShipping, Math.max(0, total - minCash));
    if (maxDiscount <= 0) return 0;

    maxByCash = Math.floor(maxDiscount / value + EPSILON);
    if (maxByCash <= 0) return 0;
  }

  let max = Math.min(balance, maxByCash);
  if (maxPerOrder > 0) max = Math.min(max, maxPerOrder);
  if (maxPercent > 0) {
    // La base es productos + fees ajenos; el envío nunca cuenta (la suma cuadra con
    // los tres llamadores de `quote()` en WordPress, que reparten distinto).
    const percentBase = Math.max(0, items + fees);
    max = Math.min(max, Math.floor((percentBase * maxPercent) / 100 / value + EPSILON));
  }
  max = Math.max(0, max);

  if (max < minRedeem || max <= 0) return 0;
  return max;
}

const REASONS = [
  "guest",
  "not_participant",
  "disabled",
  "below_minimum",
  "cart_below_cash_minimum",
] as const;

const KNOWN_REASONS: ReadonlySet<string> = new Set(REASONS);

/**
 * Normaliza el motivo: `null` si falta o es desconocido, y `undefined` (que
 * invalida la extensión) si no es un string. Misma regla que el `transform` de
 * zod que sustituye: un motivo futuro no rompe la lectura, un tipo erróneo sí.
 */
function parseReason(value: unknown): LoyaltyCartExtension["reason"] | undefined {
  if (value === undefined || value === null) return null;
  if (!isString(value)) return undefined;
  return KNOWN_REASONS.has(value) ? (value as LoyaltyCartExtension["reason"]) : null;
}

/**
 * Lee `extensions["hwe-loyalty"]` del carrito. Tolera la extensión ausente (el
 * módulo apagado no la registra) y una malformada (un plugin de terceros, una
 * versión distinta): devuelve `null` en vez de lanzar.
 *
 * Validación manual a propósito (ver `lib/validation/light.ts`): importar zod
 * aquí lo arrastraría al bundle inicial de cualquier página con cajón de carrito.
 */
export function parseLoyaltyCartExtension(
  cart: Pick<StoreCart, "extensions"> | unknown,
): LoyaltyCartExtension | null {
  if (!isRecord(cart) || !isRecord(cart.extensions)) return null;
  const raw = cart.extensions[LOYALTY_CART_EXTENSION];
  if (!isRecord(raw)) return null;

  const {
    available,
    balance,
    points_applied,
    discount,
    max_redeemable,
    min_redeem,
    full_payment_available,
  } = raw;

  if (
    !isBoolean(available) ||
    !isNonNegativeInteger(balance) ||
    !isNonNegativeInteger(points_applied) ||
    !isMinorUnits(discount) ||
    !isNonNegativeInteger(max_redeemable) ||
    !isNonNegativeInteger(min_redeem) ||
    !isBoolean(full_payment_available)
  ) {
    return null;
  }

  const reason = parseReason(raw.reason);
  // `undefined` = motivo de un tipo que no es string: contrato incumplido.
  if (reason === undefined) return null;

  // Se reconstruye con las claves del contrato: las extra se descartan.
  return {
    available,
    reason,
    balance,
    points_applied,
    discount,
    max_redeemable,
    min_redeem,
    full_payment_available,
  };
}
