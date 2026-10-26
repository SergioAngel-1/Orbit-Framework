import type { StoreCart } from "@/types/woocommerce";
import {
  isBoolean,
  isMinorUnits,
  isNonNegativeInteger,
  isRecord,
  isString,
} from "@/lib/validation/light";

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

const FREE_DELIVERY_REASONS = [
  "membership_free_delivery",
  "already_free",
  "quota_exhausted",
  "not_eligible",
  "not_available",
] as const;

const PURCHASE_NOTICES = ["renewal", "upgrade", "scheduled"] as const;

const BLOCKED_ITEM_REASONS = [
  "membership_required",
  "membership_early_access",
] as const;

const KNOWN_FREE_DELIVERY_REASONS: ReadonlySet<string> = new Set(FREE_DELIVERY_REASONS);
const KNOWN_PURCHASE_NOTICES: ReadonlySet<string> = new Set(PURCHASE_NOTICES);
const KNOWN_BLOCKED_ITEM_REASONS: ReadonlySet<string> = new Set(BLOCKED_ITEM_REASONS);

/**
 * Normaliza un motivo/aviso: `null` si falta o es desconocido, y `undefined`
 * (contrato incumplido) si no es un string. Un valor futuro no rompe la lectura
 * del resto del carrito; un tipo erróneo sí.
 */
function knownOrNull(
  value: unknown,
  known: ReadonlySet<string>,
): string | null | undefined {
  if (value === undefined || value === null) return null;
  if (!isString(value)) return undefined;
  return known.has(value) ? value : null;
}

function parseLevel(raw: unknown): MembershipLevel | null | undefined {
  if (raw === null) return null;
  if (!isRecord(raw)) return undefined;

  const { id, slug, rank, name } = raw;
  if (
    !isNonNegativeInteger(id) ||
    !isString(slug) ||
    !isNonNegativeInteger(rank) ||
    !isString(name)
  ) {
    return undefined;
  }

  return { id, slug, rank, name };
}

function parseMemberDiscount(
  raw: unknown,
): MembershipsCartExtension["member_discount"] | undefined {
  if (!isRecord(raw) || !isMinorUnits(raw.total) || !isRecord(raw.lines)) {
    return undefined;
  }

  const lines: Record<string, string> = {};
  for (const [key, value] of Object.entries(raw.lines)) {
    if (!isMinorUnits(value)) return undefined;
    lines[key] = value;
  }

  return { total: raw.total, lines };
}

function parseFreeDelivery(
  raw: unknown,
): MembershipsCartExtension["free_delivery"] | undefined {
  if (!isRecord(raw)) return undefined;

  const { available, remaining, requested, applied } = raw;
  if (
    !isBoolean(available) ||
    !isNonNegativeInteger(remaining) ||
    !isBoolean(requested) ||
    !isBoolean(applied)
  ) {
    return undefined;
  }

  const reason = knownOrNull(raw.reason, KNOWN_FREE_DELIVERY_REASONS);
  if (reason === undefined) return undefined;

  return {
    available,
    remaining,
    requested,
    applied,
    reason: reason as MembershipFreeDelivery["reason"],
  };
}

function parseBlockedItems(raw: unknown): MembershipBlockedItem[] | undefined {
  if (!Array.isArray(raw)) return undefined;

  const items: MembershipBlockedItem[] = [];
  for (const entry of raw) {
    if (!isRecord(entry)) return undefined;

    const { key, product_id, reason, min_rank, level_slug, level_name } = entry;
    if (
      !isString(key) ||
      !isNonNegativeInteger(product_id) ||
      !isString(reason) ||
      !KNOWN_BLOCKED_ITEM_REASONS.has(reason) ||
      !isNonNegativeInteger(min_rank) ||
      !isString(level_slug) ||
      !isString(level_name)
    ) {
      return undefined;
    }

    const opensAt = entry.opens_at;
    if (opensAt !== undefined && opensAt !== null && !isString(opensAt)) {
      return undefined;
    }

    items.push({
      key,
      product_id,
      reason: reason as MembershipBlockedItem["reason"],
      min_rank,
      level_slug,
      level_name,
      opens_at: opensAt ?? null,
    });
  }

  return items;
}

/**
 * Lee `extensions["hwe-memberships"]` del carrito. Devuelve `null` si la
 * extensión no existe (módulo apagado) o si no cumple el contrato: una
 * extensión malformada nunca rompe el carrito.
 *
 * Validación manual a propósito (ver `lib/validation/light.ts`): importar zod
 * aquí lo arrastraría al bundle inicial de cualquier página con cajón de carrito.
 */
export function parseMembershipsCartExtension(
  cart: Pick<StoreCart, "extensions"> | unknown,
): MembershipsCartExtension | null {
  if (!isRecord(cart) || !isRecord(cart.extensions)) return null;
  const raw = cart.extensions[MEMBERSHIPS_CART_EXTENSION];
  if (!isRecord(raw)) return null;

  const level = parseLevel(raw.level);
  const memberDiscount = parseMemberDiscount(raw.member_discount);
  const freeDelivery = parseFreeDelivery(raw.free_delivery);
  const blockedItems = parseBlockedItems(raw.blocked_items);
  const purchaseNotice = knownOrNull(raw.purchase_notice, KNOWN_PURCHASE_NOTICES);

  if (
    level === undefined ||
    memberDiscount === undefined ||
    freeDelivery === undefined ||
    blockedItems === undefined ||
    purchaseNotice === undefined
  ) {
    return null;
  }

  // Se reconstruye con las claves del contrato: las extra se descartan.
  return {
    level,
    member_discount: memberDiscount,
    free_delivery: freeDelivery,
    blocked_items: blockedItems,
    purchase_notice: purchaseNotice as MembershipPurchaseNotice,
  };
}
