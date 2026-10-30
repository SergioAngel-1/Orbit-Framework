import { beforeEach, describe, expect, it, vi } from "vitest";

const wcFetch = vi.fn();
const wcFetchPage = vi.fn();
vi.mock("@/lib/woocommerce/client", () => ({
  wcFetch: (...args: unknown[]) => wcFetch(...args),
  wcFetchPage: (...args: unknown[]) => wcFetchPage(...args),
}));
vi.mock("@/lib/auth/session", () => ({
  requireSession: async () => ({ userId: "7" }),
}));

import { findProductPurchase, getCustomerOrders, getCustomerOrdersPage } from "./data";

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

describe("getCustomerOrdersPage", () => {
  beforeEach(() => {
    wcFetchPage.mockReset();
    wcFetchPage.mockResolvedValue({ items: [{ id: 9 }], total: 57, totalPages: 3 });
  });

  it("devuelve los totales sin pedir filtros que no se usan", async () => {
    await expect(getCustomerOrdersPage()).resolves.toEqual({
      orders: [{ id: 9 }],
      total: 57,
      totalPages: 3,
    });

    const { query } = wcFetchPage.mock.calls[0]![1];
    expect(query).toMatchObject({ customer: 7, page: 1, per_page: 20 });
    expect(query.status).toBeUndefined();
    expect(query.after).toBeUndefined();
    expect(query.before).toBeUndefined();
    expect(query._fields).toBeUndefined();
  });

  it("traslada filtros y `_fields` a WooCommerce", async () => {
    await getCustomerOrdersPage({
      page: 2,
      perPage: 10,
      status: ["processing", "completed"],
      after: "2026-01-01T00:00:00",
      before: "2026-02-01T00:00:00",
      fields: ["id", "status"],
    });

    expect(wcFetchPage.mock.calls[0]![1].query).toMatchObject({
      page: 2,
      per_page: 10,
      status: "processing,completed",
      after: "2026-01-01T00:00:00",
      before: "2026-02-01T00:00:00",
      _fields: "id,status",
    });
  });

  it("acota el tamaño de página al máximo de wc/v3", async () => {
    await getCustomerOrdersPage({ perPage: 5000 });

    expect(wcFetchPage.mock.calls[0]![1].query.per_page).toBe(100);
  });

  it.each([[0], [-3], [Number.NaN], [Number.POSITIVE_INFINITY]])(
    "una página o un tamaño inválidos (%s) caen a los valores por defecto",
    async (value) => {
      await getCustomerOrdersPage({ page: value, perPage: value });

      expect(wcFetchPage.mock.calls[0]![1].query).toMatchObject({
        page: 1,
        per_page: 20,
      });
    },
  );

  it("una página o un tamaño con decimales se truncan a entero", async () => {
    await getCustomerOrdersPage({ page: 2.9, perPage: 10.5 });

    expect(wcFetchPage.mock.calls[0]![1].query).toMatchObject({
      page: 2,
      per_page: 10,
    });
  });

  it("el cliente sale siempre de la sesión", async () => {
    await getCustomerOrdersPage({ customer: 1 } as never);

    expect(wcFetchPage.mock.calls[0]![1].query.customer).toBe(7);
  });
});
