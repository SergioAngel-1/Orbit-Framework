import { describe, expect, it } from "vitest";
import { IntlMessageFormat } from "intl-messageformat";
import es from "@/i18n/messages/es.json";
import en from "@/i18n/messages/en.json";

// El rótulo de un cupón depende de CÓMO se aplica su importe: «$20.000 de
// descuento en este producto» es falso para un `fixed_cart`.

function fmt(message: string, locale: string, values: Record<string, string>) {
  return String(new IntlMessageFormat(message, locale).format(values));
}

const base = { discount: "$20.000", first: "no", scope: "all", categories: "" };

describe("rótulos de cupón según el tipo de descuento", () => {
  it("ficha: el importe fijo de carrito se dice sobre la compra, no sobre el producto", () => {
    const m = es.coupons.title.product;
    expect(fmt(m, "es", { ...base, kind: "discount", type: "percent" })).toBe(
      "$20.000 de descuento en este producto",
    );
    expect(fmt(m, "es", { ...base, kind: "discount", type: "fixed_cart" })).toBe(
      "$20.000 de descuento en tu compra",
    );
    expect(fmt(m, "es", { ...base, kind: "discount", type: "fixed_product" })).toBe(
      "$20.000 de descuento por cada unidad de este producto",
    );
  });

  it("categoría y envío gratis", () => {
    const m = es.coupons.title.category;
    expect(fmt(m, "es", { ...base, kind: "discount", type: "fixed_product" })).toBe(
      "$20.000 de descuento por cada producto de esta categoría",
    );
    expect(fmt(m, "es", { ...base, kind: "shipping", type: "percent" })).toBe(
      "Envío gratis en esta categoría",
    );
  });

  it("login/registro y «Cupones disponibles»", () => {
    const auth = es.coupons.title.auth;
    expect(
      fmt(auth, "es", { ...base, kind: "discount", type: "fixed_cart", first: "yes" }),
    ).toBe("$20.000 de descuento en tu primera compra");
    expect(
      fmt(auth, "es", {
        ...base,
        kind: "discount",
        type: "fixed_product",
        scope: "categories",
        categories: "Infusiones",
      }),
    ).toBe("$20.000 de descuento en Infusiones por producto");
    const offer = es.cart.offers.title;
    expect(fmt(offer, "es", { ...base, kind: "both", type: "fixed_cart" })).toBe(
      "$20.000 de descuento y envío gratis en tu compra",
    );
    expect(fmt(offer, "es", { ...base, kind: "discount", type: "percent" })).toBe(
      "$20.000 de descuento",
    );
  });

  it("inglés", () => {
    expect(
      fmt(en.coupons.title.product, "en", {
        ...base,
        kind: "discount",
        type: "fixed_cart",
      }),
    ).toBe("$20.000 off your order");
    expect(
      fmt(en.coupons.title.product, "en", {
        ...base,
        kind: "discount",
        type: "percent",
      }),
    ).toBe("$20.000 off this product");
    expect(
      fmt(en.cart.offers.title, "en", {
        ...base,
        kind: "discount",
        type: "fixed_product",
      }),
    ).toBe("$20.000 off per product");
  });
});
