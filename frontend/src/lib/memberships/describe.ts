import { catalogFor } from "@/i18n/messages/catalogs";
import { formatMembershipDuration } from "./format";
import type { MembershipBenefitData } from "./types";

// ============================================================================
//  Textos de cliente del módulo, compuestos en el BFF.
//
//  WordPress manda claves y datos estructurados (`key` + `publicData`, `type` +
//  `data`); aquí se traducen al idioma de la petición con los mensajes de
//  `messages/`. Funciones puras: no leen la config ni la petición, así que se
//  pueden probar y reutilizar sin contexto de Next.
//
//  Un tipo de beneficio o un evento desconocido (los que añada una instancia)
//  devuelve un texto neutro, nunca lanza.
// ============================================================================

type MembershipsMessages = ReturnType<typeof catalogFor>["memberships"];

function messagesFor(locale: string): MembershipsMessages {
  return catalogFor(locale).memberships;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function textOf(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}

function integerOf(value: unknown): number | null {
  const parsed =
    typeof value === "number"
      ? value
      : typeof value === "string" && value.trim() !== ""
        ? Number(value)
        : Number.NaN;

  return Number.isInteger(parsed) ? parsed : null;
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

/** `pt-BR` → `pt_br`; `en-US` → `en_us`. */
function localeSuffix(locale: string): string {
  return locale.trim().toLowerCase().replace(/-/g, "_");
}

/**
 * Texto de un campo traducible del Control Center (`{ locale: texto }`).
 *
 * Prueba el idioma exacto, luego su base (`en_US` → `en`) y, si no hay nada, el
 * contenido principal (español) o la primera traducción que exista. Un valor
 * que no sea un mapa de textos devuelve cadena vacía.
 */
export function localizedText(value: unknown, locale: string): string {
  if (!isRecord(value)) return "";

  const wanted = localeSuffix(locale);
  const base = wanted.split("_")[0]!;
  const candidates = [wanted, base, "es", "en"];

  for (const candidate of candidates) {
    const text = textOf(value[candidate]);
    if (text !== null) return text;
  }
  for (const entry of Object.values(value)) {
    const text = textOf(entry);
    if (text !== null) return text;
  }

  return "";
}

/** Datos públicos del beneficio (`publicData()` de WordPress). */
export interface MembershipBenefitLike {
  key: string;
  data?: unknown;
}

/** Cuántos envíos gratis declara el beneficio (campo aún abierto en el backend). */
function freeDeliveriesCount(data: MembershipBenefitData): number | null {
  for (const key of ["count", "quantity", "deliveries", "total"]) {
    const parsed = integerOf(data[key]);
    if (parsed !== null && parsed > 0) return parsed;
  }
  return null;
}

/**
 * Compone el texto de un beneficio desde su clave y sus datos públicos.
 *
 * @param benefit  Clave estable + datos del tipo (nunca una frase).
 * @param locale   Idioma de la petición; lo demás cae a español.
 */
export function describeBenefit(
  benefit: MembershipBenefitLike,
  locale: string,
): string {
  const messages = messagesFor(locale);
  const benefits = messages.benefits;
  const data = isRecord(benefit.data) ? benefit.data : {};

  switch (benefit.key) {
    case "member_discount": {
      const percent = integerOf(data.percent);
      if (percent === null || percent <= 0) return benefits.member_discount;
      return interpolate(
        data.scope === "categories"
          ? benefits.member_discount_categories
          : benefits.member_discount_all,
        { percent },
      );
    }

    case "early_access":
      return benefits.early_access;

    case "activation_points": {
      const points = integerOf(data.points);
      return points === null || points <= 0
        ? benefits.activation_points_plain
        : interpolate(benefits.activation_points, { points });
    }

    case "referral_bonus": {
      const percent = integerOf(data.percent);
      return percent === null || percent <= 0
        ? benefits.referral_bonus_plain
        : interpolate(benefits.referral_bonus, { percent });
    }

    case "referral_gift": {
      const max = integerOf(data.max_per_period);
      const days = integerOf(data.days);
      return max !== null && max > 0 && days !== null && days > 0
        ? interpolate(benefits.referral_gift, { max, days })
        : benefits.referral_gift_short;
    }

    case "free_deliveries": {
      const count = freeDeliveriesCount(data);
      return count === null
        ? benefits.free_deliveries_short
        : interpolate(benefits.free_deliveries, { count });
    }

    case "info_perk": {
      const title = localizedText(data.title, locale);
      if (title !== "") return title;
      const text = localizedText(data.text, locale);
      return text !== "" ? text : benefits.info_perk;
    }

    default:
      return benefits.unknown;
  }
}

/** Evento del historial tal como lo devuelve WordPress (tipo + datos). */
export interface MembershipHistoryLike {
  type: string;
  data?: Record<string, unknown> | null;
}

/** Tipos del ciclo que llevan la duración en `data.days`. */
const DURATION_TYPES = new Set([
  "activated",
  "renewed",
  "upgraded",
  "deferred",
  "granted_by_admin",
  "referral_gift_granted",
]);

/** Texto base de un evento; `null` si el frontend no lo conoce. */
function historyBase(type: string, messages: MembershipsMessages): string | null {
  const history = messages.history;
  switch (type) {
    case "activated":
    case "renewed":
    case "upgraded":
    case "deferred":
    case "granted_by_admin":
    case "referral_gift_granted":
    case "revoked":
    case "removed_by_admin":
    case "expired":
    case "frozen":
    case "unfrozen":
    case "points_skipped":
    case "referral_gift_limit_reached":
      return history[type];
    default:
      return null;
  }
}

/**
 * Compone el texto de un evento del historial desde su `type` y sus `data`.
 * Los eventos con duración la añaden («Membresía activada durante 30 días»).
 */
export function describeHistoryEntry(
  entry: MembershipHistoryLike,
  locale: string,
): string {
  const messages = messagesFor(locale);
  const data = isRecord(entry.data) ? entry.data : {};

  if (entry.type === "expiring") {
    const days = integerOf(data.days);
    return days === null || days <= 0
      ? messages.history.expiring_soon
      : interpolate(messages.history.expiring, {
          duration: formatMembershipDuration(days, locale),
        });
  }

  const base = historyBase(entry.type, messages);
  if (base === null) return messages.history.unknown;

  if (!DURATION_TYPES.has(entry.type)) return base;

  const days = integerOf(data.days);
  return days === null || days <= 0
    ? base
    : interpolate(messages.history.with_duration, {
        text: base,
        duration: formatMembershipDuration(days, locale),
      });
}

/**
 * Texto del motivo por el que un nivel no se puede comprar. Los motivos son los
 * códigos estables de `HWE_Memberships_Purchase`; un motivo vacío (se puede
 * comprar) no compone nada y uno desconocido cae al texto neutro.
 */
export function describePurchaseReason(
  reason: string,
  data: Record<string, unknown>,
  locale: string,
): string {
  const purchase = messagesFor(locale).purchase;
  const key = reason.trim().replace(/^membership_/, "");

  switch (key) {
    case "":
      return "";
    case "login_required":
      return purchase.login_required;
    case "one_per_cart":
      return purchase.one_per_cart;
    case "not_purchasable":
      return purchase.not_purchasable;
    case "account_pending":
      return purchase.account_pending;
    case "already_indefinite":
      return purchase.already_indefinite;
    case "account_too_new": {
      const days = integerOf(data.days_left);
      return days === null || days <= 0
        ? purchase.account_too_new_plain
        : interpolate(purchase.account_too_new, {
            duration: formatMembershipDuration(days, locale),
          });
    }
    default:
      return purchase.unknown;
  }
}
