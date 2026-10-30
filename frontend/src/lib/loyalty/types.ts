// ============================================================================
//  Contrato TypeScript del módulo `loyalty` (monedero + referidos).
//
//  Los tipos de este archivo describen lo que devuelven las rutas internas de
//  WordPress (`hwe/v1/loyalty/…`) y la extensión `hwe-loyalty` de la Store API.
//  No son el contrato público del BFF: las rutas de la Tarea 14 validan su
//  entrada y devuelven estos mismos tipos (más el texto ya compuesto).
//
//  Nada de aquí depende de `@/components/**` ni de `@/instance/**`: `lib/` es
//  núcleo del framework.
// ============================================================================

/**
 * Tipos de movimiento del libro mayor (spec §5.2). El conjunto puede crecer en
 * WordPress con el filtro `hwe_loyalty_entry_types`: por eso los lectores
 * aceptan `string` y `describeEntry` tiene un texto neutro para los desconocidos.
 */
export type LoyaltyEntryType =
  // Abonos.
  | "cashback"
  | "referral_commission_l1"
  | "referral_commission_l2"
  | "referral_product_bonus"
  | "referral_signup_l1"
  | "referral_signup_l2"
  | "registration"
  | "review"
  | "birthday"
  | "transfer_in"
  | "redeem_refund"
  | "admin_credit"
  // Cargos.
  | "redeem"
  | "transfer_out"
  | "transfer_fee"
  | "expire"
  | "admin_debit"
  | "earn_reversal";

/** Resumen del monedero (`GET hwe/v1/loyalty/summary`). */
export interface LoyaltySummary {
  /** `false` si la cuenta no existe o su rol no participa (respuesta con ceros). */
  participant: boolean;
  /** Saldo vivo (suma de remanentes). */
  balance: number;
  /** Total histórico abonado. */
  earned: number;
  /** Total histórico gastado. */
  used: number;
  /** Qué cantidad caduca en los próximos 30 días y cuándo (fecha ISO 8601 UTC). */
  expiring: {
    points: number;
    date: string | null;
  };
}

/**
 * Datos estructurados de un movimiento, tal como los expone la lista blanca de
 * `ReadRoutes::DATA_FIELDS`. Las claves dependen del tipo (README del plugin).
 * Un tipo desconocido viaja con `{}`.
 */
export type LoyaltyEntryData = Record<string, unknown>;

/** Un movimiento del libro mayor. */
export interface LoyaltyTransaction {
  id: number;
  /**
   * Tipo técnico. Es `string` a propósito: WordPress admite tipos extra por
   * filtro y el frontend no debe romperse al encontrarse uno.
   */
  type: string;
  /** Puntos con signo (abono > 0, cargo < 0). */
  points: number;
  data: LoyaltyEntryData;
  /** ISO 8601 UTC; `null` si el libro no la tiene. */
  created_at: string | null;
  /** ISO 8601 UTC; `null` = no caduca. */
  expires_at: string | null;
  /**
   * Texto del movimiento ya compuesto por el BFF en el idioma de la petición
   * (`describeEntry`). Solo lo añade la ruta `/api/loyalty/transactions`; el
   * lector de servidor devuelve el tipo y sus datos.
   */
  description?: string;
}

/** Página de movimientos (`GET hwe/v1/loyalty/transactions`). */
export interface LoyaltyTransactionPage {
  items: LoyaltyTransaction[];
  total: number;
  /** `ceil(total / per_page)`; 0 sin resultados. */
  pages: number;
}

export type LoyaltyReferralStatus = "pending" | "confirmed";

/** Un referido de la red, sin ids de usuario ni datos de contacto. */
export interface LoyaltyReferral {
  /** Huella opaca y estable: clave de lista, o no repetir filas al paginar. */
  id: string;
  /** Nombre visible; vacío si la cuenta se eliminó. */
  name: string;
  /** ISO 8601 UTC de la atribución. */
  date: string | null;
  status: LoyaltyReferralStatus;
  /** Puntos que este referido ha generado al consultante. */
  points_generated: number;
}

/** Totales históricos de la red (`totals` de la ruta de referidos). */
export interface LoyaltyReferralTotals {
  level1: number;
  level2: number;
  pending: number;
  earned: number;
}

