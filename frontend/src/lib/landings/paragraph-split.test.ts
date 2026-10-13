import { describe, expect, it } from "vitest";
import { splitFirstBlock } from "./paragraph-split";

describe("splitFirstBlock", () => {
  it("separa el primer párrafo del resto", () => {
    expect(splitFirstBlock("<p>Uno</p><p>Dos</p><p>Tres</p>")).toEqual({
      first: "<p>Uno</p>",
      rest: "<p>Dos</p><p>Tres</p>",
    });
  });

  it("un cuerpo de un solo bloque no se parte", () => {
    expect(splitFirstBlock("<p>Solo</p>")).toEqual({ first: "<p>Solo</p>", rest: "" });
  });

  it("corta por la primera lista si llega antes que un párrafo", () => {
    expect(splitFirstBlock("<ul><li>a</li></ul><p>Después</p>")).toEqual({
      first: "<ul><li>a</li></ul>",
      rest: "<p>Después</p>",
    });
  });

  it("sin ningún cierre reconocible lo deja entero en `first`", () => {
    expect(splitFirstBlock("texto sin etiquetas")).toEqual({
      first: "texto sin etiquetas",
      rest: "",
    });
  });

  it("vacío o nulo da vacío", () => {
    expect(splitFirstBlock("")).toEqual({ first: "", rest: "" });
    expect(splitFirstBlock(null)).toEqual({ first: "", rest: "" });
    expect(splitFirstBlock(undefined)).toEqual({ first: "", rest: "" });
  });
});
