import { describe, expect, it } from "vitest";
import { normalizeReferralCode } from "./referral-code";

// ============================================================================
//  Formato de un código de referido, puro y apto para edge.
//
//  Debe coincidir con `HWE_Loyalty_Referrals::CODE_PATTERN` (PHP): 8 caracteres
//  del alfabeto sin ambigüedades. El proxy solo comprueba el formato; que el
//  código exista y pueda atribuirse lo decide WordPress.
// ============================================================================

describe("código de referido", () => {
  it.each(["ABCD2345", "ZZZZ2222", "HJKMNPQR"])(
    "acepta un código de 8 caracteres del alfabeto: %s",
    (code) => {
      expect(normalizeReferralCode(code)).toBe(code);
    },
  );

  it("normaliza a mayúsculas y quita todos los espacios", () => {
    expect(normalizeReferralCode("  abcd 2345\n")).toBe("ABCD2345");
  });

  it("un espacio interior se ignora al normalizar, como en WordPress", () => {
    expect(normalizeReferralCode("ABCD 2345")).toBe("ABCD2345");
  });

  it.each([
    ["", "vacío"],
    ["   ", "solo espacios"],
    ["ABCD234", "7 caracteres"],
    ["ABCD23456", "9 caracteres"],
    ["ABCD234O", "contiene la letra O (ambigua)"],
    ["ABCD234I", "contiene la letra I (ambigua)"],
    ["ABCD2340", "contiene el cero (ambiguo)"],
    ["ABCD-345", "contiene un guion"],
    ["áBCD2345", "carácter no ASCII"],
    ["ABCD2345 extra", "con texto adicional"],
  ])("rechaza %j (%s)", (value) => {
    expect(normalizeReferralCode(value)).toBeNull();
  });

  it.each([null, undefined, 12344321, {}, []])(
    "un valor que no es texto no se acepta: %j",
    (value) => {
      expect(normalizeReferralCode(value)).toBeNull();
    },
  );
});
