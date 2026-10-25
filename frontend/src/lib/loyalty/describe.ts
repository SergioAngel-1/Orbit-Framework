import { catalogFor } from "@/i18n/messages/catalogs";
import type { LoyaltyEntryData } from "./types";

// ============================================================================
//  Texto de un movimiento del libro, compuesto en el BFF.
//
//  WordPress guarda `type` + `data` estructurada (nunca una frase con el nombre
//  de la unidad, spec §5.1); aquí se traduce al idioma de la petición con los
//  mensajes de `messages/`. Es una función pura: no lee la config ni la
//  petición, así que se puede probar y reutilizar sin contexto de Next.
//
//  Un tipo desconocido (los que una instancia añada con
//  `hwe_loyalty_entry_types`) devuelve un texto neutro, nunca lanza.
// ============================================================================

type Catalog = ReturnType<typeof catalogFor>;

export interface LoyaltyEntryLike {
  type: string;
  data?: LoyaltyEntryData | null;
}

function entriesFor(locale: string): Catalog["loyalty"]["entries"] {
  return catalogFor(locale).loyalty.entries;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** Reemplaza `{clave}` por su valor; deja el marcador si el dato no vino. */
function interpolate(
  template: string,
  values: Record<string, string | number>,
): string {
  return template.replace(/\{(\w+)\}/g, (placeholder, key: string) => {
    const value = values[key];
    return value === undefined || value === "" ? placeholder : String(value);
  });
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

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

/** «texto (pedido N)» solo si el movimiento trae un pedido válido. */
function withOrder(text: string, order: number | null, template: string): string {
  return order === null ? text : interpolate(template, { text, order });
}

/**
 * Compone el texto de un movimiento desde su `type` y sus `data`.
 *
 * @param entry  Movimiento del que solo hacen falta el tipo y los datos.
 * @param locale Idioma de la petición (`es`, `en`…); lo demás cae a español.
 */
export function describeEntry(entry: LoyaltyEntryLike, locale: string): string {
  const entries = entriesFor(locale);
  const data = isRecord(entry.data) ? entry.data : {};
  const order = integerOf(data.order);

  switch (entry.type) {
    case "cashback":
    case "referral_commission_l1":
    case "referral_commission_l2":
    case "referral_product_bonus":
    case "redeem":
    case "redeem_refund":
    case "earn_reversal":
      return withOrder(entries[entry.type], order, entries.with_order);

    case "referral_signup_l1":
    case "referral_signup_l2":
    case "registration":
    case "expire":
      return entries[entry.type];

    case "review": {
      const source = textOf(data.source);
      if (source === "product") return entries.review_product;
      if (source === "site") return entries.review_site;
      return entries.review;
    }

    case "birthday": {
      const year = integerOf(data.year);
      return year === null
        ? entries.birthday_no_year
        : interpolate(entries.birthday, { year });
    }

    case "transfer_in": {
      const name = textOf(data.counterpart_name);
      return name
        ? interpolate(entries.transfer_in, { name })
        : entries.transfer_in_no_name;
    }

    case "transfer_out": {
      const name = textOf(data.counterpart_name);
      return name
        ? interpolate(entries.transfer_out, { name })
        : entries.transfer_out_no_name;
    }

    case "transfer_fee": {
      const name = textOf(data.counterpart_name);
      return name
        ? interpolate(entries.transfer_fee, { name })
        : entries.transfer_fee_no_name;
    }

    case "admin_credit":
    case "admin_debit": {
      const base = entries[entry.type];
      const note = textOf(data.note);
      return note ? interpolate(entries.with_note, { text: base, note }) : base;
    }

    default:
      return entries.unknown;
  }
}
