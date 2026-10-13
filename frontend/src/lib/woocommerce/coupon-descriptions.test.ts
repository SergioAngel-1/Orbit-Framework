import { beforeEach, describe, expect, it, vi } from "vitest";

const wcFetch = vi.fn();
vi.mock("./client", () => ({ wcFetch: (...args: unknown[]) => wcFetch(...args) }));
vi.mock("@/lib/observability/logger", () => ({ logger: { warn: vi.fn() } }));

import { withCouponDescriptions } from "./coupon-descriptions";
import type { StoreCart } from "@/types/woocommerce";

const cart = (codes: string[]) =>
  ({
    items: [],
    coupons: codes.map((code) => ({
      code,
      discount_type: "percent",
      totals: { currency_code: "COP", currency_minor_unit: 0, total_discount: "100" },
    })),
  }) as unknown as StoreCart;

describe("withCouponDescriptions", () => {
  beforeEach(() => wcFetch.mockReset());

  it("añade la descripción de cada cupón aplicado", async () => {
    wcFetch.mockResolvedValueOnce([
      { code: "bienvenida10", description: " 10% en tu primera compra " },
    ]);
    const out = await withCouponDescriptions(cart(["BIENVENIDA10"]));
    expect(out.coupons[0].description).toBe("10% en tu primera compra");
    expect(wcFetch).toHaveBeenCalledWith("/coupons", {
      query: { code: "bienvenida10" },
    });
  });

  it("marca el cupón que da envío gratis", async () => {
    wcFetch.mockResolvedValueOnce([{ code: "envio", free_shipping: true }]);
    const out = await withCouponDescriptions(cart(["ENVIO"]));
    expect(out.coupons[0].freeShipping).toBe(true);
    expect(out.coupons[0].description).toBeUndefined();
  });

  it("sin cupones no consulta nada", async () => {
    const c = cart([]);
    expect(await withCouponDescriptions(c)).toBe(c);
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("si WooCommerce falla, el carrito llega sin descripción", async () => {
    wcFetch.mockRejectedValueOnce(new Error("caído"));
    const out = await withCouponDescriptions(cart(["otro-codigo"]));
    expect(out.coupons[0].description).toBeUndefined();
  });
});
