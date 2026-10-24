import "server-only";
import { z } from "zod";
import { routing } from "@/i18n/routing";
import { logReadFailure } from "@/lib/observability/read-failure";
import { wpFetch, type WpFetchInit } from "@/lib/wordpress/internal-fetch";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { describeBenefit } from "./describe";
import { formatMemberNumber, formatMembershipDuration, fromMinorUnits } from "./format";
import { normalizeMembershipRequirement } from "./requirements";
import {
  MembershipsReadError,
  type MembershipBenefit,
  type MembershipBenefitData,
  type MembershipCardToken,
  type MembershipHistoryPage,
  type MembershipLevel,
  type MembershipPrice,
  type MembershipProduct,
  type MembershipState,
  type MembershipVerification,
} from "./types";

export { MembershipsReadError } from "./types";

// ============================================================================
//  Lectores de servidor del módulo `memberships`.
//
//  Hablan con las rutas internas de WordPress (`hwe/v1/memberships/…`) por
//  `wpFetch`, que añade el secreto interno. El `user_id` SIEMPRE lo pone el
//  llamador desde la sesión verificada: estas funciones no aceptan un id que
//  venga del navegador.
//
//  Los niveles son contenido: se cachean con ISR y el tag `memberships`. Todo lo
//  que depende del usuario va sin caché. Un fallo de lectura se registra con
//  `logReadFailure` y se lanza `MembershipsReadError`: nunca se devuelve un
//  estado inventado.
// ============================================================================

const LEVELS_PATH = "/wp-json/hwe/v1/memberships/levels";
const ME_PATH = "/wp-json/hwe/v1/memberships/me";
const HISTORY_PATH = "/wp-json/hwe/v1/memberships/history";
const PRICES_PATH = "/wp-json/hwe/v1/memberships/prices";
const PRODUCT_PATH = "/wp-json/hwe/v1/memberships/product";
const CARD_PATH = "/wp-json/hwe/v1/memberships/card";
const VERIFY_PATH = "/wp-json/hwe/v1/memberships/verify";

const READ_INIT: WpFetchInit = { cache: "no-store" };
/** Los niveles cambian al editarlos; el Control Center invalida el tag al guardar. */
const LEVELS_REVALIDATE = 300;
const PER_PAGE_DEFAULT = 20;
const PER_PAGE_MAX = 100;
const MAX_PRICE_IDS = 50;

