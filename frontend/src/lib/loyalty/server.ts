import "server-only";
import { z } from "zod";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logReadFailure } from "@/lib/observability/read-failure";
import { logger } from "@/lib/observability/logger";
import type { SiteConfig } from "@/lib/config/types";
import {
  LoyaltyReadError,
  type LoyaltyAttributeReason,
  type LoyaltyAttributeResult,
  type LoyaltyEntryData,
  type LoyaltyReferralLink,
  type LoyaltyReferralPage,
  type LoyaltySummary,
  type LoyaltyTransaction,
  type LoyaltyTransactionPage,
  type LoyaltyTransferError,
  type LoyaltyTransferInput,
  type LoyaltyTransferResult,
} from "./types";

export { LoyaltyReadError } from "./types";
export type { LoyaltyAttributeResult, LoyaltyTransferResult } from "./types";

// ============================================================================
//  Lectores y escritores de servidor del módulo `loyalty`.
//
//  Hablan con las rutas internas de WordPress (`hwe/v1/loyalty/…`) por
//  `wpFetch`, que añade el secreto interno. El `user_id` SIEMPRE lo pone el
//  llamador a partir de la sesión verificada: estas funciones no aceptan un id
//  que venga del navegador.
//
//  Lecturas (`get*`, `validate*`): si WordPress falla se registra con
//  `logReadFailure` y se lanza `LoyaltyReadError`. Nunca se devuelve un saldo 0
//  inventado. Un 404 (módulo o interruptor apagado) se distingue con
//  `code: "not_found"`.
//
//  Escrituras (`attributeReferral`, `transferPoints`): devuelven un resultado
//  discriminado con el código estable de WordPress; un error de negocio no
//  lanza. Un fallo de transporte se registra y se normaliza.
// ============================================================================

const SUMMARY_PATH = "/wp-json/hwe/v1/loyalty/summary";
const TRANSACTIONS_PATH = "/wp-json/hwe/v1/loyalty/transactions";
const REFERRALS_PATH = "/wp-json/hwe/v1/loyalty/referrals";
const REFERRAL_CODE_PATH = "/wp-json/hwe/v1/loyalty/referral-code";
const VALIDATE_CODE_PATH = "/wp-json/hwe/v1/loyalty/validate-code";
const ATTRIBUTE_PATH = "/wp-json/hwe/v1/loyalty/attribute";
const TRANSFER_PATH = "/wp-json/hwe/v1/loyalty/transfer";

const READ_INIT = { cache: "no-store" as const };
const PER_PAGE_DEFAULT = 20;
const PER_PAGE_MAX = 100;

const ATTRIBUTE_REASONS: readonly LoyaltyAttributeReason[] = [
  "disabled",
  "invalid_code",
  "self",
  "not_participant",
  "cycle",
  "already_referred",
  "storage",
];

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

function assertUser(userId: number): void {
  if (!Number.isInteger(userId) || userId <= 0) {
    throw new LoyaltyReadError("invalid_request");
  }
}

/** Página ≥ 1; cualquier valor no entero o menor cae al de por defecto. */
function pageNumber(value: number | undefined, fallback: number): number {
  return Number.isInteger(value) && (value ?? 0) > 0 ? (value as number) : fallback;
}

/** Tamaño de página entre 1 y el tope de WordPress. */
function perPageNumber(value: number | undefined): number {
  return Math.min(PER_PAGE_MAX, pageNumber(value, PER_PAGE_DEFAULT));
}

function pathWithQuery(path: string, params: Record<string, string | number>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    query.set(key, String(value));
  }
  return `${path}?${query.toString()}`;
}

/**
 * GET a una ruta interna y devuelve el JSON crudo. Centraliza el registro y el
 * error tipado: cada lector solo añade su esquema.
 */
