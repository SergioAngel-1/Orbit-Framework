import { beforeEach, describe, expect, it, vi } from "vitest";
import { logReadFailure } from "@/lib/observability/read-failure";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { storeFetch } from "@/lib/woocommerce/store-client";
import {
  getMembershipCard,
  getMembershipHistory,
  getMembershipLevels,
  getMembershipPrices,
  getMembershipProduct,
  getMembershipState,
  getPublicMembershipPrices,
  MembershipsReadError,
  verifyMembershipCard,
} from "./server";

// ============================================================================
//  Lectores de servidor del módulo `memberships`.
//
//  Hablan con las rutas internas de WordPress por `wpFetch` (secreto interno) y
//  el `user_id` SIEMPRE lo pone el llamador desde la sesión. Un fallo de lectura
//  es un error tipado + evento; nunca un estado inventado.
// ============================================================================

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

const NO_STORE = { cache: "no-store" as const };
const LEVELS_INIT = { next: { revalidate: 300, tags: ["memberships"] } };

const LEVEL = {
  id: 101,
  name: "Socio",
  description: "<p>Ventajas de socio</p>",
  slug: "socio",
  slugs: { en: "member" },
  rank: 1,
  icon: "",
  color: "#112233",
  purchasable: true,
  min_account_age_days: 0,
  benefits: [{ key: "member_discount", percent: 10, scope: "all", categories: [] }],
  products: [
    { id: 501, price: "100.00", days: 30 },
    { id: 502, price: "50.00", days: null },
  ],
};

const MEMBER_STATE = {
  status: "active",
  level: { id: 102, rank: 5 },
  ends_at: "2026-11-01T10:00:00Z",
  member_since: "2026-01-02T03:04:05Z",
  member_number: "000123",
  queue: [
    {
      level_id: 101,
      rank: 1,
      seconds: 2_592_000,
      days: 30,
      starts_at: "2026-11-01T10:00:00Z",
      ends_at: "2026-12-01T10:00:00Z",
      undated: false,
      infinite: false,
    },
  ],
  benefits: [
    {
      key: "referral_gift",
      level_id: 102,
      public: { key: "referral_gift", level: 3, days: 30, max_per_period: 2 },
      state: { active: true, frozen: false, level_id: 102, gifts_remaining: 2 },
    },
  ],
  gifts: { max: 2, used: 0, remaining: 2 },
  purchasable: [
    {
      level_id: 101,
      ok: false,
      reason: "membership_already_indefinite",
      days_left: 0,
      min_account_age_days: 0,
      account_age_days: 200,
      current_level_id: 102,
    },
  ],
};

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(storeFetch).mockReset();
  vi.mocked(logReadFailure).mockReset();
});

describe("getMembershipLevels", () => {
  it("lee los niveles con ISR y el tag del módulo, y compone los textos", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [LEVEL] }));

    const levels = await getMembershipLevels("es");

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/levels?locale=es",
      LEVELS_INIT,
    );
    expect(levels).toEqual([
      {
        id: 101,
        name: "Socio",
        description: "<p>Ventajas de socio</p>",
        slug: "socio",
        slugs: { en: "member" },
        rank: 1,
        icon: "",
        color: "#112233",
        purchasable: true,
        min_account_age_days: 0,
        benefits: [
          {
            key: "member_discount",
            data: { key: "member_discount", percent: 10, scope: "all", categories: [] },
            text: "10% de descuento en todo el catálogo",
          },
        ],
        products: [
          { id: 501, price: "100.00", days: 30, duration: "30 días" },
          { id: 502, price: "50.00", days: null, duration: "Indefinida" },
        ],
      },
    ]);
  });

  it("tolera que PHP serialice un mapa vacío como lista", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({ items: [{ ...LEVEL, slugs: [], benefits: [], products: [] }] }),
    );

    const [level] = await getMembershipLevels("es");

    expect(level!.slugs).toEqual({});
    expect(level!.benefits).toEqual([]);
  });

  it("un 404 de WordPress se distingue de un fallo real", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ code: "rest_no_route" }, 404));

    await expect(getMembershipLevels("es")).rejects.toMatchObject({
      name: "MembershipsReadError",
      code: "not_found",
      status: 404,
    });
  });

  it("un fallo de WordPress registra el evento y lanza un error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error: "internal_error" }, 500));

    const failure = await getMembershipLevels("es").catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(MembershipsReadError);
    expect(failure).toMatchObject({ code: "unavailable", status: 500 });
    expect(logReadFailure).toHaveBeenCalledWith(
      "memberships.levels_failed",
      "HTTP 500",
      expect.objectContaining({ level: "error" }),
    );
  });

  it("una respuesta fuera de contrato también es un error tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: "no" }));

    await expect(getMembershipLevels("es")).rejects.toMatchObject({
      code: "invalid_response",
    });
  });
});