/** Token del carné: 32 bytes en hexadecimal (lo fija `HWE_Memberships_Card`). */
const CARD_TOKEN_PATTERN = /^[0-9a-f]{64}$/;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function assertUser(userId: number): void {
  if (!Number.isInteger(userId) || userId <= 0) {
    throw new MembershipsReadError("invalid_request");
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

/** Ids de producto únicos, positivos y en el orden pedido. */
function normalizeIds(ids: readonly number[]): number[] {
  const out: number[] = [];
  for (const id of ids) {
    if (Number.isInteger(id) && id > 0 && !out.includes(id)) out.push(id);
  }
  return out;
}

/** Motivo estable que WordPress adjunta a un 403/400 (`error` o `code`). */
function failureReason(body: unknown): string | null {
  if (!isRecord(body)) return null;
  const code = typeof body.code === "string" ? body.code.trim() : "";
  const error = typeof body.error === "string" ? body.error.trim() : "";
  const reason = code || error;
  return reason === "" || reason === "internal_error" ? null : reason;
}

/**
 * GET a una ruta interna y devuelve el JSON crudo. Centraliza el registro y el
 * error tipado: cada lector solo añade su esquema.
 */
async function readJson(
  path: string,
  label: string,
  context: Record<string, unknown>,
  init: WpFetchInit = READ_INIT,
): Promise<unknown> {
  let res: Response;
  try {
    res = await wpFetch(path, init);
  } catch (error) {
    logReadFailure(`memberships.${label}_failed`, error, {
      level: "error",
      message: "No se pudo consultar WordPress; membresías no está disponible",
      context,
    });
    throw new MembershipsReadError("unavailable");
  }

  if (!res.ok) {
    const body: unknown = await res.json().catch(() => null);
    const reason = failureReason(body);

    if (res.status === 400) {
      logReadFailure(`memberships.${label}_invalid_request`, "HTTP 400", {
        level: "warn",
        message: "WordPress rechazó la lectura de membresías",
        context,
      });
      throw new MembershipsReadError("invalid_request", 400, reason);
    }

    if (res.status === 403) {
      // Sin nivel efectivo (carné) o sin permiso: es una condición de negocio
      // prevista, no una caída. No se registra como error.
      throw new MembershipsReadError("forbidden", 403, reason);
    }

    if (res.status === 404) {
      // El módulo está apagado en WordPress (la guarda del BFF ya debería haber
      // cortado antes). Se distingue del resto de fallos para responder 404.
      logReadFailure(`memberships.${label}_not_found`, "HTTP 404", {
        level: "warn",
        message: "La función de membresías está apagada en WordPress",
        context,
      });
      throw new MembershipsReadError("not_found", 404, reason);
    }

    logReadFailure(`memberships.${label}_failed`, `HTTP ${res.status}`, {
      level: "error",
      message: "WordPress no sirvió la lectura de membresías",
      context,
    });
    throw new MembershipsReadError("unavailable", res.status, reason);
  }

  try {
    return await res.json();
  } catch (error) {
    logReadFailure(`memberships.${label}_failed`, error, {
      level: "error",
      message: "La lectura de membresías no es JSON válido",
      context,
    });
    throw new MembershipsReadError("invalid_response", res.status);
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
    logReadFailure(`memberships.${label}_failed`, "respuesta fuera de contrato", {
      level: "error",
      message: "La respuesta de WordPress no cumple el contrato de membresías",
      context,
    });
    throw new MembershipsReadError("invalid_response");
  }
  return parsed.data;
}

/** Texto de un precio de WooCommerce como string (la API a veces manda número). */
const decimalSchema = z.union([z.string(), z.number()]).transform(String);

/**
 * Mapa de datos. PHP serializa un array vacío como `[]`, no como `{}`: un
 * beneficio o un evento sin datos debe seguir siendo un objeto del contrato.
 */
const dataSchema = z
  .union([z.record(z.string(), z.unknown()), z.array(z.unknown()), z.null()])
  .transform((value): Record<string, unknown> =>
    value === null || Array.isArray(value) ? {} : value,
  );

/** Mapa idioma → texto; un mapa vacío de PHP llega como `[]`. */
const stringMapSchema = z
  .union([z.record(z.string(), z.string()), z.array(z.unknown())])
  .transform((value): Record<string, string> => (Array.isArray(value) ? {} : value));

/** Un beneficio tal como lo publica WordPress (`publicData` + `key`). */
const benefitSchema = z.looseObject({ key: z.string() });

/** Registro de un beneficio activo (`activeFor` de `/me`). */
const activeBenefitSchema = z.looseObject({
  key: z.string(),
  level_id: z.number().int().optional().default(0),
  public: dataSchema.optional().default({}),
  state: dataSchema.optional().default({}),
});

const levelSchema = z.looseObject({
  id: z.number().int().positive(),
  name: z.string(),
  description: z.string().optional().default(""),
  slug: z.string(),
  slugs: stringMapSchema.optional().default({}),
  rank: z.number().int(),
  icon: z.string().optional().default(""),
  color: z.string().optional().default(""),
  purchasable: z.boolean().optional().default(false),
  min_account_age_days: z.number().int().optional().default(0),
  benefits: z.array(benefitSchema).optional().default([]),
  products: z
    .array(
      z.looseObject({
        id: z.number().int().positive(),
        price: decimalSchema,
        days: z.number().int().nullable().optional().default(null),
      }),
    )
    .optional()
    .default([]),
});

const levelsSchema = z.object({ items: z.array(levelSchema) });

// ---------------------------------------------------------------------------
//  Lecturas
// ---------------------------------------------------------------------------

/**
 * Niveles publicados con su nombre/descripción por idioma, rango, slug, icono,
 * beneficios (texto compuesto) y productos de compra. ISR con el tag
 * `memberships`: guardar un nivel en el panel lo invalida.
 */
export async function getMembershipLevels(locale: string): Promise<MembershipLevel[]> {
  const body = await readJson(
    pathWithQuery(LEVELS_PATH, { locale }),
    "levels",
    {},
    { next: { revalidate: LEVELS_REVALIDATE, tags: ["memberships"] } },
  );
  const parsed = parseRead(levelsSchema, body, "levels", {});

  return parsed.items.map((level) => ({
    id: level.id,
    name: level.name,
    description: level.description,
    slug: level.slug,
    slugs: level.slugs,
    rank: level.rank,
    icon: level.icon,
    color: level.color,
    purchasable: level.purchasable,
    min_account_age_days: level.min_account_age_days,
    benefits: level.benefits.map((benefit): MembershipBenefit => {
      return {
        key: benefit.key,
        data: benefit as MembershipBenefitData,
        text: describeBenefit({ key: benefit.key, data: benefit }, locale),
      };
    }),
    products: level.products.map((product) => ({
      id: product.id,
      price: product.price,
      days: product.days,
      duration: formatMembershipDuration(product.days, locale),
    })),
  }));
}

const queueSchema = z.looseObject({
  level_id: z.number().int().optional().default(0),
  rank: z.number().int().optional().default(0),
  seconds: z.number().optional().default(0),
  days: z.number().optional().default(0),
  starts_at: z.string().nullable().optional().default(null),
  ends_at: z.string().nullable().optional().default(null),
  undated: z.boolean().optional().default(false),
  infinite: z.boolean().optional().default(false),
});

const purchaseSchema = z.looseObject({
  level_id: z.number().int().optional().default(0),
  ok: z.boolean().optional().default(false),
  reason: z.string().optional().default(""),
  days_left: z.number().int().optional().default(0),
  min_account_age_days: z.number().int().optional().default(0),
  account_age_days: z.number().int().nullable().optional().default(null),
  current_level_id: z.number().int().optional().default(0),
});

const stateSchema = z.object({
  status: z.enum(["none", "active", "frozen"]),
  level: z
    .looseObject({ id: z.number().int(), rank: z.number().int() })
    .nullable()
    .optional()
    .default(null),
  ends_at: z.string().nullable().optional().default(null),
  member_since: z.string().nullable().optional().default(null),
  member_number: z.string().optional().default(""),
  queue: z.array(queueSchema).optional().default([]),
  benefits: z.array(activeBenefitSchema).optional().default([]),
  gifts: z
    .looseObject({
      max: z.number().int().optional().default(0),
      used: z.number().int().optional().default(0),
      remaining: z.number().int().optional().default(0),
    })
    .optional()
    .default({ max: 0, used: 0, remaining: 0 }),
  purchasable: z.array(purchaseSchema).optional().default([]),
});

export interface MembershipStateOptions {
  /** Idioma de los textos compuestos (beneficios y duraciones). */
  locale?: string;
  /** Prefijo público del número de socio (`config.memberships`). */
  memberNumberPrefix?: string;
}

/**
 * Estado del socio de la sesión: nivel efectivo, cola programada, beneficios
 * con su estado, regalos restantes y elegibilidad de compra por nivel.
 */
export async function getMembershipState(
  userId: number,
  options: MembershipStateOptions = {},
): Promise<MembershipState> {
  assertUser(userId);
  const { locale = routing.defaultLocale, memberNumberPrefix = "" } = options;
  const body = await readJson(pathWithQuery(ME_PATH, { user_id: userId }), "me", {
    userId,
  });
  const parsed = parseRead(stateSchema, body, "me", { userId });

  return {
    status: parsed.status,
    level: parsed.level,
    ends_at: parsed.ends_at,
    member_since: parsed.member_since,
    member_number: formatMemberNumber(parsed.member_number, memberNumberPrefix),
    queue: parsed.queue.map((entry) => ({
      level_id: entry.level_id,
      rank: entry.rank,
      seconds: entry.seconds,
      days: entry.days,
      starts_at: entry.starts_at,
      ends_at: entry.ends_at,
      undated: entry.undated,
      infinite: entry.infinite,
      duration: formatMembershipDuration(entry.days, locale, {
        infinite: entry.infinite,
        undated: entry.undated,
      }),
    })),
    benefits: parsed.benefits.map((benefit): MembershipBenefit => {
      return {
        key: benefit.key,
        data: benefit.public,
        state: benefit.state,
        text: describeBenefit({ key: benefit.key, data: benefit.public }, locale),
      };
    }),
    gifts: {
      max: parsed.gifts.max,
      used: parsed.gifts.used,
      remaining: parsed.gifts.remaining,
    },
    purchasable: parsed.purchasable.map((entry) => ({
      level_id: entry.level_id,
      ok: entry.ok,
      reason: entry.reason,
      days_left: entry.days_left,
      min_account_age_days: entry.min_account_age_days,
      account_age_days: entry.account_age_days,
      current_level_id: entry.current_level_id,
    })),
  };
}

const historyEntrySchema = z.looseObject({
  id: z.number().int(),
  type: z.string(),
  level_from: z.number().int().optional().default(0),
  level_to: z.number().int().optional().default(0),
  data: dataSchema.optional().default({}),
  created_at: z.string().nullable().optional().default(null),
});

const historySchema = z.object({
  items: z.array(historyEntrySchema),
  total: z.number().int().nonnegative().optional().default(0),
  pages: z.number().int().nonnegative().optional().default(0),
});

export interface MembershipPageOptions {
  page?: number;
  perPage?: number;
}

/** Historial del propio usuario, del más nuevo al más antiguo. */
export async function getMembershipHistory(
  userId: number,
  { page, perPage }: MembershipPageOptions = {},
): Promise<MembershipHistoryPage> {
  assertUser(userId);
  const body = await readJson(
    pathWithQuery(HISTORY_PATH, {
      user_id: userId,
      page: pageNumber(page, 1),
      per_page: perPageNumber(perPage),
    }),
    "history",
    { userId },
  );
  const parsed = parseRead(historySchema, body, "history", { userId });

  return {
    items: parsed.items.map((item) => ({
      id: item.id,
      type: item.type,
      level_from: item.level_from,
      level_to: item.level_to,
      data: item.data,
      created_at: item.created_at,
    })),
    total: parsed.total,
    pages: parsed.pages,
  };
}

const priceSchema = z.looseObject({
  id: z.number().int().positive(),
  regular: decimalSchema,
  price: decimalSchema,
  member_price: decimalSchema,
  percent: z.number().int().optional().default(0),
});

const pricesSchema = z.object({ items: z.array(priceSchema) });

/**
 * «Precio para ti» de una lista acotada de productos, con la MISMA función que
 * usa el carrito (`Pricing::priceFor`). Más de 50 ids son una petición
 * inválida; sin ids no se consulta WordPress.
 */
export async function getMembershipPrices(
  userId: number,
  ids: readonly number[],
): Promise<MembershipPrice[]> {
  assertUser(userId);
  const normalized = normalizeIds(ids);
  if (normalized.length > MAX_PRICE_IDS) {
    throw new MembershipsReadError("invalid_request");
  }
  if (normalized.length === 0) return [];

  const body = await readJson(
    pathWithQuery(PRICES_PATH, { user_id: userId, ids: normalized.join(",") }),
    "prices",
    { userId, count: normalized.length },
  );
  const parsed = parseRead(pricesSchema, body, "prices", { userId });

  return parsed.items.map((item) => ({
    id: item.id,
    regular: item.regular,
    price: item.price,
    member_price: item.member_price,
    percent: item.percent,
  }));
}

const storeProductSchema = z.looseObject({
  id: z.number().int().positive(),
  prices: z.looseObject({
    price: z.string().optional().default(""),
    regular_price: z.string().optional().default(""),
    currency_minor_unit: z.union([z.number(), z.string()]).optional().default(2),
  }),
});

function minorUnitOf(value: number | string): number {
  const parsed = typeof value === "number" ? value : Number(value);
  return Number.isInteger(parsed) ? Math.min(6, Math.max(0, parsed)) : 2;
}

/**
 * Precios públicos (sin sesión) de una lista de productos, leídos del catálogo
 * de la Store API. No hay «precio para ti» que calcular: el socio no está
 * identificado, así que `member_price` coincide con `price` y `percent` es 0.
 */
export async function getPublicMembershipPrices(
  ids: readonly number[],
): Promise<MembershipPrice[]> {
  const normalized = normalizeIds(ids);
  if (normalized.length > MAX_PRICE_IDS) {
    throw new MembershipsReadError("invalid_request");
  }
  if (normalized.length === 0) return [];

  let payload: unknown;
  try {
    const result = await storeFetch<unknown>(
      `/products?include=${encodeURIComponent(normalized.join(","))}&per_page=${normalized.length}`,
    );
    payload = result.data;
  } catch (error) {
    logReadFailure("memberships.public_prices_failed", error, {
      level: "error",
      message: "No se pudo leer el catálogo para los precios públicos",
      context: { count: normalized.length },
    });
    throw new MembershipsReadError("unavailable");
  }

  const parsed = parseRead(z.array(storeProductSchema), payload, "public_prices", {
    count: normalized.length,
  });
  const byId = new Map(parsed.map((product) => [product.id, product]));

  return normalized.flatMap((id) => {
    const product = byId.get(id);
    if (!product) return [];
    const minorUnit = minorUnitOf(product.prices.currency_minor_unit);
    const price = fromMinorUnits(product.prices.price, minorUnit);
    return [
      {
        id,
        regular: fromMinorUnits(
          product.prices.regular_price || product.prices.price,
          minorUnit,
        ),
        price,
        member_price: price,
        percent: 0,
      },
    ];
  });
}

const productImageSchema = z.looseObject({
  id: z.number().int().optional().default(0),
  src: z.string().optional().default(""),
  alt: z.string().optional().default(""),
});

const productCategorySchema = z.looseObject({
  id: z.number().int().optional().default(0),
  name: z.string().optional().default(""),
  slug: z.string().optional().default(""),
});

const productAttributeSchema = z.looseObject({
  id: z.number().int().optional().default(0),
  name: z.string().optional().default(""),
  options: z.array(z.string()).optional().default([]),
  visible: z.boolean().optional().default(true),
  variation: z.boolean().optional().default(false),
});

const productVariationSchema = z.looseObject({
  id: z.number().int(),
  price: decimalSchema.optional().default("0"),
  regular_price: decimalSchema.optional().default("0"),
  sale_price: decimalSchema.nullable().optional().default(null),
  on_sale: z.boolean().optional().default(false),
  stock_status: z.string().optional().default("instock"),
  manage_stock: z.boolean().optional().default(false),
  stock_quantity: z.number().int().nullable().optional().default(null),
  backorders_allowed: z.boolean().optional().default(false),
  attributes: z
    .array(
      z.looseObject({
        name: z.string().optional().default(""),
        option: z.string().optional().default(""),
      }),
    )
    .optional()
    .default([]),
});

const productSchema = z.looseObject({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  type: z.string().optional().default("simple"),
  status: z.string().optional().default("publish"),
  purchasable: z.boolean().optional().default(false),
  on_sale: z.boolean().optional().default(false),
  price: decimalSchema.optional().default("0"),
  regular_price: decimalSchema.optional().default("0"),
  sale_price: decimalSchema.nullable().optional().default(null),
  stock_status: z.string().optional().default("instock"),
  manage_stock: z.boolean().optional().default(false),
  stock_quantity: z.number().int().nullable().optional().default(null),
  backorders_allowed: z.boolean().optional().default(false),
  categories: z.array(productCategorySchema).optional().default([]),
  images: z.array(productImageSchema).optional().default([]),
  locked: z.boolean().optional().default(false),
  hwe_membership: z
    .looseObject({
      required_level: z.unknown().optional(),
      opens_at: z.unknown().optional(),
    })
    .nullable()
    .optional(),
  short_description: z.string().optional(),
  description: z.string().optional(),
  attributes: z.array(productAttributeSchema).optional(),
  variations: z.array(productVariationSchema).optional(),
  hweFields: z
    .array(
      z.looseObject({
        key: z.string(),
        value: z.string().optional().default(""),
      }),
    )
    .optional(),
});

export interface MembershipProductOptions {
  slug?: string;
  id?: number;
  locale?: string;
}

/**
 * Ficha del módulo por slug o id: completa si el socio cumple el requisito y
 * ficha de escaparate si no (lo decide WordPress con `Teaser`). Sin caché
 * compartida: la misma URL cambia según quién la pida.
 */
export async function getMembershipProduct(
  userId: number,
  { slug, id, locale }: MembershipProductOptions,
): Promise<MembershipProduct> {
  assertUser(userId);

  const hasId = id !== undefined;
  const cleanSlug = typeof slug === "string" ? slug.trim() : "";

  if (hasId) {
    if (!Number.isInteger(id) || (id ?? 0) <= 0) {
      throw new MembershipsReadError("invalid_request");
    }
  } else if (cleanSlug === "") {
    throw new MembershipsReadError("invalid_request");
  }

  const params: Record<string, string | number> = { user_id: userId };
  if (hasId) {
    params.id = id as number;
  } else {
    params.slug = cleanSlug;
  }
  if (locale) params.locale = locale;

  const body = await readJson(pathWithQuery(PRODUCT_PATH, params), "product", {
    userId,
    productId: id ?? null,
  });
  const parsed = parseRead(productSchema, body, "product", {
    userId,
    productId: id ?? null,
  });

  const requirement = normalizeMembershipRequirement(parsed.hwe_membership) ?? null;

  return {
    id: parsed.id,
    name: parsed.name,
    slug: parsed.slug,
    type: parsed.type,
    status: parsed.status,
    purchasable: parsed.purchasable,
    on_sale: parsed.on_sale,
    price: parsed.price,
    regular_price: parsed.regular_price,
    sale_price: parsed.sale_price,
    stock_status: parsed.stock_status,
    manage_stock: parsed.manage_stock,
    stock_quantity: parsed.stock_quantity,
    backorders_allowed: parsed.backorders_allowed,
    categories: parsed.categories,
    images: parsed.images,
    locked: parsed.locked,
    hwe_membership: {
      required_level: requirement?.requiredLevel ?? null,
      opens_at: requirement?.opensAt ?? null,
    },
    ...(parsed.short_description !== undefined
      ? { short_description: parsed.short_description }
      : {}),
    ...(parsed.description !== undefined ? { description: parsed.description } : {}),
    ...(parsed.attributes !== undefined ? { attributes: parsed.attributes } : {}),
    ...(parsed.variations !== undefined ? { variations: parsed.variations } : {}),
    ...(parsed.hweFields !== undefined ? { hweFields: parsed.hweFields } : {}),
  };
}

// ---------------------------------------------------------------------------
//  Carné
// ---------------------------------------------------------------------------

const cardSchema = z.looseObject({
  token: z.string().min(1),
  expires_at: z.string().min(1),
  verify_path: z.string().optional().default(""),
});

/** Emite el carné del socio. Sin nivel efectivo, WordPress responde 403 tipado. */
export async function getMembershipCard(userId: number): Promise<MembershipCardToken> {
  assertUser(userId);
  const body = await readJson(pathWithQuery(CARD_PATH, { user_id: userId }), "card", {
    userId,
  });
  return parseRead(cardSchema, body, "card", { userId });
}

const verificationSchema = z.union([
  z.object({ valid: z.literal(false) }),
  z.object({
    valid: z.literal(true),
    level: z.object({ name: z.string(), slug: z.string() }),
    member_since: z.string().nullable().optional().default(null),
    member_number: z.string().optional().default(""),
    member_name: z.string().optional().default(""),
    document: z
      .looseObject({ type: z.string().optional().default(""), number: z.string() })
      .optional(),
  }),
]);

/**
 * Verificación pública del carné. Un token con formato imposible, inexistente,
 * caducado o revocado devuelve EXACTAMENTE `{ valid: false }`: no se distingue
 * el motivo (spec §9).
 */
export async function verifyMembershipCard(
  token: string,
): Promise<MembershipVerification> {
  const clean = token.trim();
  if (!CARD_TOKEN_PATTERN.test(clean)) return { valid: false };

  let body: unknown;
  try {
    body = await readJson(pathWithQuery(VERIFY_PATH, { token: clean }), "verify", {});
  } catch (error) {
    // Un 400 de WordPress (token ausente) tiene el mismo significado público
    // que un token no válido: ni se distingue ni se convierte en un 5xx.
    if (error instanceof MembershipsReadError && error.code === "invalid_request") {
      return { valid: false };
    }
    throw error;
  }

  const parsed = parseRead(verificationSchema, body, "verify", {});
  if (parsed.valid === false) return { valid: false };

  return {
    valid: true,
    level: parsed.level,
    member_since: parsed.member_since,
    member_number: parsed.member_number,
    member_name: parsed.member_name,
    ...(parsed.document ? { document: parsed.document } : {}),
  };
}
