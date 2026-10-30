import { beforeEach, describe, expect, it, vi } from "vitest";
import { getProducts } from "@/lib/catalog/products";
import { rateLimit } from "@/lib/security/rate-limit";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSiteConfig } from "@/lib/config";
import { GET } from "./route";

// ============================================================================
//  GET /api/store/products — presupuesto del listado público.
//
//  TODO este GET acaba en WordPress (WPGraphQL para el listado, wc/v3 para la
//  lista de ids) y el BFF llama con el secreto interno, así que WordPress no le
//  aplica su propio límite. Un atacante anónimo que varía `category`, `first`,
//  `after`… obtiene una consulta nueva por cada URL (la clave del Data Cache
//  incluye las variables): el presupuesto por IP tiene que cubrir cualquier
//  forma del GET, no solo `?search=`, y aplicarse ANTES de tocar WordPress.
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

const OK = { success: true, remaining: 10, retryAfter: 0, limit: 60 };
const EMPTY = { products: [], pageInfo: { hasNextPage: false, endCursor: null } };

const request = (query: string, ip = "203.0.113.5") =>
  new Request(`https://tienda.example/api/store/products${query}`, {
    headers: { "x-forwarded-for": ip },
  });

const lastFilters = () =>
  vi.mocked(getProducts).mock.calls.at(-1)![0] as Record<string, unknown>;

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

describe("presupuesto por IP de todo el GET", () => {
  it.each([
    ["listado base", ""],
    ["con filtros", "?category=ropa&minPrice=10&maxPrice=99.5&sort=price-asc"],
    ["paginación", "?after=YXJyYXljb25uZWN0aW9uOjEy&first=12"],
    ["búsqueda", "?search=camiseta"],
    ["lista de ids", "?include=1"],
  ])("%s consume el mismo límite", async (_caso, query) => {
    const res = await GET(request(query));

    expect(res.status).toBe(200);
    expect(rateLimit).toHaveBeenCalledWith("products-list:203.0.113.5", 60, 60);
  });

  it("la petición 61 devuelve 429 con Retry-After y no consulta el catálogo", async () => {
    let calls = 0;
    vi.mocked(rateLimit).mockImplementation(async () => {
      calls += 1;
      return calls > 60
        ? { ...OK, success: false, remaining: 0, retryAfter: 42 }
        : { ...OK, remaining: 60 - calls };
    });

    const responses: Response[] = [];
    for (let i = 0; i < 61; i++) {
      responses.push(await GET(request(`?category=f${i}&first=12`)));
    }

    expect(responses.slice(0, 60).every((r) => r.status === 200)).toBe(true);
    expect(responses[60]!.status).toBe(429);
    expect(responses[60]!.headers.get("Retry-After")).toBe("42");
    // El 429 sale antes de resolver la config del sitio y del catálogo: solo las
    // 60 peticiones aceptadas llegan a WordPress.
    expect(getSiteConfig).toHaveBeenCalledTimes(60);
    expect(getProducts).toHaveBeenCalledTimes(60);
    expect(wcFetch).not.toHaveBeenCalled();
  });
});

describe("parámetros de la consulta", () => {
  it("acota `first` al máximo de la frontera", async () => {
    await GET(request("?first=1000000"));

    expect(lastFilters().first).toBe(24);
  });

  it("un `first` que no es un entero positivo cae al tamaño por defecto", async () => {
    await GET(request("?first=abc"));

    expect(lastFilters().first).toBe(12);
  });

  it("descarta `category`, precios y `after` mal formados antes de consultar", async () => {
    await GET(
      request("?category=..%2Fetc&minPrice=abc&maxPrice=-3&after=%3Cscript%3E"),
    );

    expect(lastFilters()).toMatchObject({
      category: undefined,
      minPrice: undefined,
      maxPrice: undefined,
      after: undefined,
    });
  });

  it("las formas válidas siguen pasando tal cual", async () => {
    await GET(
      request(
        "?search=camiseta&category=ropa&minPrice=10&maxPrice=99.5&sort=price-asc" +
          "&after=YXJyYXljb25uZWN0aW9uOjEy&first=12&locale=en",
      ),
    );

    expect(lastFilters()).toEqual({
      locale: "en",
      search: "camiseta",
      after: "YXJyYXljb25uZWN0aW9uOjEy",
      category: "ropa",
      minPrice: "10",
      maxPrice: "99.5",
      sort: "price-asc",
      first: 12,
    });
  });
});

// ============================================================================
//  GET /api/store/products?exclude=1,2,3: páginas completas sin los ids indicados.
//  Ruta pública: el parámetro solo resta del listado, va acotado y normalizado.
// ============================================================================

describe("exclude", () => {
  it("sin el parámetro, la consulta no cambia", async () => {
    await GET(request("?first=2"));

    expect(lastFilters()).not.toHaveProperty("exclude");
  });

  it("traslada los ids al listado, ordenados y sin repetir", async () => {
    await GET(request("?exclude=30,7,30,12"));

    expect(lastFilters().exclude).toEqual([7, 12, 30]);
  });

  it("descarta lo que no es un id, sin tocar la consulta", async () => {
    await GET(request("?exclude=abc,-1,1.5,0,1%20OR%201"));

    expect(lastFilters()).not.toHaveProperty("exclude");
  });

  it("acota la lista: no se puede pedir una consulta sin límite", async () => {
    const ids = Array.from({ length: 500 }, (_, index) => index + 1).join(",");

    await GET(request(`?exclude=${ids}`));

    expect(lastFilters().exclude).toHaveLength(100);
  });

  it("gasta el presupuesto común por IP, antes de consultar nada", async () => {
    vi.mocked(rateLimit).mockResolvedValue({ success: false, retryAfter: 9 } as never);
    const callsBefore = vi.mocked(getProducts).mock.calls.length;

    const res = await GET(request("?exclude=1"));

    expect(res.status).toBe(429);
    expect(vi.mocked(getProducts).mock.calls.length).toBe(callsBefore);
  });

  it("no entra en la rama de `include`, que lee con credenciales del servidor", async () => {
    vi.mocked(wcFetch).mockResolvedValue([] as never);

    await GET(request("?include=1,2&exclude=2"));

    const { query } = vi.mocked(wcFetch).mock.calls.at(-1)![1]!;
    expect(query).not.toHaveProperty("exclude");
    expect(query).toMatchObject({ status: "publish" });
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
});

describe("con las funciones apagadas en el Control Center", () => {
  it("búsqueda apagada: ?search= responde 404 y no consulta el catálogo", async () => {
    flags.search_enabled = false;

    const res = await GET(request("?search=camiseta"));

    expect(res.status).toBe(404);
    expect(getProducts).not.toHaveBeenCalled();
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
