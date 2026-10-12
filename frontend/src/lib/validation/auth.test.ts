import { describe, expect, it } from "vitest";
import { disable2faBodySchema, verifyEmailBodySchema } from "./auth";

describe("disable2faBodySchema", () => {
  it("acepta un código TOTP de 6 dígitos", () => {
    expect(disable2faBodySchema.parse({ code: "123456" })).toEqual({ code: "123456" });
  });

  it("acepta un código de recuperación y lo recorta", () => {
    expect(disable2faBodySchema.parse({ recoveryCode: "  aaaaa-bbbbb " })).toEqual({
      recoveryCode: "aaaaa-bbbbb",
    });
  });

  it("acepta ambos (el endpoint da prioridad al de recuperación)", () => {
    expect(
      disable2faBodySchema.safeParse({ code: "123456", recoveryCode: "aaaaa-bbbbb" })
        .success,
    ).toBe(true);
  });

  it.each([
    ["sin ninguna prueba", {}],
    ["código con menos de 6 dígitos", { code: "12345" }],
    ["código con más de 6 dígitos", { code: "1234567" }],
    ["código con letras", { code: "12345a" }],
    ["código vacío", { code: "" }],
    ["código numérico en lugar de texto", { code: 123456 }],
    ["código de recuperación vacío", { recoveryCode: "" }],
    ["código de recuperación solo espacios", { recoveryCode: "   " }],
    ["código de recuperación que no es texto", { recoveryCode: { $ne: "" } }],
    ["código de recuperación desmesurado", { recoveryCode: "a".repeat(101) }],
  ])("rechaza %s", (_caso, body) => {
    expect(disable2faBodySchema.safeParse(body).success).toBe(false);
  });

  it("señala la falta de prueba sobre el campo `code`, para que el 422 diga dónde mirar", () => {
    const result = disable2faBodySchema.safeParse({});

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.flatten().fieldErrors.code).toBeDefined();
    }
  });
});

describe("verifyEmailBodySchema", () => {
  it("acepta el token del enlace del correo (64 hex)", () => {
    const token = "a".repeat(64);

    expect(verifyEmailBodySchema.parse({ token })).toEqual({ token });
  });

  it.each([
    ["vacío", { token: "" }],
    ["ausente", {}],
    ["numérico", { token: 123 }],
    ["una lista", { token: ["a"] }],
    ["desmesurado", { token: "a".repeat(201) }],
  ])("rechaza un token %s", (_caso, body) => {
    expect(verifyEmailBodySchema.safeParse(body).success).toBe(false);
  });
});
