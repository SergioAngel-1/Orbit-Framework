import { describe, it, expect } from "vitest";
import { hmacSha256, sha256, safeEqual } from "@/lib/payments/signature";

describe("hmacSha256", () => {
  it("es determinista para el mismo secreto y payload", () => {
    const a = hmacSha256("payload", "secret");
    const b = hmacSha256("payload", "secret");
    expect(a).toBe(b);
  });
  it("cambia con el secreto", () => {
    expect(hmacSha256("p", "s1")).not.toBe(hmacSha256("p", "s2"));
  });
  it("soporta base64", () => {
    expect(hmacSha256("p", "s", "base64")).toMatch(/[A-Za-z0-9+/=]+/);
  });
});

describe("sha256", () => {
  it("produce el hash hexadecimal esperado", () => {
    // SHA-256("abc") conocido.
    expect(sha256("abc")).toBe(
      "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
    );
  });
});

describe("safeEqual", () => {
  it("true para cadenas idénticas", () => {
    expect(safeEqual("token", "token")).toBe(true);
  });
  it("false para distinta longitud o contenido", () => {
    expect(safeEqual("token", "token2")).toBe(false);
    expect(safeEqual("aaaa", "bbbb")).toBe(false);
  });
});
