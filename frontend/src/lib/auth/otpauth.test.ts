import { describe, expect, it } from "vitest";
import { buildOtpauthUri } from "./otpauth";

// ============================================================================
//  URI `otpauth://` del alta de 2FA (formato "Key URI" de Google Authenticator).
//  Se arma en el servidor: el cliente ya no construye nada con el secreto.
// ============================================================================

const SECRET = "JBSWY3DPEHPK3PXP";

describe("buildOtpauthUri", () => {
  it("genera una URI totp con etiqueta emisor:cuenta, secreto y emisor", () => {
    const uri = buildOtpauthUri({
      issuer: "Mi Tienda",
      account: "ana@example.com",
      secret: SECRET,
    });

    const url = new URL(uri);
    expect(url.protocol).toBe("otpauth:");
    expect(url.host).toBe("totp");
    expect(decodeURIComponent(url.pathname)).toBe("/Mi Tienda:ana@example.com");
    expect(url.searchParams.get("secret")).toBe(SECRET);
    expect(url.searchParams.get("issuer")).toBe("Mi Tienda");
  });

  it("codifica los caracteres especiales del emisor y de la cuenta", () => {
    const uri = buildOtpauthUri({
      issuer: "Café & Té: «Premium»",
      account: "ana+compras@example.com",
      secret: SECRET,
    });

    const url = new URL(uri);
    expect(url.searchParams.get("issuer")).toBe("Café & Té: «Premium»");
    expect(decodeURIComponent(url.pathname)).toBe(
      "/Café & Té: «Premium»:ana+compras@example.com",
    );
    // Los separadores de la URI no se pueden colar por el emisor ni la cuenta.
    expect(uri.split("?")).toHaveLength(2);
    expect(uri.match(/secret=/g)).toHaveLength(1);
  });

  it("un emisor con ':' no rompe la etiqueta (se codifica como %3A)", () => {
    const uri = buildOtpauthUri({ issuer: "A:B", account: "x", secret: SECRET });

    expect(uri).toContain("/A%3AB:x?");
  });

  it("un valor malicioso no inyecta parámetros nuevos", () => {
    const uri = buildOtpauthUri({
      issuer: "Tienda&secret=EVIL",
      account: "x?secret=EVIL",
      secret: SECRET,
    });

    const url = new URL(uri);
    expect(url.searchParams.getAll("secret")).toEqual([SECRET]);
    expect(url.searchParams.getAll("issuer")).toEqual(["Tienda&secret=EVIL"]);
  });
});