async function readJson(
  path: string,
  label: string,
  context: Record<string, unknown>,
): Promise<unknown> {
  let res: Response;
  try {
    res = await wpFetch(path, READ_INIT);
  } catch (error) {
    logReadFailure(`loyalty.${label}_failed`, error, {
      level: "error",
      message: "No se pudo consultar WordPress; el monedero no está disponible",
      context,
    });
    throw new LoyaltyReadError("unavailable");
  }

  if (res.status === 404) {
    // El módulo o el interruptor están apagados en WordPress. La guarda del BFF
    // ya debería haber cortado antes: se registra como aviso y se distingue del
    // resto de fallos para que la ruta pueda responder 404 sin inventar datos.
    logReadFailure(`loyalty.${label}_not_found`, "HTTP 404", {
      level: "warn",
      message: "La función de fidelización está apagada en WordPress",
      context,
    });
    throw new LoyaltyReadError("not_found", 404);
  }

  if (!res.ok) {
    logReadFailure(`loyalty.${label}_failed`, `HTTP ${res.status}`, {
      level: "error",
      message: "WordPress no sirvió la lectura de fidelización",
      context,
    });
    throw new LoyaltyReadError("unavailable", res.status);
  }

  try {
    return await res.json();
  } catch (error) {
    logReadFailure(`loyalty.${label}_failed`, error, {
      level: "error",
      message: "La lectura de fidelización no es JSON válido",
      context,
    });
    throw new LoyaltyReadError("invalid_response", res.status);
  }
}

/** Valida el cuerpo contra el contrato; un fallo también es un error tipado. */
function parseRead<T>(
  schema: z.ZodType<T>,
  body: unknown,
  label: string,
  context: Record<string, unknown>,
): T {
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    logReadFailure(`loyalty.${label}_failed`, "respuesta fuera de contrato", {
      level: "error",
      message: "La respuesta de WordPress no cumple el contrato de fidelización",
      context,
    });
    throw new LoyaltyReadError("invalid_response");
  }
  return parsed.data;
}

// ---------------------------------------------------------------------------
//  Lecturas
// ---------------------------------------------------------------------------

const summarySchema: z.ZodType<LoyaltySummary> = z.object({
  participant: z.boolean(),
  balance: z.number().int(),
  earned: z.number().int(),
  used: z.number().int(),
  expiring: z.object({
    points: z.number().int(),
    date: z.string().nullable(),
  }),
});

/** Resumen del monedero del usuario de la sesión. */
export async function getLoyaltySummary(userId: number): Promise<LoyaltySummary> {
  assertUser(userId);
  const body = await readJson(
    pathWithQuery(SUMMARY_PATH, { user_id: userId }),
    "summary",
    { userId },
  );
  return parseRead(summarySchema, body, "summary", { userId });
}

const transactionSchema = z.object({
  id: z.number().int(),
  type: z.string(),
  points: z.number().int(),
  data: z.unknown().optional(),
  created_at: z.string().nullable().optional(),
  expires_at: z.string().nullable().optional(),
});

const transactionPageSchema = z.object({
  items: z.array(transactionSchema),
  total: z.number().int().nonnegative(),
  pages: z.number().int().nonnegative(),
});

interface PageOptions {
  page?: number;
  perPage?: number;
}

/** Movimientos paginados, del más nuevo al más antiguo. */
export async function getLoyaltyTransactions(
  userId: number,
  { page, perPage }: PageOptions = {},
): Promise<LoyaltyTransactionPage> {
  assertUser(userId);
  const body = await readJson(
    pathWithQuery(TRANSACTIONS_PATH, {
      user_id: userId,
      page: pageNumber(page, 1),
      per_page: perPageNumber(perPage),
    }),
    "transactions",
    { userId },
  );
  const parsed = parseRead(transactionPageSchema, body, "transactions", {
    userId,
  });

  const items: LoyaltyTransaction[] = parsed.items.map((item) => ({
    id: item.id,
    type: item.type,
    points: item.points,
    // PHP serializa una lista vacía como `[]`: el contrato es un objeto de datos.
    data: (isRecord(item.data) ? item.data : {}) as LoyaltyEntryData,
    created_at: item.created_at ?? null,
    expires_at: item.expires_at ?? null,
  }));

  return { items, total: parsed.total, pages: parsed.pages };
}

