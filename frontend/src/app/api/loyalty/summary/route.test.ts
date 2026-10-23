import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getLoyaltySummary } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";
import { GET } from "./route";

// ============================================================================
//  GET /api/loyalty/summary.
//
//  Ruta por usuario: el `user_id` sale SIEMPRE de la sesión (nunca de la query),
//  con el módulo y «Puntos» encendidos. Un fallo de WordPress es un 5xx tipado,
//  jamás un saldo 0 inventado.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/loyalty/server", () => ({ getLoyaltySummary: vi.fn() }));
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

const SUMMARY = {
  participant: true,
  balance: 320,
  earned: 500,
  used: 180,
  expiring: { points: 50, date: "2026-11-20T00:00:00Z" },
};

const request = (query = "") =>
  new Request(`https://tienda.example/api/loyalty/summary${query}`);

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getLoyaltySummary).mockResolvedValue(SUMMARY);
});

describe("GET /api/loyalty/summary", () => {
  it("devuelve el resumen con el saldo convertido a dinero", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ ...SUMMARY, balance_value: 80 });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("toma el user_id de la sesión aunque la query traiga otro", async () => {
    await GET(request("?user_id=999"));

    expect(getLoyaltySummary).toHaveBeenCalledWith(7);
    expect(getLoyaltySummary).not.toHaveBeenCalledWith(999);
  });

  it("sin sesión responde 401 y no consulta el monedero", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request());

    expect(res.status).toBe(401);
    expect(getLoyaltySummary).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.loyalty = false;

    const res = await GET(request());

    expect(res.status).toBe(404);
    expect(getLoyaltySummary).not.toHaveBeenCalled();
  });

  it("con «Puntos» apagado responde 404", async () => {
    state.site.loyalty.points_enabled = false;

    const res = await GET(request());

    expect(res.status).toBe(404);
    expect(getLoyaltySummary).not.toHaveBeenCalled();
  });

  it("un 404 de WordPress apagado se propaga como 404", async () => {
    vi.mocked(getLoyaltySummary).mockRejectedValue(
      new LoyaltyReadError("not_found", 404),
    );

    const res = await GET(request());

    expect(res.status).toBe(404);
  });

  it("un fallo de WordPress es un 5xx tipado y no un saldo 0", async () => {
    vi.mocked(getLoyaltySummary).mockRejectedValue(
      new LoyaltyReadError("unavailable", 503),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json).toMatchObject({ code: "unavailable" });
    expect(json.balance).toBeUndefined();
  });

  it("una respuesta fuera de contrato es 502", async () => {
    vi.mocked(getLoyaltySummary).mockRejectedValue(
      new LoyaltyReadError("invalid_response"),
    );

    const res = await GET(request());

    expect(res.status).toBe(502);
  });

  it("un error inesperado se registra y responde 500", async () => {
    vi.mocked(getLoyaltySummary).mockRejectedValue(new Error("boom"));

    const res = await GET(request());

    expect(res.status).toBe(500);
    expect(vi.mocked(logger.error)).toHaveBeenCalledWith(
      expect.objectContaining({ event: "loyalty.summary_failed" }),
      expect.any(String),
    );
  });
});
