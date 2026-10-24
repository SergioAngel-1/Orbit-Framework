import { describe, expect, it } from "vitest";
import { hasOwnCatalog } from "@/i18n/messages/catalogs";
import {
  buildVerifyUrl,
  formatMemberNumber,
  formatMembershipDuration,
  fromMinorUnits,
} from "./format";

// ============================================================================
//  Helpers puros de formato del módulo: duraciones, número de socio, importes
//  de la Store API (unidades menores) y la URL absoluta del QR del carné.
//
//  Las aserciones en inglés solo aplican si el proyecto trae el catálogo inglés: una
//  instancia monolingüe borra `messages/en.json` y todo cae a español.
// ============================================================================

const hasEnglish = hasOwnCatalog("en");

describe("formatMembershipDuration", () => {
  it("compone la duración en días en es y en", () => {
    expect(formatMembershipDuration(30, "es")).toBe("30 días");
    expect(formatMembershipDuration(1, "es")).toBe("1 día");
    if (hasEnglish) {
      expect(formatMembershipDuration(30, "en")).toBe("30 days");
      expect(formatMembershipDuration(1, "en")).toBe("1 day");
    }
  });

  it("`null` es una membresía indefinida", () => {
    expect(formatMembershipDuration(null, "es")).toBe("Indefinida");
    if (hasEnglish) expect(formatMembershipDuration(null, "en")).toBe("Unlimited");
  });

  it("los estados de la cola mandan sobre los días", () => {
    expect(formatMembershipDuration(30, "es", { infinite: true })).toBe("Indefinida");
    expect(formatMembershipDuration(30, "es", { undated: true })).toBe("Sin fecha");
    if (hasEnglish) {
      expect(formatMembershipDuration(30, "en", { undated: true })).toBe("No date");
    }
  });

  it("un idioma desconocido cae a español", () => {
    expect(formatMembershipDuration(7, "pt-BR")).toBe("7 días");
  });
});

describe("formatMemberNumber", () => {
  it("antepone el prefijo configurable al número guardado", () => {
    expect(formatMemberNumber("000123", "SOC-")).toBe("SOC-000123");
  });

  it("sin prefijo devuelve el número tal cual y recorta espacios", () => {
    expect(formatMemberNumber(" 000123 ", "")).toBe("000123");
  });

  it("sin número no inventa uno", () => {
    expect(formatMemberNumber("", "SOC-")).toBe("");
    expect(formatMemberNumber("   ", "SOC-")).toBe("");
  });
});

describe("fromMinorUnits", () => {
  it("convierte unidades menores a decimal de la tienda", () => {
    expect(fromMinorUnits("2000", 2)).toBe("20.00");
    expect(fromMinorUnits(2000, 2)).toBe("20.00");
    expect(fromMinorUnits("5", 2)).toBe("0.05");
    expect(fromMinorUnits("2000", 0)).toBe("2000");
  });

  it("tolera un valor ausente o no numérico con cero", () => {
    expect(fromMinorUnits(null, 2)).toBe("0.00");
    expect(fromMinorUnits(undefined, 2)).toBe("0.00");
    expect(fromMinorUnits("abc", 2)).toBe("0.00");
    expect(fromMinorUnits("", 2)).toBe("0.00");
  });
});

describe("buildVerifyUrl", () => {
  it("compone la URL del QR con la marca del frontend, nunca con WordPress", () => {
    expect(buildVerifyUrl("https://tienda.example", "/verify", "abc123")).toBe(
      "https://tienda.example/verify/abc123",
    );
  });

  it("tolera barras de más en la base y en la ruta", () => {
    expect(buildVerifyUrl("https://tienda.example/", "verify/", "abc123")).toBe(
      "https://tienda.example/verify/abc123",
    );
  });

  it("una base sin URL absoluta válida devuelve null", () => {
    expect(buildVerifyUrl("", "/verify", "abc123")).toBeNull();
    expect(buildVerifyUrl("tienda.example", "/verify", "abc123")).toBeNull();
    expect(buildVerifyUrl("javascript:alert(1)", "/verify", "abc123")).toBeNull();
  });

  it("una ruta vacía también es un fallo de configuración", () => {
    expect(buildVerifyUrl("https://tienda.example", "", "abc123")).toBeNull();
    expect(buildVerifyUrl("https://tienda.example", "   ", "abc123")).toBeNull();
  });

  it("codifica el token en la ruta", () => {
    expect(buildVerifyUrl("https://tienda.example", "/verify", "a/b")).toBe(
      "https://tienda.example/verify/a%2Fb",
    );
  });
});
