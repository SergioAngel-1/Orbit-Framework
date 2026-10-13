import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import crypto from "node:crypto";
import { clearPaymentSettingsCache } from "../credentials";
import { WompiProvider } from "./wompi";

// ============================================================================
//  Wompi: firma de integridad del Web Checkout y verificación de eventos.
// ============================================================================

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const EVENTS = "prod_events_secreto-de-prueba";
const wompi = new WompiProvider();

function sha(value: string) {
  return crypto.createHash("sha256").update(value).digest("hex");
}

function event(
  overrides: {
    properties?: string[];
    environment?: string;
    tx?: Record<string, unknown>;
  } = {},
) {
  const tx = {
    id: "1234-1610641025-49201",
    status: "APPROVED",
    amount_in_cents: 4490000,
    reference: "42",
    currency: "COP",
    ...overrides.tx,
  };
  const properties = overrides.properties ?? [
    "transaction.id",
    "transaction.status",
    "transaction.amount_in_cents",
  ];
  const timestamp = 1530291411;
  const values = properties
    .map((p) =>
      String((tx as Record<string, unknown>)[p.replace("transaction.", "")] ?? ""),
    )
    .join("");
  return JSON.stringify({
    event: "transaction.updated",
    data: { transaction: tx },
    environment: overrides.environment ?? "prod",
    signature: { properties, checksum: sha(values + timestamp + EVENTS).toUpperCase() },
    timestamp,
  });
}

/** Respuesta de `GET /v1/transactions/{id}` de Wompi con la transacción del evento. */
function apiTransaction(overrides: Record<string, unknown> = {}, status = 200) {
  return new Response(
    JSON.stringify({
      data: {
        id: "1234-1610641025-49201",
        status: "APPROVED",
        amount_in_cents: 4490000,
        reference: "42",
        currency: "COP",
        ...overrides,
      },
    }),
    { status },
  );
}

const fetchMock = vi.fn();

