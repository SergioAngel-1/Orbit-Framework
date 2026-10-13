import { beforeEach, describe, expect, it, vi } from "vitest";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Lo que el BFF completa del carrito antes de mandarlo al navegador y que la
//  Store API no trae: la descripción de los cupones aplicados y los nombres
//  como texto (llegan pasados por `wptexturize`: «Curso &#8211; Taller»).
// ============================================================================

const wcFetch = vi.fn();
vi.mock("./client", () => ({ wcFetch: (...args: unknown[]) => wcFetch(...args) }));
vi.mock("@/lib/observability/logger", () => ({ logger: { warn: vi.fn() } }));

import { completeStoreCart } from "./complete-cart";
import { getSiteConfig } from "@/lib/config";
import { getCartProductRefs } from "@/lib/catalog/coupon-refs";

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/catalog/coupon-refs", () => ({ getCartProductRefs: vi.fn() }));

function cart(overrides: Record<string, unknown> = {}): StoreCart {
  return {
    items: [{ key: "a", id: 1, quantity: 1, name: "Camiseta" }],
    coupons: [],
    ...overrides,
  } as unknown as StoreCart;
}

function config(opts: { module?: boolean; coupons?: boolean }) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "coupon-rules": opts.module ?? false },
    ecommerce: { coupons_enabled: opts.coupons ?? false },
  } as never);
}

beforeEach(() => {
  wcFetch.mockReset();
  vi.mocked(getCartProductRefs).mockReset();
  config({});
});

describe("completeStoreCart", () => {
  it("decodifica las entidades HTML del nombre: React lo pinta como texto", async () => {
    const out = await completeStoreCart(
      cart({
        items: [
          {
            key: "a",
            id: 1,
            quantity: 1,
            name: "Curso &#8211; Taller &amp; m&aacute;s",
          },
        ],
      }),
    );

    expect(out.items[0]!.name).toBe("Curso – Taller & más");
  });

  it("añade la descripción de los cupones aplicados", async () => {
    wcFetch.mockResolvedValueOnce([
      { code: "hola10", description: "10% de bienvenida" },
    ]);

    const out = await completeStoreCart(
      cart({ coupons: [{ code: "HOLA10", discount_type: "percent" }] }),
    );

    expect(out.coupons[0]!.description).toBe("10% de bienvenida");
  });

  it("un carrito vacío no consulta nada", async () => {
    const empty = cart({ items: [] });

    expect(await completeStoreCart(empty)).toBe(empty);
    expect(wcFetch).not.toHaveBeenCalled();
  });
});

describe("completeStoreCart: referencia de catálogo para los cupones", () => {
  const line = { key: "a", id: 3, quantity: 1, name: "Camiseta" };

  it("con el módulo y los cupones activos, cada línea trae su slug y categorías (con ancestros)", async () => {
    config({ module: true, coupons: true });
    vi.mocked(getCartProductRefs).mockResolvedValue(
      new Map([[3, { slug: "camiseta", categorySlugs: ["ropa", "moda"] }]]),
    );

    const out = await completeStoreCart(cart({ items: [line] }));

    expect(out.items[0]!.couponRef).toEqual({
      slug: "camiseta",
      categorySlugs: ["ropa", "moda"],
    });
    expect(getCartProductRefs).toHaveBeenCalledWith([3]);
  });

  it.each([
    ["el módulo apagado", { module: false, coupons: true }],
    ["los cupones desactivados", { module: true, coupons: false }],
  ])("con %s no consulta el catálogo", async (_name, opts) => {
    config(opts);

    const out = await completeStoreCart(cart({ items: [line] }));

    expect(getCartProductRefs).not.toHaveBeenCalled();
    expect(out.items[0]!.couponRef).toBeUndefined();
  });

  it("si el catálogo no responde, la línea llega sin referencia (el carrito no se rompe)", async () => {
    config({ module: true, coupons: true });
    vi.mocked(getCartProductRefs).mockResolvedValue(new Map());

    const out = await completeStoreCart(cart({ items: [line] }));

    expect(out.items[0]!.name).toBe("Camiseta");
    expect(out.items[0]!.couponRef).toBeUndefined();
  });
});
