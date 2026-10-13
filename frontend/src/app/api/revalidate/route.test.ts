import crypto from "node:crypto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { revalidateTag } from "next/cache";
import { rateLimit } from "@/lib/security/rate-limit";
import { POST } from "./route";

// ============================================================================
//  POST /api/revalidate.
//
//  Dos firmas (WooCommerce en base64, HWE Control Center en hex con `sha256=`)
//  que ahora pasan por el mismo `verifyHmacSignature`, y un rate-limit por IP
//  ANTES de leer el cuerpo: sin él, cualquiera podía martillear el endpoint
//  (HMAC + log por petición) sin coste.
// ============================================================================

vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const WC_SECRET = "wc-secret-de-prueba";
const HWE_SECRET = "hwe-secret-de-prueba-suficientemente-largo";

const hmac = (body: string, secret: string, enc: "hex" | "base64") =>
  crypto.createHmac("sha256", secret).update(body).digest(enc);

function request(body: string, headers: Record<string, string>) {
  return new Request("https://tienda.example/api/revalidate", {
    method: "POST",
    headers: { "x-forwarded-for": "203.0.113.7", ...headers },
    body,
  });
}

const saved = {
  wc: process.env.WC_WEBHOOK_SECRET,
  hwe: process.env.HWE_REVALIDATION_SECRET,
};
beforeEach(() => {
  vi.clearAllMocks();
  process.env.WC_WEBHOOK_SECRET = WC_SECRET;
  process.env.HWE_REVALIDATION_SECRET = HWE_SECRET;
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 10,
    retryAfter: 0,
    limit: 120,
  });
});
afterEach(() => {
  for (const [key, value] of [
    ["WC_WEBHOOK_SECRET", saved.wc],
    ["HWE_REVALIDATION_SECRET", saved.hwe],
  ] as const) {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  }
});

describe("firmas", () => {
  it("WooCommerce: una firma base64 válida revalida el catálogo", async () => {
    const body = '{"id":5}';

    const res = await POST(
      request(body, { "x-wc-webhook-signature": hmac(body, WC_SECRET, "base64") }),
    );

    expect(res.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledWith("products", "max");
  });

  it("HWE: una firma hex con prefijo válida revalida el tag pedido", async () => {
    const body = '{"tag":"banners"}';

    const res = await POST(
      request(body, { "x-hwe-signature": `sha256=${hmac(body, HWE_SECRET, "hex")}` }),
    );

    expect(res.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledWith("banners", "max");
  });

  it("HWE: el tag del módulo site-reviews es de los que revalida el núcleo", async () => {
    const body = '{"tag":"site-reviews"}';

    const res = await POST(
      request(body, { "x-hwe-signature": `sha256=${hmac(body, HWE_SECRET, "hex")}` }),
    );

    expect(res.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledWith("site-reviews", "max");
  });

  it("HWE: el tag del módulo coupon-rules también", async () => {
    const body = '{"tag":"coupons"}';

    const res = await POST(
      request(body, { "x-hwe-signature": `sha256=${hmac(body, HWE_SECRET, "hex")}` }),
    );

    expect(res.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledWith("coupons", "max");
  });

  it.each([
    ["WooCommerce", { "x-wc-webhook-signature": "firma-falsa" }],
    ["HWE", { "x-hwe-signature": "sha256=deadbeef" }],
    ["HWE sin el prefijo", { "x-hwe-signature": hmac("{}", HWE_SECRET, "hex") }],
    ["ninguna cabecera de firma", {}],
  ])("una firma inválida (%s) es 401 y no revalida nada", async (_caso, headers) => {
    const res = await POST(request("{}", headers));

    expect(res.status).toBe(401);
    expect(revalidateTag).not.toHaveBeenCalled();
  });

  it("un tag HWE no permitido es 400 aunque la firma sea válida", async () => {
    const body = '{"tag":"cualquier-cosa"}';

    const res = await POST(
      request(body, { "x-hwe-signature": `sha256=${hmac(body, HWE_SECRET, "hex")}` }),
    );

    expect(res.status).toBe(400);
    expect(revalidateTag).not.toHaveBeenCalled();
  });
});

describe("rate-limit", () => {
  it("se aplica por IP", async () => {
    const body = '{"id":5}';
    await POST(
      request(body, { "x-wc-webhook-signature": hmac(body, WC_SECRET, "base64") }),
    );

    expect(rateLimit).toHaveBeenCalledWith(
      expect.stringContaining("revalidate:203.0.113.7"),
      expect.any(Number),
      expect.any(Number),
    );
  });

  it("superar el límite da 429 con Retry-After, antes de mirar la firma", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 42,
      limit: 120,
    });
    const body = '{"id":5}';

    const res = await POST(
      request(body, { "x-wc-webhook-signature": hmac(body, WC_SECRET, "base64") }),
    );

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("42");
    expect(revalidateTag).not.toHaveBeenCalled();
  });
});
