import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { verifyCsrf } from "@/lib/security/csrf";
import { assertAllowedOrigin } from "@/lib/security/origin";
import { rateLimit } from "@/lib/security/rate-limit";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { POST } from "./route";

// POST /api/shipping/reverse-geocode: confirma con una dirección legible el pin que ya marcó el
// cliente. Las coordenadas se validan aquí: basura (NaN, cadenas, fuera de rango, 0,0) es un 422
// y WordPress ni se entera.

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/security/csrf", () => ({ verifyCsrf: vi.fn() }));
vi.mock("@/lib/security/origin", () => ({ assertAllowedOrigin: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

function reverse(body: unknown) {
  return POST(
    new Request("https://tienda.example/api/shipping/reverse-geocode", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );
}

function wpJson(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { shipping: true },
    ecommerce: { checkout_login_required: true },
  } as never);
  vi.mocked(getSession).mockResolvedValue({ token: "jwt", userId: "7" } as never);
  vi.mocked(assertAllowedOrigin).mockReturnValue(true);
  vi.mocked(verifyCsrf).mockResolvedValue(true);
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    limit: 10,
    remaining: 9,
    retryAfter: 0,
  });
  vi.mocked(wpFetch).mockResolvedValue(wpJson(200, { label: "Calle 1, Bogotá" }));
});

describe("POST /api/shipping/reverse-geocode", () => {
  it("con el módulo apagado responde 404 y no llama a WordPress", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      modules: { shipping: false },
      ecommerce: { checkout_login_required: true },
    } as never);

    expect((await reverse({ lat: 4.65, lng: -74.08 })).status).toBe(404);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("sin CSRF válido responde 403", async () => {
    vi.mocked(verifyCsrf).mockResolvedValue(false);

    expect((await reverse({ lat: 4.65, lng: -74.08 })).status).toBe(403);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("con el límite agotado responde 429", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      limit: 10,
      remaining: 0,
      retryAfter: 30,
    });

    expect((await reverse({ lat: 4.65, lng: -74.08 })).status).toBe(429);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("sin sesión con login obligatorio responde 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    expect((await reverse({ lat: 4.65, lng: -74.08 })).status).toBe(401);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it.each([
    ["0,0 (valor por defecto de un mapa sin marcar)", { lat: 0, lng: 0 }],
    ["latitud fuera de rango", { lat: 91, lng: -74 }],
    ["longitud fuera de rango", { lat: 4.65, lng: 181 }],
    ["coordenadas como cadenas", { lat: "4.65", lng: "-74.08" }],
    ["falta la longitud", { lat: 4.65 }],
    ["coordenadas nulas", { lat: null, lng: null }],
  ])("coordenadas basura (%s) son un 422 y no llaman a WordPress", async (_, body) => {
    const res = await reverse(body);

    expect(res.status).toBe(422);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("unas coordenadas válidas devuelven la etiqueta y solo ella", async () => {
    const res = await reverse({ lat: 4.65, lng: -74.08 });

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ label: "Calle 1, Bogotá" });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(JSON.parse(String(vi.mocked(wpFetch).mock.calls[0][1]?.body))).toEqual({
      lat: 4.65,
      lng: -74.08,
    });
  });

  it("si WordPress no encuentra dirección, 422 con código", async () => {
    vi.mocked(wpFetch).mockResolvedValue(wpJson(422, { code: "no_results" }));

    const res = await reverse({ lat: 4.65, lng: -74.08 });

    expect(res.status).toBe(422);
    expect((await res.json()).code).toBe("no_results");
  });
});
