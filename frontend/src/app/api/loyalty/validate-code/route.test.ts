import { beforeEach, describe, expect, it, vi } from "vitest";
import { rateLimit } from "@/lib/security/rate-limit";
import { validateReferralCode } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { GET } from "./route";

// ============================================================================
//  GET /api/loyalty/validate-code.
//
//  La ÚNICA ruta pública de `/api/loyalty/*`: rate-limit por IP y respuesta
//  `{ valid }` y nada más (ni nombre ni id del dueño del código).
// ============================================================================

vi.mock("@/lib/loyalty/server", () => ({ validateReferralCode: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { loyalty: true as boolean },
    loyalty: {
      points_enabled: true,
      referrals_enabled: true,
      transfers_enabled: true,
      point_value: "0.25",
      currency_name: "punto",
      currency_name_plural: "puntos",
      currency_icon: "",
    },
    brand: { url: "https://tienda.example" },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const request = (query = "", ip = "203.0.113.9") =>
  new Request(`https://tienda.example/api/loyalty/validate-code${query}`, {
    headers: { "x-forwarded-for": ip },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  state.site.loyalty.referrals_enabled = true;
  vi.mocked(rateLimit).mockResolvedValue({
    success: true,
    remaining: 29,
    retryAfter: 0,
    limit: 30,
  });
  vi.mocked(validateReferralCode).mockResolvedValue({ valid: true });
});

describe("GET /api/loyalty/validate-code", () => {
  it("responde solo `{ valid }` y no datos del dueño del código", async () => {
    const res = await GET(request("?code=ABC12345"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ valid: true });
    expect(Object.keys(json)).toEqual(["valid"]);
    expect(validateReferralCode).toHaveBeenCalledWith("ABC12345");
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("un código inexistente responde `{ valid: false }`", async () => {
    vi.mocked(validateReferralCode).mockResolvedValue({ valid: false });

    const res = await GET(request("?code=NOPE1234"));

    await expect(res.json()).resolves.toEqual({ valid: false });
  });

  it("recorta el código antes de consultarlo", async () => {
    await GET(request("?code=%20ABC12345%20"));

    expect(validateReferralCode).toHaveBeenCalledWith("ABC12345");
  });

  it("sin código responde `{ valid: false }` sin llamar a WordPress", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ valid: false });
    expect(validateReferralCode).not.toHaveBeenCalled();
  });

  it("un código con formato imposible responde `{ valid: false }`", async () => {
    const res = await GET(request(`?code=${"A".repeat(65)}`));

    expect(res.status).toBe(200);
    await expect(res.json()).resolves.toEqual({ valid: false });
    expect(validateReferralCode).not.toHaveBeenCalled();
  });

  it("aplica rate-limit por IP", async () => {
    vi.mocked(rateLimit).mockResolvedValue({
      success: false,
      remaining: 0,
      retryAfter: 30,
      limit: 30,
    });

    const res = await GET(request("?code=ABC12345", "198.51.100.4"));

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("30");
    expect(rateLimit).toHaveBeenCalledWith(
      "loyalty-validate-code:198.51.100.4",
      30,
      60,
    );
    expect(validateReferralCode).not.toHaveBeenCalled();
  });

  it("con el módulo, «Puntos» o «Referidos» apagados responde 404", async () => {
    state.site.modules.loyalty = false;
    expect((await GET(request("?code=ABC12345"))).status).toBe(404);

    state.site.modules.loyalty = true;
    state.site.loyalty.points_enabled = false;
    expect((await GET(request("?code=ABC12345"))).status).toBe(404);

    state.site.loyalty.points_enabled = true;
    state.site.loyalty.referrals_enabled = false;
    expect((await GET(request("?code=ABC12345"))).status).toBe(404);

    expect(validateReferralCode).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado, no un `valid: false`", async () => {
    vi.mocked(validateReferralCode).mockRejectedValue(
      new LoyaltyReadError("unavailable", 503),
    );

    const res = await GET(request("?code=ABC12345"));
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.valid).toBeUndefined();
    expect(json.code).toBe("unavailable");
  });
});
