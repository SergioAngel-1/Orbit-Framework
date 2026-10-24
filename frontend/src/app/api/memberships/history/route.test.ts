import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getMembershipHistory } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/history.
//
//  Historial del propio usuario, paginado con tope, con el texto de cada evento
//  compuesto en el BFF (WordPress solo manda tipo + datos estructurados).
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/memberships/server", () => ({
  getMembershipHistory: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: { modules: { memberships: true as boolean } },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const ITEM = {
  id: 9,
  type: "activated",
  level_from: 0,
  level_to: 102,
  data: { origin: "order", days: 30, status: "active" },
  created_at: "2026-10-01T10:00:00Z",
};

const request = (query = "", locale = "es") =>
  new Request(`https://tienda.example/api/memberships/history${query}`, {
    headers: { "x-hwe-locale": locale },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getMembershipHistory).mockResolvedValue({
    items: [ITEM],
    total: 1,
    pages: 1,
  });
});

describe("GET /api/memberships/history", () => {
  it("compone el texto de cada evento en el idioma de la petición", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json.items[0]).toMatchObject({
      id: 9,
      type: "activated",
      description: "Membresía activada durante 30 días",
    });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("en inglés compone el texto en inglés", async () => {
    const res = await GET(request("", "en"));
    const json = await res.json();

    expect(json.items[0].description).toBe("Membership activated for 30 days");
  });

  it("recorta la paginación antes de llegar a WordPress", async () => {
    await GET(request("?page=3&per_page=500"));

    expect(getMembershipHistory).toHaveBeenCalledWith(7, {
      page: 3,
      perPage: 100,
    });
  });

  it("una página inválida cae a la primera", async () => {
    await GET(request("?page=0&per_page=abc"));

    expect(getMembershipHistory).toHaveBeenCalledWith(7, {
      page: 1,
      perPage: 20,
    });
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    expect((await GET(request())).status).toBe(401);
    expect(getMembershipHistory).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.memberships = false;

    expect((await GET(request())).status).toBe(404);
    expect(getMembershipHistory).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado", async () => {
    vi.mocked(getMembershipHistory).mockRejectedValue(
      new MembershipsReadError("invalid_response"),
    );

    const res = await GET(request());

    expect(res.status).toBe(502);
  });
});
