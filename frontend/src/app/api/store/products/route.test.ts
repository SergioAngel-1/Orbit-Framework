import { beforeEach, describe, expect, it, vi } from "vitest";
import { getProducts } from "@/lib/catalog/products";
import { rateLimit } from "@/lib/security/rate-limit";
import { GET } from "./route";

// ============================================================================
//  GET /api/store/products — rate-limit en la búsqueda no autenticada.
//
//  La búsqueda es la parte cara y abusable (un recorrido por el catálogo por cada
//  pulsación); el listado normal es cacheable y no se limita.
// ============================================================================

vi.mock("@/lib/catalog/products", () => ({ getProducts: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const OK = { success: true, remaining: 10, retryAfter: 0, limit: 20 };
const EMPTY = { products: [], pageInfo: { hasNextPage: false, endCursor: null } };

const request = (query: string, ip = "203.0.113.5") =>
  new Request(`https://tienda.example/api/store/products${query}`, {
    headers: { "x-forwarded-for": ip },
  });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(rateLimit).mockResolvedValue(OK);
  vi.mocked(getProducts).mockResolvedValue(EMPTY as never);
});

describe("búsqueda", () => {
  it("se limita por IP", async () => {
    const res = await GET(request("?search=camiseta"));

    expect(res.status).toBe(200);
    expect(rateLimit).toHaveBeenCalledWith("products-search:203.0.113.5", 20, 60);
  });

  it("superar el límite da 429 con Retry-After y no consulta el catálogo", async () => {
    vi.mocked(rateLimit).mockResolvedValue({ ...OK, success: false, retryAfter: 33 });

    const res = await GET(request("?search=camiseta"));

    expect(res.status).toBe(429);
    expect(res.headers.get("Retry-After")).toBe("33");
    expect(getProducts).not.toHaveBeenCalled();
  });

  it("una búsqueda en blanco no cuenta como búsqueda", async () => {
    await GET(request("?search=%20%20"));

    expect(rateLimit).not.toHaveBeenCalled();
  });
});

describe("listado sin búsqueda", () => {
  it("no se limita (es cacheable)", async () => {
    const res = await GET(request("?category=ropa&first=12"));

    expect(res.status).toBe(200);
    expect(rateLimit).not.toHaveBeenCalled();
  });
});
