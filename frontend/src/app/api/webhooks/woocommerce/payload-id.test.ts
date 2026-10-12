import crypto from "node:crypto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { rateLimit } from "@/lib/security/rate-limit";
import { markEventOnce } from "@/lib/security/replay";
import {
  dispatchOrderEffects,
  recordAndDiffStatus,
} from "@/lib/woocommerce/order-events";
import { POST as orderCreated } from "./order-created/route";
import { POST as orderUpdated } from "./order-updated/route";

// ============================================================================
//  Webhooks de pedido de WooCommerce: `payload.id`.
//
//  Tras verificar la firma, el id se usaba con un cast (`as number | string`): un
//  objeto, una lista o un texto cualquiera llegaba a la clave de Redis del estado
//  del pedido y a los efectos. Ahora solo se acepta un id de pedido (entero
//  positivo, como número o como texto numérico); el resto es 400 sin efectos.
// ============================================================================

vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/security/replay", () => ({ markEventOnce: vi.fn() }));
vi.mock("@/lib/woocommerce/order-events", () => ({
  recordAndDiffStatus: vi.fn(),
  dispatchOrderEffects: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SECRET = "wc-webhook-secret-de-prueba";

function signed(payload: unknown): Request {
  const body = JSON.stringify(payload);
  const signature = crypto.createHmac("sha256", SECRET).update(body).digest("base64");
  return new Request("https://tienda.example/api/webhooks/woocommerce/x", {
    method: "POST",
    headers: { "x-wc-webhook-signature": signature },
    body,
  });
}

let saved: string | undefined;
beforeEach(() => {
  vi.clearAllMocks();
  saved = process.env.WC_WEBHOOK_SECRET;
  process.env.WC_WEBHOOK_SECRET = SECRET;
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 100,
    retryAfter: 0,
    limit: 120,
  });
  vi.mocked(markEventOnce).mockResolvedValue(true);
  vi.mocked(recordAndDiffStatus).mockResolvedValue(null);
});
afterEach(() => {
  if (saved === undefined) delete process.env.WC_WEBHOOK_SECRET;
  else process.env.WC_WEBHOOK_SECRET = saved;
});

describe.each([
  ["order-created", orderCreated],
  ["order-updated", orderUpdated],
] as const)("%s", (_nombre, handler) => {
  it.each([
    ["un objeto", { id: { $ne: 0 }, status: "processing" }],
    ["una lista", { id: [1], status: "processing" }],
    ["un booleano", { id: true, status: "processing" }],
    ["un texto no numérico", { id: "abc", status: "processing" }],
    ["un texto con separador de clave", { id: "12:otra", status: "processing" }],
    ["un número negativo", { id: -4, status: "processing" }],
    ["un decimal", { id: 1.5, status: "processing" }],
    ["nulo", { id: null, status: "processing" }],
    ["ausente", { status: "processing" }],
  ])("rechaza un id que es %s: 400 y sin efectos", async (_caso, payload) => {
    const res = await handler(signed(payload));

    expect(res.status).toBe(400);
    expect(recordAndDiffStatus).not.toHaveBeenCalled();
    expect(dispatchOrderEffects).not.toHaveBeenCalled();
  });

  it("con un id válido registra el estado y despacha los efectos", async () => {
    const res = await handler(signed({ id: 77, status: "processing" }));

    expect(res.status).toBe(200);
    expect(recordAndDiffStatus).toHaveBeenCalledWith(77, "processing");
    expect(dispatchOrderEffects).toHaveBeenCalledWith(
      expect.objectContaining({ orderId: 77, status: "processing" }),
    );
  });

  it("un id numérico enviado como texto se normaliza a número", async () => {
    const res = await handler(signed({ id: "77", status: "processing" }));

    expect(res.status).toBe(200);
    expect(recordAndDiffStatus).toHaveBeenCalledWith(77, "processing");
  });

  it("un estado ausente sigue siendo 400", async () => {
    const res = await handler(signed({ id: 77 }));

    expect(res.status).toBe(400);
    expect(dispatchOrderEffects).not.toHaveBeenCalled();
  });

  it("una firma inválida es 401 antes de mirar el id", async () => {
    const req = new Request("https://tienda.example/api/webhooks/woocommerce/x", {
      method: "POST",
      headers: { "x-wc-webhook-signature": "falsa" },
      body: JSON.stringify({ id: 77, status: "processing" }),
    });

    const res = await handler(req);

    expect(res.status).toBe(401);
    expect(dispatchOrderEffects).not.toHaveBeenCalled();
  });
});
