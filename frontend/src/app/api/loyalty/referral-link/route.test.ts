import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { GET } from "./route";

// ============================================================================
//  GET /api/loyalty/referral-link.
//
//  El código lo devuelve WordPress, pero la URL pública se compone con
//  `config.brand.url` (nunca con `home_url()` de WordPress, que en headless
//  apunta al backend). El `user_id` sale de la sesión.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
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

const request = (query = "") =>
  new Request(`https://tienda.example/api/loyalty/referral-link${query}`);

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  state.site.loyalty.referrals_enabled = true;
  state.site.brand.url = "https://tienda.example";
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(wpFetch).mockResolvedValue(Response.json({ code: "ABC12345" }));
});

describe("GET /api/loyalty/referral-link", () => {
  it("compone el enlace con config.brand.url, no con una constante", async () => {
    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual({
      code: "ABC12345",
      url: "https://tienda.example/?ref=ABC12345",
    });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("no duplica la barra si brand.url termina en /", async () => {
    state.site.brand.url = "https://tienda.example/";

    const res = await GET(request());

    await expect(res.json()).resolves.toMatchObject({
      url: "https://tienda.example/?ref=ABC12345",
    });
  });

  it("toma el user_id de la sesión aunque la query traiga otro", async () => {
    await GET(request("?user_id=999"));

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/loyalty/referral-code?user_id=7",
      { cache: "no-store" },
    );
  });

  it("sin código devuelve un enlace nulo", async () => {
    vi.mocked(wpFetch).mockResolvedValue(Response.json({ code: "" }));

    const res = await GET(request());
    const json = await res.json();

    expect(json).toEqual({ code: "", url: null });
  });

  it("sin sesión responde 401 y no llama a WordPress", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request());

    expect(res.status).toBe(401);
    expect(wpFetch).not.toHaveBeenCalled();
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

    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es 5xx y no un enlace inventado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(new Response("boom", { status: 503 }));

    const res = await GET(request());
    const json = await res.json();

    expect(res.status).toBe(503);
    expect(json.url).toBeUndefined();
    expect(json.code).toBe("unavailable");
  });

  it("un 404 de WordPress apagado se propaga como 404", async () => {
    vi.mocked(wpFetch).mockResolvedValue(new Response("{}", { status: 404 }));

    const res = await GET(request());

    expect(res.status).toBe(404);
  });
});
