import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getMembershipProduct } from "@/lib/memberships/server";
import { MembershipsReadError } from "@/lib/memberships/types";
import { logger } from "@/lib/observability/logger";
import { GET } from "./route";

// ============================================================================
//  GET /api/memberships/products/[slug].
//
//  Ficha completa para el socio que cumple el requisito y ficha de escaparate
//  para el resto (la decide WordPress). Exige sesión y no se cachea: la misma
//  URL puede devolver dos formas distintas según quién la pida.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/memberships/server", () => ({
  getMembershipProduct: vi.fn(),
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

const PRODUCT = {
  id: 601,
  name: "Socio Plus",
  slug: "socio-plus",
  type: "simple",
  status: "publish",
  purchasable: true,
  on_sale: false,
  price: "20.00",
  regular_price: "25.00",
  sale_price: null,
  stock_status: "instock",
  manage_stock: false,
  stock_quantity: null,
  backorders_allowed: false,
  categories: [],
  images: [],
  locked: true,
  hwe_membership: { required_level: null, opens_at: null },
};

const params = (slug: string) => ({ params: Promise.resolve({ slug }) });
const request = () =>
  new Request("https://tienda.example/api/memberships/products/socio-plus", {
    headers: { "x-hwe-locale": "en" },
  });

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "JWT" });
  vi.mocked(getMembershipProduct).mockResolvedValue(PRODUCT);
});

describe("GET /api/memberships/products/[slug]", () => {
  it("devuelve la ficha del socio sin caché compartida", async () => {
    const res = await GET(request(), params("socio-plus"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json).toEqual(PRODUCT);
    expect(getMembershipProduct).toHaveBeenCalledWith(7, {
      slug: "socio-plus",
      locale: "en",
    });
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("sin sesión responde 401 (la ficha de escaparate también exige socio)", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    const res = await GET(request(), params("socio-plus"));

    expect(res.status).toBe(401);
    expect(getMembershipProduct).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.memberships = false;

    expect((await GET(request(), params("socio-plus"))).status).toBe(404);
    expect(getMembershipProduct).not.toHaveBeenCalled();
  });

  it("un producto inexistente responde 404 con el mismo cuerpo", async () => {
    vi.mocked(getMembershipProduct).mockRejectedValue(
      new MembershipsReadError("not_found", 404),
    );

    const missing = await GET(request(), params("no-existe"));
    const notPublished = await GET(request(), params("borrador"));

    expect(missing.status).toBe(404);
    expect(notPublished.status).toBe(404);
    await expect(missing.json()).resolves.toEqual(await notPublished.json());
  });

  it("un slug vacío es un 400 sin consultar WordPress", async () => {
    const res = await GET(request(), params("   "));

    expect(res.status).toBe(400);
    expect(getMembershipProduct).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress es un 5xx tipado y se registra", async () => {
    vi.mocked(getMembershipProduct).mockRejectedValue(
      new MembershipsReadError("unavailable", 503),
    );

    const res = await GET(request(), params("socio-plus"));

    expect(res.status).toBe(503);
    expect(vi.mocked(logger.error)).toHaveBeenCalledWith(
      expect.objectContaining({ event: "memberships.product_unavailable" }),
      expect.any(String),
    );
  });
});
