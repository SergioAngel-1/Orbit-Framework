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

beforeEach(() => {
  clearPaymentSettingsCache();
  vi.stubEnv("HWE_REVALIDATION_SECRET", "");
  vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_prod_llave");
  vi.stubEnv(
    "WOMPI_INTEGRITY_SECRET",
    "prod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6",
  );
  vi.stubEnv("WOMPI_EVENTS_SECRET", EVENTS);
});

afterEach(() => vi.unstubAllEnvs());

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

describe("isSandbox", () => {
  it("se deduce de la llave pública", async () => {
    expect(await wompi.isSandbox()).toBe(false);
    clearPaymentSettingsCache();
    vi.stubEnv("WOMPI_PUBLIC_KEY", "pub_test_llave");
    expect(await wompi.isSandbox()).toBe(true);
  });
});