describe("getMembershipState", () => {
  it("lee el estado del usuario de la sesión, compone la cola y antepone el prefijo", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(MEMBER_STATE));

    const state = await getMembershipState(7, {
      locale: "es",
      memberNumberPrefix: "SOC-",
    });

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/me?user_id=7",
      NO_STORE,
    );
    expect(state.member_number).toBe("SOC-000123");
    expect(state.queue[0]!.duration).toBe("30 días");
    expect(state.benefits[0]).toEqual({
      key: "referral_gift",
      data: { key: "referral_gift", level: 3, days: 30, max_per_period: 2 },
      state: { active: true, frozen: false, level_id: 102, gifts_remaining: 2 },
      text: "Regala hasta 2 membresías de 30 días",
    });
    expect(state.purchasable[0]!.reason).toBe("membership_already_indefinite");
    expect(state.gifts).toEqual({ max: 2, used: 0, remaining: 2 });
  });

  it("un usuario inexistente es 200 con el estado vacío coherente", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({
        status: "none",
        level: null,
        ends_at: null,
        member_since: null,
        member_number: "",
        queue: [],
        benefits: [],
        gifts: { max: 0, used: 0, remaining: 0 },
        purchasable: [],
      }),
    );

    const state = await getMembershipState(999, { locale: "es" });

    expect(state).toMatchObject({
      status: "none",
      level: null,
      member_number: "",
      queue: [],
      benefits: [],
    });
  });

  it("un user_id inválido se rechaza antes de tocar WordPress", async () => {
    await expect(getMembershipState(0, { locale: "es" })).rejects.toMatchObject({
      code: "invalid_request",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("un 401 de la sesión no se convierte en un estado vacío", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error: "internal_error" }, 503));

    await expect(getMembershipState(7, { locale: "es" })).rejects.toMatchObject({
      code: "unavailable",
      status: 503,
    });
  });
});

describe("getMembershipHistory", () => {
  it("pagina y recorta `per_page` al tope de WordPress", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [], total: 0, pages: 0 }));

    await getMembershipHistory(7, { page: 2, perPage: 500 });

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/history?user_id=7&page=2&per_page=100",
      NO_STORE,
    );
  });

  it("un evento sin datos (`data: []` de PHP) se normaliza a objeto vacío", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({
        items: [
          {
            id: 10,
            type: "expired",
            level_from: 102,
            level_to: 0,
            data: [],
            created_at: "2026-10-02T10:00:00Z",
          },
        ],
        total: 1,
        pages: 1,
      }),
    );

    const page = await getMembershipHistory(7);

    expect(page.items[0]).toEqual({
      id: 10,
      type: "expired",
      level_from: 102,
      level_to: 0,
      data: {},
      created_at: "2026-10-02T10:00:00Z",
    });
  });

  it("conserva el tipo y los datos estructurados de cada evento", async () => {
    const item = {
      id: 9,
      type: "activated",
      level_from: 0,
      level_to: 102,
      data: { origin: "order", days: 30, status: "active" },
      created_at: "2026-10-01T10:00:00Z",
    };
    vi.mocked(wpFetch).mockResolvedValue(json({ items: [item], total: 1, pages: 1 }));

    const page = await getMembershipHistory(7);

    expect(page).toEqual({ items: [item], total: 1, pages: 1 });
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/history?user_id=7&page=1&per_page=20",
      NO_STORE,
    );
  });
});

