import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { rememberGuestOrder } from "@/lib/checkout/guest-order-cookie";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
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
//
//  También aquí: quién puede comprar (invitado según
//  `ecommerce.checkout_login_required`) y el guard de envío del servidor.
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/checkout/guest-order-cookie", () => ({
  rememberGuestOrder: vi.fn(async () => undefined),
}));
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
const cookieJar = new Map<string, string>();
vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) =>
      cookieJar.has(name) ? { name, value: cookieJar.get(name) } : undefined,
  }),
}));
vi.mock("@/i18n/navigation", () => ({
  getPathname: ({ href }: { href: string }) => href,
}));
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

function checkout(
  paymentMethod: string,
  extra: Record<string, unknown> = {},
  headers: Record<string, string> = {},
) {
  return new Request("https://tienda.example/api/store/checkout", {
    method: "POST",
    headers: { "content-type": "application/json", ...headers },
    body: JSON.stringify({
      billing_address: ADDRESS,
      payment_method: paymentMethod,
      ...extra,
    }),
  });
}

function payments(
  overrides: Record<string, boolean> = {},
  ecommerce: Record<string, boolean> = {},
) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    brand: { url: "https://tienda.example" },
    ecommerce: { checkout_login_required: true, ...ecommerce },
    payments: {
      gateway_enabled: true,
      whatsapp_enabled: false,
      cash_enabled: false,
      ...overrides,
    },
  } as never);
}

const SESSION = { token: "jwt-del-cliente", userId: "7" };
const ORDER_RESPONSE = {
  order_id: 42,
  status: "pending",
  order_key: "wc_order_AbC123",
};

/** Carrito que devuelve `GET /cart` (el guard de envío lo lee antes de pagar). */
function cartIs(cart: Record<string, unknown>) {
  vi.mocked(storeFetch).mockImplementation((async (path: string) =>
    path === "/cart"
      ? { data: cart, cartToken: "cart-token" }
      : { data: ORDER_RESPONSE, cartToken: "next-token" }) as never);
}

const orderPosts = () =>
  vi.mocked(storeFetch).mock.calls.filter(([path]) => path === "/checkout");

beforeEach(() => {
  vi.clearAllMocks();
  cookieJar.clear();
  payments();
  vi.mocked(getSession).mockResolvedValue(SESSION as never);
  vi.mocked(isValidIdempotencyKey).mockReturnValue(false);
  cartIs({ needs_shipping: false, shipping_rates: [] });
  vi.mocked(wcFetch).mockResolvedValue({ customer_id: 7 } as never);
});

