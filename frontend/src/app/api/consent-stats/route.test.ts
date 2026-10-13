import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { readConsentStats, recordConsentEvent } from "@/lib/analytics/consent-stats";
import { GET, POST } from "./route";

// /api/consent-stats: totales anónimos de la respuesta al banner. POST lo manda
// el navegador (sin CSRF); GET lo pide WordPress con el secreto interno para su
// pantalla «Consentimientos de cookies». Del módulo `consent-log`.

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/analytics/consent-stats", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/analytics/consent-stats")>()),
  recordConsentEvent: vi.fn(),
  readConsentStats: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SECRET = "dummy-ci-revalidation-secret-24-chars";

const post = (body: unknown) =>
  new Request("https://tienda.example/api/consent-stats", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
const get = (secret?: string, query = "") =>
  new Request(`https://tienda.example/api/consent-stats${query}`, {
    headers: secret ? { "X-HWE-Internal-Secret": secret } : {},
  });

const modules = (enabled: boolean) =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "consent-log": enabled },
  } as never);

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubEnv("HWE_REVALIDATION_SECRET", SECRET);
  modules(true);
  vi.mocked(recordConsentEvent).mockResolvedValue(true);
  vi.mocked(readConsentStats).mockResolvedValue({ days: [], totals: {} });
});

describe("POST", () => {
  it("cuenta el evento y responde 204", async () => {
    const res = await POST(post({ event: "shown", variant: "bar" }));

    expect(res.status).toBe(204);
    expect(recordConsentEvent).toHaveBeenCalledWith("shown", "bar");
  });

  it("sin Redis también 204: el navegador no tiene nada que hacer con el fallo", async () => {
    vi.mocked(recordConsentEvent).mockResolvedValue(false);

    expect((await POST(post({ event: "accepted", variant: "bar" }))).status).toBe(204);
  });

  it("un evento inválido es un 400", async () => {
    expect((await POST(post({ event: "visto", variant: "bar" }))).status).toBe(400);
  });

  it("con el módulo apagado no cuenta: 404", async () => {
    modules(false);

    expect((await POST(post({ event: "shown", variant: "bar" }))).status).toBe(404);
    expect(recordConsentEvent).not.toHaveBeenCalled();
  });
});

describe("GET (informe para WordPress)", () => {
  it("sin el secreto interno es un 401", async () => {
    expect((await GET(get())).status).toBe(401);
    expect(readConsentStats).not.toHaveBeenCalled();
  });

  it("con otro secreto también", async () => {
    expect((await GET(get("otro-secreto-cualquiera"))).status).toBe(401);
  });

  it("con el secreto devuelve el informe, con tope de días y zona válida", async () => {
    const res = await GET(get(SECRET, "?days=9999&tz=Mars/Olympus"));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toMatchObject({ period: 180, timeZone: "UTC" });
    expect(readConsentStats).toHaveBeenCalledWith(180, "UTC");
  });

  it("sin Redis es un 503, distinto de «sin datos»", async () => {
    vi.mocked(readConsentStats).mockResolvedValue(null);

    expect((await GET(get(SECRET))).status).toBe(503);
  });

  it("sin el secreto configurado en el servidor, falla cerrado", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "");

    expect((await GET(get("cualquiera"))).status).toBe(401);
  });
});
