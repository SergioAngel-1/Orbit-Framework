import { describe, expect, it } from "vitest";
import {
  extractRecs,
  preparePostHeadings,
  slugifyHeading,
  splitPostHtml,
} from "./post-content";

describe("extractRecs", () => {
  it("sustituye el comentario por un marcador y lo anota", () => {
    const { html, recs } = extractRecs(
      '<p>Antes</p><!-- hwe-rec type="product" slug="camiseta" --><p>Después</p>',
    );
    expect(recs).toEqual([{ type: "product", slug: "camiseta" }]);
    expect(html).toBe('<p>Antes</p><div data-hwe-rec="0"></div><p>Después</p>');
  });

  it("acepta entradas y comillas simples, y numera por orden", () => {
    const { recs } = extractRecs(
      "<!-- hwe-rec type='post' slug='otra-entrada' --><!-- hwe-rec type=\"product\" slug=\"gorra\" -->",
    );
    expect(recs.map((r) => r.slug)).toEqual(["otra-entrada", "gorra"]);
  });

  it("un tipo desconocido, sin slug o con un slug raro se descarta, sin dejar el comentario", () => {
    const { html, recs } = extractRecs(
      [
        '<!-- hwe-rec type="video" slug="x" -->',
        '<!-- hwe-rec type="product" -->',
        '<!-- hwe-rec type="product" slug="a b<script>" -->',
      ].join(""),
    );
    expect(recs).toEqual([]);
    expect(html).toBe("");
  });
});

describe("preparePostHeadings", () => {
  it("añade id a h2/h3 y construye la TOC", () => {
    const { html, headings } = preparePostHeadings(
      "<h2>Primera parte</h2><p>x</p><h3>Detalle útil</h3>",
    );
    expect(headings).toEqual([
      { id: "primera-parte", text: "Primera parte", level: 2 },
      { id: "detalle-util", text: "Detalle útil", level: 3 },
    ]);
    expect(html).toContain('<h2 id="primera-parte">Primera parte</h2>');
  });

  it("los títulos repetidos reciben un sufijo único", () => {
    const { headings } = preparePostHeadings(
      "<h2>Notas</h2><h2>Notas</h2><h2>Notas</h2>",
    );
    expect(headings.map((h) => h.id)).toEqual(["notas", "notas-2", "notas-3"]);
  });

  it("respeta el id que ya traía y conserva los demás atributos", () => {
    const { html, headings } = preparePostHeadings(
      '<h2 class="x" id="intro">Hola</h2>',
    );
    expect(headings[0]!.id).toBe("intro");
    expect(html).toBe('<h2 class="x" id="intro">Hola</h2>');
  });

  it("el espacio duro del título deja de pegar las palabras", () => {
    const { html, headings } = preparePostHeadings(
      "<h2>Una&nbsp;frase&nbsp;larga</h2>",
    );
    expect(headings[0]!.text).toBe("Una frase larga");
    expect(html).toContain(">Una frase larga</h2>");
  });
});

describe("splitPostHtml", () => {
  it("parte la prosa en torno a cada recomendación", () => {
    const segments = splitPostHtml('<p>a</p><div data-hwe-rec="0"></div><p>b</p>');
    expect(segments).toEqual([
      { kind: "html", html: "<p>a</p>" },
      { kind: "rec", index: 0 },
      { kind: "html", html: "<p>b</p>" },
    ]);
  });

  it("no emite segmentos de prosa en blanco", () => {
    expect(splitPostHtml('  <div data-hwe-rec="0"></div>\n')).toEqual([
      { kind: "rec", index: 0 },
    ]);
  });
});

describe("slugifyHeading", () => {
  it("un texto sin caracteres utilizables da `section`", () => {
    expect(slugifyHeading("—")).toBe("section");
  });
});