describe("medio de pago", () => {
  it("rechaza un gateway que el checkout no ofrece (422) sin crear el pedido", async () => {
    const res = await POST(checkout("bacs"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("rechaza efectivo si está apagado en el Control Center", async () => {
    const res = await POST(checkout("cod"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("rechaza el link por WhatsApp si está apagado", async () => {
    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("acepta el pago en línea con la pasarela activa", async () => {
    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect(orderPosts()).toHaveLength(1);
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

    expect(wcFetch).not.toHaveBeenCalledWith(
      "/orders/42",
      expect.objectContaining({ body: { status: "on-hold" } }),
    );
  });

  it("si no se puede pasar a «En espera», el pedido ya creado no se pierde", async () => {
    payments({ whatsapp_enabled: true });
    vi.mocked(wcFetch).mockRejectedValue(new Error("wc caído"));

    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(201);
    expect((await res.json()).order_id).toBe(42);
  });
});

describe("quién puede comprar", () => {
  it("sin sesión y con login obligatorio no se crea el pedido (401)", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(401);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin sesión y con compra de invitado, crea el pedido y guarda su credencial", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    payments({}, { checkout_login_required: false });

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect(rememberGuestOrder).toHaveBeenCalledWith({
      orderId: 42,
      orderKey: "wc_order_AbC123",
    });
  });

  it("con sesión no deja credencial de invitado y fija el cliente del pedido", async () => {
    vi.mocked(wcFetch).mockResolvedValue({ customer_id: 0 } as never);

    await POST(checkout("hwe_offline"));

    expect(rememberGuestOrder).not.toHaveBeenCalled();
    expect(wcFetch).toHaveBeenCalledWith("/orders/42", {
      method: "PUT",
      body: { customer_id: 7 },
    });
  });
});

describe("guard de envío del servidor", () => {
  it("un carrito que necesita envío y no tiene tarifa no crea pedido (422) y libera la clave", async () => {
    vi.mocked(isValidIdempotencyKey).mockReturnValue(true);
    vi.mocked(reserveIdempotencyKey).mockResolvedValue({ status: "reserved" } as never);
    cartIs({ needs_shipping: true, shipping_rates: [{ shipping_rates: [] }] });

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
    expect(releaseIdempotencyKey).toHaveBeenCalled();
  });

  it("con tarifa disponible, crea el pedido", async () => {
    cartIs({
      needs_shipping: true,
      shipping_rates: [{ shipping_rates: [{ rate_id: "flat_rate:1" }] }],
    });

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
  });
});

describe("medio de pago frente a lo que WooCommerce ofrece para este carrito", () => {
  it("efectivo activado en el panel pero no ofrecido por Woo: 422, no se sustituye", async () => {
    payments({ cash_enabled: true });
    cartIs({ needs_shipping: false, shipping_rates: [], payment_methods: ["bacs"] });

    const res = await POST(checkout("cod"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("pago en línea no ofrecido: cae a otro medio de cobro, nunca a efectivo", async () => {
    cartIs({
      needs_shipping: false,
      shipping_rates: [],
      payment_methods: ["cod", "bacs"],
    });

    await POST(checkout("hwe_offline"));

    expect(orderPosts()[0]![1]).toMatchObject({
      body: expect.objectContaining({ payment_method: "bacs" }),
    });
  });
});

describe("contexto de medición del pedido", () => {
  /** Metas que el checkout escribió en el pedido (PUT con `meta_data`). */
  const trackingMeta = () => {
    const put = vi
      .mocked(wcFetch)
      .mock.calls.find(
        ([path, opts]) =>
          path === "/orders/42" &&
          opts?.method === "PUT" &&
          (opts.body as { meta_data?: unknown[] })?.meta_data,
      );
    return Object.fromEntries(
      (
        (put?.[1]?.body as { meta_data: { key: string; value: string }[] })
          ?.meta_data ?? []
      ).map((m) => [m.key, m.value]),
    );
  };

  it("con consentimiento, guarda las cookies del píxel, la IP, el navegador y la URL de «gracias»", async () => {
    cookieJar.set("_fbp", "fb.1.1727440000000.1234567890");
    cookieJar.set("_fbc", "fb.1.1727440000000.IwAR0abc");

    await POST(
      checkout(
        "hwe_offline",
        { tracking: { consent: true, locale: "es" } },
        { "user-agent": "Mozilla/5.0", "x-forwarded-for": "203.0.113.9" },
      ),
    );

    expect(trackingMeta()).toMatchObject({
      _hwe_tracking_consent: "accepted",
      _hwe_fbp: "fb.1.1727440000000.1234567890",
      _hwe_fbc: "fb.1.1727440000000.IwAR0abc",
      _hwe_client_user_agent: "Mozilla/5.0",
      _hwe_event_source_url: "https://tienda.example/checkout/return?ref=42",
    });
  });

  it("guarda el comprobante de consentimiento del dispositivo, y solo con «Aceptar»", async () => {
    const receipt = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c";
    cookieJar.set("hwe_consent", receipt);

    await POST(checkout("hwe_offline", { tracking: { consent: true, locale: "es" } }));
    expect(trackingMeta()).toMatchObject({ _hwe_consent_receipt: receipt });

    vi.mocked(wcFetch).mockClear();
    await POST(checkout("hwe_offline", { tracking: { consent: false, locale: "es" } }));
    expect(trackingMeta()).toEqual({ _hwe_tracking_consent: "declined" });
  });

  it("sin consentimiento, solo constancia de que no se aceptó: ni cookies ni IP", async () => {
    cookieJar.set("_fbp", "fb.1.1727440000000.1234567890");

    await POST(
      checkout(
        "hwe_offline",
        { tracking: { consent: false, locale: "es" } },
        {
          "user-agent": "Mozilla/5.0",
        },
      ),
    );

    expect(trackingMeta()).toEqual({ _hwe_tracking_consent: "declined" });
  });

  it("sin el campo `tracking` (cliente que no lo manda) cuenta como no aceptado", async () => {
    await POST(checkout("hwe_offline"));

    expect(trackingMeta()).toEqual({ _hwe_tracking_consent: "declined" });
  });

  it("una cookie que no tiene la forma del píxel no se guarda", async () => {
    cookieJar.set("_fbp", "<script>alert(1)</script>");

    await POST(checkout("hwe_offline", { tracking: { consent: true, locale: "es" } }));

    expect(trackingMeta()).not.toHaveProperty("_hwe_fbp");
  });

  it("un idioma que el sitio no tiene es un 422", async () => {
    const res = await POST(
      checkout("hwe_offline", { tracking: { consent: true, locale: "xx" } }),
    );

    expect(res.status).toBe(422);
  });

  it("si no se puede guardar la medición, el pedido ya creado no se pierde", async () => {
    vi.mocked(wcFetch).mockImplementation((async (
      path: string,
      opts?: { method?: string; body?: { meta_data?: unknown } },
    ) => {
      if (opts?.method === "PUT" && opts.body?.meta_data) throw new Error("woo caído");
      return { customer_id: 7 };
    }) as never);

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
  });
});
