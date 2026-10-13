import { describe, expect, it } from "vitest";
import {
  canConfirmReceipt,
  canRateOrder,
  orderReviewState,
  reviewableProductIds,
  siteReviewFromMeta,
} from "./order-review";
import type { WooOrderLineItem } from "@/types/woocommerce";

describe("canRateOrder", () => {
  it("solo Completado se califica", () => {
    expect(canRateOrder("completed")).toBe(true);
  });
  it.each(["processing", "pending", "on-hold", "cancelled", "failed", "refunded", "x"])(
    "%s no",
    (s) => {
      expect(canRateOrder(s)).toBe(false);
    },
  );
});

describe("canConfirmReceipt", () => {
  it("solo un pedido Procesando se marca como recibido", () => {
    expect(canConfirmReceipt("processing")).toBe(true);
    expect(canConfirmReceipt("completed")).toBe(false);
    expect(canConfirmReceipt("on-hold")).toBe(false);
    expect(canConfirmReceipt("pending")).toBe(false);
  });
});

describe("siteReviewFromMeta", () => {
  it("lee el espejo del pedido", () => {
    expect(
      siteReviewFromMeta([
        { key: "_otra_meta", value: "x" },
        {
          key: "_hwe_site_review",
          value: {
            id: 9,
            rating: 4,
            content: "Muy bien",
            date: "2026-10-07T12:00:00+00:00",
          },
        },
      ]),
    ).toEqual({ rating: 4, content: "Muy bien", date: "2026-10-07T12:00:00+00:00" });
  });

  it("sin espejo o con uno roto, sin reseña", () => {
    expect(siteReviewFromMeta(undefined)).toBeNull();
    expect(siteReviewFromMeta([])).toBeNull();
    expect(siteReviewFromMeta([{ key: "_hwe_site_review", value: "" }])).toBeNull();
    expect(
      siteReviewFromMeta([{ key: "_hwe_site_review", value: { rating: 9 } }]),
    ).toBeNull();
  });
});

describe("orderReviewState", () => {
  const lines = [
    { id: 1, name: "x", product_id: 3, quantity: 1 },
  ] as WooOrderLineItem[];
  const reviewed = [
    { key: "_hwe_site_review", value: { rating: 3, content: "", date: "" } },
  ];

  it("Completado sin reseña: calificar, con sus productos", () => {
    expect(orderReviewState({ status: "completed", line_items: lines })).toEqual({
      show: true,
      review: null,
      mode: "rate",
      productCount: 1,
      allProductsReviewed: false,
      someProductsReviewed: false,
    });
  });

  it("Procesando: «Recibí mi pedido»", () => {
    expect(orderReviewState({ status: "processing", line_items: lines }).mode).toBe(
      "receive",
    );
  });

  it.each(["pending", "on-hold", "cancelled"])("%s sin reseña: nada", (status) => {
    expect(orderReviewState({ status, line_items: lines })).toMatchObject({
      show: false,
      mode: null,
    });
  });

  it("con reseña se enseña siempre y no ofrece nada más", () => {
    expect(
      orderReviewState({ status: "refunded", line_items: lines, meta_data: reviewed }),
    ).toMatchObject({ show: true, mode: null, review: { rating: 3 } });
  });
});

describe("orderReviewState — productos ya reseñados", () => {
  const three = [3, 4, 5].map((product_id) => ({
    id: product_id,
    name: "x",
    product_id,
    quantity: 1,
  })) as WooOrderLineItem[];

  it("cuenta solo los que faltan por reseñar", () => {
    expect(
      orderReviewState({ status: "completed", line_items: three }, new Set([4])),
    ).toMatchObject({
      mode: "rate",
      productCount: 2,
      someProductsReviewed: true,
      allProductsReviewed: false,
    });
  });

  it("todos reseñados: se sigue pudiendo calificar el pedido, sin check", () => {
    expect(
      orderReviewState({ status: "completed", line_items: three }, new Set([3, 4, 5])),
    ).toMatchObject({
      mode: "rate",
      productCount: 0,
      allProductsReviewed: true,
      someProductsReviewed: false,
    });
  });

  it("sin productos reseñables no dice «ya los reseñaste»", () => {
    expect(
      orderReviewState({ status: "completed", line_items: [] }).allProductsReviewed,
    ).toBe(false);
  });
});

describe("reviewableProductIds", () => {
  it("deduplica y descarta productos borrados", () => {
    const line = (product_id: number) =>
      ({ id: product_id, name: "x", product_id, quantity: 1 }) as WooOrderLineItem;
    expect(reviewableProductIds([line(3), line(3), line(0), line(5)])).toEqual([3, 5]);
  });

  it("excluye las líneas marcadas como no reseñables (su ficha no muestra reseñas)", () => {
    const lines = [
      { id: 1, name: "Camiseta", product_id: 3, quantity: 1 },
      {
        id: 2,
        name: "Pack a medida",
        product_id: 9,
        quantity: 1,
        meta_data: [{ key: "_hwe_not_reviewable", value: "1" }],
      },
    ] as WooOrderLineItem[];
    expect(reviewableProductIds(lines)).toEqual([3]);
  });
});
