import { beforeEach, describe, expect, it, vi } from "vitest";
import { getOrder } from "@/lib/payments/orders";
import { getActiveProvider } from "@/lib/payments/registry";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { readGuestOrderCredential } from "@/lib/checkout/guest-order-cookie";
import { POST } from "./route";

// ============================================================================
//  POST /api/payments/create — solo se cobra en la pasarela un pedido de pago
//  en línea. El efectivo se paga al recibirlo y el link por WhatsApp lo envía
//  la tienda: el checkout ya no llama aquí con esos medios, pero una petición
//  manual sí podía iniciar un cobro en la pasarela (y, con el pago en línea
//  apagado en el Control Center, seguir cobrando por ella).
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/checkout/guest-order-cookie", () => ({
  readGuestOrderCredential: vi.fn(),
}));
vi.mock("@/lib/payments/providers", () => ({}));
vi.mock("@/lib/payments/registry", () => ({ getActiveProvider: vi.fn() }));
vi.mock("@/lib/payments/orders", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/payments/orders")>()),
  getOrder: vi.fn(),
}));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const createCheckout = vi.fn();

function order(overrides: Record<string, unknown> = {}) {
  return {
    id: 42,
    status: "pending",
    total: "59.99",
    currency: "EUR",
    customer_id: 7,
    payment_method: "hwe_offline",
    billing: { email: "ana@example.com", first_name: "Ana", last_name: "Pérez" },
    ...overrides,
  };
}

function payments(overrides: Record<string, boolean> = {}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    payments: {
      gateway_enabled: true,
      whatsapp_enabled: true,
      cash_enabled: true,
      ...overrides,
    },
  } as never);
}

const request = () =>
  new Request("https://tienda.example/api/payments/create", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ reference: 42 }),
  });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
  vi.mocked(readGuestOrderCredential).mockResolvedValue(null);
  payments();
  vi.mocked(getOrder).mockResolvedValue(order() as never);
  createCheckout.mockResolvedValue({
    mode: "redirect",
    redirectUrl: "https://pay.test/x",
  });
  vi.mocked(getActiveProvider).mockResolvedValue({
    id: "wompi",
    createCheckout,
  } as never);
});

describe("qué pedidos se cobran en la pasarela", () => {
  it("un pedido de pago en línea inicia el cobro", async () => {
    const res = await POST(request());

    expect(res.status).toBe(200);
    expect(createCheckout).toHaveBeenCalledTimes(1);
  });

  it("no manda a la pasarela un `order_key` que en realidad es el id del pedido", async () => {
    await POST(request());

    const { metadata } = createCheckout.mock.calls[0]![0] as {
      metadata: Record<string, string>;
    };
    // `order_key` es la credencial de un pedido de invitado en WooCommerce: la clave
    // no puede llevar el id ni parecer que viaja la credencial.
    expect(metadata).not.toHaveProperty("order_key");
    expect(metadata).toHaveProperty("order_id");
  });

  it.each(["cod", "hwe_whatsapp_link"])(
    "un pedido de %s no se cobra en la pasarela (409)",
    async (method) => {
      vi.mocked(getOrder).mockResolvedValue(order({ payment_method: method }) as never);

      const res = await POST(request());

      expect(res.status).toBe(409);
      expect(createCheckout).not.toHaveBeenCalled();
    },
  );

  it("con el pago en línea apagado en el Control Center no se cobra (409)", async () => {
    payments({ gateway_enabled: false });

    const res = await POST(request());

    expect(res.status).toBe(409);
    expect(createCheckout).not.toHaveBeenCalled();
  });
});

describe("quién puede iniciar el cobro", () => {
  const GUEST_ORDER = { customer_id: 0, order_key: "wc_order_AbC123" };

  it("sin sesión ni credencial de invitado: 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await POST(request());

    expect(res.status).toBe(401);
    expect(createCheckout).not.toHaveBeenCalled();
  });

  it("el invitado con la credencial de SU pedido inicia el cobro", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    vi.mocked(getOrder).mockResolvedValue(order(GUEST_ORDER) as never);
    vi.mocked(readGuestOrderCredential).mockResolvedValue({
      orderId: 42,
      orderKey: "wc_order_AbC123",
    });

    const res = await POST(request());

    expect(res.status).toBe(200);
    expect(createCheckout).toHaveBeenCalledTimes(1);
  });

  it("la credencial de otro pedido no abre este (404)", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    vi.mocked(getOrder).mockResolvedValue(order(GUEST_ORDER) as never);
    vi.mocked(readGuestOrderCredential).mockResolvedValue({
      orderId: 41,
      orderKey: "wc_order_AbC123",
    });

    const res = await POST(request());

    expect(res.status).toBe(404);
    expect(createCheckout).not.toHaveBeenCalled();
  });

  it("un cliente con sesión no cobra el pedido de otro (404)", async () => {
    vi.mocked(getOrder).mockResolvedValue(order({ customer_id: 8 }) as never);

    const res = await POST(request());

    expect(res.status).toBe(404);
  });
});
