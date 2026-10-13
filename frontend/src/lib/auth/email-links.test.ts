import { afterEach, describe, expect, it } from "vitest";
import { authEmailLocale, authEmailUrl } from "./email-links";

afterEach(() => {
  delete process.env.NEXT_PUBLIC_SITE_URL;
});

describe("authEmailLocale", () => {
  it("acepta un idioma del sitio y cae al por defecto con cualquier otra cosa", () => {
    expect(authEmailLocale("en")).toBe("en");
    expect(authEmailLocale("es")).toBe("es");
    expect(authEmailLocale("xx")).toBe("es");
    expect(authEmailLocale(undefined)).toBe("es");
    expect(authEmailLocale({ toString: () => "en" })).toBe("es");
  });
});

describe("authEmailUrl", () => {
  it("lleva el prefijo del idioma salvo en el por defecto", () => {
    process.env.NEXT_PUBLIC_SITE_URL = "https://tienda.example/";
    expect(authEmailUrl("/reset-password", "es")).toBe(
      "https://tienda.example/reset-password",
    );
    expect(authEmailUrl("/verify-email", "en")).toBe(
      "https://tienda.example/en/verify-email",
    );
  });
});
