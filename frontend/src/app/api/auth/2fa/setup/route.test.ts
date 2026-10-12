import { beforeEach, describe, expect, it, vi } from "vitest";
import { FakeRedis } from "../../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { requireSession } from "@/lib/auth/session";
import { isEmailVerified } from "@/lib/auth/email-verified";
import { getSiteConfig } from "@/lib/config";
import { guardMutation } from "@/lib/api/guard";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/2fa/setup — genera el secreto TOTP y lo deja PENDIENTE en el
//  servidor para que GET /2fa/qr lo sirva por sesión.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ requireSession: vi.fn() }));
vi.mock("@/lib/auth/email-verified", () => ({ isEmailVerified: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let redis: FakeRedis;
const request = () =>
  new Request("https://tienda.example/api/auth/2fa/setup", { method: "POST" });

beforeEach(() => {
  vi.clearAllMocks();
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(isEmailVerified).mockResolvedValue(true);
  vi.mocked(getSiteConfig).mockResolvedValue({ brand: { name: "Mi Tienda" } } as never);
  vi.mocked(guardMutation).mockResolvedValue(null);
});

describe("POST /api/auth/2fa/setup", () => {
  it("devuelve un secreto base32 y el nombre del sitio de la config", async () => {
    const res = await POST(request());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.secret).toMatch(/^[A-Z2-7]{16,}$/);
    expect(body.site_name).toBe("Mi Tienda");
  });

  it("deja ese mismo secreto PENDIENTE en el servidor, por usuario", async () => {
    const body = await (await POST(request())).json();

    expect(redis.store.get("2fa_pending:7")).toBe(body.secret);
    expect(redis.ttls.get("2fa_pending:7")).toBeGreaterThan(0);
  });

  it("cada alta genera un secreto distinto y sustituye al pendiente anterior", async () => {
    const a = await (await POST(request())).json();
    const b = await (await POST(request())).json();

    expect(a.secret).not.toBe(b.secret);
    expect(redis.store.get("2fa_pending:7")).toBe(b.secret);
  });

  it("sin sesión responde 401 y no guarda nada", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await POST(request());

    expect(res.status).toBe(401);
    expect(redis.store.size).toBe(0);
  });

  it("con el email sin verificar responde 403, no revela ningún secreto y no guarda nada", async () => {
    vi.mocked(isEmailVerified).mockResolvedValue(false);

    const res = await POST(request());
    const text = await res.text();

    expect(res.status).toBe(403);
    expect(text).not.toContain("secret");
    expect(redis.store.size).toBe(0);
  });

  it("respeta el guard (Origin, CSRF y rate-limit) antes de hacer nada", async () => {
    vi.mocked(guardMutation).mockResolvedValue(
      Response.json({ error: "bloqueado" }, { status: 429 }) as never,
    );

    const res = await POST(request());

    expect(res.status).toBe(429);
    expect(requireSession).not.toHaveBeenCalled();
    expect(redis.store.size).toBe(0);
  });

  it("sin Redis el alta NO se rompe: devuelve el secreto para el código manual (sin QR)", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    const res = await POST(request());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.secret).toMatch(/^[A-Z2-7]{16,}$/);
  });

  it("con Redis caído el alta tampoco se rompe", async () => {
    redis.down();

    const res = await POST(request());

    expect(res.status).toBe(200);
  });

  it("sin nombre de marca usa el del framework, nunca una variable de entorno", async () => {
    vi.stubEnv("NEXT_PUBLIC_SITE_NAME", "NO-DEBE-USARSE");
    vi.mocked(getSiteConfig).mockResolvedValue({ brand: { name: "" } } as never);

    const body = await (await POST(request())).json();
    vi.unstubAllEnvs();

    expect(body.site_name).toBeTruthy();
    expect(body.site_name).not.toBe("NO-DEBE-USARSE");
  });
});
