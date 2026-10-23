import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { describeEntry } from "@/lib/loyalty/describe";
import { getLoyaltyTransactions } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { GET } from "./route";

// ============================================================================
//  GET /api/loyalty/transactions.
//
//  `per_page` se recorta (1..100) antes de llegar a WordPress y cada movimiento
//  viaja con su texto ya compuesto en el idioma de la petición.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/loyalty/server", () => ({ getLoyaltyTransactions: vi.fn() }));
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

const ITEM = {
  id: 11,
  type: "cashback",
  points: 25,
  data: { order: 42 },
  created_at: "2026-10-01T10:00:00Z",
  expires_at: null,
};

const PAGE = { items: [ITEM], total: 1, pages: 1 };

const request = (query = "", locale = "es") =>
  new Request(`https://tienda.example/api/loyalty/transactions${query}`, {
    headers: { "X-HWE-Locale": locale },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getLoyaltyTransactions).mockResolvedValue(PAGE);
});

describe("GET /api/loyalty/transactions", () => {
  it("compone el texto de cada movimiento en el idioma de la petición", async () => {
    const res = await GET(request("", "en"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json.items[0]).toMatchObject({
      id: 11,
      type: "cashback",
      points: 25,
      data: { order: 42 },
      description: describeEntry(ITEM, "en"),
    });
    expect(json).toMatchObject({ total: 1, pages: 1 });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("recorta per_page al tope de 100", async () => {
    await GET(request("?page=2&per_page=500"));

    expect(getLoyaltyTransactions).toHaveBeenCalledWith(7, { page: 2, perPage: 100 });
  });

  it("recorta per_page por abajo a 1", async () => {
    await GET(request("?per_page=0"));

    expect(getLoyaltyTransactions).toHaveBeenCalledWith(7, { page: 1, perPage: 1 });
  });

  it("un per_page no numérico cae al valor por defecto", async () => {
    await GET(request("?per_page=abc"));

    expect(getLoyaltyTransactions).toHaveBeenCalledWith(7, { page: 1, perPage: 20 });
  });

  it("una página no válida cae a la primera", async () => {
    await GET(request("?page=-3"));

    expect(getLoyaltyTransactions).toHaveBeenCalledWith(7, { page: 1, perPage: 20 });
  });

  it("toma el user_id de la sesión aunque la query traiga otro", async () => {
    await GET(request("?user_id=999"));

    expect(getLoyaltyTransactions).toHaveBeenCalledWith(7, expect.any(Object));
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request());

    expect(res.status).toBe(401);
    expect(getLoyaltyTransactions).not.toHaveBeenCalled();
  });

  it("con el módulo o «Puntos» apagados responde 404", async () => {
    state.site.modules.loyalty = false;
    expect((await GET(request())).status).toBe(404);

    state.site.modules.loyalty = true;
    state.site.loyalty.points_enabled = false;
    expect((await GET(request())).status).toBe(404);

    expect(getLoyaltyTransactions).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es 5xx y no una página vacía", async () => {
    vi.mocked(getLoyaltyTransactions).mockRejectedValue(
      new LoyaltyReadError("unavailable", 503),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.items).toBeUndefined();
    expect(json.code).toBe("unavailable");
  });
});
