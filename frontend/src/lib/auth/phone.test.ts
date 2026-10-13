import { describe, expect, it } from "vitest";
import { classifyLoginIdentifier, isE164, normalizePhoneE164 } from "./phone";

describe("normalizePhoneE164", () => {
  it("une prefijo y número quitando separadores y ceros iniciales", () => {
    expect(normalizePhoneE164("+34", "600 11 22 33")).toBe("+34600112233");
    expect(normalizePhoneE164("44", "07911 123456")).toBe("+447911123456");
  });

  it("rechaza lo que no es un E.164 válido", () => {
    expect(normalizePhoneE164("", "600112233")).toBeNull();
    expect(normalizePhoneE164("+34", "")).toBeNull();
    expect(normalizePhoneE164("+34", "12")).toBeNull();
    expect(normalizePhoneE164("+1", "1234567890123456")).toBeNull();
  });
});

describe("isE164", () => {
  it("exige + y de 8 a 15 cifras sin empezar por 0", () => {
    expect(isE164("+34600112233")).toBe(true);
    expect(isE164("34600112233")).toBe(false);
    expect(isE164("+0600112233")).toBe(false);
  });
});

describe("classifyLoginIdentifier", () => {
  const on = { enabled: true };

  it("un email siempre es email (en minúsculas)", () => {
    expect(classifyLoginIdentifier(" Ana@Example.COM ", on)).toEqual({
      kind: "email",
      value: "ana@example.com",
    });
  });

  it("con el flag apagado, un número es un nombre de usuario", () => {
    expect(classifyLoginIdentifier("+34600112233", { enabled: false })).toEqual({
      kind: "username",
      value: "+34600112233",
    });
  });

  it("con prefijo (+ o 00) es teléfono", () => {
    expect(classifyLoginIdentifier("+34 600 11 22 33", on)).toEqual({
      kind: "phone",
      value: "+34600112233",
    });
    expect(classifyLoginIdentifier("0034600112233", on)).toEqual({
      kind: "phone",
      value: "+34600112233",
    });
  });

  it("sin prefijo solo es teléfono si hay prefijo por defecto (sin suponer un país)", () => {
    expect(classifyLoginIdentifier("600112233", on).kind).toBe("username");
    expect(
      classifyLoginIdentifier("600112233", { enabled: true, defaultDialCode: "+34" }),
    ).toEqual({ kind: "phone", value: "+34600112233" });
  });

  it("un nombre de usuario se queda como está", () => {
    expect(classifyLoginIdentifier("ana.perez", on)).toEqual({
      kind: "username",
      value: "ana.perez",
    });
  });
});
