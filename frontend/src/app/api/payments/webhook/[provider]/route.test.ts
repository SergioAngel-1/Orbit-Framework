import { beforeEach, describe, expect, it, vi } from "vitest";
import crypto from "node:crypto";
import { markEventOnce, releaseEvent } from "@/lib/security/replay";
import { getOrder, markOrderPaid } from "@/lib/payments/orders";
import { sendPurchaseToMeta } from "@/lib/analytics/meta-capi";
import { POST } from "./route";

// ============================================================================
//  POST /api/payments/webhook/[provider].
//
//  Regresión crítica: la pasarela de pruebas `noop` firma con un secreto
//  PÚBLICO (`noop-sandbox-secret`, en el repo) y su webhook se atendía siempre,
//  fuera cual fuera la pasarela activa y también en producción: cualquiera
//  podía firmar un «aprobado» para su propio pedido y marcarlo pagado.
//
//  Y la marca anti-replay se ponía antes de tocar el pedido sin soltarla nunca:
//  un fallo transitorio de WooCommerce dejaba el pedido sin pagar para siempre,
//  porque cada reintento de la pasarela recibía «duplicado».
// ============================================================================

vi.mock("@/lib/security/rate-limit", () => ({
  rateLimit: vi.fn(async () => ({ success: true })),
}));
vi.mock("@/lib/security/replay", () => ({
  markEventOnce: vi.fn(async () => true),
  releaseEvent: vi.fn(async () => undefined),
}));
vi.mock("@/lib/payments/orders", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/payments/orders")>()),
  getOrder: vi.fn(),
  markOrderPaid: vi.fn(),
  markOrderCancelled: vi.fn(),
}));
// `after()` solo existe dentro de una petición de Next: aquí corre al momento.
vi.mock("next/server", async (importOriginal) => ({
  ...(await importOriginal<typeof import("next/server")>()),
  after: (callback: () => unknown) => void callback(),
}));
vi.mock("@/lib/analytics/meta-capi", () => ({
  sendPurchaseToMeta: vi.fn(async () => "sent"),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ORDER = {
  id: 42,
  status: "pending",
  total: "59.99",
  currency: "EUR",
  customer_id: 7,
};

function noopEvent(overrides: Record<string, unknown> = {}) {
  const body = JSON.stringify({
    reference: "42",
    status: "APPROVED",
    transactionId: "tx-1",
    amountMinor: 5999,
    currency: "EUR",
    ...overrides,
  });
  const signature = crypto
    .createHmac("sha256", "noop-sandbox-secret")
    .update(body)
    .digest("hex");
  return new Request("https://tienda.example/api/payments/webhook/noop", {
    method: "POST",
    headers: { "content-type": "application/json", "x-noop-signature": signature },
    body,
  });
}

const call = (request: Request, provider = "noop") =>
  POST(request, { params: Promise.resolve({ provider }) });

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllEnvs();
  delete process.env.HWE_REVALIDATION_SECRET;
  vi.mocked(getOrder).mockResolvedValue(ORDER as never);
  vi.mocked(markOrderPaid).mockResolvedValue({
    ...ORDER,
    status: "processing",
  } as never);
});

describe("la pasarela de pruebas (noop)", () => {
  it("con otra pasarela activa, su webhook no existe (404) y no toca ningún pedido", async () => {
    vi.stubEnv("PAYMENT_PROVIDER", "wompi");

    const res = await call(noopEvent());

    expect(res.status).toBe(404);
    expect(markOrderPaid).not.toHaveBeenCalled();
  });

  it("en producción sin pasarela decidida tampoco se atiende", async () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("PAYMENT_PROVIDER", "");

    const res = await call(noopEvent());

    expect(res.status).toBe(404);
    expect(markOrderPaid).not.toHaveBeenCalled();
  });

  it("activa (desarrollo, CI), sí: marca el pedido pagado", async () => {
    vi.stubEnv("PAYMENT_PROVIDER", "noop");

    const res = await call(noopEvent());

    expect(res.status).toBe(200);
    expect(markOrderPaid).toHaveBeenCalledWith("42", "tx-1");
  });
});

describe("anti-replay", () => {
  beforeEach(() => vi.stubEnv("PAYMENT_PROVIDER", "noop"));

  it("si WooCommerce falla a medias, suelta la marca para que el reintento se aplique", async () => {
    vi.mocked(markOrderPaid).mockRejectedValue(new Error("wc caído"));

    const res = await call(noopEvent());

    expect(res.status).toBeGreaterThanOrEqual(500);
    expect(releaseEvent).toHaveBeenCalledTimes(1);
  });

  it("si el importe no cuadra (422, la pasarela reintentará), también la suelta", async () => {
    const res = await call(noopEvent({ amountMinor: 1 }));

    expect(res.status).toBe(422);
    expect(releaseEvent).toHaveBeenCalledTimes(1);
    expect(markOrderPaid).not.toHaveBeenCalled();
  });

  it("un evento ya procesado se reconoce como duplicado sin tocar el pedido", async () => {
    vi.mocked(markEventOnce).mockResolvedValue(false);

    const res = await call(noopEvent());

    expect((await res.json()).applied).toBe("duplicate");
    expect(getOrder).not.toHaveBeenCalled();
  });
});

describe("medición de la compra (Conversions API de Meta)", () => {
  beforeEach(() => {
    vi.stubEnv("PAYMENT_PROVIDER", "noop");
    vi.mocked(markEventOnce).mockResolvedValue(true);
    vi.mocked(sendPurchaseToMeta).mockResolvedValue("sent");
  });

  it("al marcar el pedido pagado, envía la compra desde el servidor", async () => {
    const paid = { ...ORDER, status: "processing" };
    vi.mocked(markOrderPaid).mockResolvedValue(paid as never);

    const res = await call(noopEvent());

    expect((await res.json()).applied).toBe("paid");
    expect(sendPurchaseToMeta).toHaveBeenCalledWith(paid);
  });

  it("un reintento de la pasarela con el pedido ya pagado también lo intenta (la marca del pedido evita duplicar)", async () => {
    const paid = { ...ORDER, status: "processing" };
    vi.mocked(getOrder).mockResolvedValue(paid as never);

    const res = await call(noopEvent());

    expect((await res.json()).applied).toBe("already_paid");
    expect(sendPurchaseToMeta).toHaveBeenCalledWith(paid);
  });

  it("si el importe no cuadra, no se envía nada", async () => {
    await call(noopEvent({ amountMinor: 1 }));

    expect(sendPurchaseToMeta).not.toHaveBeenCalled();
  });

  it("un fallo del envío no cambia la respuesta a la pasarela", async () => {
    vi.mocked(markOrderPaid).mockResolvedValue({
      ...ORDER,
      status: "processing",
    } as never);
    vi.mocked(sendPurchaseToMeta).mockRejectedValue(new Error("meta caído"));

    const res = await call(noopEvent());

    expect(res.status).toBe(200);
  });
});
