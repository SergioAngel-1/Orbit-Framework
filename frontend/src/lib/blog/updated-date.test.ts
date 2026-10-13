import { describe, it, expect } from "vitest";
import { showUpdatedDate } from "@/lib/blog/updated-date";

// «Actualizado el…» solo cuando de verdad se revisó el artículo. WordPress mueve
// `modified` con cualquier guardado (una errata, una traducción añadida): sin un
// umbral, casi toda entrada diría «actualizada» el mismo día que se publicó.

describe("showUpdatedDate", () => {
  const published = "2026-03-10T08:00:00";

  it("el mismo día no cuenta como actualización", () => {
    expect(showUpdatedDate(published, "2026-03-10T20:00:00")).toBe(false);
  });

  it("a partir de un día de diferencia, sí", () => {
    expect(showUpdatedDate(published, "2026-03-11T08:00:00")).toBe(true);
    expect(showUpdatedDate(published, "2026-09-01T10:00:00")).toBe(true);
  });

  it("una fecha de modificación anterior a la de publicación se ignora", () => {
    expect(showUpdatedDate(published, "2026-01-01T00:00:00")).toBe(false);
  });

  it.each([null, undefined, "", "no-es-una-fecha"])(
    "sin fecha de modificación válida (%s) no se enseña nada",
    (modified) => {
      expect(showUpdatedDate(published, modified)).toBe(false);
    },
  );

  it("sin fecha de publicación válida tampoco", () => {
    expect(showUpdatedDate("", "2026-09-01T10:00:00")).toBe(false);
  });
});
