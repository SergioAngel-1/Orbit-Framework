import { describe, expect, it } from "vitest";
import { productLandingEntry } from "./product-landing-entry";
import { staticEntries } from "./registry";

describe("productLandingEntry", () => {
  const registry = staticEntries({
    "crema-guia": {
      label: "Crema",
      uniqueSections: [{ key: "beneficios", after: "hero" }],
      consumesBase: ["lead"],
    },
  });

  it("usa la entrada declarada: con ella viajan sus secciones únicas", () => {
    const entry = productLandingEntry(registry, "crema-guia", "Otra etiqueta");

    expect(entry.label).toBe("Crema");
    expect(entry.uniqueSections).toEqual([{ key: "beneficios", after: "hero" }]);
  });

  it("sin entrada usa un molde genérico válido", () => {
    const entry = productLandingEntry(registry, "nueva", "Nueva");

    expect(entry).toMatchObject({
      slug: "nueva",
      label: "Nueva",
      uniqueSections: [],
      consumesBase: [],
      dynamic: true,
    });
  });
});
