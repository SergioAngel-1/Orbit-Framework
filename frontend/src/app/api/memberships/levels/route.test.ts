import { beforeEach, describe, expect, it, vi } from "vitest";
import { getMembershipLevels } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { logger } from "@/lib/observability/logger";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/levels.
//
//  Pública y cacheada por ISR (tag `memberships`), con el texto de cada nivel y
//  de sus beneficios ya compuesto en el idioma de la petición. Con el módulo
//  apagado, 404; un fallo de WordPress es un 5xx tipado.
// ============================================================================

vi.mock("@/lib/memberships/server", () => ({
  getMembershipLevels: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { memberships: true as boolean },
    memberships: { card_enabled: false },
    loyalty: { currency_name_plural: "puntos" },
    brand: { url: "https://tienda.example", locale: "es" },
  },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => state.site),
}));

const LEVEL = {
  id: 101,
  name: "Socio",
  description: "",
  slug: "socio",
  slugs: {},
  rank: 1,
  icon: "",
  color: "",
  purchasable: true,
  min_account_age_days: 0,
  benefits: [],
  products: [],
};

const request = (locale = "es") =>
  new Request("https://tienda.example/api/memberships/levels", {
    headers: { "x-hwe-locale": locale },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  vi.mocked(getMembershipLevels).mockResolvedValue([LEVEL]);
});

describe("GET /api/memberships/levels", () => {
  it("devuelve los niveles en el idioma pedido con caché pública y `Vary`", async () => {
    const res = await GET(request("en"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({ items: [LEVEL] });
    expect(getMembershipLevels).toHaveBeenCalledWith("en");
    expect(res.headers.get("Cache-Control")).toContain("s-maxage");
    expect(res.headers.get("Vary")).toBe("X-HWE-Locale");
  });

  it("con el módulo apagado responde 404 sin leer niveles", async () => {
    state.site.modules.memberships = false;

    const res = await GET(request());

    expect(res.status).toBe(404);
    expect(getMembershipLevels).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado con evento", async () => {
    vi.mocked(getMembershipLevels).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.code).toBe("unavailable");
    expect(json.items).toBeUndefined();
    expect(vi.mocked(logger.error)).toHaveBeenCalledWith(
      expect.objectContaining({ event: "memberships.levels_unavailable" }),
      expect.any(String),
    );
  });
});
