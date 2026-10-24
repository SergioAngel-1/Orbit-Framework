import { z } from "zod";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Lectura de `extensions["hwe-memberships"]` del carrito.
//
//  WordPress publica en la Store API el nivel efectivo, el descuento de
//  miembro (unidades MENORES como string), el estado del envío gratis, las
//  líneas bloqueadas y el aviso de compra de la membresía. El navegador nunca
//  decide importes: solo lee lo que calculó WordPress para pintarlo.
//
//  El parser tolera que la extensión no exista (módulo apagado) y que una
//  versión futura añada motivos o avisos que este frontend aún no conoce.
// ============================================================================

/** Namespace de la extensión que publica el plugin en la Store API. */
export const MEMBERSHIPS_CART_EXTENSION = "hwe-memberships";

/** Nivel efectivo del socio, o `null` sin membresía / congelado. */
export interface MembershipLevel {
  id: number;
  slug: string;
  rank: number;
  name: string;
}

/**
 * Motivos estables del estado del envío gratis. `membership_free_delivery`
 * significa que el beneficio se aplicó a este carrito; el resto explica por
 * qué no (o por qué no hacía falta).
 */
export type MembershipFreeDeliveryReason =
  | "membership_free_delivery"
  | "already_free"
  | "quota_exhausted"
  | "not_eligible"
  | "not_available";

export interface MembershipFreeDelivery {
  /** El socio tiene el beneficio activo y le quedan envíos en el periodo. */
  available: boolean;
  /** Envíos gratis que le quedan en el periodo. */
  remaining: number;
  /** El cliente declaró «usar un envío gratis» en este carrito. */
  requested: boolean;
  /** WordPress lo aplicó a las tarifas de este carrito. */
  applied: boolean;
  reason: MembershipFreeDeliveryReason | null;
}

/** Motivo por el que una línea del carrito no se puede comprar. */
export type MembershipBlockedItemReason =
  "membership_required" | "membership_early_access";

export interface MembershipBlockedItem {
  /** Clave de la línea del carrito. */
  key: string;
  /** Id comprable de la línea (la variación cuando la hay). */
  product_id: number;
  reason: MembershipBlockedItemReason;
  min_rank: number;
  level_slug: string;
  level_name: string;
  /** ISO 8601 UTC de apertura al público, o `null` si no está programada. */
  opens_at: string | null;
}

/** Qué pasará al cobrar la línea de membresía del carrito. */
export type MembershipPurchaseNotice = "renewal" | "upgrade" | "scheduled" | null;

export interface MembershipsCartExtension {
  level: MembershipLevel | null;
  member_discount: {
    /** Unidades menores de la moneda, como string de dígitos. */
    total: string;
    /** Unidades menores por clave de línea, como string de dígitos. */
    lines: Record<string, string>;
  };
  free_delivery: MembershipFreeDelivery;
  blocked_items: MembershipBlockedItem[];
  purchase_notice: MembershipPurchaseNotice;
}

const MINOR_UNITS = /^\d+$/;

const FREE_DELIVERY_REASONS = [
  "membership_free_delivery",
  "already_free",
  "quota_exhausted",
  "not_eligible",
  "not_available",
] as const;

const PURCHASE_NOTICES = ["renewal", "upgrade", "scheduled"] as const;

/**
 * Un motivo futuro no debe romper la lectura del resto del carrito: se
 * normaliza a `null`, igual que hace el lector de fidelización.
 */
const freeDeliveryReasonSchema = z
  .string()
  .nullish()
  .transform((reason): MembershipFreeDeliveryReason | null =>
    reason && (FREE_DELIVERY_REASONS as readonly string[]).includes(reason)
      ? (reason as MembershipFreeDeliveryReason)
      : null,
  );

const purchaseNoticeSchema = z
  .string()
  .nullish()
  .transform((notice): MembershipPurchaseNotice =>
    notice && (PURCHASE_NOTICES as readonly string[]).includes(notice)
      ? (notice as Exclude<MembershipPurchaseNotice, null>)
      : null,
  );

const extensionSchema = z.object({
  level: z
    .object({
      id: z.number().int().nonnegative(),
      slug: z.string(),
      rank: z.number().int().nonnegative(),
      name: z.string(),
    })
    .nullable(),
  member_discount: z.object({
    total: z.string().regex(MINOR_UNITS),
    lines: z.record(z.string(), z.string().regex(MINOR_UNITS)),
  }),
  free_delivery: z.object({
    available: z.boolean(),
    remaining: z.number().int().nonnegative(),
    requested: z.boolean(),
    applied: z.boolean(),
    reason: freeDeliveryReasonSchema,
  }),
  blocked_items: z.array(
    z.object({
      key: z.string(),
      product_id: z.number().int().nonnegative(),
      reason: z.enum(["membership_required", "membership_early_access"]),
      min_rank: z.number().int().nonnegative(),
      level_slug: z.string(),
      level_name: z.string(),
      opens_at: z
        .string()
        .nullish()
        .transform((opensAt) => opensAt ?? null),
    }),
  ),
  purchase_notice: purchaseNoticeSchema,
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/**
 * Lee `extensions["hwe-memberships"]` del carrito. Devuelve `null` si la
 * extensión no existe (módulo apagado) o si no cumple el contrato: una
 * extensión malformada nunca rompe el carrito.
 */
export function parseMembershipsCartExtension(
  cart: Pick<StoreCart, "extensions"> | unknown,
): MembershipsCartExtension | null {
  if (!isRecord(cart) || !isRecord(cart.extensions)) return null;
  const raw = cart.extensions[MEMBERSHIPS_CART_EXTENSION];
  if (!isRecord(raw)) return null;

  const parsed = extensionSchema.safeParse(raw);
  return parsed.success ? parsed.data : null;
}
