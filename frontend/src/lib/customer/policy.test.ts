import { describe, expect, it } from "vitest";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../tests/helpers/geo-pack";
import { identityPolicy } from "./policy";

describe("identityPolicy", () => {
  it("con el módulo apagado no pide nada, aunque el país tenga documentos", () => {
    expect(
      identityPolicy({ modules: { "customer-identity": false } as never }, CO_PACK),
    ).toEqual({
      enabled: false,
      documentTypes: [],
    });
    expect(identityPolicy({ modules: undefined as never }, CO_PACK).enabled).toBe(
      false,
    );
  });

  it("encendido, pide los tipos del paquete de país", () => {
    const policy = identityPolicy(
      { modules: { "customer-identity": true } as never },
      CO_PACK,
    );

    expect(policy.enabled).toBe(true);
    expect(policy.documentTypes.map((d) => d.code)).toEqual(["CC"]);
  });

  it("encendido en un país sin documentos, solo pide los términos", () => {
    expect(
      identityPolicy(
        { modules: { "customer-identity": true } as never },
        FALLBACK_ES_PACK,
      ),
    ).toEqual({ enabled: true, documentTypes: [] });
  });
});
