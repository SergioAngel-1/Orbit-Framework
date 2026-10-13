import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { recordConsentDecision } from "@/lib/analytics/consent-ledger";
import { POST } from "./route";

// POST /api/consent: la decisión del banner como PRUEBA. Es del módulo
// `consent-log` (apagado, 404); sin CSRF a propósito (el visitante de primera
// visita no tiene token), acotado por Origin y rate-limit.

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/analytics/consent-ledger", () => ({ recordConsentDecision: vi.fn() }));

const VALID = {
  decision: "accepted",
  action: "first",
  variant: "bar",
  locale: "es",
  path: "/",
  clientAt: "2026-10-13T10:00:00.000Z",
  services: ["ga4"],
  generation: 2,
};

const request = (body: unknown) =>
  new Request("https://tienda.example/api/consent", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

const modules = (enabled: boolean) =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "consent-log": enabled },
  } as never);

beforeEach(() => {
  vi.clearAllMocks();
  modules(true);
  vi.mocked(recordConsentDecision).mockResolvedValue({
    ok: true,
    receipt: "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c",
  });
});

describe("POST /api/consent", () => {
  it("registra la decisión y devuelve el comprobante sin cachear", async () => {
    const res = await POST(request(VALID));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      receipt: "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c",
    });
    expect(res.headers.get("cache-control")).toBe("no-store");
    expect(recordConsentDecision).toHaveBeenCalledWith(
      expect.any(Request),
      expect.objectContaining({ decision: "accepted", services: ["ga4"] }),
    );
  });

  it("con el módulo apagado la ruta no existe (404) y no toca nada", async () => {
    modules(false);

    const res = await POST(request(VALID));

    expect(res.status).toBe(404);
    expect(recordConsentDecision).not.toHaveBeenCalled();
  });

  it.each([
    ["decisión inventada", { ...VALID, decision: "quizas" }],
    ["idioma que el sitio no tiene", { ...VALID, locale: "xx" }],
    ["servicio desconocido", { ...VALID, services: ["facebook"] }],
    ["lista de servicios vacía", { ...VALID, services: [] }],
    ["variante inválida", { ...VALID, variant: "popup" }],
  ])("%s: 400 y no se registra", async (_name, body) => {
    const res = await POST(request(body));

    expect(res.status).toBe(400);
    expect(recordConsentDecision).not.toHaveBeenCalled();
  });

  it("sin nada que consentir (sin servicios configurados): 409", async () => {
    vi.mocked(recordConsentDecision).mockResolvedValue({
      ok: false,
      reason: "nothing_to_consent",
    });

    expect((await POST(request(VALID))).status).toBe(409);
  });

  it("si WordPress no lo guardó: 503 para que el navegador lo conserve en cola", async () => {
    vi.mocked(recordConsentDecision).mockResolvedValue({
      ok: false,
      reason: "unavailable",
    });

    const res = await POST(request(VALID));

    expect(res.status).toBe(503);
    expect(res.headers.get("cache-control")).toBe("no-store");
  });
});
