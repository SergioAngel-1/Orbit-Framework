import { describe, expect, it } from "vitest";
import { z } from "zod";
import { validateForm } from "./form";

const schema = z.object({
  name: z.string().min(2, "Nombre demasiado corto"),
  email: z.email("Email no válido"),
});

describe("validateForm", () => {
  it("devuelve los datos tipados cuando es válido", () => {
    const result = validateForm(schema, { name: "Ana", email: "ana@example.com" });
    expect(result).toEqual({
      success: true,
      data: { name: "Ana", email: "ana@example.com" },
      errors: null,
    });
  });

  it("da el PRIMER mensaje de cada campo, listo para pintar bajo su input", () => {
    const result = validateForm(schema, { name: "A", email: "no-es-un-email" });
    expect(result.success).toBe(false);
    expect(result.errors).toEqual({
      name: "Nombre demasiado corto",
      email: "Email no válido",
    });
  });

  it("un campo con varios errores solo enseña el primero", () => {
    const multi = z.object({
      pass: z.string().min(8, "Mínimo 8").regex(/\d/, "Necesita un número"),
    });
    const result = validateForm(multi, { pass: "abc" });
    expect(result.errors).toEqual({ pass: "Mínimo 8" });
  });

  it("un campo ausente o un cuerpo que no es objeto no lanza", () => {
    expect(validateForm(schema, {}).success).toBe(false);
    expect(validateForm(schema, null).success).toBe(false);
    expect(validateForm(schema, "x").success).toBe(false);
  });
});
