import { z } from "zod";
import type { LoyaltyCartExtension } from "./types";
import type { LoyaltyConfig } from "@/lib/config/types";
import type { StoreCart } from "@/types/woocommerce";

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

const extensionSchema = z.object({
  available: z.boolean(),
  // Un motivo futuro no debe romper la lectura: se normaliza a `null`.
  reason: z
    .string()
    .nullish()
    .transform((reason): LoyaltyCartExtension["reason"] =>
      reason && (REASONS as readonly string[]).includes(reason)
        ? (reason as LoyaltyCartExtension["reason"])
        : null,
    ),
  balance: z.number().int().nonnegative(),
  points_applied: z.number().int().nonnegative(),
  // Importe de la Store API: unidades menores como string de dígitos.
  discount: z.string().regex(/^\d+$/),
  max_redeemable: z.number().int().nonnegative(),
  min_redeem: z.number().int().nonnegative(),
  full_payment_available: z.boolean(),
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/**
 * Lee `extensions["hwe-loyalty"]` del carrito. Tolera la extensión ausente (el
 * módulo apagado no la registra) y una malformada (un plugin de terceros, una
 * versión distinta): devuelve `null` en vez de lanzar.
 */
export function parseLoyaltyCartExtension(
  cart: Pick<StoreCart, "extensions"> | unknown,
): LoyaltyCartExtension | null {
  if (!isRecord(cart) || !isRecord(cart.extensions)) return null;
  const raw = cart.extensions[LOYALTY_CART_EXTENSION];
  if (!isRecord(raw)) return null;

  const parsed = extensionSchema.safeParse(raw);
  return parsed.success ? parsed.data : null;
}
