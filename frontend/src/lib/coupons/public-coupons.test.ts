import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { getCategoryPaths } from "@/lib/catalog/coupon-refs";
import {
  getCartCoupons,
  getCategoryCoupons,
  getProductCoupons,
  getUsedUpCouponCodes,
} from "./public-coupons";

// ============================================================================
//  Cupones públicos del BFF: la lista compartida (ISR) y lo que depende del
//  cliente. El código de un cupón que aún no empieza NO viaja al carrito ni al
//  checkout (páginas dinámicas), y uno que el cliente ya agotó no se ofrece.
// ============================================================================

vi.mock("react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react")>()),
  // `cache()` memoiza por petición; en los tests cada llamada es una petición.
  cache: <T extends (...args: never[]) => unknown>(fn: T) => fn,
}));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/catalog/coupon-refs", () => ({ getCategoryPaths: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const NOW = Date.now();
const secs = (offsetMs: number) => Math.floor((NOW + offsetMs) / 1000);

function row(overrides: Record<string, unknown> = {}) {
  return {
    code: "HOLA10",
    discountType: "percent",
    amount: 10,
    freeShipping: false,
    scope: "all",
    productSlugs: [],
    categorySlugs: [],
    excludedProductSlugs: [],
    excludedCategorySlugs: [],
    excludeSaleItems: false,
    minimumAmount: null,
    maximumAmount: null,
    requiresLogin: false,
    firstOrderOnly: false,
    perUserLimited: false,
    startsAt: null,
    endsAt: null,
    ...overrides,
  };
}

const respond = (body: unknown, status = 200) =>
  new Response(typeof body === "string" ? body : JSON.stringify(body), { status });

/** `wpFetch` según la ruta pedida. */
function wordpress(routes: { list?: unknown[]; usedUp?: string }) {
  vi.mocked(wpFetch).mockImplementation((async (path: string) => {
    if (path.startsWith("/wp-json/hwe/v1/coupons/public/used-up")) {
      return respond(routes.usedUp ?? '{"codes":[]}');
    }
    return respond({ items: routes.list ?? [] });
  }) as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubEnv("HWE_REVALIDATION_SECRET", "dummy-ci-revalidation-secret-24-chars");
  vi.mocked(getCategoryPaths).mockResolvedValue(new Map());
});

describe("lista pública", () => {
  it("se lee de WordPress con el tag `coupons` (ISR) por wpFetch", async () => {
    wordpress({ list: [row()] });

    await getCartCoupons(null);

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/coupons/public",
      expect.objectContaining({
        next: expect.objectContaining({ tags: ["coupons"] }),
      }),
    );
  });

  it("si WordPress falla, no se anuncia nada (el cupón sigue funcionando con su código)", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("caído"));

    expect(await getCartCoupons(null)).toEqual([]);
  });

  it("sin el secreto interno no llama a WordPress", async () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "");

    expect(await getCartCoupons(null)).toEqual([]);
    expect(wpFetch).not.toHaveBeenCalled();
  });
});

describe("carrito y checkout (páginas dinámicas)", () => {
  it("no reciben un cupón que todavía no empieza", async () => {
    wordpress({
      list: [
        row({ code: "VIGENTE" }),
        row({ code: "FUTURO", startsAt: secs(2 * 3600_000) }),
      ],
    });

    const codes = (await getCartCoupons(null)).map((c) => c.code);

    expect(codes).toEqual(["VIGENTE"]);
  });

  it("un cliente con sesión no ve el que ya agotó", async () => {
    wordpress({
      list: [
        row({ code: "UNAVEZ", perUserLimited: true }),
        row({ code: "LIBRE", perUserLimited: false }),
      ],
      usedUp: '{"codes":["UNAVEZ"]}',
    });

    const codes = (await getCartCoupons("7")).map((c) => c.code);

    expect(codes).toEqual(["LIBRE"]);
  });

  it("sin cupones que dependan del cliente no pregunta por su historial", async () => {
    wordpress({ list: [row({ perUserLimited: false })] });

    await getCartCoupons("7");

    expect(
      vi.mocked(wpFetch).mock.calls.some(([p]) => String(p).includes("used-up")),
    ).toBe(false);
  });

  it("un invitado ve los que dependen del cliente solo si no los sabe agotados: con ellos no hay historial", async () => {
    wordpress({ list: [row({ code: "UNAVEZ", perUserLimited: true })] });

    // Sin sesión no hay lista de agotados: `shouldShowCoupon` espera saberla
    // (`usedUp` vacío, no `null`), así que el invitado lo ve.
    const codes = (await getCartCoupons(null)).map((c) => c.code);

    expect(codes).toEqual(["UNAVEZ"]);
  });
});

describe("fichas y categorías (ISR)", () => {
  it("la ficha recibe los cupones de su alcance, con ancestros de categoría", async () => {
    wordpress({
      list: [
        row({ code: "BEBIDAS", scope: "categories", categorySlugs: ["bebidas"] }),
        row({ code: "OTRA", scope: "categories", categorySlugs: ["ropa"] }),
      ],
    });
    vi.mocked(getCategoryPaths).mockResolvedValue(
      new Map([["cafe", ["cafe", "bebidas"]]]),
    );

    const coupons = await getProductCoupons({
      slug: "espresso",
      categorySlugs: ["cafe"],
    });

    expect(coupons.map((c) => c.code)).toEqual(["BEBIDAS"]);
  });

  it("la ficha sí recibe uno que empieza en unas horas (lo oculta el navegador), marcado como no vigente", async () => {
    wordpress({ list: [row({ code: "PRONTO", startsAt: secs(2 * 3600_000) })] });

    const [coupon] = await getProductCoupons({ slug: "x", categorySlugs: [] });

    expect(coupon).toMatchObject({ code: "PRONTO", activeAtRender: false });
  });

  it("la categoría recibe los cupones de todo el catálogo y de su categoría", async () => {
    wordpress({
      list: [
        row({ code: "TODO" }),
        row({ code: "CAFE", scope: "categories", categorySlugs: ["cafe"] }),
        row({ code: "PRODUCTOS", scope: "products", productSlugs: ["espresso"] }),
      ],
    });
    vi.mocked(getCategoryPaths).mockResolvedValue(new Map([["cafe", ["cafe"]]]));

    const codes = (await getCategoryCoupons("cafe")).map((c) => c.code);

    expect(codes).toEqual(["TODO", "CAFE"]);
  });
});

describe("cupones ya agotados por el cliente", () => {
  it("devuelve los códigos en minúsculas", async () => {
    wordpress({ usedUp: '{"codes":["HOLA10","Otro"]}' });

    expect([...(await getUsedUpCouponCodes("7"))]).toEqual(["hola10", "otro"]);
  });

  it("tolera un aviso de PHP impreso antes del JSON", async () => {
    wordpress({ usedUp: 'Deprecated: algo en x.php on line 3\n{"codes":["HOLA10"]}' });

    expect([...(await getUsedUpCouponCodes("7"))]).toEqual(["hola10"]);
  });

  it("un id de usuario que no es un entero positivo no llama a WordPress", async () => {
    expect((await getUsedUpCouponCodes("abc")).size).toBe(0);
    expect((await getUsedUpCouponCodes("0")).size).toBe(0);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("si WordPress falla, no quita nada", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("caído"));

    expect((await getUsedUpCouponCodes("7")).size).toBe(0);
  });
});
