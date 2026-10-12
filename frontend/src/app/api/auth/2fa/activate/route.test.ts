import { beforeEach, describe, expect, it, vi } from "vitest";
import { verify } from "otplib";
import { FakeRedis } from "../../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { requireSession } from "@/lib/auth/session";
import { isEmailVerified } from "@/lib/auth/email-verified";
import { setPending2faSecret } from "@/lib/auth/two-factor-pending";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/2fa/activate.
//
//  Orden de las comprobaciones (importa): sesión → email verificado → TOTP.
//  La verificación TOTP es la parte cara, así que no se paga para quien no tiene
//  sesión ni email verificado. Al activar, el secreto deja de estar "pendiente":
//  GET /2fa/qr no debe seguir sirviéndolo como QR escaneable.
// ============================================================================

const SECRET = "JBSWY3DPEHPK3PXP";

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/auth/email-verified", () => ({ isEmailVerified: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("otplib", () => ({ verify: vi.fn() }));

let redis: FakeRedis;
let wpCalls: Array<{ url: string; init: RequestInit }>;

const request = (body: unknown) =>
  new Request("https://tienda.example/api/auth/2fa/activate", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: typeof body === "string" ? body : JSON.stringify(body),
  });

function stubWordPress(response: Response) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init: RequestInit) => {
      wpCalls.push({ url, init });
      return response;
    }) as unknown as typeof fetch,
  );
}

beforeEach(async () => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  wpCalls = [];
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(isEmailVerified).mockResolvedValue(true);
  vi.mocked(verify).mockResolvedValue({ valid: true } as never);
  stubWordPress(Response.json({ recovery_codes: ["AAAA-1111", "BBBB-2222"] }));
  await setPending2faSecret("7", SECRET);
});

describe("orden de las comprobaciones", () => {
  it("un JSON inválido responde 400 sin tocar la sesión", async () => {
    const res = await POST(request("{no es json"));

    expect(res.status).toBe(400);
    expect(requireSession).not.toHaveBeenCalled();
  });

  it("un cuerpo con formato inválido responde 422 sin tocar la sesión", async () => {
    const res = await POST(request({ secret: SECRET, code: "12" }));

    expect(res.status).toBe(422);
    expect(requireSession).not.toHaveBeenCalled();
    expect(verify).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401 y NO verifica el TOTP", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(401);
    expect(verify).not.toHaveBeenCalled();
    expect(wpCalls).toHaveLength(0);
  });

  it("con el email sin verificar responde 403 y NO verifica el TOTP", async () => {
    vi.mocked(isEmailVerified).mockResolvedValue(false);

    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(403);
    expect(verify).not.toHaveBeenCalled();
    expect(wpCalls).toHaveLength(0);
  });

  it("un código TOTP incorrecto responde 400, no llama a WordPress y conserva el pendiente", async () => {
    vi.mocked(verify).mockResolvedValue({ valid: false } as never);

    const res = await POST(request({ secret: SECRET, code: "000000" }));

    expect(res.status).toBe(400);
    expect(wpCalls).toHaveLength(0);
    expect(redis.store.get("2fa_pending:7")).toBe(SECRET);
  });
});

describe("activación correcta", () => {
  it("verifica el TOTP contra el secreto recibido", async () => {
    await POST(request({ secret: SECRET, code: "123456" }));

    expect(verify).toHaveBeenCalledWith({ token: "123456", secret: SECRET });
  });

  it("guarda el secreto en WordPress con el token de la sesión y el código (WP re-verifica)", async () => {
    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(200);
    expect(wpCalls).toHaveLength(1);
    expect(wpCalls[0]!.url).toContain("/wp-json/hwe/v1/auth/2fa-secret");
    expect(wpCalls[0]!.init.method).toBe("PUT");
    expect((wpCalls[0]!.init.headers as Record<string, string>).Authorization).toBe(
      "Bearer JWT",
    );
    expect(JSON.parse(String(wpCalls[0]!.init.body))).toEqual({
      secret: SECRET,
      code: "123456",
    });
  });

  it("entrega los códigos de recuperación una vez", async () => {
    const body = await (await POST(request({ secret: SECRET, code: "123456" }))).json();

    expect(body).toEqual({ ok: true, recovery_codes: ["AAAA-1111", "BBBB-2222"] });
  });

  it("INVALIDA el secreto pendiente: el QR ya no puede servirlo", async () => {
    await POST(request({ secret: SECRET, code: "123456" }));

    expect(redis.store.has("2fa_pending:7")).toBe(false);
  });

  it("si WordPress no guarda el secreto responde 502 y deja el pendiente para reintentar", async () => {
    stubWordPress(new Response("boom", { status: 500 }));

    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(502);
    expect(redis.store.get("2fa_pending:7")).toBe(SECRET);
  });

  it("si falla la red hacia WordPress responde 502 y deja el pendiente", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("fetch failed");
      }) as unknown as typeof fetch,
    );

    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(502);
    expect(redis.store.get("2fa_pending:7")).toBe(SECRET);
  });

  it("sin Redis la activación funciona igual (el secreto viaja en el cuerpo)", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    const res = await POST(request({ secret: SECRET, code: "123456" }));

    expect(res.status).toBe(200);
  });
});