describe("getMembershipPrices", () => {
  it("cotiza los ids deduplicados con el usuario de la sesión", async () => {
    const items = [
      { id: 601, regular: "25.00", price: "20.00", member_price: "18.00", percent: 10 },
    ];
    vi.mocked(wpFetch).mockResolvedValue(json({ items }));

    await expect(getMembershipPrices(7, [601, 601, 602])).resolves.toEqual(items);
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/prices?user_id=7&ids=601%2C602",
      NO_STORE,
    );
  });

  it("sin ids no consulta WordPress", async () => {
    await expect(getMembershipPrices(7, [])).resolves.toEqual([]);
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("más de 50 ids es una petición inválida", async () => {
    const ids = Array.from({ length: 51 }, (_, index) => index + 1);

    await expect(getMembershipPrices(7, ids)).rejects.toMatchObject({
      code: "invalid_request",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });
});

describe("getPublicMembershipPrices", () => {
  it("sin sesión lee los precios públicos del catálogo en unidades menores", async () => {
    vi.mocked(storeFetch).mockResolvedValue({
      data: [
        {
          id: 602,
          prices: { price: "1500", regular_price: "2000", currency_minor_unit: 2 },
        },
        {
          id: 601,
          prices: { price: "2000", regular_price: "2500", currency_minor_unit: 2 },
        },
      ],
      cartToken: null,
    });

    const prices = await getPublicMembershipPrices([601, 602, 999]);

    expect(storeFetch).toHaveBeenCalledWith(
      "/products?include=601%2C602%2C999&per_page=3",
    );
    expect(prices).toEqual([
      {
        id: 601,
        regular: "25.00",
        price: "20.00",
        member_price: "20.00",
        percent: 0,
      },
      {
        id: 602,
        regular: "20.00",
        price: "15.00",
        member_price: "15.00",
        percent: 0,
      },
    ]);
  });

  it("un fallo del catálogo es un 5xx tipado y se registra", async () => {
    vi.mocked(storeFetch).mockRejectedValue(new Error("sin red"));

    await expect(getPublicMembershipPrices([601])).rejects.toMatchObject({
      code: "unavailable",
    });
    expect(logReadFailure).toHaveBeenCalledWith(
      "memberships.public_prices_failed",
      expect.anything(),
      expect.objectContaining({ level: "error" }),
    );
  });
});

describe("getMembershipProduct", () => {
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
    hwe_membership: {
      required_level: { slug: "socio", rank: 1, name: "Socio" },
      opens_at: null,
    },
  };

  it("pide la ficha por slug y la devuelve sin caché compartida", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(PRODUCT));

    const product = await getMembershipProduct(7, {
      slug: "socio-plus",
      locale: "en",
    });

    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/product?user_id=7&slug=socio-plus&locale=en",
      NO_STORE,
    );
    expect(product).toMatchObject({
      id: 601,
      locked: true,
      hwe_membership: { required_level: { slug: "socio", rank: 1 } },
    });
  });

  it("un producto inexistente o no publicado es un 404 tipado", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ error: "not_found" }, 404));

    await expect(getMembershipProduct(7, { slug: "no-existe" })).rejects.toMatchObject({
      code: "not_found",
      status: 404,
    });
  });

  it("exige un slug o un id", async () => {
    await expect(getMembershipProduct(7, { slug: "  " })).rejects.toMatchObject({
      code: "invalid_request",
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });
});

describe("carné", () => {
  it("emite el carné con el user_id de la sesión", async () => {
    const issued = {
      token: "a".repeat(64),
      expires_at: "2026-01-02T00:00:00Z",
      verify_path: "/verify",
    };
    vi.mocked(wpFetch).mockResolvedValue(json(issued));

    await expect(getMembershipCard(7)).resolves.toEqual(issued);
    expect(wpFetch).toHaveBeenCalledWith(
      "/wp-json/hwe/v1/memberships/card?user_id=7",
      NO_STORE,
    );
  });

  it("sin nivel efectivo propaga el 403 tipado del carné", async () => {
    vi.mocked(wpFetch).mockResolvedValue(
      json({ error: "membership_card_unavailable" }, 403),
    );

    await expect(getMembershipCard(7)).rejects.toMatchObject({
      code: "forbidden",
      status: 403,
      reason: "membership_card_unavailable",
    });
  });

  it("un token con formato imposible no consulta WordPress", async () => {
    await expect(verifyMembershipCard("no-es-un-token")).resolves.toEqual({
      valid: false,
    });
    expect(wpFetch).not.toHaveBeenCalled();
  });

  it("una verificación válida devuelve solo los datos mínimos", async () => {
    const payload = {
      valid: true,
      level: { name: "Socio", slug: "socio" },
      member_since: "2026-01-02T03:04:05Z",
      member_number: "SOC-000123",
      member_name: "Ana P.",
    };
    vi.mocked(wpFetch).mockResolvedValue(json(payload));

    await expect(verifyMembershipCard("b".repeat(64))).resolves.toEqual(payload);
    expect(wpFetch).toHaveBeenCalledWith(
      `/wp-json/hwe/v1/memberships/verify?token=${"b".repeat(64)}`,
      NO_STORE,
    );
  });

  it("cualquier token no válido da exactamente `{ valid: false }`", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ valid: false }));

    await expect(verifyMembershipCard("c".repeat(64))).resolves.toEqual({
      valid: false,
    });
  });
});
