import { describe, expect, it } from "vitest";
import { normalizeEmail, validateEmail } from "./email";

describe("validateEmail", () => {
  it("acepta correos válidos", () => {
    expect(validateEmail("Nombre@MiTienda.com")).toEqual({
      ok: true,
      email: "nombre@mitienda.com",
    });
    expect(validateEmail("a.b+tag@mail.co")).toMatchObject({ ok: true });
  });

  it("rechaza vacíos, largos e inválidos", () => {
    expect(validateEmail("")).toMatchObject({ ok: false, reason: "empty" });
    expect(validateEmail("sin-arroba")).toMatchObject({ ok: false, reason: "invalid" });
    expect(validateEmail("a@b")).toMatchObject({ ok: false, reason: "invalid" });
    expect(validateEmail("a@b.c")).toMatchObject({ ok: false, reason: "invalid" });
    expect(validateEmail("a@@b.com")).toMatchObject({ ok: false, reason: "invalid" });
    expect(validateEmail("a@b..com")).toMatchObject({ ok: false, reason: "invalid" });
  });
});

describe("normalizeEmail", () => {
  it("trim + lower", () => {
    expect(normalizeEmail("  A@B.Com ")).toBe("a@b.com");
  });
});