const referralSchema = z.object({
  // Con `default` para no rechazar la página entera si el plugin aún no lo publica.
  id: z.string().default(""),
  name: z.string(),
  date: z.string().nullable().optional(),
  status: z.enum(["pending", "confirmed"]),
  points_generated: z.number().int(),
});

const referralPageSchema = z.object({
  items: z.array(referralSchema),
  total: z.number().int().nonnegative(),
  pages: z.number().int().nonnegative(),
  totals: z.object({
    level1: z.number().int(),
    level2: z.number().int(),
    pending: z.number().int(),
    earned: z.number().int(),
  }),
});

export interface ReferralOptions extends PageOptions {
  level: 1 | 2;
}

/** Red N1/N2 paginada, sin ids de usuario ni datos de contacto. */
export async function getLoyaltyReferrals(
  userId: number,
  { level, page, perPage }: ReferralOptions,
): Promise<LoyaltyReferralPage> {
  assertUser(userId);
  if (level !== 1 && level !== 2) {
    throw new LoyaltyReadError("invalid_request");
  }

  const body = await readJson(
    pathWithQuery(REFERRALS_PATH, {
      user_id: userId,
      level,
      page: pageNumber(page, 1),
      per_page: perPageNumber(perPage),
    }),
    "referrals",
    { userId },
  );
  const parsed = parseRead(referralPageSchema, body, "referrals", { userId });

  return {
    items: parsed.items.map((item) => ({
      id: item.id,
      name: item.name,
      date: item.date ?? null,
      status: item.status,
      points_generated: item.points_generated,
    })),
    total: parsed.total,
    pages: parsed.pages,
    totals: parsed.totals,
  };
}

const referralCodeSchema = z.object({ code: z.string() });

/**
 * Código y URL pública de referido. La URL se compone con `config.brand.url`
 * (nunca con `home_url()` de WordPress, que en headless apunta al backend).
 */
export async function getReferralLink(
  userId: number,
  config: Pick<SiteConfig, "brand">,
): Promise<LoyaltyReferralLink> {
  assertUser(userId);
  const body = await readJson(
    pathWithQuery(REFERRAL_CODE_PATH, { user_id: userId }),
    "referral_link",
    { userId },
  );
  const { code } = parseRead(referralCodeSchema, body, "referral_link", { userId });
  const trimmed = code.trim();
  if (!trimmed) return { code: "", url: null };

  const base = config.brand.url.replace(/\/+$/, "");
  return {
    code: trimmed,
    url: `${base}/?ref=${encodeURIComponent(trimmed)}`,
  };
}

const validateCodeSchema = z.object({ valid: z.boolean() });

/** `{ valid }` y nada más: no revela el dueño ni los datos de la cuenta. */
export async function validateReferralCode(code: string): Promise<{ valid: boolean }> {
  const trimmed = code.trim();
  if (!trimmed) return { valid: false };

  const body = await readJson(
    pathWithQuery(VALIDATE_CODE_PATH, { code: trimmed }),
    "validate_code",
    {},
  );
  return parseRead(validateCodeSchema, body, "validate_code", {});
}

// ---------------------------------------------------------------------------
//  Escrituras
// ---------------------------------------------------------------------------

const attributeSchema = z.object({ result: z.string() });

/**
 * Atribuye una cuenta recién creada al dueño de un código. Pensado para el
 * registro: un fallo se registra y devuelve `unavailable`, nunca rompe el alta.
 */
