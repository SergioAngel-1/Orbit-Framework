import { beforeEach, describe, expect, it, vi } from "vitest";
import { getProducts } from "@/lib/catalog/products";
import { rateLimit } from "@/lib/security/rate-limit";
import { wcFetch } from "@/lib/woocommerce/client";
import { GET } from "./route";

// ============================================================================
//  GET /api/store/products — rate-limit en la búsqueda no autenticada.
//
//  La búsqueda es la parte cara y abusable (un recorrido por el catálogo por cada
//  pulsación); el listado normal es cacheable y no se limita.
// ============================================================================

vi.mock("@/lib/catalog/products", () => ({ getProducts: vi.fn() }));
vi.mock("@/lib/security/rate-limit", () => ({ rateLimit: vi.fn() }));
// Flags de la tienda (`config.ecommerce`): por defecto, todo encendido.
const flags = vi.hoisted(() => ({
  reviews_enabled: true,
  wishlist_enabled: true,
  search_enabled: true,
  coupons_enabled: true,
}));
vi.mock("@/lib/config", () => ({
  getSiteConfig: vi.fn(async () => ({ ecommerce: flags })),
}));

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
  Object.assign(flags, {
    reviews_enabled: true,
    wishlist_enabled: true,
    search_enabled: true,
    coupons_enabled: true,
  });
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

// ============================================================================
//  GET /api/store/products?include=1,2,3 (la página de la wishlist).
//
//  Esta rama llama a `wc/v3/products` con las credenciales del SERVIDOR, y WooCommerce
//  devuelve por defecto productos de CUALQUIER estado (`status=any`): un anónimo podía
//  leer nombre, precio y descripción de productos en borrador, pendientes o privados
//  pidiéndolos por id. Además, `?include=abc` dejaba la lista de ids vacía, y un
//  `include` vacío no filtra nada: devolvía los primeros 100 productos.
// ============================================================================

describe("include (ids concretos)", () => {
  beforeEach(() => {
    vi.mocked(wcFetch).mockResolvedValue([
      { id: 1, name: "Publicado", slug: "publicado", images: [] },
    ] as never);
  });

  const lastQuery = () =>
    vi.mocked(wcFetch).mock.calls.at(-1)![1]!.query as Record<string, unknown>;

  it("pide SOLO productos publicados", async () => {
    await GET(request("?include=1,2,3"));

    expect(wcFetch).toHaveBeenCalledWith("/products", expect.anything());
    expect(lastQuery()).toMatchObject({ include: "1,2,3", status: "publish" });
  });

  it("devuelve los productos en la forma del catálogo", async () => {
    const res = await GET(request("?include=1"));
    const json = await res.json();

    expect(res.status).toBe(200);
    expect(json.products).toHaveLength(1);
    expect(json.products[0]).toMatchObject({ databaseId: 1, name: "Publicado" });
  });

  it.each([
    ["texto", "?include=abc"],
    ["vacío", "?include="],
    ["solo separadores", "?include=,,,"],
    ["ids negativos o cero", "?include=-3,0"],
    ["decimales", "?include=1.5,2.7"],
  ])(
    "sin ningún id válido (%s) no consulta WooCommerce y devuelve una lista vacía",
    async (_caso, query) => {
      const res = await GET(request(query));
      const json = await res.json();

      expect(wcFetch).not.toHaveBeenCalled();
      expect(json.products).toEqual([]);
    },
  );

  it("descarta lo que no es un id positivo y quita duplicados", async () => {
    await GET(request("?include=3,abc,-5,2.5,3,7"));

    expect(lastQuery().include).toBe("3,7");
  });

  it("acota la lista a 100 ids (el per_page de WooCommerce)", async () => {
    const ids = Array.from({ length: 150 }, (_, i) => i + 1).join(",");

    await GET(request(`?include=${ids}`));

    expect(String(lastQuery().include).split(",")).toHaveLength(100);
  });

  it("no pasa por el rate-limit de la búsqueda", async () => {
    await GET(request("?include=1"));

    expect(rateLimit).not.toHaveBeenCalled();
  });
});

describe("con las funciones apagadas en el Control Center", () => {
  it("búsqueda apagada: ?search= responde 404 y no consulta el catálogo", async () => {
    flags.search_enabled = false;

    const res = await GET(request("?search=camiseta"));

    expect(res.status).toBe(404);
    expect(getProducts).not.toHaveBeenCalled();
    expect(rateLimit).not.toHaveBeenCalled();
  });

  it("búsqueda apagada: el listado sin búsqueda sigue sirviéndose", async () => {
    flags.search_enabled = false;

    expect((await GET(request("?sort=price-asc"))).status).toBe(200);
    expect((await GET(request("?search=%20"))).status).toBe(200);
  });

  it("lista de deseos apagada: ?include= responde 404 y no consulta WooCommerce", async () => {
    flags.wishlist_enabled = false;

    const res = await GET(request("?include=1,2"));

    expect(res.status).toBe(404);
    expect(wcFetch).not.toHaveBeenCalled();
  });
});
