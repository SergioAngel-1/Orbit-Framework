import { beforeEach, describe, expect, it, vi } from "vitest";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/verify-email.
//
//  El cuerpo se leía con un cast manual: un cuerpo `null` reventaba con un
//  TypeError (500) y un token desmesurado se reenviaba tal cual a WordPress.
//  Ahora lo valida un schema Zod y todo lo inválido es 422 con el detalle, sin
//  llamar a WordPress.
//
//  Y NO exige sesión: el enlace del correo se abre muchas veces en otro
//  navegador o dispositivo; WordPress identifica al usuario por el token.
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const TOKEN = "a".repeat(64);

const request = (body: unknown) =>
  new Request("https://tienda.example/api/auth/verify-email", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
  vi.clearAllMocks();
  fetchMock = vi.fn(async () => new Response("{}", { status: 200 }));
  vi.stubGlobal("fetch", fetchMock);
});

describe("validación del cuerpo", () => {
  it.each([
    ["token vacío", { token: "" }],
    ["sin token", {}],
    ["token numérico", { token: 123 }],
    ["token que es una lista", { token: ["a"] }],
    ["token desmesurado", { token: "a".repeat(201) }],
    ["token que no es el de hwe-auth", { token: "z".repeat(64) }],
  ])("%s: 422 con el detalle y sin llamar a WordPress", async (_caso, body) => {
    const res = await POST(request(body));
    const json = await res.json();

    expect(res.status).toBe(422);
    expect(json.details).toHaveProperty("token");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("un cuerpo `null` es 422 y no revienta con un TypeError", async () => {
    const res = await POST(request(null));

    expect(res.status).toBe(422);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("un JSON ilegible sigue siendo 400", async () => {
    const res = await POST(
      new Request("https://tienda.example/api/auth/verify-email", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: "{no es json",
      }),
    );

    expect(res.status).toBe(400);
  });
});

describe("comportamiento válido", () => {
  it("reenvía el token a WordPress sin necesitar sesión", async () => {
    const res = await POST(request({ token: TOKEN }));

    expect(res.status).toBe(200);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toContain("/wp-json/hwe/v1/auth/verify-email");
    expect(JSON.parse(init.body as string)).toEqual({ token: TOKEN });
    expect((init.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it("un token que WordPress rechaza es 400 con su mensaje", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: "Token caducado." }), { status: 400 }),
    );

    const res = await POST(request({ token: TOKEN }));

    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe("Token caducado.");
  });

  it("si WordPress falla (no un 400), responde 502", async () => {
    fetchMock.mockResolvedValue(new Response("boom", { status: 500 }));

    expect((await POST(request({ token: TOKEN }))).status).toBe(502);
  });
});
