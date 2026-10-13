import { describe, expect, it } from "vitest";
import { editorialProductSlugs } from "./product-bound-landings";

const config = (editorial: Record<string, unknown>, enabled = true) =>
  ({
    modules: { landings: enabled },
    landings: { registry: "a | A | article\nb | B | article", editorial },
  }) as never;

describe("editorialProductSlugs", () => {
  it("lista los productos de las landings publicadas y registradas", () => {
    const slugs = editorialProductSlugs(
      config({
        a: { published: true, product: " camiseta " },
        b: { published: false, product: "gorra" },
        c: { published: true, product: "no-registrada" },
        d: { published: true },
      }),
    );

    expect(slugs).toEqual(["camiseta"]);
  });

  it("con el módulo apagado no hay ninguno", () => {
    expect(
      editorialProductSlugs(
        config({ a: { published: true, product: "camiseta" } }, false),
      ),
    ).toEqual([]);
  });
});
