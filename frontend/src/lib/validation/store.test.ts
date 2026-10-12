import { describe, expect, it } from "vitest";
import { createReviewSchema, wishlistBodySchema } from "./store";

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
