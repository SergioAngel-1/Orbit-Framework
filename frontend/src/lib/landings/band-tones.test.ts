import { describe, expect, it } from "vitest";
import { assignContentTones, type FixedTone } from "./band-tones";

const flow = (...ids: string[]) => ids.map((id) => ({ id }));

describe("assignContentTones", () => {
  it("alterna desde el hero (que abre en background)", () => {
    const tones = assignContentTones(flow("hero", "s1", "s2", "s3"), {
      hero: "background",
    });

    expect([...tones.entries()]).toEqual([
      ["s1", "surface"],
      ["s2", "background"],
      ["s3", "surface"],
    ]);
  });

  it("una banda de color propio no recibe tono ni corta la cadena", () => {
    const tones = assignContentTones(flow("hero", "s1", "quote", "s2"), {
      hero: "background",
      quote: "other",
    });

    expect(tones.get("s1")).toBe("surface");
    expect(tones.get("s2")).toBe("background");
    expect(tones.has("quote")).toBe(false);
  });

  it("mira hacia delante: no deja dos bandas iguales seguidas ante un color fijo", () => {
    const fixed: Record<string, FixedTone> = {
      hero: "background",
      closing: "background",
    };

    const tones = assignContentTones(flow("hero", "s1", "s2", "closing"), fixed);

    expect(tones.get("s1")).toBe("surface");
    // s2 alternaría a `background`, igual que el cierre que le sigue: se invierte.
    expect(tones.get("s2")).toBe("surface");
  });

  it("un color fijo reinicia la alternancia", () => {
    const tones = assignContentTones(flow("hero", "lead", "s1"), {
      hero: "background",
      lead: "surface",
    });

    expect(tones.get("s1")).toBe("background");
  });

  it("sin nodos no hay tonos", () => {
    expect(assignContentTones([], {}).size).toBe(0);
  });
});
