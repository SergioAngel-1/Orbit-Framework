import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { WooOrder } from "@/types/woocommerce";

const getSiteConfigMock = vi.fn();
const wcFetchMock = vi.fn();
const getMetaCapiCredentialsMock = vi.fn();

vi.mock("@/lib/config", () => ({ getSiteConfig: getSiteConfigMock }));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: wcFetchMock }));
vi.mock("./meta-capi-credentials", () => ({
  getMetaCapiCredentials: getMetaCapiCredentialsMock,
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/i18n/navigation", () => ({
  getPathname: ({ href }: { href: string }) => href,
}));

const {
  buildPurchaseEvent,
  normalizeEmail,
  normalizePhone,
  paidAtUnix,
  sendPurchaseToMeta,
  sha256Hex,
} = await import("./meta-capi");

const FBP = "fb.1.1727440000000.1234567890";

/** `config.integrations` completo (la config real siempre trae todos los campos). */
const INTEGRATIONS = {
  analytics_provider: "none",
  analytics_id: "",
  gtm_id: "",
  meta_pixel_id: "",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
};

function order(over: Partial<WooOrder> = {}): WooOrder {
  return {
    id: 1234,
    number: "1234",
    status: "processing",
    currency: "EUR",
    total: "95.00",
    subtotal: "90.00",
    total_discount: "0",
    total_shipping: "5.00",
    total_tax: "0",
    customer_id: 0,
    date_created: "2026-09-27T10:00:00",
    date_modified: "2026-09-27T10:05:00",
    date_paid_gmt: "2026-09-27T15:05:00",
    billing: { email: " Ana@Example.com ", phone: "+34 600 123 456", country: "ES" },
    shipping: {},
    line_items: [
      {
        id: 1,
        name: "Camiseta secreta",
        product_id: 7,
        quantity: 2,
        price: 45,
        total: "90.00",
        subtotal: "90.00",
      },
    ],
    coupon_lines: [],
    payment_method: "hwe_offline",
    payment_method_title: "Pago en línea",
    meta_data: [
      { key: "_hwe_tracking_consent", value: "accepted" },
      { key: "_hwe_fbp", value: FBP },
      { key: "_hwe_client_ip", value: "181.50.1.2" },
      { key: "_hwe_client_user_agent", value: "Mozilla/5.0" },
      {
        key: "_hwe_event_source_url",
        value: "https://tienda.example/checkout/return?ref=1234",
      },
    ],
    ...over,
  } as WooOrder;
}

describe("normalizeEmail / normalizePhone", () => {
  it("email en minúsculas y sin espacios", () => {
    expect(normalizeEmail(" Ana@Example.COM ")).toBe("ana@example.com");
    expect(normalizeEmail("no-es-un-correo")).toBe("");
    expect(normalizeEmail(undefined)).toBe("");
  });

  it("teléfono solo con dígitos; E.164 queda como está", () => {
    expect(normalizePhone("+34 600 123 456")).toBe("34600123456");
    expect(normalizePhone("0034600123456")).toBe("34600123456");
    expect(normalizePhone("123")).toBe("");
    expect(normalizePhone("")).toBe("");
  });

  it("un número nacional recibe el indicativo por defecto de la tienda, sin el cero inicial", () => {
    expect(normalizePhone("600123456", "+34")).toBe("34600123456");
    expect(normalizePhone("0600123456", "+34")).toBe("34600123456");
    expect(normalizePhone("34600123456", "+34")).toBe("34600123456");
  });

  it("sin indicativo conocido no inventa uno", () => {
    expect(normalizePhone("600123456")).toBe("600123456");
  });
});

describe("paidAtUnix", () => {
  it("lee date_paid_gmt como UTC", () => {
    expect(paidAtUnix({ date_paid_gmt: "2026-09-27T15:05:00" })).toBe(
      Date.parse("2026-09-27T15:05:00Z") / 1000,
    );
  });

  it("sin fecha de pago, ahora", () => {
    const now = Date.parse("2026-10-01T00:00:00Z");
    expect(paidAtUnix({ date_paid_gmt: null }, now)).toBe(now / 1000);
  });
});

