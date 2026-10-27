import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { wcFetch } from "@/lib/woocommerce/client";
import { createSiteReview } from "@/lib/account/site-review-api";
import { POST } from "./route";

// ============================================================================
//  POST /api/store/orders/[id]/review — calificar un pedido (módulo
//  `site-reviews`). Solo el dueño, solo un pedido completado, y con el módulo
//  apagado la ruta no existe (404).
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/account/site-review-api", () => ({ createSiteReview: vi.fn() }));
vi.mock("@/lib/account/review-eligibility", () => ({
  invalidateCachedEligibility: vi.fn(async () => undefined),
}));
vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const VALID = { rating: 5, content: "Todo llegó perfecto y a tiempo." };
const REVIEW = { rating: 5, content: VALID.content, date: "2026-10-13" };

const order = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  customer_id: 7,
  status: "completed",
  line_items: [{ product_id: 3 }, { product_id: 4 }],
  ...overrides,
});

const call = (body: unknown = VALID, id = "10") =>
  POST(
    new Request("https://tienda.example/api/store/orders/10/review", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
    { params: Promise.resolve({ id }) },
  );

function siteConfig({
  siteReviews = true,
  reviewsEnabled = true,
}: { siteReviews?: boolean; reviewsEnabled?: boolean } = {}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "site-reviews": siteReviews },
    ecommerce: { reviews_enabled: reviewsEnabled },
  } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  siteConfig();
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
  vi.mocked(createSiteReview).mockResolvedValue({ ok: true, review: REVIEW });
  vi.mocked(wcFetch).mockImplementation((async (path: string) =>
    path.startsWith("/customers/")
      ? { first_name: "Ana", last_name: "Ruiz", email: "ana@example.com" }
      : path === "/products/reviews"
        ? []
        : path === "/products/reviews/batch"
          ? { create: [{ id: 1 }, { id: 2 }] }
          : order()) as never);
});

describe("módulo site-reviews", () => {
  it("apagado: 404 y no toca nada", async () => {
    siteConfig({ siteReviews: false });

    const res = await call();

    expect(res.status).toBe(404);
    expect(createSiteReview).not.toHaveBeenCalled();
    expect(wcFetch).not.toHaveBeenCalled();
  });
});

describe("flag ecommerce.reviews_enabled", () => {
  it("apagado: guarda la reseña del pedido pero no publica en los productos", async () => {
    siteConfig({ reviewsEnabled: false });

    const res = await call({ ...VALID, products: true });

    expect(res.status).toBe(201);
    expect((await res.json()).productsReviewed).toBe(0);
    // La reseña del sitio (módulo site-reviews) NO la apaga reviews_enabled.
    expect(createSiteReview).toHaveBeenCalled();
    expect(
      vi
        .mocked(wcFetch)
        .mock.calls.some(([path]) => path === "/products/reviews/batch"),
    ).toBe(false);
  });

  it("encendido: sigue publicando en los productos", async () => {
    siteConfig({ reviewsEnabled: true });

    const res = await call({ ...VALID, products: true });

    expect((await res.json()).productsReviewed).toBe(2);
    expect(
      vi
        .mocked(wcFetch)
        .mock.calls.some(([path]) => path === "/products/reviews/batch"),
    ).toBe(true);
  });
});

describe("POST /api/store/orders/[id]/review", () => {
  it("crea la reseña del sitio de un pedido completado del cliente", async () => {
    const res = await call();

    expect(res.status).toBe(201);
    expect(createSiteReview).toHaveBeenCalledWith({
      orderId: 10,
      userId: 7,
      rating: 5,
      content: VALID.content,
    });
    expect((await res.json()).review).toEqual(REVIEW);
  });

  it("con `products` publica también la reseña en los productos del pedido", async () => {
    const res = await call({ ...VALID, products: true });

    expect((await res.json()).productsReviewed).toBe(2);
  });

  it("sin `products` no publica reseñas de producto", async () => {
    const res = await call(VALID);

    expect((await res.json()).productsReviewed).toBe(0);
    expect(
      vi
        .mocked(wcFetch)
        .mock.calls.some(([path]) => path === "/products/reviews/batch"),
    ).toBe(false);
  });

  it("el pedido de otro cliente es un 404 y no se crea nada", async () => {
    vi.mocked(wcFetch).mockResolvedValue(order({ customer_id: 8 }) as never);

    const res = await call();

    expect(res.status).toBe(404);
    expect(createSiteReview).not.toHaveBeenCalled();
  });

  it.each(["processing", "pending", "on-hold", "cancelled"])(
    "un pedido «%s» no se califica (422)",
    async (status) => {
      vi.mocked(wcFetch).mockResolvedValue(order({ status }) as never);

      const res = await call();

      expect(res.status).toBe(422);
      expect(createSiteReview).not.toHaveBeenCalled();
    },
  );

  it("si WordPress dice que ya está calificado, 409", async () => {
    vi.mocked(createSiteReview).mockResolvedValue({
      ok: false,
      reason: "already_reviewed",
    });

    expect((await call()).status).toBe(409);
  });

  it("si la reseña de producto falla, la del sitio ya guardada se confirma igualmente", async () => {
    vi.mocked(wcFetch).mockImplementation((async (path: string) => {
      if (path.startsWith("/customers/")) throw new Error("woo caído");
      return order();
    }) as never);

    const res = await call({ ...VALID, products: true });

    expect(res.status).toBe(201);
    expect((await res.json()).productsReviewed).toBe(0);
  });

  it("sin sesión, 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    expect((await call()).status).toBe(401);
  });

  it("una reseña inválida es un 422 con el detalle", async () => {
    const res = await call({ rating: 7, content: "corto" });

    expect(res.status).toBe(422);
    expect((await res.json()).details).toBeDefined();
  });

  it("un id que no es un entero positivo es un 400", async () => {
    expect((await call(VALID, "abc")).status).toBe(400);
  });
});
