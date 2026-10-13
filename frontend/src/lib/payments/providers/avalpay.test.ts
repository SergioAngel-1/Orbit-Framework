import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearPaymentSettingsCache } from "../credentials";
import { AvalPayProvider, avalPaySignature } from "./avalpay";

// ============================================================================
//  Aval Pay (PlacetoPay): la notificación solo firma requestId + estado + fecha.
//
//  Regresión de seguridad: la `reference` del cuerpo NO va firmada y se usaba
//  para elegir el pedido: reenviar un «aprobado» legítimo con la referencia de
//  otro pedido del mismo importe lo daba por pagado. Ahora referencia, estado e
//  importe salen de la consulta autoritativa de la sesión.
// ============================================================================

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => ({ brand: { name: "Tienda" } }),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SECRET = "secreto-avalpay";
const aval = new AvalPayProvider();
const fetchMock = vi.fn();

function notification(
  overrides: Record<string, unknown> = {},
  algorithm: "sha256" | "sha1" = "sha256",
) {
  const base = {
    requestId: 777,
    reference: "42",
    status: { status: "APPROVED", date: "2026-10-12T10:00:00-05:00" },
    ...overrides,
  };
  const status = base.status as { status: string; date: string };
  const hash = avalPaySignature(
    String(base.requestId),
    status.status,
    status.date,
    SECRET,
    algorithm,
  );
  return JSON.stringify({
    ...base,
    signature: algorithm === "sha256" ? `sha256:${hash}` : hash,
  });
}

function sessionQuery(reference = "42", total = 59.99, status = "APPROVED") {
  return Response.json({
    status: { status },
    request: { payment: { reference, amount: { total, currency: "COP" } } },
  });
}

beforeEach(() => {
  clearPaymentSettingsCache();
  vi.stubEnv("HWE_REVALIDATION_SECRET", "");
  vi.stubEnv("AVALPAY_LOGIN", "login");
  vi.stubEnv("AVALPAY_SECRET_KEY", SECRET);
  vi.stubEnv("AVALPAY_BASE_URL", "https://checkout.test.example/");
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("verifyWebhook", () => {
  it("con la firma válida, confirma referencia, estado e importe con la consulta de la sesión", async () => {
    fetchMock.mockResolvedValue(sessionQuery());

    const result = await aval.verifyWebhook(notification());

    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://checkout.test.example/api/session/777",
    );
    expect(result).toMatchObject({
      valid: true,
      reference: "42",
      status: "approved",
      amountMinor: 5999,
      currency: "COP",
    });
  });

  it("acepta también la firma SHA-1 de legado", async () => {
    fetchMock.mockResolvedValue(sessionQuery());
    expect((await aval.verifyWebhook(notification({}, "sha1"))).valid).toBe(true);
  });

  it("una referencia que no es la de la sesión se rechaza (no está firmada)", async () => {
    fetchMock.mockResolvedValue(sessionQuery("42"));

    const result = await aval.verifyWebhook(notification({ reference: "99" }));

    expect(result.valid).toBe(false);
  });

  it("el estado lo dice la consulta, no la notificación", async () => {
    fetchMock.mockResolvedValue(sessionQuery("42", 59.99, "REJECTED"));
    expect((await aval.verifyWebhook(notification())).status).toBe("declined");
  });

  it("una firma que no cuadra se rechaza sin consultar nada", async () => {
    const body = JSON.parse(notification());
    body.status.status = "REJECTED";
    expect((await aval.verifyWebhook(JSON.stringify(body))).valid).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("si la consulta falla, no se da nada por válido", async () => {
    fetchMock.mockResolvedValue(new Response("boom", { status: 500 }));
    expect((await aval.verifyWebhook(notification())).valid).toBe(false);
  });
});

describe("isSandbox", () => {
  it("la URL base de pruebas es sandbox", async () => {
    expect(await aval.isSandbox()).toBe(true);
  });
});
