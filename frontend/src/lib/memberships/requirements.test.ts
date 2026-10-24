import { describe, expect, it } from "vitest";
import {
  isMembershipLocked,
  normalizeMembershipRequirement,
  secondsToDays,
} from "./requirements";

// ============================================================================
//  Lectura tolerante del requisito de membresía de la ficha de escaparate.
//
//  Con el módulo apagado el campo no existe; con el módulo encendido puede
//  llegar con las claves de GraphQL (`requiredLevel`) o con las de las rutas
//  internas de WordPress (`required_level`). Las dos formas se normalizan al
//  mismo tipo y cualquier dato inválido degrada a «sin requisito», nunca lanza.
// ============================================================================

const LEVEL = { slug: "socio", rank: 2, name: "Socio" };

describe("normalizeMembershipRequirement", () => {
  it("normaliza la forma de GraphQL (camelCase)", () => {
    expect(
      normalizeMembershipRequirement({
        requiredLevel: LEVEL,
        opensAt: "2026-11-01T00:00:00Z",
        locked: true,
      }),
    ).toEqual({
      requiredLevel: LEVEL,
      opensAt: "2026-11-01T00:00:00Z",
      locked: true,
    });
  });

  it("normaliza la forma de las rutas internas (snake_case)", () => {
    expect(
      normalizeMembershipRequirement({
        required_level: LEVEL,
        opens_at: "2026-11-01T00:00:00Z",
      }),
    ).toEqual({
      requiredLevel: LEVEL,
      opensAt: "2026-11-01T00:00:00Z",
      locked: false,
    });
  });

  it("un nivel sin rango o sin slug no bloquea nada", () => {
    expect(
      normalizeMembershipRequirement({
        requiredLevel: { slug: "", rank: 0, name: "" },
        locked: true,
      }),
    ).toEqual({ requiredLevel: null, opensAt: null, locked: true });
  });

  it("sin campo (módulo apagado) devuelve null", () => {
    expect(normalizeMembershipRequirement(undefined)).toBeNull();
    expect(normalizeMembershipRequirement(null)).toBeNull();
    expect(normalizeMembershipRequirement("no")).toBeNull();
  });

  it("un `opensAt` que no es fecha se descarta", () => {
    expect(
      normalizeMembershipRequirement({ requiredLevel: LEVEL, opensAt: 42 }),
    ).toMatchObject({ opensAt: null });
  });
});

describe("isMembershipLocked", () => {
  it("solo está bloqueado con la marca explícita", () => {
    expect(
      isMembershipLocked({ requiredLevel: LEVEL, opensAt: null, locked: true }),
    ).toBe(true);
    expect(
      isMembershipLocked({ requiredLevel: LEVEL, opensAt: null, locked: false }),
    ).toBe(false);
    expect(isMembershipLocked(null)).toBe(false);
  });
});

describe("secondsToDays", () => {
  it("convierte segundos a días sin redondear a la baja", () => {
    expect(secondsToDays(2_592_000)).toBe(30);
    expect(secondsToDays(129_600)).toBe(1.5);
    expect(secondsToDays(0)).toBe(0);
    expect(secondsToDays(-10)).toBe(0);
  });
});
