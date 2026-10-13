import { beforeEach, describe, expect, it, vi } from "vitest";
import { revalidateTag } from "next/cache";
import { getSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { findProductPurchase } from "@/lib/account/data";
import {
  readCachedEligibility,
  writeCachedEligibility,
  invalidateCachedEligibility,
} from "@/lib/account/review-eligibility";
import { rateLimit } from "@/lib/security/rate-limit";
import { GET, POST } from "./route";

// ============================================================================
//  /api/store/reviews/[productId].
//
//  Identidad: el autor SIEMPRE sale del perfil WooCommerce de la sesión, nunca
//  del cuerpo (antes cualquier petición directa podía publicar una reseña
//  «verificada» atribuida a un tercero).
//
//  Compra: solo reseña quien tiene un pedido COMPLETADO con el producto. Uno
//  «Procesando» responde 409 (`not_received`): primero «Recibí mi producto».
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
// Flags de la tienda (`config.ecommerce`): por defecto, todo encendido.
const flags = vi.hoisted(() => ({
  reviews_enabled: true,
  wishlist_enabled: true,
  search_enabled: true,
  coupons_enabled: true,
}));
vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => ({ ecommerce: flags })),
}));

vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));
vi.mock("@/lib/account/data", () => ({ findProductPurchase: vi.fn() }));
vi.mock("@/lib/account/review-eligibility", () => ({
  readCachedEligibility: vi.fn(),
  writeCachedEligibility: vi.fn(),
  invalidateCachedEligibility: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
// Se conserva `WooCommerceError`: `handleApiError` hace `instanceof` contra él.
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const PROFILE = { first_name: "Ana", last_name: "Pérez", email: "ana@example.com" };
const VALID = { rating: 5, content: "Muy buen producto, lo recomiendo." };
const COMPLETED = { id: 40, number: "40", productIds: [9, 12, 13] };

const request = (body: unknown) =>
  new Request("https://tienda.example/api/store/reviews/9", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
const post = (body: unknown, productId = "9") =>
  POST(request(body), { params: Promise.resolve({ productId }) });
const get = (productId = "9") =>
  GET(new Request("https://tienda.example/api/store/reviews/9"), {
    params: Promise.resolve({ productId }),
  });

/** Reseñas creadas en WooCommerce (POST a /products/reviews). */
const reviewPosts = () =>
  vi
    .mocked(wcFetch)
    .mock.calls.filter(
      ([path, opts]) => path === "/products/reviews" && opts?.method === "POST",
    );

/** Reseñas del cliente que ya existen (lectura de /products/reviews). */
function alreadyReviewed(...productIds: number[]) {
  vi.mocked(wcFetch).mockImplementation((async (
    path: string,
    opts?: { method?: string },
  ) => {
    if (path.startsWith("/customers/")) return PROFILE;
    if (path === "/products/reviews" && opts?.method === "POST") {
      return { id: 55, status: "approved" };
    }
    if (path === "/products/reviews/batch") return { create: [{ id: 70 }, { id: 71 }] };
    if (path === "/products/reviews") {
      return productIds.map((id) => ({ id: 1, product_id: id }));
    }
    return {};
  }) as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  Object.assign(flags, {
    reviews_enabled: true,
    wishlist_enabled: true,
    search_enabled: true,
    coupons_enabled: true,
  });
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" } as never);
  vi.mocked(findProductPurchase).mockResolvedValue({
    completed: COMPLETED,
    processing: null,
  });
  vi.mocked(readCachedEligibility).mockResolvedValue(null);
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 10,
    retryAfter: 0,
    limit: 60,
  });
  alreadyReviewed();
});

describe("POST: la identidad del autor sale de la cuenta", () => {
  it("ignora el name y el email del cuerpo y usa el perfil del usuario autenticado", async () => {
    const res = await post({
      ...VALID,
      name: "Víctima Suplantada",
      email: "victima@example.com",
    });

    expect(res.status).toBe(201);
    expect(reviewPosts()).toHaveLength(1);
    expect(reviewPosts()[0]![1]).toMatchObject({
      method: "POST",
      body: {
        product_id: 9,
        review: VALID.content,
        reviewer: "Ana Pérez",
        reviewer_email: "ana@example.com",
        rating: 5,
      },
    });
  });

  it("lee el perfil del usuario de la SESIÓN, no uno indicado por el cliente", async () => {
    await post({ ...VALID, userId: 1, customer_id: 1 });

    expect(vi.mocked(wcFetch)).toHaveBeenCalledWith("/customers/7");
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalledWith("/customers/1");
  });

  it("sin nombre en el perfil publica con un texto neutro, nunca con el del cuerpo", async () => {
    vi.mocked(wcFetch).mockImplementation((async (
      path: string,
      opts?: { method?: string },
    ) => {
      if (path.startsWith("/customers/")) {
        return { first_name: "", last_name: "", email: "ana@example.com" };
      }
      return opts?.method === "POST" ? { id: 55, status: "approved" } : [];
    }) as never);

    await post({ ...VALID, name: "Víctima Suplantada" });

    expect(reviewPosts()[0]![1]).toMatchObject({
      body: { reviewer: "Cliente verificado", reviewer_email: "ana@example.com" },
    });
  });

  it("si no se puede leer el perfil, no publica nada", async () => {
    vi.mocked(wcFetch).mockRejectedValue(new Error("woocommerce caído"));

    const res = await post(VALID);

    expect(res.status).toBeGreaterThanOrEqual(500);
    expect(reviewPosts()).toHaveLength(0);
  });
});

describe("POST: compra verificada", () => {
  it("con un pedido completado publica la reseña aprobada y verificada, y refresca la ficha", async () => {
    const res = await post(VALID);

    expect(res.status).toBe(201);
    expect(reviewPosts()[0]![1]).toMatchObject({
      body: { status: "approved", verified: true },
    });
    expect(revalidateTag).toHaveBeenCalledWith("products", "max");
    expect(invalidateCachedEligibility).toHaveBeenCalled();
  });

  it("sin ninguna compra del producto, 403 y no publica", async () => {
    vi.mocked(findProductPurchase).mockResolvedValue({
      completed: null,
      processing: null,
    });

    const res = await post(VALID);

    expect(res.status).toBe(403);
    expect(reviewPosts()).toHaveLength(0);
  });

  it("con el pedido todavía «Procesando», 409 not_received: primero «Recibí mi producto»", async () => {
    vi.mocked(findProductPurchase).mockResolvedValue({
      completed: null,
      processing: { id: 41, number: "41", productIds: [9] },
    });

    const res = await post(VALID);

    expect(res.status).toBe(409);
    expect((await res.json()).reason).toBe("not_received");
    expect(reviewPosts()).toHaveLength(0);
  });

  it("una segunda reseña del mismo producto se rechaza (409), aunque la UI no la ofrezca", async () => {
    alreadyReviewed(9);

    const res = await post(VALID);

    expect(res.status).toBe(409);
    expect((await res.json()).reason).toBe("already_reviewed");
    expect(reviewPosts()).toHaveLength(0);
  });

  it("con `sameForOrder` publica la misma reseña en los demás productos del pedido", async () => {
    const res = await post({ ...VALID, sameForOrder: true });

    expect((await res.json()).othersReviewed).toBe(2);
    const batch = vi
      .mocked(wcFetch)
      .mock.calls.find(([path]) => path === "/products/reviews/batch");
    expect(
      (batch![1] as { body: { create: { product_id: number }[] } }).body.create.map(
        (r) => r.product_id,
      ),
    ).toEqual([12, 13]);
  });
});

describe("POST: validación y acceso", () => {
  it.each([
    ["valoración fuera de rango", { ...VALID, rating: 9 }],
    ["texto demasiado corto", { ...VALID, content: "corto" }],
    ["cuerpo vacío", {}],
  ])("%s: 422 con el detalle y sin tocar WooCommerce", async (_caso, body) => {
    const res = await post(body);
    const json = await res.json();

    expect(res.status).toBe(422);
    expect(json.details).toBeDefined();
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un cuerpo `null` es 422 y no revienta", async () => {
    expect((await post(null)).status).toBe(422);
  });

  it("sin sesión responde 401 y no toca WooCommerce", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await post(VALID);

    expect(res.status).toBe(401);
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });

  it("un id de producto inválido responde 400", async () => {
    const res = await post(VALID, "abc");

    expect(res.status).toBe(400);
    expect(vi.mocked(wcFetch)).not.toHaveBeenCalled();
  });
});

describe("GET: elegibilidad", () => {
  it("sin sesión no es 401: dice «auth» para que la ficha ofrezca iniciar sesión", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await get();

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ canReview: false, reason: "auth" });
  });

  it("sin compra: not_purchased", async () => {
    vi.mocked(findProductPurchase).mockResolvedValue({
      completed: null,
      processing: null,
    });

    expect(await (await get()).json()).toEqual({
      canReview: false,
      reason: "not_purchased",
    });
  });

  it("solo un pedido en preparación: not_received con ese pedido", async () => {
    vi.mocked(findProductPurchase).mockResolvedValue({
      completed: null,
      processing: { id: 41, number: "41", productIds: [9] },
    });

    expect(await (await get()).json()).toEqual({
      canReview: false,
      reason: "not_received",
      order: { id: 41, number: "41" },
    });
  });

  it("pedido completado y sin reseña: puede reseñar, con el nombre y los otros productos pendientes", async () => {
    alreadyReviewed(12);

    expect(await (await get()).json()).toEqual({
      canReview: true,
      reason: null,
      reviewerName: "Ana Pérez",
      order: { id: 40, number: "40", otherProducts: 1 },
    });
  });

  it("ya reseñado: already_reviewed (el formulario no reaparece al recargar)", async () => {
    alreadyReviewed(9);

    expect((await (await get()).json()).reason).toBe("already_reviewed");
  });

  it("una entrada cacheada se devuelve sin tocar WooCommerce", async () => {
    vi.mocked(readCachedEligibility).mockResolvedValue({
      canReview: false,
      reason: "not_purchased",
    });

    await get();

    expect(findProductPurchase).not.toHaveBeenCalled();
    expect(writeCachedEligibility).not.toHaveBeenCalled();
  });

  it("el cálculo (no el acierto de caché) tiene rate-limit: 429 antes de tocar WooCommerce", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 30,
      limit: 60,
    });

    const res = await get();

    expect(res.status).toBe(429);
    expect(findProductPurchase).not.toHaveBeenCalled();
  });
});

describe("con las reseñas apagadas en el Control Center", () => {
  const params = { params: Promise.resolve({ productId: "5" }) };

  it("GET responde 404 y no consulta nada", async () => {
    flags.reviews_enabled = false;

    const res = await GET(
      new Request("https://tienda.example/api/store/reviews/5"),
      params,
    );

    expect(res.status).toBe(404);
    expect(getSession).not.toHaveBeenCalled();
    expect(findProductPurchase).not.toHaveBeenCalled();
  });

  it("POST responde 404 y no publica", async () => {
    flags.reviews_enabled = false;

    const res = await POST(
      new Request("https://tienda.example/api/store/reviews/5", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(VALID),
      }),
      params,
    );

    expect(res.status).toBe(404);
    expect(wcFetch).not.toHaveBeenCalled();
  });
});