/** Página de la red de referidos (`GET hwe/v1/loyalty/referrals`). */
export interface LoyaltyReferralPage {
  items: LoyaltyReferral[];
  total: number;
  pages: number;
  totals: LoyaltyReferralTotals;
}

/** Motivos por los que el canje no está disponible (extensión de la Store API). */
export type LoyaltyCartUnavailableReason =
  | "guest"
  | "not_participant"
  | "disabled"
  | "below_minimum"
  | "cart_below_cash_minimum";

/**
 * Datos de `extensions["hwe-loyalty"]` del carrito de la Store API. El
 * descuento viaja en unidades MENORES (string), como el resto de importes de la
 * Store API: formatéalo con `formatStoreAmount`.
 */
export interface LoyaltyCartExtension {
  available: boolean;
  reason: LoyaltyCartUnavailableReason | null;
  balance: number;
  points_applied: number;
  /** Descuento aplicado, en unidades menores de la moneda. */
  discount: string;
  max_redeemable: number;
  min_redeem: number;
  full_payment_available: boolean;
}

/** Códigos estables de la ruta interna de transferencia. */
export type LoyaltyTransferError =
  | "disabled"
  | "invalid_request"
  | "conflict"
  | "below_minimum"
  | "insufficient"
  | "invalid_recipient"
  | "self"
  | "daily_cap"
  | "failed";

/** Resultado discriminado de `transferPoints` (no lanza por errores de negocio). */
export type LoyaltyTransferResult =
  | {
      ok: true;
      /** Lo que sale del emisor, comisión incluida. */
      sent: number;
      /** Lo que recibe la otra parte. */
      received: number;
      /** Comisión que se queda la tienda. */
      fee: number;
      /** Nombre visible del receptor (nunca email ni login). */
      recipient_name: string;
    }
  | { ok: false; error: LoyaltyTransferError };

/** Entrada de `transferPoints` y del cliente del navegador. */
export interface LoyaltyTransferInput {
  /** Código de referido del receptor. */
  code: string;
  /** Total que sale del emisor (comisión incluida). */
  points: number;
  /** Nota libre opcional que guarda el libro. */
  note?: string;
  /** Solo en el cliente: si falta, se genera una al enviar. */
  idempotencyKey?: string;
}

/**
 * Motivos estables de una atribución ignorada (`HWE_Loyalty_Referrals::attribute`).
 * `unavailable` es propio del BFF: WordPress no respondió o la respuesta no
 * cumplió el contrato.
 */
export type LoyaltyAttributeReason =
  | "disabled"
  | "invalid_code"
  | "self"
  | "not_participant"
  | "cycle"
  | "already_referred"
  | "storage"
  | "unavailable";

/** Resultado discriminado de `attributeReferral` (best-effort en el registro). */
export type LoyaltyAttributeResult =
  { ok: true } | { ok: false; reason: LoyaltyAttributeReason };

/** Código y enlace públicos de referido. */
export interface LoyaltyReferralLink {
  code: string;
  /** `null` si la cuenta aún no tiene código. */
  url: string | null;
}

/** Códigos de fallo de un lector del monedero. */
export type LoyaltyReadErrorCode =
  "not_found" | "unavailable" | "invalid_response" | "invalid_request";

/**
 * Error tipado de los lectores del monedero. Un fallo de WordPress NUNCA se
 * convierte en un saldo 0: se lanza esto (spec §9.2 «Fallos»). `not_found`
 * distingue el 404 de módulo/interruptor apagado de una caída real.
 */
export class LoyaltyReadError extends Error {
  readonly code: LoyaltyReadErrorCode;
  readonly status: number | null;

  constructor(code: LoyaltyReadErrorCode, status: number | null = null) {
    super(MESSAGE[code]);
    this.name = "LoyaltyReadError";
    this.code = code;
    this.status = status;
  }
}

const MESSAGE: Record<LoyaltyReadErrorCode, string> = {
  not_found: "La función de fidelización no está disponible.",
  unavailable: "No se pudo consultar el programa de fidelización.",
  invalid_response: "La respuesta de fidelización no cumple el contrato.",
  invalid_request: "La petición de fidelización no es válida.",
};
