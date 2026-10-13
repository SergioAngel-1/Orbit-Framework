import { describe, it, expect } from "vitest";
import { cleanExcerpt, htmlToPlainText } from "@/lib/format";

describe("cleanExcerpt — resumen legible", () => {
  it("quita las etiquetas HTML del CMS", () => {
    expect(
      cleanExcerpt(
        "<em><strong>Autumn leaves</strong></em> favorece el desapego.",
        100,
      ),
    ).toBe("Autumn leaves favorece el desapego.");
  });

  it("separa los bloques con un salto de línea, no con un espacio", () => {
    // Un salto REAL: quien no pida `whitespace-pre-line` ve un espacio, porque
    // el HTML colapsa el blanco.
    const raw = "Cualidad: calma\r\n\r\nPara los que se sienten al límite.";
    expect(cleanExcerpt(raw, 200)).toBe(
      "Cualidad: calma\nPara los que se sienten al límite.",
    );
  });

  it("reconoce el salto que publica wpautop como <br>", () => {
    const raw =
      "<p>Cualidad: Claridad para reconocer tu fuerza<br />\nTe ayuda a retirar los bloqueos.</p>";
    expect(cleanExcerpt(raw, 200)).toBe(
      "Cualidad: Claridad para reconocer tu fuerza\nTe ayuda a retirar los bloqueos.",
    );
  });

  it("reconoce la frontera entre dos párrafos de wpautop", () => {
    const raw = "<p>Primera idea</p>\n<p>Segunda idea.</p>";
    expect(cleanExcerpt(raw, 200)).toBe("Primera idea\nSegunda idea.");
  });

  it("corta por palabra completa aunque el último blanco sea el salto", () => {
    // Con `lastIndexOf(" ")` el corte se saltaba el salto de bloque y partía la
    // primera palabra del cuerpo por la mitad.
    expect(cleanExcerpt("Cualidad: calma.\nAcompaña las noches inquietas.", 24)).toBe(
      "Cualidad: calma…",
    );
  });

  it("no deja un separador colgando al principio ni al final", () => {
    expect(cleanExcerpt("<p></p><p>Solo un bloque.</p><p>  </p>", 200)).toBe(
      "Solo un bloque.",
    );
  });

  it("decodifica entidades y normaliza el espacio duro", () => {
    expect(cleanExcerpt("Calma&nbsp;&amp; bienestar&hellip;", 100)).toBe(
      "Calma & bienestar…",
    );
  });

  it("elimina caracteres de control y de ancho cero", () => {
    expect(cleanExcerpt("Es\u200bencia floral\ufeff", 100)).toBe("Esencia floral");
  });

  it("trunca por palabra completa y añade puntos suspensivos", () => {
    const raw = "Para personas quejumbrosas que sienten que la vida es injusta.";
    const out = cleanExcerpt(raw, 30);
    expect(out).toBe("Para personas quejumbrosas…");
    expect(out.length).toBeLessThanOrEqual(31);
  });

  it("no añade puntos suspensivos si el texto ya cabe", () => {
    expect(cleanExcerpt("Texto corto.", 50)).toBe("Texto corto.");
  });

  it("no deja puntuación colgando antes de los puntos suspensivos", () => {
    expect(cleanExcerpt("Componentes: lavanda, romero y menta.", 22)).toBe(
      "Componentes: lavanda…",
    );
  });

  it("convierte las secuencias de escape literales en espacios", () => {
    // Barra invertida + n COMO TEXTO (una importación mal hecha), no saltos reales.
    const raw = String.raw`Zinnia elegans\n\n\n\nPara personas serias.`;
    expect(cleanExcerpt(raw, 200)).toBe("Zinnia elegans Para personas serias.");
  });

  it("devuelve cadena vacía con entrada vacía o nula", () => {
    expect(cleanExcerpt(null, 50)).toBe("");
    expect(cleanExcerpt(undefined, 50)).toBe("");
    expect(cleanExcerpt("   \n\n  ", 50)).toBe("");
  });

  it("corta una palabra larguísima sin espacios en vez de devolverla entera", () => {
    expect(cleanExcerpt("A".repeat(80), 20)).toBe("A".repeat(20) + "…");
  });
});

describe("htmlToPlainText", () => {
  it("conserva los saltos de párrafo y de línea como \\n", () => {
    expect(htmlToPlainText("<p>Buen producto</p><p>Lo recomiendo<br>mucho</p>")).toBe(
      "Buen producto\n\nLo recomiendo\nmucho",
    );
  });

  it("vacío con entrada vacía", () => {
    expect(htmlToPlainText(null)).toBe("");
  });
});
