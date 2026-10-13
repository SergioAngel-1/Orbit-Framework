import { describe, expect, it } from "vitest";
import { instanceRegistry } from "@/instance/registry";
import { buildBaseFlow } from "@/lib/landings/base-flow";
import { buildEditorialRegistry, findEditorialByPath, staticEntries } from "@/lib/landings/registry";
import { resolveSections, unplacedAnchors } from "@/lib/landings/resolve-sections";
import { UNIQUE_SECTIONS } from "@/components/landings/editorial/unique-sections";

// La landing con diseño propio del ejemplo: su entrada llega al registro, su sección única se
// inserta tras `s1`, absorbe la FAQ y tiene pieza de código.

const section = (id: "s1" | "s2") => ({
  id,
  layout: "image-right" as const,
  kicker: null,
  title: id,
  body: null,
  image: null,
  imageAlt: null,
  image2: null,
  image2Alt: null,
});

describe("landing de ejemplo", () => {
  const registry = buildEditorialRegistry("", undefined, staticEntries());
  const entry = registry["example-guide"]!;

  it("está en el registro, en el área, y se resuelve por su tramo", () => {
    expect(entry).toMatchObject({ base: "area", dynamic: false, jsonLd: "article" });
    expect(findEditorialByPath(registry, "area", "example-guide", "es")?.slug).toBe(
      "example-guide",
    );
  });

  it("su única va tras s1, la FAQ queda fuera del render y no hay anclas huérfanas", () => {
    const base = buildBaseFlow(
      {
        lead: null,
        sections: [section("s1"), section("s2")],
        quote: null,
        faq: { title: null, items: [{ question: "¿?", answer: "<p>x</p>" }] },
        products: null,
        closing: null,
      },
      entry,
    );

    const ids = resolveSections(base, entry).map((node) => node.id);

    expect(ids).toEqual(["hero", "s1", "unique:example-callout", "s2"]);
    expect(unplacedAnchors(base, entry)).toEqual([]);
  });

  it("la clave declarada tiene su pieza de código", () => {
    for (const { key } of instanceRegistry.landings!.staticEntries["example-guide"]!
      .uniqueSections!) {
      expect(UNIQUE_SECTIONS[key], key).toBeTypeOf("function");
    }
  });
});
