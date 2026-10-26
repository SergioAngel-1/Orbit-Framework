import { beforeEach, describe, expect, it, vi } from "vitest";

// El enlace se fija sin mapa de rutas públicas: los remapeos de una instancia se
// prueban en `email-links.public-paths.test.ts` y aquí solo estorbarían.
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

import { POST } from "./route";

// ============================================================================
//  POST /api/auth/forgot-password: el correo sale en el idioma del visitante y
//  su enlace ya va localizado (WordPress no sabe en qué idioma navega).
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const fetchMock = vi.fn();

const call = (body: unknown) =>
  POST(
    new Request("https://tienda.example/api/auth/forgot-password", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );

const sent = () => JSON.parse(String(fetchMock.mock.calls[0][1].body));

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal("fetch", fetchMock);
  process.env.NEXT_PUBLIC_SITE_URL = "https://tienda.example";
  process.env.HWE_REVALIDATION_SECRET = "secreto-interno-de-pruebas-0123456789";
  fetchMock.mockResolvedValue(Response.json({ ok: true }));
});

describe("POST /api/auth/forgot-password", () => {
  it("manda a WordPress el idioma y la URL localizada del enlace", async () => {
    const res = await call({ email: "ana@example.com", locale: "en" });

    expect(res.status).toBe(200);
    expect(sent()).toEqual({
      user_login: "ana@example.com",
      frontend_url: "https://tienda.example",
      reset_url: "https://tienda.example/en/reset-password",
      locale: "en",
    });
  });

  it("un idioma que no es del sitio cae al por defecto", async () => {
    await call({ email: "ana@example.com", locale: "xx" });

    expect(sent()).toMatchObject({
      reset_url: "https://tienda.example/reset-password",
      locale: "es",
    });
  });
});