export async function attributeReferral(
  userId: number,
  code: string,
): Promise<LoyaltyAttributeResult> {
  const trimmed = code.trim();
  if (!Number.isInteger(userId) || userId <= 0 || !trimmed) {
    return { ok: false, reason: "invalid_code" };
  }

  let res: Response;
  try {
    res = await wpFetch(ATTRIBUTE_PATH, {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ user_id: userId, code: trimmed }),
    });
  } catch {
    logger.error(
      { event: "loyalty.attribute_failed", reason: "transport", userId },
      "No se pudo atribuir el referido en WordPress",
    );
    return { ok: false, reason: "unavailable" };
  }

  const body: unknown = await res.json().catch(() => null);
  const parsed = attributeSchema.safeParse(body);
  if (!parsed.success) {
    logger.error(
      {
        event: "loyalty.attribute_failed",
        reason: "invalid_response",
        httpStatus: res.status,
        userId,
      },
      "La atribución de referido devolvió una respuesta fuera de contrato",
    );
    return { ok: false, reason: "unavailable" };
  }

  if (parsed.data.result === "attributed") return { ok: true };

  if (parsed.data.result.startsWith("ignored:")) {
    const reason = parsed.data.result.slice("ignored:".length);
    if ((ATTRIBUTE_REASONS as readonly string[]).includes(reason)) {
      return { ok: false, reason: reason as LoyaltyAttributeReason };
    }
  }

  logger.error(
    {
      event: "loyalty.attribute_failed",
      reason: "unknown_result",
      httpStatus: res.status,
      userId,
    },
    "La atribución de referido devolvió un resultado desconocido",
  );
  return { ok: false, reason: "unavailable" };
}

const transferSuccessSchema = z.object({
  ok: z.literal(true),
  sent: z.number().int().nonnegative(),
  received: z.number().int().nonnegative(),
  fee: z.number().int().nonnegative(),
  recipient_name: z.string(),
});

const transferErrorSchema = z.object({ error: z.string() });

/**
 * Transfiere unidades a otro usuario. `points` es el total que SALE del emisor
 * (comisión incluida). La clave de idempotencia la decide el BFF y la repite el
 * cliente en un reintento: la misma clave devuelve el mismo resultado.
 */
export async function transferPoints(
  userId: number,
  input: LoyaltyTransferInput,
  idempotencyKey: string,
): Promise<LoyaltyTransferResult> {
  const code = input.code.trim();
  const key = idempotencyKey.trim();

  if (
    !Number.isInteger(userId) ||
    userId <= 0 ||
    !code ||
    !Number.isInteger(input.points) ||
    input.points <= 0 ||
    !key
  ) {
    return { ok: false, error: "invalid_request" };
  }

  const note = input.note?.trim();
  const payload = {
    user_id: userId,
    code,
    points: input.points,
    idempotency_key: key,
    ...(note ? { note } : {}),
  };

  let res: Response;
  try {
    res = await wpFetch(TRANSFER_PATH, {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  } catch {
    logger.error(
      { event: "loyalty.transfer_failed", reason: "transport", userId },
      "No se pudo completar la transferencia en WordPress",
    );
    return { ok: false, error: "failed" };
  }

  const body: unknown = await res.json().catch(() => null);

  if (res.ok) {
    const parsed = transferSuccessSchema.safeParse(body);
    if (parsed.success) {
      return {
        ok: true,
        sent: parsed.data.sent,
        received: parsed.data.received,
        fee: parsed.data.fee,
        recipient_name: parsed.data.recipient_name,
      };
    }
  } else {
    const parsed = transferErrorSchema.safeParse(body);
    if (
      parsed.success &&
      (TRANSFER_ERRORS as readonly string[]).includes(parsed.data.error)
    ) {
      return { ok: false, error: parsed.data.error as LoyaltyTransferError };
    }
  }

  logger.error(
    {
      event: "loyalty.transfer_failed",
      reason: "invalid_response",
      httpStatus: res.status,
      userId,
    },
    "La transferencia devolvió una respuesta fuera de contrato",
  );
  return { ok: false, error: "failed" };
}
