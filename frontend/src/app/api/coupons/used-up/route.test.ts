import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { getUsedUpCouponCodes } from "@/lib/coupons/public-coupons";
import { GET } from "./route";

// GET /api/coupons/used-up: los cupones públicos que ESTE cliente ya no puede
// usar. La sesión sale de la cookie httpOnly, nunca de un parámetro. Es del
// módulo `coupon-rules` y de `coupons_enabled`: apagados, la ruta no existe.

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/coupons/public-coupons", () => ({ getUsedUpCouponCodes: vi.fn() }));

function config(opts: { module?: boolean; coupons?: boolean }) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "coupon-rules": opts.module ?? true },
    ecommerce: { coupons_enabled: opts.coupons ?? true },
  } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  config({});
});

describe("GET /api/coupons/used-up", () => {
  it("sin sesión no consulta a WordPress: un invitado no tiene historial", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET();

    expect(await res.json()).toEqual({ codes: [], authenticated: false });
    expect(getUsedUpCouponCodes).not.toHaveBeenCalled();
    expect(res.headers.get("cache-control")).toContain("no-store");
  });

  it("con sesión devuelve los códigos del propio cliente, tomados de la sesión", async () => {
    vi.mocked(getSession).mockResolvedValue({ userId: "42", token: "jwt" } as never);
    vi.mocked(getUsedUpCouponCodes).mockResolvedValue(new Set(["bienvenida10"]));

    const res = await GET();

    expect(await res.json()).toEqual({ codes: ["bienvenida10"], authenticated: true });
    expect(getUsedUpCouponCodes).toHaveBeenCalledWith("42");
  });

  it("con el módulo apagado es un 404", async () => {
    config({ module: false });

    expect((await GET()).status).toBe(404);
    expect(getSession).not.toHaveBeenCalled();
  });

  it("con los cupones desactivados es un 404", async () => {
    config({ coupons: false });

    expect((await GET()).status).toBe(404);
  });
});
