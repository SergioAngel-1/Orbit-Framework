import { beforeEach, describe, expect, it, vi } from "vitest";
import type { WooOrder } from "@/types/woocommerce";

// ============================================================================
//  Transiciones del pedido que dispara la pasarela: SOLO un webhook verificado lo
//  marca pagado; un evento negativo tardío nunca revierte un pedido ya pagado.
// ============================================================================

const wcFetch = vi.fn();
vi.mock("@/lib/woocommerce/client", () => ({
  wcFetch: (...args: unknown[]) => wcFetch(...args),
}));

import {
  getOrder,
  markOrderAwaitingPaymentLink,
  markOrderCancelled,
  markOrderPaid,
} from "./orders";

const order = (status: string): WooOrder => ({ id: 9, status }) as WooOrder;

/** Las escrituras (PUT) que se hicieron contra wc/v3. */
const puts = () =>
  wcFetch.mock.calls.filter(
    ([, init]) => (init as { method?: string })?.method === "PUT",
  );

beforeEach(() => {
  wcFetch.mockReset();
});

describe("getOrder", () => {
  it("lee el pedido por su referencia", async () => {
    wcFetch.mockResolvedValue(order("pending"));

    expect(await getOrder("9")).toMatchObject({ id: 9 });
    expect(wcFetch).toHaveBeenCalledWith("/orders/9");
  });
});

describe("markOrderPaid", () => {
  it("un pedido pendiente pasa a processing con set_paid y la transacción de la pasarela", async () => {
    wcFetch
      .mockResolvedValueOnce(order("pending"))
      .mockResolvedValueOnce(order("processing"));

    await markOrderPaid(9, "tx-123");

    expect(puts()).toEqual([
      [
        "/orders/9",
        {
          method: "PUT",
          body: { status: "processing", set_paid: true, transaction_id: "tx-123" },
        },
      ],
    ]);
  });

  it("sin id de transacción no manda el campo", async () => {
    wcFetch
      .mockResolvedValueOnce(order("pending"))
      .mockResolvedValueOnce(order("processing"));

    await markOrderPaid(9);

    expect((puts()[0]![1] as { body: object }).body).toEqual({
      status: "processing",
      set_paid: true,
    });
  });

  it("es idempotente: un pedido ya pagado no se vuelve a escribir", async () => {
    wcFetch.mockResolvedValueOnce(order("completed"));

    await markOrderPaid(9, "tx-123");

    expect(puts()).toHaveLength(0);
  });
});

describe("markOrderCancelled", () => {
  it("cancela un pedido sin pagar", async () => {
    wcFetch
      .mockResolvedValueOnce(order("pending"))
      .mockResolvedValueOnce(order("cancelled"));

    await markOrderCancelled(9);

    expect(puts()[0]).toEqual([
      "/orders/9",
      { method: "PUT", body: { status: "cancelled" } },
    ]);
  });

  it("admite el estado «failed» para un pago fallido", async () => {
    wcFetch
      .mockResolvedValueOnce(order("pending"))
      .mockResolvedValueOnce(order("failed"));

    await markOrderCancelled(9, "failed");

    expect((puts()[0]![1] as { body: object }).body).toEqual({ status: "failed" });
  });

  it("un evento negativo tardío NO revierte un pedido ya pagado", async () => {
    for (const status of ["processing", "completed", "refunded"]) {
      wcFetch.mockReset();
      wcFetch.mockResolvedValueOnce(order(status));

      const result = await markOrderCancelled(9);

      expect(result.status).toBe(status);
      expect(puts()).toHaveLength(0);
    }
  });
});

describe("markOrderAwaitingPaymentLink", () => {
  it("deja el pedido en espera (on-hold): recibido, falta confirmar el pago", async () => {
    wcFetch.mockResolvedValue(order("on-hold"));

    await markOrderAwaitingPaymentLink(9);

    expect(wcFetch).toHaveBeenCalledWith("/orders/9", {
      method: "PUT",
      body: { status: "on-hold" },
    });
  });
});
