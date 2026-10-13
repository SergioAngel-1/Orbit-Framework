import { describe, expect, it } from "vitest";
import { pickTopTags } from "./top-tags";

const tag = (slug: string, count?: number | null) => ({ slug, count });

describe("pickTopTags", () => {
  it("se queda con las más usadas y deja el resto para «+N»", () => {
    const { visible, hidden } = pickTopTags(
      [tag("a", 2), tag("b", 30), tag("c", 5), tag("d", 1)],
      2,
    );
    expect(visible.map((t) => t.slug)).toEqual(["b", "c"]);
    expect(hidden.map((t) => t.slug)).toEqual(["a", "d"]);
  });

  it("un empate o sin `count` conserva el orden de WordPress", () => {
    const { visible } = pickTopTags(
      [tag("a"), tag("b", 3), tag("c", 3), tag("d", null)],
      2,
    );
    expect(visible.map((t) => t.slug)).toEqual(["b", "c"]);
  });

  it("con menos etiquetas que el límite, ninguna queda fuera", () => {
    expect(pickTopTags([tag("a", 1)], 2)).toEqual({
      visible: [tag("a", 1)],
      hidden: [],
    });
  });
});
