import { beforeEach, describe, expect, it, vi } from "vitest";
import { requireSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { invalidateCachedEligibility } from "@/lib/account/review-eligibility";
import { POST } from "./route";

// ============================================================================
//  POST /api/store/orders/[id]/received — «Recibí mi pedido».
//  Solo el dueño, y solo desde «Procesando»: un pedido sin pagar no se completa
//  desde aquí. Completarlo habilita calificarlo.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/account/review-eligibility", () => ({
  invalidateCachedEligibility: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const order = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  customer_id: 7,
  status: "processing",
  line_items: [{ product_id: 3 }, { product_id: 4 }],
  ...overrides,
});

const call = (id = "10") =>
  POST(
    new Request("https://tienda.example/api/store/orders/10/received", {
      method: "POST",
    }),
    {
      params: Promise.resolve({ id }),
    },
  );

const puts = () =>
  vi.mocked(wcFetch).mock.calls.filter(([, opts]) => opts?.method === "PUT");

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
  vi.mocked(wcFetch).mockResolvedValue(order() as never);
});

describe("POST /api/store/orders/[id]/received", () => {
  it("pasa un pedido «Procesando» a «Completado» y deja constancia", async () => {
    const res = await call();

    expect(res.status).toBe(200);
    expect(puts()).toHaveLength(1);
    expect(puts()[0]![0]).toBe("/orders/10");
    expect(puts()[0]![1]).toMatchObject({
      body: {
        status: "completed",
        meta_data: [{ key: "_hwe_received_by_customer" }],
      },
    });
  });

  it("la elegibilidad cacheada de los productos del pedido se invalida", async () => {
    await call();

    expect(invalidateCachedEligibility).toHaveBeenCalledWith("7", 3);
    expect(invalidateCachedEligibility).toHaveBeenCalledWith("7", 4);
  });

  it("el pedido de otro cliente es un 404 y no se toca", async () => {
    vi.mocked(wcFetch).mockResolvedValue(order({ customer_id: 8 }) as never);

    const res = await call();

    expect(res.status).toBe(404);
    expect(puts()).toHaveLength(0);
  });

  it("un pedido ya completado responde 200 sin volver a escribir (doble clic)", async () => {
    vi.mocked(wcFetch).mockResolvedValue(order({ status: "completed" }) as never);

    const res = await call();

    expect(res.status).toBe(200);
    expect(puts()).toHaveLength(0);
  });

  it.each(["pending", "on-hold", "cancelled", "failed", "refunded"])(
    "un pedido «%s» no se puede marcar como recibido (422)",
    async (status) => {
      vi.mocked(wcFetch).mockResolvedValue(order({ status }) as never);

      const res = await call();

      expect(res.status).toBe(422);
      expect(puts()).toHaveLength(0);
    },
  );

  it("sin sesión, 401", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    expect((await call()).status).toBe(401);
  });

  it("un id que no es un entero positivo es un 400", async () => {
    expect((await call("abc")).status).toBe(400);
    expect((await call("0")).status).toBe(400);
  });
});
