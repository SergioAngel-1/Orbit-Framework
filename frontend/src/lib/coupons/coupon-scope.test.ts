import { describe, expect, it } from "vitest";
import {
  couponAppliesToCart,
  couponAppliesToCategory,
  couponBenefit,
  couponFitsSubtotal,
  couponMessageType,
  formatCouponDiscount,
  couponAppliesToProduct,
  isCouponActive,
  nextCouponBoundary,
  parsePublicCoupons,
  shouldShowCoupon,
  type PublicCoupon,
} from "./coupon-scope";

function coupon(overrides: Partial<PublicCoupon> = {}): PublicCoupon {
  return {
    code: "bienvenida10",
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

describe("parsePublicCoupons", () => {
  it("normaliza la respuesta y descarta filas inválidas", () => {
    const parsed = parsePublicCoupons({
      items: [
        {
          code: "bienvenida10",
          discountType: "percent",
          amount: 10,
          scope: "categories",
          categorySlugs: ["formulas", 3, ""],
          requiresLogin: true,
          firstOrderOnly: true,
          startsAt: 1790830800,
          endsAt: 1793509200,
        },
        { code: "", discountType: "percent", amount: 10, scope: "all" },
        { code: "x", discountType: "percent", amount: 0, scope: "all" },
        { code: "y", discountType: "bogus", amount: 5, scope: "all" },
        { code: "z", discountType: "fixed_cart", amount: 5, scope: "nope" },
        null,
      ],
    });
    expect(parsed).toHaveLength(1);
    expect(parsed[0]).toMatchObject({
      code: "bienvenida10",
      scope: "categories",
      categorySlugs: ["formulas"],
      requiresLogin: true,
      firstOrderOnly: true,
      startsAt: 1790830800,
      endsAt: 1793509200,
      excludeSaleItems: false,
    });
  });

  it("un cupón de solo envío gratis se anuncia aunque su importe sea 0", () => {
    const parsed = parsePublicCoupons({
      items: [
        {
          code: "envio",
          discountType: "fixed_cart",
          amount: 0,
          freeShipping: true,
          scope: "all",
        },
        {
          code: "nada",
          discountType: "fixed_cart",
          amount: 0,
          freeShipping: false,
          scope: "all",
        },
      ],
    });
    expect(parsed.map((c) => c.code)).toEqual(["envio"]);
    expect(parsed[0].freeShipping).toBe(true);
  });

  it("un alcance con la lista vacía aplica a todo, como en WooCommerce", () => {
    const [c] = parsePublicCoupons({
      items: [{ code: "a", discountType: "percent", amount: 5, scope: "products" }],
    });
    expect(c.scope).toBe("all");
  });

  it("sin `items` devuelve lista vacía", () => {
    expect(parsePublicCoupons(null)).toEqual([]);
    expect(parsePublicCoupons({ items: "x" })).toEqual([]);
  });
});

describe("ventana de fechas", () => {
  const c = coupon({ startsAt: 1000, endsAt: 2000 });

  it("vale desde el inicio (incluido) hasta el fin (excluido)", () => {
    expect(isCouponActive(c, 999_999)).toBe(false);
    expect(isCouponActive(c, 1_000_000)).toBe(true);
    expect(isCouponActive(c, 1_999_999)).toBe(true);
    expect(isCouponActive(c, 2_000_000)).toBe(false);
  });

  it("sin fechas siempre vale", () => {
    expect(isCouponActive(coupon(), 0)).toBe(true);
  });

  it("programa el siguiente cambio de estado", () => {
    expect(nextCouponBoundary(c, 500_000)).toBe(1_000_000);
    expect(nextCouponBoundary(c, 1_500_000)).toBe(2_000_000);
    expect(nextCouponBoundary(c, 2_500_000)).toBeNull();
  });
});

describe("couponAppliesToProduct", () => {
  const product = {
    slug: "taza",
    categorySlugs: ["formulas", "infusiones"],
    onSale: false,
  };

  it("por categoría, también si la categoría es una ancestra", () => {
    expect(
      couponAppliesToProduct(
        coupon({ scope: "categories", categorySlugs: ["infusiones"] }),
        product,
      ),
    ).toBe(true);
    expect(
      couponAppliesToProduct(
        coupon({ scope: "categories", categorySlugs: ["libros"] }),
        product,
      ),
    ).toBe(false);
  });

  it("por producto", () => {
    expect(
      couponAppliesToProduct(
        coupon({ scope: "products", productSlugs: ["taza"] }),
        product,
      ),
    ).toBe(true);
    expect(
      couponAppliesToProduct(
        coupon({ scope: "products", productSlugs: ["dormiya"] }),
        product,
      ),
    ).toBe(false);
  });

  it("respeta las exclusiones y los artículos rebajados", () => {
    expect(
      couponAppliesToProduct(coupon({ excludedProductSlugs: ["taza"] }), product),
    ).toBe(false);
    expect(
      couponAppliesToProduct(coupon({ excludedCategorySlugs: ["formulas"] }), product),
    ).toBe(false);
    expect(
      couponAppliesToProduct(coupon({ excludeSaleItems: true }), {
        ...product,
        onSale: true,
      }),
    ).toBe(false);
  });
});

describe("couponAppliesToCategory", () => {
  const path = ["te-clasico", "infusiones"];

  it("se anuncia en la categoría del alcance y en sus descendientes", () => {
    const c = coupon({ scope: "categories", categorySlugs: ["infusiones"] });
    expect(couponAppliesToCategory(c, path)).toBe(true);
    expect(couponAppliesToCategory(c, ["libros"])).toBe(false);
  });

  it("uno de productos sueltos no se anuncia en ninguna categoría", () => {
    expect(
      couponAppliesToCategory(coupon({ scope: "products", productSlugs: ["x"] }), path),
    ).toBe(false);
  });

  it("uno de todo el catálogo, en todas salvo las excluidas", () => {
    expect(couponAppliesToCategory(coupon(), path)).toBe(true);
    expect(
      couponAppliesToCategory(coupon({ excludedCategorySlugs: ["infusiones"] }), path),
    ).toBe(false);
  });
});

describe("couponBenefit / formatCouponDiscount", () => {
  it("distingue descuento, envío gratis y ambos", () => {
    expect(couponBenefit(coupon())).toBe("discount");
    expect(couponBenefit(coupon({ freeShipping: true }))).toBe("both");
    expect(couponBenefit(coupon({ amount: 0, freeShipping: true }))).toBe("shipping");
  });

  it("formatea porcentaje e importe", () => {
    expect(formatCouponDiscount(coupon(), "es", "EUR")).toMatch(/10\s?%/);
    expect(
      formatCouponDiscount(
        coupon({ discountType: "fixed_cart", amount: 5000 }),
        "es",
        "EUR",
        0,
      ),
    ).toMatch(/5000|5\.000/);
  });
});

describe("couponAppliesToCart", () => {
  const price = { regular_price: "1000", sale_price: "1000" };
  const item = (slug: string, categorySlugs: string[]) => ({
    couponRef: { slug, categorySlugs },
    prices: price,
  });

  it("aplica si algún artículo está en su alcance", () => {
    const c = coupon({ scope: "categories", categorySlugs: ["cafes"] });
    expect(
      couponAppliesToCart(c, [
        item("jabon", ["jabones"]),
        item("taza", ["formulas", "cafes"]),
      ]),
    ).toBe(true);
    expect(couponAppliesToCart(c, [item("jabon", ["jabones"])])).toBe(false);
  });

  it("un artículo rebajado no cuenta si el cupón excluye rebajas", () => {
    const c = coupon({ excludeSaleItems: true });
    expect(
      couponAppliesToCart(c, [
        {
          couponRef: { slug: "a", categorySlugs: [] },
          prices: { regular_price: "1000", sale_price: "800" },
        },
      ]),
    ).toBe(false);
  });

  it("sin referencia del catálogo solo cuenta un cupón de todo el catálogo", () => {
    const loose = [{ couponRef: null, prices: price }];
    expect(couponAppliesToCart(coupon(), loose)).toBe(true);
    expect(
      couponAppliesToCart(coupon({ scope: "products", productSlugs: ["a"] }), loose),
    ).toBe(false);
  });
});

describe("gasto mínimo y máximo", () => {
  it("parsea los límites y trata 0 o texto como sin límite", () => {
    const [a, b] = parsePublicCoupons({
      items: [
        {
          code: "a",
          discountType: "percent",
          amount: 10,
          scope: "all",
          minimumAmount: 150000,
          maximumAmount: 0,
        },
        {
          code: "b",
          discountType: "percent",
          amount: 10,
          scope: "all",
          minimumAmount: "150000",
        },
      ],
    });
    expect(a).toMatchObject({ minimumAmount: 150000, maximumAmount: null });
    expect(b).toMatchObject({ minimumAmount: null, maximumAmount: null });
  });

  it("el mínimo se compara con el subtotal más alto y el máximo con el más bajo", () => {
    const c = coupon({ minimumAmount: 100, maximumAmount: 500 });
    expect(couponFitsSubtotal(c, { exclTax: 90, inclTax: 99 })).toBe(false);
    // Con impuestos llega al mínimo: se ofrece (WordPress decide según su ajuste).
    expect(couponFitsSubtotal(c, { exclTax: 90, inclTax: 107 })).toBe(true);
    expect(couponFitsSubtotal(c, { exclTax: 501, inclTax: 600 })).toBe(false);
    expect(couponFitsSubtotal(c, { exclTax: 480, inclTax: 520 })).toBe(true);
    expect(couponFitsSubtotal(coupon(), { exclTax: 1, inclTax: 1 })).toBe(true);
  });

  it("un carrito por debajo del mínimo no recibe la oferta", () => {
    const c = coupon({ minimumAmount: 100 });
    const items = [
      {
        couponRef: { slug: "a", categorySlugs: [] },
        prices: { regular_price: "1000", sale_price: "1000" },
      },
    ];
    expect(couponAppliesToCart(c, items, { exclTax: 50, inclTax: 50 })).toBe(false);
    expect(couponAppliesToCart(c, items, { exclTax: 150, inclTax: 150 })).toBe(true);
    expect(couponAppliesToCart(c, items)).toBe(true);
  });
});

describe("couponMessageType", () => {
  it("distingue cómo se aplica el importe", () => {
    expect(couponMessageType(coupon({ discountType: "fixed_cart" }))).toBe(
      "fixed_cart",
    );
    expect(couponMessageType(coupon({ discountType: "fixed_product" }))).toBe(
      "fixed_product",
    );
    expect(couponMessageType(coupon())).toBe("percent");
  });

  it("un cupón de solo envío gratis no lleva complemento de importe", () => {
    expect(
      couponMessageType(
        coupon({ discountType: "fixed_cart", amount: 0, freeShipping: true }),
      ),
    ).toBe("percent");
  });
});

describe("shouldShowCoupon — el único criterio de visibilidad", () => {
  const limited = coupon({ perUserLimited: true });
  const open = coupon({ code: "ENVIO", perUserLimited: false });

  it("fuera de su ventana nunca se enseña", () => {
    expect(shouldShowCoupon(open, { active: false, usedUp: new Set() })).toBe(false);
    expect(shouldShowCoupon(limited, { active: false, usedUp: new Set() })).toBe(false);
  });

  it("un cupón que no depende del cliente se enseña sin esperar su historial", () => {
    expect(shouldShowCoupon(open, { active: true, usedUp: null })).toBe(true);
    expect(shouldShowCoupon(open, { active: true, usedUp: new Set(["envio"]) })).toBe(
      true,
    );
  });

  it("uno que depende del cliente espera a saber si ya lo agotó", () => {
    expect(shouldShowCoupon(limited, { active: true, usedUp: null })).toBe(false);
    expect(shouldShowCoupon(limited, { active: true, usedUp: new Set() })).toBe(true);
  });

  it("no se enseña al cliente que ya lo agotó, sin distinguir mayúsculas", () => {
    expect(
      shouldShowCoupon(limited, { active: true, usedUp: new Set(["bienvenida10"]) }),
    ).toBe(false);
    expect(
      shouldShowCoupon(coupon({ code: "BIENVENIDA10", perUserLimited: true }), {
        active: true,
        usedUp: new Set(["bienvenida10"]),
      }),
    ).toBe(false);
  });
});
