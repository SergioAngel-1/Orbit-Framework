import { beforeEach, describe, expect, it, vi } from "vitest";

const wcFetch = vi.fn();
vi.mock("@/lib/woocommerce/client", () => ({
  wcFetch: (...args: unknown[]) => wcFetch(...args),
}));
vi.mock("@/lib/auth/session", () => ({
  requireSession: async () => ({ userId: "7" }),
}));

import { findProductPurchase, getCustomerOrders } from "./data";

describe("findProductPurchase", () => {
  beforeEach(() => wcFetch.mockReset());

  it("ordena por fecha explícitamente y pide solo lo que mira", async () => {
    wcFetch.mockResolvedValue([]);

    await findProductPurchase(42);

    expect(wcFetch).toHaveBeenCalledWith(
      "/orders",
      expect.objectContaining({
        query: expect.objectContaining({
          customer: 7,
          orderby: "date",
          order: "desc",
          status: "processing,completed",
          _fields: "id,number,status,line_items",
        }),
      }),
    );
  });

  it("sigue a la página siguiente hasta encontrar un completado", async () => {
    const line = { id: 1, name: "x", product_id: 42, quantity: 1 };
    const processing = {
      id: 200,
      number: "200",
      status: "processing",
      line_items: [line],
    };
    const filler = { id: 1, number: "1", status: "completed", line_items: [] };
    wcFetch
      .mockResolvedValueOnce([processing, ...Array(99).fill(filler)])
      .mockResolvedValueOnce([
        { id: 50, number: "50", status: "completed", line_items: [line] },
      ]);

    const found = await findProductPurchase(42);

    expect(wcFetch).toHaveBeenCalledTimes(2);
    expect(found.processing?.id).toBe(200);
    expect(found.completed?.id).toBe(50);
  });

  it("solo pide los pedidos del cliente de la sesión", async () => {
    wcFetch.mockResolvedValue([]);

    await findProductPurchase(42);

    expect(wcFetch.mock.calls[0]![1].query.customer).toBe(7);
  });
});

describe("getCustomerOrders", () => {
  beforeEach(() => wcFetch.mockReset());

  it("pagina con valores por defecto y los más recientes primero", async () => {
    wcFetch.mockResolvedValue([]);

    await getCustomerOrders();

    expect(wcFetch).toHaveBeenCalledWith(
      "/orders",
      expect.objectContaining({
        query: expect.objectContaining({
          customer: 7,
          page: 1,
          per_page: 20,
          orderby: "date",
          order: "desc",
        }),
      }),
    );
  });

  it("permite elegir la página y el tamaño", async () => {
    wcFetch.mockResolvedValue([]);

    await getCustomerOrders(2, 50);

    expect(wcFetch.mock.calls[0]![1].query).toMatchObject({ page: 2, per_page: 50 });
  });
});
