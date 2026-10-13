// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import type { NoticeCoupon } from "@/lib/coupons/coupon-scope";
import { CartCouponOffers } from "./cart-coupon-offers";

// «Cupones disponibles»: solo los que aplican a algo del carrito y no están ya
// aplicados; «Usar» los aplica y los que exigen sesión mandan al invitado a
// iniciarla.

const applyCoupon = vi.fn();
const cartState = vi.hoisted(() => ({ cart: null as Record<string, unknown> | null }));

vi.mock("@/i18n/navigation", () => ({
  Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
vi.mock("@/components/cart/cart-context", () => ({
  useCart: () => ({ cart: cartState.cart, loading: false, applyCoupon }),
}));

const price = { regular_price: "2500", sale_price: "2500" };

function cart(overrides: Record<string, unknown> = {}) {
  return {
    items: [
      {
        id: 3,
        prices: price,
        couponRef: { slug: "camiseta", categorySlugs: ["ropa"] },
      },
    ],
    coupons: [],
    totals: {
      total_items: "5000",
      total_items_tax: "0",
      currency_code: "EUR",
      currency_minor_unit: 2,
    },
    ...overrides,
  };
}

function coupon(overrides: Partial<NoticeCoupon> = {}): NoticeCoupon {
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
    activeAtRender: true,
    ...overrides,
  };
}

function renderOffers(coupons: NoticeCoupon[], guest = false) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <CartCouponOffers coupons={coupons} guest={guest} />
    </NextIntlClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  cartState.cart = cart();
});
afterEach(cleanup);

describe("CartCouponOffers", () => {
  it("ofrece un cupón que aplica y «Usar» lo aplica con su código", () => {
    renderOffers([coupon()]);

    fireEvent.click(screen.getByRole("button", { name: /Usar el cupón HOLA10/ }));

    expect(applyCoupon).toHaveBeenCalledWith("HOLA10");
  });

  it("no ofrece un cupón de categorías que no tiene nada del carrito", () => {
    renderOffers([coupon({ scope: "categories", categorySlugs: ["bebidas"] })]);

    expect(screen.queryByRole("button")).toBeNull();
  });

  it("no ofrece uno cuyo gasto mínimo el carrito no alcanza", () => {
    renderOffers([coupon({ minimumAmount: 100 })]);

    expect(screen.queryByRole("button")).toBeNull();
  });

  it("no repite el que ya está aplicado", () => {
    cartState.cart = cart({ coupons: [{ code: "hola10" }] });

    renderOffers([coupon()]);

    expect(screen.queryByRole("button")).toBeNull();
  });

  it("a un invitado, un cupón que exige sesión le ofrece iniciarla, no aplicarlo", () => {
    renderOffers([coupon({ requiresLogin: true })], true);

    expect(screen.queryByRole("button")).toBeNull();
    expect(
      screen.getByRole("link", { name: /Inicia sesión para usar el cupón HOLA10/ }),
    ).toBeTruthy();
  });

  it("con sesión, el mismo cupón se aplica", () => {
    renderOffers([coupon({ requiresLogin: true })], false);

    expect(screen.getByRole("button", { name: /Usar el cupón HOLA10/ })).toBeTruthy();
  });

  it("sin carrito o sin cupones no pinta nada", () => {
    cartState.cart = null;
    const { container } = renderOffers([coupon()]);

    expect(container.firstChild).toBeNull();
  });
});
