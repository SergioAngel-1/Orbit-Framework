import { describe, it, expect } from "vitest";
import { metaDescription, META_DESCRIPTION_MAX } from "./meta-description";

// Un corte crudo a 160 partía la última palabra por la mitad («…y las l»). Es
// el caso que motivó esto, con una descripción de 197 caracteres.
const BRAND =
  "Ropa de algodón orgánico, confeccionada con el rigor de 35 años de oficio textil. Prendas básicas, colecciones de temporada y las líneas de abrigo para toda la familia y los viajes del invierno.";

describe("metaDescription", () => {
  it("no parte la última palabra por la mitad", () => {
    const out = metaDescription(BRAND);
    expect(BRAND.length).toBeGreaterThan(META_DESCRIPTION_MAX);
    expect(out.endsWith("…")).toBe(true);
    // Lo que queda antes de los puntos suspensivos es una palabra entera del
    // original, no un prefijo suyo.
    const ultima = out.slice(0, -1).trim().split(" ").pop() ?? "";
    expect(BRAND.split(/\s+/)).toContain(ultima);
  });

  it("respeta el tope (los puntos suspensivos van aparte)", () => {
    expect(metaDescription(BRAND).length).toBeLessThanOrEqual(META_DESCRIPTION_MAX + 1);
    expect(metaDescription(BRAND, 80).length).toBeLessThanOrEqual(81);
  });

  it("devuelve el texto entero si ya cabe, sin puntos suspensivos", () => {
    expect(metaDescription("Ropa de algodón.")).toBe("Ropa de algodón.");
  });

  it("limpia el HTML del CMS y las entidades", () => {
    expect(
      metaDescription("<p>Ropa <strong>básica</strong> &amp; algo m&#225;s</p>"),
    ).toBe("Ropa básica & algo más");
  });

  it("aplasta el salto de bloque a un espacio (dentro de un atributo no pinta nada)", () => {
    const out = metaDescription("<p>Calidad: Suave.</p><p>Punto acompaña.</p>");
    expect(out).not.toContain("\n");
    expect(out).toBe("Calidad: Suave. Punto acompaña.");
  });

  it("sin texto devuelve cadena vacía, para que el llamante decida", () => {
    expect(metaDescription("")).toBe("");
    expect(metaDescription(null)).toBe("");
    expect(metaDescription(undefined)).toBe("");
  });
});
