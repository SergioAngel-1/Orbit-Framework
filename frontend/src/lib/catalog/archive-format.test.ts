import { describe, expect, it } from "vitest";
import { groupProductsByInitial } from "./archive-format";
import type { ProductUrlEntry } from "./products";

const entry = (slug: string, es: string, en = es): ProductUrlEntry => ({
  slug,
  slugs: { es: slug, en: `${slug}-en` },
  names: { es, en },
  locales: ["es", "en"],
  hidden: false,
});

describe("groupProductsByInitial", () => {
  it("ordena con la colación del idioma y agrupa por inicial sin tilde", () => {
    const groups = groupProductsByInitial(
      [entry("b", "Bolso"), entry("a2", "Ánfora"), entry("a1", "abanico")],
      "es",
    );
    expect(groups.map((g) => g.initial)).toEqual(["A", "B"]);
    expect(groups[0].products.map((p) => p.name)).toEqual(["abanico", "Ánfora"]);
  });

  it("usa el nombre y el tramo del idioma de la página", () => {
    const [group] = groupProductsByInitial(
      [entry("camiseta", "Camiseta", "T-shirt")],
      "en",
    );
    expect(group.initial).toBe("T");
    expect(group.products[0]).toEqual({
      slug: "camiseta",
      urlSlug: "camiseta-en",
      name: "T-shirt",
    });
  });

  it("lo que no empieza por letra va al final, en «#»", () => {
    const groups = groupProductsByInitial(
      [entry("x", "3 en 1"), entry("z", "Zapato")],
      "es",
    );
    expect(groups.map((g) => g.initial)).toEqual(["Z", "#"]);
  });
});
