import { beforeEach, describe, expect, it, vi } from "vitest";
import { verify } from "otplib";
import { FakeRedis } from "../../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { requireSession } from "@/lib/auth/session";
import { setPending2faSecret } from "@/lib/auth/two-factor-pending";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/2fa/disable — solo lo que atañe al secreto PENDIENTE.
//
//  Si quedó un intento de alta abandonado a medias (POST /2fa/setup llamado de
//  nuevo con 2FA ya activo), su secreto no debe seguir vivo tras desactivar.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("otplib", () => ({ verify: vi.fn() }));

let redis: FakeRedis;

const request = (body: unknown) =>
  new Request("https://tienda.example/api/auth/2fa/disable", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

/** WordPress: lectura del secreto activo y su borrado (PUT con `secret: null`). */
function stubWordPress({ putOk = true }: { putOk?: boolean } = {}) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (_url: string, init?: RequestInit) => {
      if (init?.method === "PUT")
        return new Response("{}", { status: putOk ? 200 : 500 });
      return Response.json({ secret: "SECRETO-ACTIVO" });
    }) as unknown as typeof fetch,
  );
}

beforeEach(async () => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
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
    expect(redis.store.get("2fa_pending:7")).toBe("SECRETO-PENDIENTE");
  });

  it("si WordPress no llega a desactivarlo responde 502 y el pendiente sigue", async () => {
    stubWordPress({ putOk: false });

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
