import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSiteConfig } from "@/lib/config";
import { POST } from "./route";

// ============================================================================
//  POST /api/store/checkout — medio de pago.
//
//  El BFF reenviaba `payment_method` tal cual a la Store API: un cliente podía
//  pedir cualquier gateway que WooCommerce tuviera activo (transferencia,
//  efectivo con el efectivo apagado en el Control Center…) y crear un pedido
//  con un medio que la tienda no ofrece. Ahora solo pasan los tres medios del
//  checkout, cada uno si está activado.
//
//  Y un pedido con link de pago por WhatsApp debe quedar «En espera»: en
//  `pending` WooCommerce no manda ningún correo.
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  readCartToken: vi.fn(async () => "cart-token"),
  writeCartToken: vi.fn(async () => undefined),
  clearCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/security/idempotency", () => ({
  isValidIdempotencyKey: vi.fn(() => false),
  reserveIdempotencyKey: vi.fn(),
  storeIdempotentResult: vi.fn(),
  releaseIdempotencyKey: vi.fn(),
}));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ADDRESS = {
  first_name: "Ana",
  last_name: "Pérez",
  address_1: "Calle 1",
  city: "Madrid",
  postcode: "28001",
  country: "ES",
  email: "ana@example.com",
};

function checkout(paymentMethod: string) {
  return new Request("https://tienda.example/api/store/checkout", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ billing_address: ADDRESS, payment_method: paymentMethod }),
  });
}

function payments(overrides: Record<string, boolean> = {}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    payments: {
      gateway_enabled: true,
      whatsapp_enabled: false,
      cash_enabled: false,
      ...overrides,
    },
  } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  payments();
  vi.mocked(storeFetch).mockResolvedValue({
    data: { order_id: 42, status: "pending" },
    cartToken: "next-token",
  } as never);
  vi.mocked(wcFetch).mockResolvedValue({} as never);
});

describe("medio de pago", () => {
  it("rechaza un gateway que el checkout no ofrece (422) sin crear el pedido", async () => {
    const res = await POST(checkout("bacs"));

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("rechaza efectivo si está apagado en el Control Center", async () => {
    const res = await POST(checkout("cod"));

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("rechaza el link por WhatsApp si está apagado", async () => {
    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("acepta el pago en línea con la pasarela activa", async () => {
    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect(storeFetch).toHaveBeenCalledTimes(1);
  });

  it("acepta efectivo cuando está activado", async () => {
    payments({ cash_enabled: true });

    const res = await POST(checkout("cod"));

    expect(res.status).toBe(201);
  });
});

describe("link de pago por WhatsApp", () => {
  it("deja el pedido «En espera» para que WooCommerce envíe los correos", async () => {
    payments({ whatsapp_enabled: true });

    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(201);
    expect(wcFetch).toHaveBeenCalledWith("/orders/42", {
      method: "PUT",
      body: { status: "on-hold" },
    });
  });

  it("el pago en línea y el efectivo no tocan el estado del pedido", async () => {
    payments({ cash_enabled: true });

    await POST(checkout("hwe_offline"));
    await POST(checkout("cod"));

    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("si no se puede pasar a «En espera», el pedido ya creado no se pierde", async () => {
    payments({ whatsapp_enabled: true });
    vi.mocked(wcFetch).mockRejectedValue(new Error("wc caído"));

    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(201);
    expect((await res.json()).order_id).toBe(42);
  });
});
