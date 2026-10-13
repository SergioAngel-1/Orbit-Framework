import { describe, expect, it } from "vitest";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import {
  checkDocument,
  documentInputSchema,
  findDocumentType,
  isValidDocument,
  normalizeDocumentNumber,
  termsAcceptedField,
} from "./identity";

const TYPES = [
  { code: "CC", label: "Cédula", pattern: "^[1-9]\\d{3,9}$" },
  { code: "NIT", label: "NIT", pattern: "^\\d{9,10}$" },
];

describe("normalizeDocumentNumber", () => {
  it.each([
    [" 1.012.345.678 ", "1012345678"],
    ["900-123-456", "900123456"],
    ["ab 12 345", "AB12345"],
  ])("«%s» → %s", (raw, expected) => {
    expect(normalizeDocumentNumber(raw)).toBe(expected);
  });
});

describe("isValidDocument", () => {
  it("compara el número normalizado con el patrón de su tipo", () => {
    expect(isValidDocument(TYPES, "CC", "1012345678")).toBe(true);
    expect(isValidDocument(TYPES, "CC", "0123456789")).toBe(false);
    expect(isValidDocument(TYPES, "NIT", "9001234567")).toBe(true);
    expect(isValidDocument(TYPES, "NIT", "12")).toBe(false);
  });

  it("un tipo que el paquete no tiene no valida nada", () => {
    expect(isValidDocument(TYPES, "DNI", "12345678")).toBe(false);
    expect(findDocumentType(TYPES, null)).toBeUndefined();
  });

  it("un patrón que no compila no valida (y no revienta)", () => {
    expect(isValidDocument([{ code: "X", label: "X", pattern: "^(" }], "X", "1")).toBe(
      false,
    );
  });

  it("los patrones del paquete de Colombia aceptan lo que aceptaba el origen", () => {
    const types = CO_PACK.documentTypes;
    expect(isValidDocument(types, "CC", "1012345678")).toBe(true);
  });
});

describe("documentInputSchema", () => {
  it("normaliza el número al parsear", () => {
    expect(
      documentInputSchema.parse({
        documentType: "CC",
        documentNumber: " 1.012.345.678 ",
      }),
    ).toEqual({ documentType: "CC", documentNumber: "1012345678" });
  });

  it("acota la forma", () => {
    expect(
      documentInputSchema.safeParse({ documentType: "", documentNumber: "1" }).success,
    ).toBe(false);
    expect(
      documentInputSchema.safeParse({ documentType: "CC", documentNumber: "" }).success,
    ).toBe(false);
    expect(
      documentInputSchema.safeParse({
        documentType: "CC",
        documentNumber: "9".repeat(31),
      }).success,
    ).toBe(false);
  });
});

describe("checkDocument", () => {
  it("devuelve null si es válido y el mensaje del campo si no", () => {
    expect(
      checkDocument(TYPES, { documentType: "CC", documentNumber: "1012345678" }),
    ).toBeNull();
    expect(
      checkDocument(TYPES, { documentType: "XX", documentNumber: "1012345678" }),
    ).toBe("Tipo de documento no válido");
    expect(checkDocument(TYPES, { documentType: "CC", documentNumber: "12" })).toBe(
      "Número de documento no válido",
    );
  });
});

describe("termsAcceptedField", () => {
  it("solo vale true", () => {
    expect(termsAcceptedField.safeParse(true).success).toBe(true);
    expect(termsAcceptedField.safeParse(false).success).toBe(false);
    expect(termsAcceptedField.safeParse("true").success).toBe(false);
  });
});
