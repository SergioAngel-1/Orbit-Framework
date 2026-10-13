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

function cart(overrides: Record<string, unknown> = {}): StoreCart {
  return {
    items: [{ key: "a", id: 1, quantity: 1, name: "Camiseta" }],
    coupons: [],
    ...overrides,
  } as unknown as StoreCart;
}

beforeEach(() => wcFetch.mockReset());

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