beforeEach(() => {
  fetchMock.mockReset();
  fetchMock.mockImplementation(async () => apiTransaction());
  vi.stubGlobal("fetch", fetchMock);
  clearPaymentSettingsCache();
  vi.stubEnv("HWE_REVALIDATION_SECRET", "");
  vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_prod_llave");
  vi.stubEnv(
    "WOMPI_INTEGRITY_SECRET",
    "prod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6",
  );
  vi.stubEnv("WOMPI_EVENTS_SECRET", EVENTS);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("createCheckout", () => {
  it("firma la integridad como la documentación de Wompi (vector oficial)", async () => {
    const result = await wompi.createCheckout({
      reference: "sk8-438k4-xmxm392-sn2m",
      amountMinor: 2490000,
      currency: "COP",
      customer: {
        email: "ana@example.com",
        fullName: "Ana Pérez",
        phone: "+573001234567",
      },
      returnUrl: "https://tienda.example/checkout/return",
    });

    const url = new URL(result.redirectUrl!);
    expect(url.origin + url.pathname).toBe("https://checkout.wompi.co/p/");
    expect(url.searchParams.get("signature:integrity")).toBe(
      "37c8407747e595535433ef8f6a811d853cd943046624a0ec04662b17bbf33bf5",
    );
    expect(url.searchParams.get("customer-data:phone-number")).toBe("3001234567");
  });

  it("sin credenciales falla con un error claro", async () => {
    vi.stubEnv("WOMPI_INTEGRITY_SECRET", "");
    await expect(
      wompi.createCheckout({
        reference: "1",
        amountMinor: 1,
        currency: "COP",
        customer: { email: "", fullName: "" },
        returnUrl: "https://tienda.example/",
      }),
    ).rejects.toMatchObject({ code: "provider_misconfigured" });
  });
});

describe("verifyWebhook", () => {
  it("un evento firmado se acepta con importe, moneda y unidades", async () => {
    const result = await wompi.verifyWebhook(event(), new Headers());

    expect(result).toMatchObject({
      valid: true,
      reference: "42",
      status: "approved",
      amountMinor: 4490000,
      currency: "COP",
      minorUnit: 2,
    });
  });

  it("un checksum que no cuadra se rechaza", async () => {
    const body = JSON.parse(event());
    body.data.transaction.amount_in_cents = 1;
    expect((await wompi.verifyWebhook(JSON.stringify(body), new Headers())).valid).toBe(
      false,
    );
  });

  it("si la firma no cubre el importe, se rechaza aunque el checksum cuadre", async () => {
    const body = event({ properties: ["transaction.id", "transaction.status"] });
    expect((await wompi.verifyWebhook(body, new Headers())).valid).toBe(false);
  });

  it("un evento de sandbox no vale con una llave de producción", async () => {
    expect(
      (await wompi.verifyWebhook(event({ environment: "test" }), new Headers())).valid,
    ).toBe(false);
  });

  it("sin secreto de eventos no se acepta nada", async () => {
    vi.stubEnv("WOMPI_EVENTS_SECRET", "");
    expect((await wompi.verifyWebhook(event(), new Headers())).valid).toBe(false);
  });
});

// La `reference` NO va entre las propiedades firmadas por Wompi (su documentación
// firma id, estado e importe): con la firma válida se consulta la transacción a la
// API de Wompi y de ESA respuesta sale la referencia que selecciona el pedido.
describe("verifyWebhook: conciliación con la API de Wompi", () => {
  it("consulta la transacción en producción y toma de ahí la referencia", async () => {
    const result = await wompi.verifyWebhook(event(), new Headers());

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(String(fetchMock.mock.calls[0]![0])).toBe(
      "https://production.wompi.co/v1/transactions/1234-1610641025-49201",
    );
    expect(result).toMatchObject({ valid: true, reference: "42" });
  });

  it("con una llave de sandbox consulta el sandbox", async () => {
    vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_test_llave");
    clearPaymentSettingsCache();

    await wompi.verifyWebhook(event({ environment: "test" }), new Headers());

    expect(String(fetchMock.mock.calls[0]![0])).toBe(
      "https://sandbox.wompi.co/v1/transactions/1234-1610641025-49201",
    );
  });

  it("una referencia ajena en un evento con firma válida se rechaza (PAY-01)", async () => {
    // El evento trae la referencia del pedido de OTRO, pero la transacción firmada
    // es la propia: la API de Wompi dice que su referencia es «42».
    const forged = event({ tx: { reference: "99" } });

    const result = await wompi.verifyWebhook(forged, new Headers());

    expect(result.valid).toBe(false);
  });

  it("un importe que la API no confirma se rechaza", async () => {
    fetchMock.mockImplementation(async () => apiTransaction({ amount_in_cents: 100 }));

    expect((await wompi.verifyWebhook(event(), new Headers())).valid).toBe(false);
  });

  it("el estado sale de la API, no del cuerpo del evento", async () => {
    fetchMock.mockImplementation(async () => apiTransaction({ status: "VOIDED" }));

    const result = await wompi.verifyWebhook(event(), new Headers());

    expect(result).toMatchObject({ valid: true, status: "voided" });
  });

  it("una transacción que Wompi no conoce (404) se rechaza", async () => {
    fetchMock.mockImplementation(async () => apiTransaction({}, 404));

    expect((await wompi.verifyWebhook(event(), new Headers())).valid).toBe(false);
  });

  it("si Wompi no responde, falla con 502 para que reintente (no se descarta el cobro)", async () => {
    fetchMock.mockRejectedValue(new Error("timeout"));
    await expect(wompi.verifyWebhook(event(), new Headers())).rejects.toMatchObject({
      status: 502,
    });

    fetchMock.mockImplementation(async () => apiTransaction({}, 503));
    await expect(wompi.verifyWebhook(event(), new Headers())).rejects.toMatchObject({
      status: 502,
    });
  });

  it("con la firma inválida no se llama a Wompi", async () => {
    const body = JSON.parse(event());
    body.data.transaction.amount_in_cents = 1;

    await wompi.verifyWebhook(JSON.stringify(body), new Headers());

    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("isSandbox", () => {
  it("se deduce de la llave pública", async () => {
    expect(await wompi.isSandbox()).toBe(false);
    clearPaymentSettingsCache();
    vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_test_llave");
    expect(await wompi.isSandbox()).toBe(true);
  });
});
