import { beforeEach, describe, expect, it, vi } from "vitest";
import { verify } from "otplib";
import { FakeRedis } from "../../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { requireSession } from "@/lib/auth/session";
import { setPending2faSecret } from "@/lib/auth/two-factor-pending";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/2fa/disable.
//
//  Dos cosas:
//   1. Lo que atañe al secreto PENDIENTE: si quedó un intento de alta abandonado
//      (POST /2fa/setup llamado de nuevo con 2FA ya activo), su secreto no debe
//      seguir vivo tras desactivar.
//   2. La prueba de posesión llega a WordPress. El endpoint `2fa-secret` de WP es
//      alcanzable sin pasar por el BFF y exige su propia prueba (`code` o
//      `recovery_code`) para desactivar: si el BFF no la reenvía, no se podría
//      desactivar el 2FA. Con un código de recuperación, WP es quien lo verifica
//      Y lo consume, una sola vez, en ese mismo PUT: si el BFF lo consumiera antes
//      (`2fa-recovery/verify`), el PUT lo encontraría ya gastado y fallaría.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("otplib", () => ({ verify: vi.fn() }));

let redis: FakeRedis;
let wpCalls: Array<{ url: string; method: string; body: unknown }>;

const request = (body: unknown) =>
  new Request("https://tienda.example/api/auth/2fa/disable", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

/**
 * WordPress: lectura del secreto activo y su borrado (PUT con `secret: null`).
 * `putStatus` es lo que responde el PUT (403 = WP rechaza la prueba).
 */
function stubWordPress({ putStatus = 200 }: { putStatus?: number } = {}) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      wpCalls.push({
        url,
        method,
        body: typeof init?.body === "string" ? JSON.parse(init.body) : null,
      });
      if (method === "PUT") return new Response("{}", { status: putStatus });
      return Response.json({ secret: "SECRETO-ACTIVO" });
    }) as unknown as typeof fetch,
  );
}

const putCalls = () => wpCalls.filter((c) => c.method === "PUT");

beforeEach(async () => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  wpCalls = [];
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(verify).mockResolvedValue({ valid: true } as never);
  stubWordPress();
  await setPending2faSecret("7", "SECRETO-PENDIENTE");
});

describe("desactivar 2FA y el secreto pendiente", () => {
  it("tras desactivar con éxito, invalida el secreto pendiente", async () => {
    const res = await POST(request({ code: "123456" }));

    expect(res.status).toBe(200);
    expect(redis.store.has("2fa_pending:7")).toBe(false);
  });

  it("si el código es incorrecto no desactiva nada y el pendiente sigue", async () => {
    vi.mocked(verify).mockResolvedValue({ valid: false } as never);

    const res = await POST(request({ code: "000000" }));

    expect(res.status).toBe(401);
    expect(putCalls()).toHaveLength(0);
    expect(redis.store.get("2fa_pending:7")).toBe("SECRETO-PENDIENTE");
  });

  it("si WordPress no llega a desactivarlo responde 502 y el pendiente sigue", async () => {
    stubWordPress({ putStatus: 500 });

    const res = await POST(request({ code: "123456" }));

    expect(res.status).toBe(502);
    expect(redis.store.get("2fa_pending:7")).toBe("SECRETO-PENDIENTE");
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await POST(request({ code: "123456" }));

    expect(res.status).toBe(401);
  });
});

describe("la prueba de posesión llega a WordPress", () => {
  it("con un código TOTP, el PUT lo lleva para que WP lo verifique también", async () => {
    await POST(request({ code: "123456" }));

    expect(putCalls()).toHaveLength(1);
    expect(putCalls()[0]!.body).toEqual({ secret: null, code: "123456" });
  });

  it("con un código de recuperación, WP lo verifica y consume UNA sola vez, en el PUT", async () => {
    const res = await POST(request({ recoveryCode: "aaaaa-bbbbb" }));

    expect(res.status).toBe(200);
    expect(putCalls()).toHaveLength(1);
    expect(putCalls()[0]!.body).toEqual({ secret: null, recovery_code: "aaaaa-bbbbb" });
    // El BFF no lo consume antes: el PUT lo encontraría ya gastado y fallaría.
    expect(wpCalls.some((c) => c.url.includes("2fa-recovery/verify"))).toBe(false);
  });

  it("un código de recuperación que WP rechaza (403) se responde 401 y no desactiva", async () => {
    stubWordPress({ putStatus: 403 });

    const res = await POST(request({ recoveryCode: "aaaaa-bbbbb" }));
    const body = await res.json();

    expect(res.status).toBe(401);
    expect(body.error).toMatch(/recuperación/i);
    expect(redis.store.get("2fa_pending:7")).toBe("SECRETO-PENDIENTE");
  });

  it("un código TOTP que WP rechaza (403) se responde 401", async () => {
    stubWordPress({ putStatus: 403 });

    const res = await POST(request({ code: "123456" }));

    expect(res.status).toBe(401);
  });

  it("sin ninguna prueba no llama a WordPress para desactivar", async () => {
    const res = await POST(request({}));

    expect(res.status).toBe(422);
    expect(putCalls()).toHaveLength(0);
  });

  it("un recoveryCode que no es texto no se reenvía", async () => {
    const res = await POST(request({ recoveryCode: { $ne: "" } }));

    expect(res.status).toBe(422);
    expect(putCalls()).toHaveLength(0);
  });
});

// El cuerpo se leía con un cast manual: un cuerpo `null` reventaba con un
// TypeError (500) y los errores de validación no decían qué campo fallaba. Ahora
// lo valida un schema Zod y todo lo inválido es 422 con el detalle.
describe("validación del cuerpo", () => {
  it.each([
    ["sin ninguna prueba", {}],
    ["código con menos de 6 dígitos", { code: "12345" }],
    ["código con letras", { code: "12345a" }],
    ["código numérico en lugar de texto", { code: 123456 }],
    ["código de recuperación solo espacios", { recoveryCode: "   " }],
    ["código de recuperación que no es texto", { recoveryCode: { $ne: "" } }],
  ])("%s: 422 con el detalle y sin llamar a WordPress", async (_caso, body) => {
    const res = await POST(request(body));
    const json = await res.json();

    expect(res.status).toBe(422);
    expect(json.details).toBeDefined();
    expect(wpCalls).toHaveLength(0);
  });

  it("un cuerpo `null` es 422 y no revienta con un TypeError", async () => {
    const res = await POST(request(null));

    expect(res.status).toBe(422);
    expect(wpCalls).toHaveLength(0);
  });

  it("un JSON ilegible sigue siendo 400", async () => {
    const res = await POST(
      new Request("https://tienda.example/api/auth/2fa/disable", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: "{no es json",
      }),
    );

    expect(res.status).toBe(400);
  });

  it("con código TOTP y de recuperación a la vez, manda el de recuperación", async () => {
    const res = await POST(request({ code: "123456", recoveryCode: "  aaaaa-bbbbb " }));

    expect(res.status).toBe(200);
    expect(putCalls()[0]!.body).toEqual({ secret: null, recovery_code: "aaaaa-bbbbb" });
  });
});
