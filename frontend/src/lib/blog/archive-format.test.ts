import { describe, it, expect } from "vitest";
import { groupPostsByYear } from "@/lib/blog/archive-format";

const post = (slug: string, date: string) => ({ slug, title: slug, date });

describe("groupPostsByYear", () => {
  it("agrupa por año, el más reciente primero, y respeta el orden dado dentro de cada año", () => {
    const groups = groupPostsByYear([
      post("c", "2026-09-01T15:46:17"),
      post("b", "2026-03-10T08:00:00"),
      post("a", "2025-12-31T23:59:59"),
      post("z", "2023-01-02T00:00:00"),
    ]);
    expect(groups.map((g) => g.year)).toEqual(["2026", "2025", "2023"]);
    expect(groups[0].posts.map((p) => p.slug)).toEqual(["c", "b"]);
  });

  it("lee el año de la fecha de WordPress SIN pasarla por la zona horaria", () => {
    // `2025-12-31T23:59:59` es 31 de diciembre en la hora del sitio: convertirla a
    // UTC la mandaría a 2026 y la entrada saldría bajo el año equivocado.
    expect(groupPostsByYear([post("a", "2025-12-31T23:59:59")])[0].year).toBe("2025");
  });

  it("una fecha ilegible no pierde la entrada: va a un grupo aparte, al final", () => {
    const groups = groupPostsByYear([post("a", "2026-01-01T00:00:00"), post("x", "")]);
    expect(groups.map((g) => g.year)).toEqual(["2026", ""]);
    expect(groups[1].posts.map((p) => p.slug)).toEqual(["x"]);
  });

  it("sin entradas, sin grupos", () => {
    expect(groupPostsByYear([])).toEqual([]);
  });
});
