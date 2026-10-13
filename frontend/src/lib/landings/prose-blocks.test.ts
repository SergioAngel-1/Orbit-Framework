import { describe, expect, it } from "vitest";
import { splitByH3 } from "./prose-blocks";

describe("splitByH3", () => {
  it("sin subtítulos devuelve todo como introducción (prosa corrida)", () => {
    expect(splitByH3("<p>Texto</p>")).toEqual({ intro: "<p>Texto</p>", blocks: [] });
  });

  it("separa la introducción y un bloque por cada <h3>", () => {
    const result = splitByH3(
      "<p>Intro</p><h3>Uno</h3><p>Texto uno</p><h3><em>Dos</em></h3><p>Texto dos</p>",
    );

    expect(result.intro).toBe("<p>Intro</p>");
    expect(result.blocks.map((b) => b.heading)).toEqual(["Uno", "Dos"]);
    expect(result.blocks[0]!.html).toBe("<p>Texto uno</p>");
  });

  it("los párrafos finales que son solo un enlace pasan a ser CTA del bloque", () => {
    const result = splitByH3(
      '<h3>Uno</h3><p>Texto</p><p><a href="/products">Ver productos</a></p><p><a href="https://x.example">Otro</a></p>',
    );

    expect(result.blocks[0]!.html).toBe("<p>Texto</p>");
    expect(result.blocks[0]!.ctas).toEqual([
      { href: "/products", label: "Ver productos" },
      { href: "https://x.example", label: "Otro" },
    ]);
  });

  it("un enlace en mitad de la prosa nunca se convierte en CTA", () => {
    const result = splitByH3(
      '<h3>Uno</h3><p><a href="/x">Enlace</a></p><p>Texto final</p>',
    );

    expect(result.blocks[0]!.ctas).toEqual([]);
    expect(result.blocks[0]!.html).toContain("Enlace");
  });

  it("vacío o nulo da vacío", () => {
    expect(splitByH3("")).toEqual({ intro: "", blocks: [] });
    expect(splitByH3(null)).toEqual({ intro: "", blocks: [] });
  });
});
