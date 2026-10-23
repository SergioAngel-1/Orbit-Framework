import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { WooCommerceError, wcFetch } from "@/lib/woocommerce/client";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { rememberGuestOrder } from "@/lib/checkout/guest-order-cookie";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
  releaseIdempotencyKey,
} from "@/lib/security/idempotency";
import { getGeoPack, GENERIC_PACK } from "@/lib/geo";
import { CO_PACK } from "../../../../../tests/helpers/geo-pack";
import { attachIdentity } from "@/lib/customer/identity-api";
import { FALLBACK_ES_PACK } from "../../../../../tests/helpers/geo-pack";
import { logger } from "@/lib/observability/logger";
import { getShippingMethods } from "@/lib/shipping";
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
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));
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
vi.mock("@/lib/customer/identity-api", () => ({ attachIdentity: vi.fn() }));
vi.mock("@/lib/shipping", () => ({ getShippingMethods: vi.fn() }));
vi.mock("@/lib/geo", async () => ({
  getGeoPack: vi.fn(),
  GENERIC_PACK: (await import("@/lib/geo/generic")).GENERIC_PACK,
}));
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
  vi.mocked(getGeoPack).mockResolvedValue(GENERIC_PACK);
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

describe("pago en línea sin ningún medio de cobro disponible", () => {
  it("si Woo solo ofrece efectivo o WhatsApp: 422, no nace un pedido «en línea» contra reembolso", async () => {
    cartIs({
      needs_shipping: false,
      shipping_rates: [],
      payment_methods: ["cod", "hwe_whatsapp_link"],
    });

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
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

describe("dirección según el paquete de país", () => {
  const CO_ADDRESS = { ...ADDRESS, city: "Pasto", country: "CO", postcode: "" };
  const sentBilling = () =>
    (orderPosts()[0][1] as { body: { billing_address: Record<string, string> } }).body
      .billing_address;

  beforeEach(() => {
    vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  });

  it("el departamento por nombre llega a la Store API como código", async () => {
    const res = await POST(
      checkout("hwe_offline", {
        billing_address: { ...CO_ADDRESS, state: "Nariño", phone: "300 123 4567" },
      }),
    );

    expect(res.status).toBe(201);
    expect(sentBilling()).toMatchObject({ state: "CO-NAR", phone: "+573001234567" });
  });

  it("una ciudad conocida fija el departamento que el cliente no indicó", async () => {
    const res = await POST(
      checkout("hwe_offline", { billing_address: { ...CO_ADDRESS, city: "Bogotá" } }),
    );

    expect(res.status).toBe(201);
    expect(sentBilling().state).toBe("CO-DC");
  });

  it("sin departamento (y sin ciudad que lo fije) no se crea el pedido: 422 por campo", async () => {
    const res = await POST(checkout("hwe_offline", { billing_address: CO_ADDRESS }));

    expect(res.status).toBe(422);
    expect((await res.json()).details).toEqual({
      "billing_address.state": ["Departamento requerido"],
    });
    expect(orderPosts()).toHaveLength(0);
  });

  it("un departamento que no corresponde a la ciudad tampoco crea el pedido", async () => {
    const res = await POST(
      checkout("hwe_offline", {
        billing_address: { ...CO_ADDRESS, city: "Bogotá", state: "Antioquia" },
      }),
    );

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("valida también la dirección de envío", async () => {
    const res = await POST(
      checkout("hwe_offline", {
        billing_address: { ...CO_ADDRESS, state: "Nariño" },
        shipping_address: { ...CO_ADDRESS, state: "Atlantis" },
      }),
    );

    expect(res.status).toBe(422);
    expect(Object.keys((await res.json()).details)).toEqual(["shipping_address.state"]);
  });

  it("con el paquete genérico el estado es texto libre", async () => {
    vi.mocked(getGeoPack).mockResolvedValue(GENERIC_PACK);

    const res = await POST(
      checkout("hwe_offline", { billing_address: { ...ADDRESS, state: "Madrid" } }),
    );

    expect(res.status).toBe(201);
    expect(sentBilling().state).toBe("Madrid");
  });
});

describe("documento y términos (módulo customer-identity)", () => {
  const CO_ADDRESS = {
    ...ADDRESS,
    city: "Pasto",
    country: "CO",
    postcode: "",
    state: "Nariño",
  };
  const DOC = { documentType: "CC", documentNumber: "1.012.345.678" };

  /** Módulo encendido, con el país de la tienda y la cuenta que se indique. */
  function identityOn(account: Record<string, string> = {}) {
    vi.mocked(getSiteConfig).mockResolvedValue({
      brand: { url: "https://tienda.example" },
      ecommerce: { checkout_login_required: false },
      payments: { gateway_enabled: true, whatsapp_enabled: false, cash_enabled: false },
      modules: { "customer-identity": true },
    } as never);
    vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
    vi.mocked(wcFetch).mockImplementation((async (path: string) =>
      path.startsWith("/customers/")
        ? { meta_data: Object.entries(account).map(([key, value]) => ({ key, value })) }
        : { customer_id: 7 }) as never);
    vi.mocked(attachIdentity).mockResolvedValue({
      ok: true,
      identity: { documentType: null, documentNumber: null, termsAcceptedAt: null },
    });
  }

  const post = (extra: Record<string, unknown> = {}) =>
    POST(checkout("hwe_offline", { billing_address: CO_ADDRESS, ...extra }));

  const sentBody = () => (orderPosts()[0][1] as { body: Record<string, unknown> }).body;
  const orderMetaPut = () =>
    vi
      .mocked(wcFetch)
      .mock.calls.find(
        ([path, init]) => String(path).startsWith("/orders/") && init?.method === "PUT",
      );

  const ACCOUNT = {
    billing_document_type: "CC",
    billing_document: "1098765432",
    hwe_terms_accepted_at: "2025-01-01T00:00:00.000Z",
  };

  it("con el módulo apagado no pide ni envía nada de identidad", async () => {
    vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);

    const res = await post({ identity: DOC, terms_accepted: true });

    expect(res.status).toBe(201);
    expect(sentBody()).not.toHaveProperty("extensions");
    expect(attachIdentity).not.toHaveBeenCalled();
  });

  it("invitado: sin documento o sin términos no se crea el pedido (422 por campo)", async () => {
    identityOn();
    vi.mocked(getSession).mockResolvedValue(null);

    const noDoc = await post({ terms_accepted: true });
    expect(noDoc.status).toBe(422);
    expect((await noDoc.json()).details).toHaveProperty("identity");

    const noTerms = await post({ identity: DOC });
    expect(noTerms.status).toBe(422);
    expect((await noTerms.json()).details).toHaveProperty("terms_accepted");
    expect(orderPosts()).toHaveLength(0);
  });

  it("un documento que no cuadra con el paquete de país es un 422", async () => {
    identityOn();
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await post({
      identity: { documentType: "CC", documentNumber: "12" },
      terms_accepted: true,
    });

    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("invitado con documento y términos: el pedido NACE con ellos (extensions) y los deja en su meta", async () => {
    identityOn();
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await post({ identity: DOC, terms_accepted: true });

    expect(res.status).toBe(201);
    expect(sentBody().extensions).toEqual({
      "hwe-identity": {
        document_type: "CC",
        document_number: "1012345678",
        terms_accepted_at: expect.any(String),
      },
    });
    // `identity` y `terms_accepted` son del BFF: no viajan a la Store API tal cual.
    expect(sentBody()).not.toHaveProperty("identity");
    expect(sentBody()).not.toHaveProperty("terms_accepted");
    const meta = (orderMetaPut()?.[1] as { body: { meta_data: { key: string }[] } })
      .body.meta_data;
    expect(meta.map((m) => m.key)).toEqual(
      expect.arrayContaining(["_hwe_document_number", "_hwe_terms_accepted_at"]),
    );
    expect(attachIdentity).not.toHaveBeenCalled();
  });

  it("una cuenta con documento y términos: manda lo suyo y no se guarda nada", async () => {
    identityOn(ACCOUNT);

    const res = await post({ identity: DOC });

    expect(res.status).toBe(201);
    expect(sentBody().extensions).toMatchObject({
      "hwe-identity": {
        document_number: "1098765432",
        terms_accepted_at: ACCOUNT.hwe_terms_accepted_at,
      },
    });
    expect(attachIdentity).not.toHaveBeenCalled();
  });

  it("una cuenta sin documento lo guarda en la cuenta ANTES de crear el pedido", async () => {
    identityOn({ hwe_terms_accepted_at: ACCOUNT.hwe_terms_accepted_at });

    const res = await post({ identity: DOC });

    expect(res.status).toBe(201);
    expect(attachIdentity).toHaveBeenCalledWith(7, {
      documentType: "CC",
      documentNumber: "1012345678",
    });
    expect(vi.mocked(attachIdentity).mock.invocationCallOrder[0]).toBeLessThan(
      vi.mocked(storeFetch).mock.invocationCallOrder.at(-1)!,
    );
  });

  it("un documento de otra cuenta es un 409 y no se crea el pedido", async () => {
    identityOn({ hwe_terms_accepted_at: ACCOUNT.hwe_terms_accepted_at });
    vi.mocked(attachIdentity).mockResolvedValue({
      ok: false,
      reason: "document_taken",
    });

    const res = await post({ identity: DOC });

    expect(res.status).toBe(409);
    expect((await res.json()).details).toHaveProperty("identity");
    expect(orderPosts()).toHaveLength(0);
  });

  it("si WordPress no responde al guardar en la cuenta, el pedido sigue y el fallo se registra", async () => {
    identityOn({ hwe_terms_accepted_at: ACCOUNT.hwe_terms_accepted_at });
    vi.mocked(attachIdentity).mockResolvedValue({ ok: false, reason: "unavailable" });

    const res = await post({ identity: DOC });

    expect(res.status).toBe(201);
    expect(
      vi.mocked(logger.error).mock.calls.map(([o]) => (o as { event: string }).event),
    ).toContain("checkout.identity_persist_failed");
  });

  it("en un país sin tipos de documento solo pide los términos", async () => {
    identityOn();
    vi.mocked(getSession).mockResolvedValue(null);
    vi.mocked(getGeoPack).mockResolvedValue(FALLBACK_ES_PACK);

    const billing = { ...ADDRESS };
    const noTerms = await POST(checkout("hwe_offline", { billing_address: billing }));
    expect(noTerms.status).toBe(422);

    const res = await POST(
      checkout("hwe_offline", { billing_address: billing, terms_accepted: true }),
    );
    expect(res.status).toBe(201);
    expect(
      (sentBody().extensions as Record<string, { document_type: unknown }>)[
        "hwe-identity"
      ].document_type,
    ).toBeNull();
  });
});

describe("extras de envío (módulo shipping)", () => {
  const METHODS = [
    { id: 3, name: "Seguro", addedValue: 5 },
    { id: 4, name: "Caja de regalo", addedValue: 0 },
  ];

  function shippingOn(on: boolean) {
    vi.mocked(getSiteConfig).mockResolvedValue({
      brand: { url: "https://tienda.example" },
      ecommerce: { checkout_login_required: true },
      payments: { gateway_enabled: true, whatsapp_enabled: false, cash_enabled: false },
      modules: { shipping: on },
    } as never);
    vi.mocked(getShippingMethods).mockResolvedValue(METHODS);
  }
  const feePut = () =>
    vi
      .mocked(wcFetch)
      .mock.calls.find(
        ([path, init]) =>
          String(path).startsWith("/orders/") &&
          init?.method === "PUT" &&
          "fee_lines" in ((init.body as object) ?? {}),
      );

  it("suma los extras elegidos al pedido como cargos, con el importe vigente de WordPress", async () => {
    shippingOn(true);

    const res = await POST(checkout("hwe_offline", { shipping_method_ids: [3, 4] }));

    expect(res.status).toBe(201);
    expect(getShippingMethods).toHaveBeenCalledWith({ fresh: true });
    expect((feePut()?.[1] as { body: unknown }).body).toEqual({
      fee_lines: [
        { name: "Seguro", total: "5", tax_status: "none" },
        { name: "Caja de regalo", total: "0", tax_status: "none" },
      ],
    });
  });

  it("un id que ya no existe o está inactivo no cuenta, y el importe nunca sale del cliente", async () => {
    shippingOn(true);

    await POST(
      checkout("hwe_offline", { shipping_method_ids: [3, 999], shipping_fee: 99999 }),
    );

    expect(
      (feePut()?.[1] as { body: { fee_lines: unknown[] } }).body.fee_lines,
    ).toEqual([{ name: "Seguro", total: "5", tax_status: "none" }]);
  });

  it("los ids no viajan a la Store API", async () => {
    shippingOn(true);

    await POST(checkout("hwe_offline", { shipping_method_ids: [3] }));

    expect(JSON.stringify(orderPosts()[0][1])).not.toContain("shipping_method_ids");
  });

  it("con el módulo apagado se ignoran los extras y no se lee nada de WordPress", async () => {
    shippingOn(false);

    const res = await POST(checkout("hwe_offline", { shipping_method_ids: [3] }));

    expect(res.status).toBe(201);
    expect(getShippingMethods).not.toHaveBeenCalled();
    expect(feePut()).toBeUndefined();
  });

  it("si no se pueden aplicar los cargos, el pedido ya creado no se pierde", async () => {
    shippingOn(true);
    vi.mocked(getShippingMethods).mockRejectedValue(new Error("red"));

    const res = await POST(checkout("hwe_offline", { shipping_method_ids: [3] }));

    expect(res.status).toBe(201);
    expect(
      vi.mocked(logger.error).mock.calls.map(([o]) => (o as { event: string }).event),
    ).toContain("checkout.shipping_fees_failed");
  });

  it("rechaza una lista de ids mal formada", async () => {
    shippingOn(true);

    expect(
      (await POST(checkout("hwe_offline", { shipping_method_ids: ["a"] }))).status,
    ).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });
});

describe("pago completo con puntos (módulo loyalty)", () => {
  const EXTENSION = {
    available: true,
    reason: null,
    balance: 5000,
    points_applied: 3000,
    discount: "30000",
    max_redeemable: 3000,
    min_redeem: 100,
    full_payment_available: true,
  };

  /** Config con el módulo de fidelización y los puntos encendidos. */
  function loyaltyOn({ shipping = false, whatsapp = false } = {}) {
    vi.mocked(getSiteConfig).mockResolvedValue({
      brand: { url: "https://tienda.example" },
      ecommerce: { checkout_login_required: true },
      payments: {
        gateway_enabled: true,
        whatsapp_enabled: whatsapp,
        cash_enabled: false,
      },
      modules: { loyalty: true, shipping },
      loyalty: { points_enabled: true, full_payment_enabled: true },
    } as never);
  }

  /** Carrito que Woo ya dejó en total 0 por el canje de puntos. */
  function cartPaidWithPoints(overrides: Record<string, unknown> = {}) {
    return {
      needs_shipping: false,
      shipping_rates: [],
      // Woo no ofrece el pago en línea para este carrito: el flujo normal lo
      // rechazaría. Con el canje confirmado, el medio no se valida.
      payment_methods: ["cod"],
      totals: { total_price: "0" },
      extensions: { "hwe-loyalty": EXTENSION },
      ...overrides,
    };
  }

  /** Pedido de wc/v3 con las metas indicadas (la marca la escribe WordPress). */
  function orderWithMeta(meta: Record<string, string>) {
    vi.mocked(wcFetch).mockImplementation((async (
      path: string,
      opts?: { method?: string },
    ) => {
      if (path === "/orders/42" && !opts?.method) {
        return {
          customer_id: 7,
          meta_data: Object.entries(meta).map(([key, value]) => ({ key, value })),
        };
      }
      return { customer_id: 7 };
    }) as never);
  }

  const errorEvents = () =>
    vi.mocked(logger.error).mock.calls.map(([o]) => (o as { event: string }).event);

  it("no valida ni sustituye el medio de pago contra los de Woo", async () => {
    loyaltyOn();
    cartIs(cartPaidWithPoints());
    orderWithMeta({ _hwe_loyalty_paid_with_points: "yes" });

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect((await res.json()).paid_with_points).toBe(true);
    expect(orderPosts()).toHaveLength(1);
    expect(orderPosts()[0]![1]).toMatchObject({
      body: expect.objectContaining({ payment_method: "hwe_offline" }),
    });
    // No hay ninguna otra llamada a la tienda: ni pago ni link.
    expect(vi.mocked(storeFetch).mock.calls.map(([path]) => path)).toEqual([
      "/cart",
      "/checkout",
    ]);
  });

  it("no pasa a «En espera» aunque el medio pedido sea el link por WhatsApp", async () => {
    loyaltyOn({ whatsapp: true });
    cartIs(cartPaidWithPoints());
    orderWithMeta({ _hwe_loyalty_paid_with_points: "yes" });

    const res = await POST(checkout("hwe_whatsapp_link"));

    expect(res.status).toBe(201);
    expect((await res.json()).paid_with_points).toBe(true);
    expect(wcFetch).not.toHaveBeenCalledWith("/orders/42", {
      method: "PUT",
      body: { status: "on-hold" },
    });
  });

  it("pago completo con extras de envío responde 422 antes de crear el pedido", async () => {
    vi.mocked(isValidIdempotencyKey).mockReturnValue(true);
    vi.mocked(reserveIdempotencyKey).mockResolvedValue({
      status: "reserved",
    } as never);
    loyaltyOn({ shipping: true });
    cartIs(cartPaidWithPoints());

    const res = await POST(checkout("hwe_offline", { shipping_method_ids: [3] }));

    expect(res.status).toBe(422);
    expect((await res.json()).reason).toBe("loyalty_full_payment_with_shipping_extras");
    expect(orderPosts()).toHaveLength(0);
    expect(getShippingMethods).not.toHaveBeenCalled();
    expect(releaseIdempotencyKey).toHaveBeenCalled();
  });

  it("un carrito sin total no se da por pagado solo por la extensión", async () => {
    loyaltyOn();
    cartIs(cartPaidWithPoints({ totals: {} }));

    const res = await POST(checkout("hwe_offline"));

    // Sin prueba de que el canje cubre el pedido, se aplica el flujo normal:
    // el pago en línea no está entre los medios de Woo para este carrito.
    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("un total 0 sin la marca del pedido no se anuncia como pagado con puntos", async () => {
    loyaltyOn();
    cartIs(cartPaidWithPoints());
    orderWithMeta({});

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect(await res.json()).not.toHaveProperty("paid_with_points");
    expect(errorEvents()).toContain("checkout.paid_with_points_missing");
  });

  it.each([
    ["hwe_loyalty_insufficient_points", "loyalty_insufficient_points"],
    ["hwe_loyalty_redeem_failed", "loyalty_redeem_failed"],
  ])(
    "traduce el error %s del canje a un 409 con motivo estable",
    async (code, reason) => {
      loyaltyOn();
      vi.mocked(storeFetch).mockImplementation((async (path: string) => {
        if (path === "/cart") {
          return { data: cartPaidWithPoints(), cartToken: "cart-token" };
        }
        throw new WooCommerceError("No se pudo confirmar el canje.", 400, code);
      }) as never);

      const res = await POST(checkout("hwe_offline"));

      expect(res.status).toBe(409);
      expect(await res.json()).toMatchObject({ reason });
      expect(errorEvents()).toContain("checkout.loyalty_redeem_error");
    },
  );

  it("con el módulo apagado el total 0 por puntos se trata como cualquier carrito", async () => {
    cartIs(cartPaidWithPoints());

    const res = await POST(checkout("hwe_offline"));

    // Flujo de hoy: el pago en línea no está entre los medios de Woo → 422.
    expect(res.status).toBe(422);
    expect(orderPosts()).toHaveLength(0);
  });

  it("con el módulo apagado no se marca un pedido por la extensión del carrito", async () => {
    cartIs(cartPaidWithPoints({ payment_methods: [] }));

    const res = await POST(checkout("hwe_offline"));

    expect(res.status).toBe(201);
    expect(await res.json()).not.toHaveProperty("paid_with_points");
  });
});
