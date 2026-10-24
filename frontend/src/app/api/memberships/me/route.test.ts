import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getMembershipState } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/me.
//
//  Estado del socio de la sesión. El `user_id` nunca se acepta de la query y un
//  fallo de WordPress es un 5xx tipado, no un estado vacío inventado.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/memberships/server", () => ({
  getMembershipState: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { memberships: true as boolean },
    memberships: { card_enabled: false, member_number_prefix: "SOC-" },
    loyalty: { currency_name_plural: "puntos" },
    brand: { url: "https://tienda.example", locale: "es" },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const MEMBER = {
  status: "active" as const,
  level: { id: 102, rank: 5 },
  ends_at: "2026-11-01T10:00:00Z",
  member_since: "2026-01-02T03:04:05Z",
  member_number: "SOC-000123",
  queue: [],
  benefits: [],
  gifts: { max: 0, used: 0, remaining: 0 },
  purchasable: [],
};

const request = (query = "") =>
  new Request(`https://tienda.example/api/memberships/me${query}`, {
    headers: { "x-hwe-locale": "en" },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getMembershipState).mockResolvedValue(MEMBER);
});

describe("GET /api/memberships/me", () => {
  it("devuelve el estado del usuario de la sesión con el prefijo del número", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual(MEMBER);
    expect(getMembershipState).toHaveBeenCalledWith(
      7,
      expect.objectContaining({
        locale: "en",
        memberNumberPrefix: "SOC-",
      }),
    );
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("ignora un user_id de la query", async () => {
    await GET(request("?user_id=999"));

    expect(getMembershipState).toHaveBeenCalledWith(7, expect.anything());
    expect(getMembershipState).not.toHaveBeenCalledWith(999, expect.anything());
  });

  it("sin sesión responde 401 y no consulta WordPress", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request());

    expect(res.status).toBe(401);
    expect(getMembershipState).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.memberships = false;

    const res = await GET(request());

    expect(res.status).toBe(404);
    expect(getMembershipState).not.toHaveBeenCalled();
  });

  it("un 404 de WordPress se propaga como 404", async () => {
    vi.mocked(getMembershipState).mockRejectedValue(
      new MembershipsReadError("not_found", 404),
    );

    expect((await GET(request())).status).toBe(404);
  });

  it("un fallo de WordPress es un 5xx tipado, no un estado vacío", async () => {
    vi.mocked(getMembershipState).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json).toMatchObject({ code: "unavailable" });
    expect(json.status).toBeUndefined();
  });
});
