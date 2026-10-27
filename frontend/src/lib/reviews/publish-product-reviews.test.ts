import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { wcFetch } from "@/lib/woocommerce/client";
import { publishProductReviews } from "./publish-product-reviews";

// ============================================================================
//  publishProductReviews — defensa en profundidad de `reviews_enabled`.
//  Comparten este sink «Calificar pedido» y la ficha de producto; con el flag
//  apagado NINGÚN llamador puede publicar reseñas de producto.
// ============================================================================

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));
vi.mock("@/lib/account/review-eligibility", () => ({
  invalidateCachedEligibility: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const customer = {
  id: 7,
  email: "ana@example.com",
  first_name: "Ana",
  last_name: "Ruiz",
} as never;

const input = {
  userId: 7,
  customer,
  productIds: [3, 4],
  rating: 5,
  content: "Todo llegó perfecto y a tiempo.",
};

const flag = (enabled: boolean) =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    ecommerce: { reviews_enabled: enabled },
  } as never);

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(wcFetch).mockImplementation((async (path: string) =>
    path === "/products/reviews" ? [] : { create: [{ id: 1 }, { id: 2 }] }) as never);
});

describe("publishProductReviews y ecommerce.reviews_enabled", () => {
  it("con el flag apagado no consulta ni escribe reseñas de producto", async () => {
    flag(false);

    expect(await publishProductReviews(input)).toBe(0);
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("con el flag encendido publica el lote como antes", async () => {
    flag(true);

    expect(await publishProductReviews(input)).toBe(2);
    expect(wcFetch).toHaveBeenCalledWith(
      "/products/reviews/batch",
      expect.objectContaining({ method: "POST" }),
    );
  });
});
