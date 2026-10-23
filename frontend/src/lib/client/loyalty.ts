"use client";
import { csrfFetch } from "./csrf";
import { pageLocaleHeaders } from "./page-locale";
import type {
  LoyaltyReferralLink,
  LoyaltyReferralPage,
  LoyaltySummary,
  LoyaltyTransactionPage,
  LoyaltyTransferError,
  LoyaltyTransferInput,
  LoyaltyTransferResult,
} from "@/lib/loyalty/types";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Cliente del monedero (lado navegador). Llama siempre al BFF; nunca a
//  WordPress. Las lecturas van por GET con el idioma de la página (el BFF
//  compone el texto de los movimientos) y las escrituras por `csrfFetch`.
// ============================================================================

/**
 * Resumen que devuelve el BFF: el del lector más el valor del saldo en la
 * moneda de la tienda (unidades mayores), calculado con `pointsToMoney`.
 */
export interface LoyaltySummaryResponse extends LoyaltySummary {
  balance_value: number;
}

const TRANSFER_ERRORS: readonly LoyaltyTransferError[] = [
  "disabled",
  "invalid_request",
  "conflict",
  "below_minimum",
  "insufficient",
  "invalid_recipient",
  "self",
  "daily_cap",
  "failed",
];

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isTransferError(value: unknown): value is LoyaltyTransferError {
  return (
    typeof value === "string" && (TRANSFER_ERRORS as readonly string[]).includes(value)
  );
}

function isTransferSuccess(
  value: unknown,
): value is Extract<LoyaltyTransferResult, { ok: true }> {
  return (
    isRecord(value) &&
    value.ok === true &&
    typeof value.sent === "number" &&
    typeof value.received === "number" &&
    typeof value.fee === "number" &&
    typeof value.recipient_name === "string"
  );
}

/**
 * ¿Tiene la forma mínima de un carrito? Solo lo que la UI lee sin comprobar;
 * no valida cada campo de la Store API.
 */
function isStoreCart(value: unknown): value is StoreCart {
  if (!isRecord(value)) return false;
  return (
    Array.isArray(value.items) &&
    typeof value.items_count === "number" &&
    isRecord(value.totals)
  );
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") search.set(key, String(value));
  }
  const serialized = search.toString();
  return serialized ? `?${serialized}` : "";
}

async function getJson<T>(path: string): Promise<T> {
  const res = await fetch(path, {
    credentials: "same-origin",
    headers: pageLocaleHeaders(),
  });
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { error?: string };
    throw new Error(data.error || `Error ${res.status}`);
  }
  return (await res.json()) as T;
}

async function parseCart(res: Response): Promise<StoreCart> {
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { error?: string };
    throw new Error(data.error || `Error ${res.status}`);
  }
  const body: unknown = await res.json().catch(() => null);
  if (!isStoreCart(body)) {
    throw new Error("La respuesta del carrito no es válida.");
  }
  return body;
}

export const loyaltyApi = {
  /** Resumen del monedero de la sesión. */
  summary(): Promise<LoyaltySummaryResponse> {
    return getJson<LoyaltySummaryResponse>("/api/loyalty/summary");
  },

  /** Movimientos paginados, con el texto ya compuesto por el BFF. */
  transactions({ page, perPage }: { page?: number; perPage?: number } = {}) {
    return getJson<LoyaltyTransactionPage>(
      `/api/loyalty/transactions${query({ page, per_page: perPage })}`,
    );
  },

  /** Red N1/N2 paginada de la sesión. */
  referrals({
    level,
    page,
    perPage,
  }: {
    level: 1 | 2;
    page?: number;
    perPage?: number;
  }): Promise<LoyaltyReferralPage> {
    return getJson<LoyaltyReferralPage>(
      `/api/loyalty/referrals${query({ level, page, per_page: perPage })}`,
    );
  },

  /** Código y URL pública de referido del usuario. */
  referralLink(): Promise<LoyaltyReferralLink> {
    return getJson<LoyaltyReferralLink>("/api/loyalty/referral-link");
  },

  /** ¿El código existe y su dueño participa? Solo `{ valid }`. */
  validateCode(code: string): Promise<{ valid: boolean }> {
    return getJson<{ valid: boolean }>(`/api/loyalty/validate-code${query({ code })}`);
  },

  /**
   * Transfiere unidades a otro usuario. Si no se pasa `idempotencyKey` se genera
   * una: reintentar con la misma clave devuelve el mismo resultado y no mueve
   * las unidades dos veces. Un error de negocio se devuelve, no se lanza.
   */
  async transfer(input: LoyaltyTransferInput): Promise<LoyaltyTransferResult> {
    const key = input.idempotencyKey?.trim() || crypto.randomUUID();
    const body: Record<string, unknown> = {
      code: input.code,
      points: input.points,
    };
    if (input.note) body.note = input.note;

    try {
      const res = await csrfFetch("/api/loyalty/transfer", {
        method: "POST",
        body,
        headers: { "Idempotency-Key": key },
      });
      const data: unknown = await res.json().catch(() => null);

      if (isTransferSuccess(data)) return data;

      if (isRecord(data) && isTransferError(data.error)) {
        return { ok: false, error: data.error };
      }
    } catch {
      // Sin respuesta (red, CSRF): el resultado es un fallo, no una excepción
      // que rompa la pantalla. El reintento conserva la misma clave.
    }

    return { ok: false, error: "failed" };
  },

  /** Aplica unidades al carrito (0 = quitarlas). Devuelve el carrito recalculado. */
  async applyPoints(points: number): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/cart/points", {
        method: "POST",
        body: { points },
      }),
    );
  },

  /** Quita el canje del carrito. */
  async clearPoints(): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/cart/points", {
        method: "DELETE",
        body: { points: 0 },
      }),
    );
  },
};
