import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextResponse } from "next/server";
import { guardMutation } from "@/lib/api/guard";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import type { SiteConfig } from "@/lib/config/types";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";
import { instanceRoute } from "./instance-route";

// ============================================================================
//  `instanceRoute`: las guardas del núcleo, en su orden, para las rutas BFF de
//  una instancia (`app/api/instance/**`).
// ============================================================================

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/security/rate-limit", () => ({
  rateLimit: vi.fn(async () => ({
    success: true,
    retryAfter: 0,
    limit: 1,
    remaining: 1,
  })),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const config = {
  modules: { loyalty: true },
  ecommerce: { wishlist_enabled: false, search_enabled: true },
} as unknown as SiteConfig;

const SESSION = { userId: "7", token: "jwt" };
const ok = vi.fn(async () => NextResponse.json({ ok: true }));

const request = (method = "GET") =>
  new Request("https://tienda.example/api/instance/ejemplo", { method });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSiteConfig).mockResolvedValue(config);
  vi.mocked(getSession).mockResolvedValue(null);
  vi.mocked(guardMutation).mockResolvedValue(null);
});

describe("instanceRoute", () => {
  it("una lectura sin opciones llega al handler, sin guarda ni sesión, y no se cachea", async () => {
    const res = await instanceRoute({}, ok)(request());

    expect(res.status).toBe(200);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(guardMutation).not.toHaveBeenCalled();
    expect(getSession).not.toHaveBeenCalled();
  });

  it("respeta un Cache-Control propio del handler", async () => {
    const handler = async () =>
      NextResponse.json({}, { headers: { "Cache-Control": "public, max-age=60" } });
    const res = await instanceRoute({}, handler)(request());
    expect(res.headers.get("Cache-Control")).toBe("public, max-age=60");
  });

  it("módulo apagado → 404 antes de todo", async () => {
    const res = await instanceRoute({ module: "memberships" }, ok)(request("POST"));
    expect(res.status).toBe(404);
    expect(guardMutation).not.toHaveBeenCalled();
    expect(ok).not.toHaveBeenCalled();
  });

  it("flag apagado → 404", async () => {
    const res = await instanceRoute({ flag: "wishlist_enabled" }, ok)(request());
    expect(res.status).toBe(404);
    expect(ok).not.toHaveBeenCalled();
  });

  it("una escritura pasa por guardMutation con sus opciones, y si bloquea no sigue", async () => {
    const guard = { rateLimit: { name: "x", limit: 1, windowSeconds: 60 } };
    vi.mocked(guardMutation).mockResolvedValueOnce(
      NextResponse.json({ error: "Token CSRF inválido." }, { status: 403 }),
    );

    const res = await instanceRoute({ guard }, ok)(request("DELETE"));

    expect(res.status).toBe(403);
    expect(guardMutation).toHaveBeenCalledWith(expect.any(Request), guard);
    expect(ok).not.toHaveBeenCalled();
  });

  it("sesión exigida y ausente → 401; presente → llega al handler", async () => {
    const route = instanceRoute({ session: "required" }, ok);
    expect((await route(request())).status).toBe(401);

    vi.mocked(getSession).mockResolvedValueOnce(SESSION);
    expect((await route(request())).status).toBe(200);
    expect(ok).toHaveBeenLastCalledWith(
      expect.objectContaining({ session: SESSION, config }),
    );
  });

  it("pasa los parámetros dinámicos del segmento", async () => {
    await instanceRoute<undefined, { id: string }>({}, ok)(request(), {
      params: Promise.resolve({ id: "42" }),
    });
    expect(ok).toHaveBeenCalledWith(expect.objectContaining({ params: { id: "42" } }));
  });

  it("una excepción sale como 500 genérico, con su evento y sin el detalle", async () => {
    const res = await instanceRoute({}, async () => {
      throw new Error("detalle interno");
    })(request());

    expect(res.status).toBe(500);
    expect(JSON.stringify(await res.json())).not.toContain("detalle interno");
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "instance_route.error" }),
      expect.any(String),
    );
  });

  it("UNAUTHENTICATED lanzado por el handler (requireSession) → 401", async () => {
    const res = await instanceRoute({}, async () => {
      throw new Error("UNAUTHENTICATED");
    })(request());
    expect(res.status).toBe(401);
  });

  describe("public: ruta pública de máquina", () => {
    const guard = { rateLimit: { name: "mcp", limit: 60, windowSeconds: 60 } };

    it("una escritura no pasa por guardMutation, sí por el límite por IP", async () => {
      const res = await instanceRoute({ public: true, guard }, ok)(request("POST"));
      expect(res.status).toBe(200);
      expect(guardMutation).not.toHaveBeenCalled();
      expect(rateLimit).toHaveBeenCalledWith(
        expect.stringMatching(/^mcp:/),
        60,
        60,
        expect.anything(),
      );
    });

    it("pasado el límite responde 429", async () => {
      vi.mocked(rateLimit).mockResolvedValueOnce({
        success: false,
        retryAfter: 30,
        limit: 60,
        remaining: 0,
      } as Awaited<ReturnType<typeof rateLimit>>);
      const res = await instanceRoute({ public: true, guard }, ok)(request("POST"));
      expect(res.status).toBe(429);
      expect(res.headers.get("Retry-After")).toBe("30");
      expect(ok).not.toHaveBeenCalled();
    });

    it("un cuerpo declarado por encima del tope responde 413", async () => {
      const big = new Request("https://tienda.example/api/instance/public/mcp", {
        method: "POST",
        headers: { "content-length": String(10 * 1024 * 1024) },
        body: "x",
      });
      const res = await instanceRoute({ public: true }, ok)(big);
      expect(res.status).toBe(413);
    });

    it("no se puede declarar junto con la sesión", () => {
      expect(() => instanceRoute({ public: true, session: "optional" }, ok)).toThrow(
        /no puede leer la sesión/,
      );
    });
  });
});
