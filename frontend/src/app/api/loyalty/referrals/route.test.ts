import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getLoyaltyReferrals } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { GET } from "./route";

// ============================================================================
//  GET /api/loyalty/referrals.
//
//  Red N1/N2 paginada. `level` fuera de 1|2 es 400 (nunca se adivina); el
//  `user_id` sale de la sesión y el módulo/interruptor se respetan en el BFF.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/loyalty/server", () => ({ getLoyaltyReferrals: vi.fn() }));
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

const PAGE = {
  items: [
    {
      name: "Ana",
      date: "2026-09-01T00:00:00Z",
      status: "confirmed" as const,
      points_generated: 40,
    },
  ],
  total: 1,
  pages: 1,
  totals: { level1: 3, level2: 1, pending: 2, earned: 120 },
};

const request = (query = "") =>
  new Request(`https://tienda.example/api/loyalty/referrals${query}`);

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  state.site.loyalty.referrals_enabled = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getLoyaltyReferrals).mockResolvedValue(PAGE);
});

describe("GET /api/loyalty/referrals", () => {
  it("sin parámetros pide el nivel 1 y la primera página", async () => {
    const res = await GET(request());

    expect(res.status).toBe(200);
    expect(getLoyaltyReferrals).toHaveBeenCalledWith(7, {
      level: 1,
      page: 1,
      perPage: 20,
    });
  });

  it("pasa el nivel y la página pedidos", async () => {
    const res = await GET(request("?level=2&page=3&per_page=5"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(getLoyaltyReferrals).toHaveBeenCalledWith(7, {
      level: 2,
      page: 3,
      perPage: 5,
    });
    expect(json).toEqual(PAGE);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it.each(["3", "0", "-1", "abc", "1.5"])(
    "un level inválido (%s) responde 400 sin consultar WordPress",
    async (level) => {
      const res = await GET(request(`?level=${level}`));
      const json = await res.json();

      expect(res.status).toBe(400);
      expect(json.details).toHaveProperty("level");
      expect(getLoyaltyReferrals).not.toHaveBeenCalled();
    },
  );

  it("toma el user_id de la sesión aunque la query traiga otro", async () => {
    await GET(request("?user_id=999"));

    expect(getLoyaltyReferrals).toHaveBeenCalledWith(7, expect.any(Object));
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request());

    expect(res.status).toBe(401);
    expect(getLoyaltyReferrals).not.toHaveBeenCalled();
  });

  it("con el módulo, «Puntos» o «Referidos» apagados responde 404", async () => {
    state.site.modules.loyalty = false;
    expect((await GET(request())).status).toBe(404);

    state.site.modules.loyalty = true;
    state.site.loyalty.points_enabled = false;
    expect((await GET(request())).status).toBe(404);

    state.site.loyalty.points_enabled = true;
    state.site.loyalty.referrals_enabled = false;
    expect((await GET(request())).status).toBe(404);

    expect(getLoyaltyReferrals).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es 5xx y no una red vacía", async () => {
    vi.mocked(getLoyaltyReferrals).mockRejectedValue(
      new LoyaltyReadError("unavailable", 503),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.items).toBeUndefined();
    expect(json.code).toBe("unavailable");
  });
});
