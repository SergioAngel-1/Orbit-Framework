import { describe, expect, it } from "vitest";
import {
  createReviewSchema,
  orderReviewSchema,
  productReviewSchema,
  shippingDestinationSchema,
  wishlistBodySchema,
} from "./store";

describe("createReviewSchema", () => {
  const valid = { rating: 5, content: "Muy buen producto, lo recomiendo." };

  it("acepta valoración y texto", () => {
    expect(createReviewSchema.safeParse(valid).success).toBe(true);
  });

  it("no conserva el nombre ni el email: la identidad la fija el servidor desde la cuenta", () => {
    const result = createReviewSchema.parse({
      ...valid,
      name: "Otra persona",
      email: "victima@example.com",
    });

    expect(result).toEqual(valid);
    expect(result).not.toHaveProperty("name");
    expect(result).not.toHaveProperty("email");
  });

  it.each([
    ["valoración fuera de rango", { ...valid, rating: 6 }],
    ["valoración decimal", { ...valid, rating: 4.5 }],
    ["texto demasiado corto", { ...valid, content: "corto" }],
    ["texto ausente", { rating: 5 }],
  ])("rechaza %s", (_caso, body) => {
    expect(createReviewSchema.safeParse(body).success).toBe(false);
  });
});

describe("productReviewSchema y orderReviewSchema", () => {
  const valid = { rating: 5, content: "Muy buen producto, lo recomiendo." };

  it("`sameForOrder` y `products` valen false si no se envían", () => {
    expect(productReviewSchema.parse(valid).sameForOrder).toBe(false);
    expect(orderReviewSchema.parse(valid).products).toBe(false);
  });

  it("conservan la opción y siguen sin aceptar identidad del cuerpo", () => {
    const product = productReviewSchema.parse({
      ...valid,
      sameForOrder: true,
      name: "X",
    });
    const order = orderReviewSchema.parse({
      ...valid,
      products: true,
      email: "x@y.es",
    });

    expect(product).toEqual({ ...valid, sameForOrder: true });
    expect(order).toEqual({ ...valid, products: true });
  });

  it("mantienen los límites de la reseña", () => {
    expect(orderReviewSchema.safeParse({ rating: 5, content: "corto" }).success).toBe(
      false,
    );
  });
});

describe("wishlistBodySchema", () => {
  it("acepta un id de producto entero y positivo", () => {
    expect(wishlistBodySchema.parse({ productId: 42 })).toEqual({ productId: 42 });
  });

  it.each([
    ["ausente", {}],
    ["texto", { productId: "42" }],
    ["cero", { productId: 0 }],
    ["negativo", { productId: -3 }],
    ["decimal", { productId: 1.5 }],
    ["nulo", { productId: null }],
  ])("rechaza un productId %s", (_caso, body) => {
    expect(wishlistBodySchema.safeParse(body).success).toBe(false);
  });
});

describe("shippingDestinationSchema", () => {
  const base = { city: "Bogotá", country: "co", state: "CO-DC" };

  it("acepta el destino sin calle ni pin (compatibilidad) y normaliza el país", () => {
    const r = shippingDestinationSchema.parse(base);
    expect(r.country).toBe("CO");
    expect(r).not.toHaveProperty("location");
  });

  it("acepta calle y pin válidos", () => {
    const r = shippingDestinationSchema.parse({
      ...base,
      address_1: "  Calle 72 # 10-34 ",
      location: { lat: 4.6533, lng: -74.0836 },
    });
    expect(r.address_1).toBe("Calle 72 # 10-34");
    expect(r.location).toEqual({ lat: 4.6533, lng: -74.0836 });
  });

  it("`location: null` es el borrado explícito del pin; ausente no lo toca", () => {
    const cleared = shippingDestinationSchema.parse({ ...base, location: null });
    expect(cleared.location).toBeNull();
    expect(shippingDestinationSchema.parse(base).location).toBeUndefined();
  });

  it.each([
    ["pin 0,0", { lat: 0, lng: 0 }],
    ["pin fuera de rango", { lat: 200, lng: -74 }],
    ["pin con cadenas", { lat: "4.6", lng: "-74" }],
    ["pin NaN", { lat: NaN, lng: -74 }],
  ])("rechaza un destino con %s (422 en el BFF)", (_caso, location) => {
    expect(
      shippingDestinationSchema.safeParse({ ...base, address_1: "Calle 1", location })
        .success,
    ).toBe(false);
  });
});
