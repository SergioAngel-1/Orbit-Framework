import crypto from "node:crypto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MAX_WEBHOOK_BODY_BYTES } from "@/lib/api/body";
import { rateLimit } from "@/lib/security/rate-limit";
import { POST } from "./route";

// ============================================================================
//  POST /api/webhooks/woocommerce/order-created sin tope de cuerpo.
//
//  La ruta es pública (server-to-server, exenta de Origin y CSRF) y leía el
//  cuerpo entero con `request.text()` ANTES de comprobar la firma: cualquiera
//  podía enviar 130 MB anónimos y el proceso los buffereaba y los hasheaba en
//  memoria (sin firma válida, respuesta 401 al final). El tope debe responder
//  413 y dejar de leer en cuanto se pasa, sin llegar a materializar el resto.
// ============================================================================

vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/security/replay", () => ({
  markEventOnce: vi.fn(async () => true),
}));
vi.mock("@/lib/woocommerce/order-events", () => ({
  recordAndDiffStatus: vi.fn(async () => null),
  dispatchOrderEffects: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

const WC_SECRET = "secreto-webhook-de-prueba";
const MAX_WEBHOOK = MAX_WEBHOOK_BODY_BYTES;

/** Stream que produce `chunk` hasta `totalBytes`, contando lo entregado. */
function countedStream(totalBytes: number, chunkSize = 32 * 1024) {
  const chunk = new Uint8Array(chunkSize).fill(65); // "A"
  let sent = 0;
  let cancelled = false;
  const stream = new ReadableStream<Uint8Array>({
    pull(controller) {
      if (sent + chunkSize > totalBytes) {
        controller.close();
        return;
      }
      sent += chunkSize;
      controller.enqueue(chunk);
    },
    cancel() {
      cancelled = true;
    },
  });
  return { stream, sent: () => sent, cancelled: () => cancelled };
}

function request(
  body: BodyInit | ReadableStream<Uint8Array>,
  headers: Record<string, string> = {},
) {
  return new Request("https://tienda.example/api/webhooks/woocommerce/order-created", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "x-forwarded-for": "203.0.113.9",
      ...headers,
    },
    body,
    duplex: "half",
  } as RequestInit);
}

const savedSecret = process.env.WC_WEBHOOK_SECRET;

beforeEach(() => {
  vi.clearAllMocks();
  process.env.WC_WEBHOOK_SECRET = WC_SECRET;
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 10,
    retryAfter: 0,
    limit: 120,
  });
});

afterEach(() => {
  if (savedSecret === undefined) delete process.env.WC_WEBHOOK_SECRET;
  else process.env.WC_WEBHOOK_SECRET = savedSecret;
});

describe("tope de cuerpo del webhook de pedidos", () => {
  it("un cuerpo troceado mayor que el tope responde 413 y deja de leer", async () => {
    const { stream, sent, cancelled } = countedStream(MAX_WEBHOOK * 4);
    const res = await POST(request(stream));

    expect(res.status).toBe(413);
    expect(cancelled()).toBe(true);
    // Muy por debajo de los 4x el tope enviados: se cortó al pasarse (el
    // Request puede haber preentregado un bloque al construirse).
    expect(sent()).toBeLessThan(MAX_WEBHOOK * 2);
  });

  it("un Content-Length declarado por encima del tope responde 413 sin leer el cuerpo", async () => {
    const { stream, sent } = countedStream(MAX_WEBHOOK * 4);
    const req = request(stream, {
      "content-length": String(MAX_WEBHOOK + 1),
    });

    const res = await POST(req);

    expect(res.status).toBe(413);
    expect(req.bodyUsed).toBe(false);
    expect(sent()).toBeLessThanOrEqual(32 * 1024);
  });

  it("un webhook firmado normal sigue verificando y procesando", async () => {
    const body = JSON.stringify({ id: 42, status: "processing" });
    const signature = crypto
      .createHmac("sha256", WC_SECRET)
      .update(body)
      .digest("base64");

    const res = await POST(request(body, { "x-wc-webhook-signature": signature }));

    expect(res.status).toBe(200);
    await expect(res.json()).resolves.toMatchObject({ received: true, orderId: 42 });
  });

  it("una firma inválida bajo el tope sigue respondiendo 401", async () => {
    const res = await POST(
      request(JSON.stringify({ id: 42, status: "processing" }), {
        "x-wc-webhook-signature": "AAAA",
      }),
    );

    expect(res.status).toBe(401);
  });
});