describe("buildPurchaseEvent", () => {
  const tracking = {
    consent: true,
    fbp: FBP,
    ip: "181.50.1.2",
    userAgent: "Mozilla/5.0",
    sourceUrl: "https://tienda.example/checkout/return?ref=1234",
  };

  it("arma el Purchase con el mismo event_id que el píxel y datos cifrados", () => {
    const event = buildPurchaseEvent(order(), tracking, "https://x/y", "+34")!;

    expect(event).toMatchObject({
      event_name: "Purchase",
      event_id: "purchase_1234",
      action_source: "website",
      event_source_url: tracking.sourceUrl,
    });
    expect(event.user_data).toMatchObject({
      client_ip_address: "181.50.1.2",
      client_user_agent: "Mozilla/5.0",
      fbp: FBP,
      em: [sha256Hex("ana@example.com")],
      ph: [sha256Hex("34600123456")],
    });
    expect(event.custom_data).toMatchObject({
      value: 95,
      currency: "EUR",
      order_id: "1234",
      content_ids: ["7"],
    });
  });

  it("nunca lleva el nombre del producto ni el correo en claro", () => {
    const json = JSON.stringify(buildPurchaseEvent(order(), tracking, "https://x/y"));

    expect(json).not.toContain("Camiseta secreta");
    expect(json).not.toContain("ana@example.com");
  });

  it("sin total válido no hay evento", () => {
    expect(
      buildPurchaseEvent(order({ total: "abc" }), tracking, "https://x/y"),
    ).toBeNull();
  });

  it("sin URL de origen en el pedido usa la de respaldo", () => {
    const event = buildPurchaseEvent(
      order(),
      { consent: true },
      "https://tienda.example/checkout/return?ref=1234",
    )!;

    expect(event.event_source_url).toBe(
      "https://tienda.example/checkout/return?ref=1234",
    );
  });
});

describe("sendPurchaseToMeta", () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    getSiteConfigMock.mockResolvedValue({
      brand: { url: "https://tienda.example" },
      auth: { phone_default_dial_code: "+34" },
      integrations: { ...INTEGRATIONS, meta_pixel_id: "123456789" },
    });
    getMetaCapiCredentialsMock.mockResolvedValue({
      accessToken: "EAAB-SECRETO",
      testEventCode: "",
    });
    wcFetchMock.mockResolvedValue({});
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ events_received: 1, fbtrace_id: "x" }), {
        status: 200,
      }),
    );
    vi.stubGlobal("fetch", fetchMock);
  });
  afterEach(() => vi.unstubAllGlobals());

  it("envía a la Graph API con el token en el CUERPO (no en la URL) y marca el pedido", async () => {
    expect(await sendPurchaseToMeta(order())).toBe("sent");

    const [url, init] = fetchMock.mock.calls[0]!;
    expect(String(url)).toBe("https://graph.facebook.com/v25.0/123456789/events");
    expect(String(url)).not.toContain("EAAB");
    const body = JSON.parse(String((init as RequestInit).body));
    expect(body.access_token).toBe("EAAB-SECRETO");
    expect(body.data).toHaveLength(1);
    expect(wcFetchMock).toHaveBeenCalledWith(
      "/orders/1234",
      expect.objectContaining({
        method: "PUT",
        body: {
          meta_data: [expect.objectContaining({ key: "_hwe_meta_capi_purchase_sent" })],
        },
      }),
    );
  });

  it("con código de prueba en el panel, lo manda", async () => {
    getMetaCapiCredentialsMock.mockResolvedValue({
      accessToken: "EAAB",
      testEventCode: "TEST123",
    });

    await sendPurchaseToMeta(order());

    expect(JSON.parse(String(fetchMock.mock.calls[0]![1].body)).test_event_code).toBe(
      "TEST123",
    );
  });

  it("sin «Aceptar» guardado en el pedido no envía nada", async () => {
    expect(await sendPurchaseToMeta(order({ meta_data: [] }))).toBe("no_consent");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("el efectivo no es una compra", async () => {
    expect(await sendPurchaseToMeta(order({ payment_method: "cod" }))).toBe(
      "not_a_purchase",
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("un pedido ya enviado no se reenvía (reintento de la pasarela)", async () => {
    const sent = order();
    sent.meta_data = [
      ...sent.meta_data!,
      { key: "_hwe_meta_capi_purchase_sent", value: "2026-09-27T15:06:00Z" },
    ];

    expect(await sendPurchaseToMeta(sent)).toBe("already_sent");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("sin token o sin píxel configurado no envía", async () => {
    getMetaCapiCredentialsMock.mockResolvedValue({
      accessToken: "",
      testEventCode: "",
    });
    expect(await sendPurchaseToMeta(order())).toBe("not_configured");

    getMetaCapiCredentialsMock.mockResolvedValue({
      accessToken: "EAAB",
      testEventCode: "",
    });
    getSiteConfigMock.mockResolvedValue({
      brand: { url: "https://tienda.example" },
      auth: { phone_default_dial_code: "" },
      integrations: { ...INTEGRATIONS, meta_pixel_id: "" },
    });
    expect(await sendPurchaseToMeta(order())).toBe("not_configured");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("si Meta rechaza, no marca el pedido (el reintento podrá enviarlo) y no lanza", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: { message: "Invalid token", code: 190 } }), {
        status: 400,
      }),
    );

    expect(await sendPurchaseToMeta(order())).toBe("failed");
    expect(wcFetchMock).not.toHaveBeenCalled();
  });

  it("un fallo de red no lanza", async () => {
    fetchMock.mockRejectedValue(new Error("ECONNRESET"));

    await expect(sendPurchaseToMeta(order())).resolves.toBe("failed");
  });
});
