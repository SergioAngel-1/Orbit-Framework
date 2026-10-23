import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { verifyCsrf } from "@/lib/security/csrf";
import { assertAllowedOrigin } from "@/lib/security/origin";
import { rateLimit } from "@/lib/security/rate-limit";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { POST } from "./route";

// POST /api/shipping/geocode: el buscador del mapa del checkout. Pasa por el guard real
// (Origin → CSRF → rate-limit); aquí solo se simulan sus dependencias externas.

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/security/csrf", () => ({ verifyCsrf: vi.fn() }));
vi.mock("@/lib/security/origin", () => ({ assertAllowedOrigin: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

const SESSION = { token: "jwt", userId: "7" };

function search(body: unknown, headers: Record<string, string> = {}) {
  return POST(
    new Request("https://tienda.example/api/shipping/geocode", {
      method: "POST",
      headers: { "content-type": "application/json", ...headers },
      body: typeof body === "string" ? body : JSON.stringify(body),
    }),
  );
}

function wpJson(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function siteConfig(overrides: { shipping?: boolean; loginRequired?: boolean } = {}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { shipping: overrides.shipping ?? true },
    ecommerce: { checkout_login_required: overrides.loginRequired ?? true },
  } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  siteConfig();
  vi.mocked(getSession).mockResolvedValue(SESSION as never);
  vi.mocked(assertAllowedOrigin).mockReturnValue(true);
  vi.mocked(verifyCsrf).mockResolvedValue(true);
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    limit: 10,
    remaining: 9,
    retryAfter: 0,
  });
  vi.mocked(wpFetch).mockResolvedValue(
    wpJson(200, { results: [{ lat: 4.65, lng: -74.08, label: "Calle 1" }] }),
  );
});

describe("POST /api/shipping/geocode", () => {
  it("con el módulo `shipping` apagado responde 404 y no llama a WordPress", async () => {
    siteConfig({ shipping: false });

    expect((await search({ query: "Calle 1" })).status).toBe(404);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("sin CSRF válido responde 403 y no llama a WordPress", async () => {
    vi.mocked(verifyCsrf).mockResolvedValue(false);

    expect((await search({ query: "Calle 1" })).status).toBe(403);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("con el límite agotado responde 429 y no llama a WordPress", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      limit: 10,
      remaining: 0,
      retryAfter: 42,
    });

    const res = await search({ query: "Calle 1" });

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("42");
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("el límite de 10 por 60 s es por usuario cuando hay sesión", async () => {
    await search({ query: "Calle 1" });

    const [identifier, limit, windowSeconds] = vi.mocked(rateLimit).mock.calls[0];
    expect(identifier.startsWith("shipping_geocode:")).toBe(true);
    expect(identifier.endsWith(":7")).toBe(true);
    expect([limit, windowSeconds]).toEqual([10, 60]);
  });

  it("sin sesión con compra de invitado apagada responde 401 y no llama a WordPress", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    siteConfig({ loginRequired: true });

    expect((await search({ query: "Calle 1" })).status).toBe(401);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("sin sesión con compra de invitado admitida, busca y limita por IP", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    siteConfig({ loginRequired: false });

    const res = await search(
      { query: "Calle 1" },
      { "x-forwarded-for": "203.0.113.9" },
    );

    expect(res.status).toBe(200);
    const [identifier] = vi.mocked(rateLimit).mock.calls[0];
    expect(identifier.startsWith("shipping_geocode:")).toBe(true);
    expect(identifier.endsWith(":7")).toBe(false);
  });

  it("una consulta vacía o de más de 200 caracteres es un 422 sin llamar a WordPress", async () => {
    expect((await search({ query: "   " })).status).toBe(422);
    expect((await search({ query: "x".repeat(201) })).status).toBe(422);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un cuerpo que no es JSON es un 400", async () => {
    expect((await search("{no json")).status).toBe(400);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("devuelve los candidatos de WordPress tal cual el contrato (`{ results }`)", async () => {
    const res = await search({ query: "  Calle 1  " });

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      results: [{ lat: 4.65, lng: -74.08, label: "Calle 1" }],
    });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(JSON.parse(String(vi.mocked(wpFetch).mock.calls[0][1]?.body))).toEqual({
      query: "Calle 1",
    });
  });

  it("sin resultados responde 422 con código y texto localizado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpJson(422, { code: "no_results" }));

    const res = await search({ query: "Nada" });
    const body = await res.json();

    expect(res.status).toBe(422);
    expect(body.code).toBe("no_results");
    expect(typeof body.error).toBe("string");
    expect(body.error.length).toBeGreaterThan(0);
  });

  it("con el backoff de Nominatim responde 503 con su código", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpJson(503, { code: "geocoding_backoff" }));

    const res = await search({ query: "Calle 1" });

    expect(res.status).toBe(503);
    expect((await res.json()).code).toBe("geocoding_backoff");
  });

  it("si WordPress no responde, 503 sin detalles del proveedor", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("ECONNREFUSED"));

    const res = await search({ query: "Calle 1" });
    const text = JSON.stringify(await res.json());

    expect(res.status).toBe(503);
    expect(text).not.toContain("ECONNREFUSED");
  });
});
