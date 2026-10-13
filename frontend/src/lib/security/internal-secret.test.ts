import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  INTERNAL_SECRET_HEADER,
  hasValidInternalSecret,
  internalSecretHeaders,
} from "./internal-secret";

// ============================================================================
//  Secreto interno compartido BFF → WordPress.
//
//  El mu-plugin `hwe-bff-only` solo deja pasar a GraphQL, la Store API y
//  `hwe/v1/auth|consent` a quien presente este secreto (o a WP-CLI). Es una
//  credencial, no una suposición sobre la red.
// ============================================================================

let saved: string | undefined;
beforeEach(() => {
  saved = process.env.HWE_REVALIDATION_SECRET;
});
afterEach(() => {
  if (saved === undefined) delete process.env.HWE_REVALIDATION_SECRET;
  else process.env.HWE_REVALIDATION_SECRET = saved;
});

describe("internalSecretHeaders", () => {
  it("lleva el secreto en X-HWE-Internal-Secret", () => {
    process.env.HWE_REVALIDATION_SECRET = "secreto-de-prueba";

    expect(internalSecretHeaders()).toEqual({
      "X-HWE-Internal-Secret": "secreto-de-prueba",
    });
    expect(INTERNAL_SECRET_HEADER).toBe("X-HWE-Internal-Secret");
  });

  it("recorta los espacios, igual que el mu-plugin al compararlo", () => {
    process.env.HWE_REVALIDATION_SECRET = "  secreto-de-prueba \n";

    expect(internalSecretHeaders()).toEqual({
      "X-HWE-Internal-Secret": "secreto-de-prueba",
    });
  });

  it.each([undefined, "", "   "])(
    "sin secreto (%j) no manda cabecera: WordPress rechazará",
    (value) => {
      if (value === undefined) delete process.env.HWE_REVALIDATION_SECRET;
      else process.env.HWE_REVALIDATION_SECRET = value;

      expect(internalSecretHeaders()).toEqual({});
    },
  );

  it("lee el entorno en cada llamada (no lo congela al importar)", () => {
    process.env.HWE_REVALIDATION_SECRET = "uno";
    expect(internalSecretHeaders()[INTERNAL_SECRET_HEADER]).toBe("uno");

    process.env.HWE_REVALIDATION_SECRET = "dos";
    expect(internalSecretHeaders()[INTERNAL_SECRET_HEADER]).toBe("dos");
  });
});

describe("hasValidInternalSecret", () => {
  const SECRET = "dummy-ci-revalidation-secret-24-chars";
  const req = (value?: string) =>
    new Request("https://tienda.example/x", {
      headers: value === undefined ? {} : { "X-HWE-Internal-Secret": value },
    });

  beforeEach(() => vi.stubEnv("HWE_REVALIDATION_SECRET", SECRET));
  afterEach(() => vi.unstubAllEnvs());

  it("acepta el secreto exacto", () => {
    expect(hasValidInternalSecret(req(SECRET))).toBe(true);
  });

  it.each([
    ["sin cabecera", undefined],
    ["vacío", ""],
    ["otro valor", "otro-secreto-de-la-misma-longitud-xx"],
    ["un prefijo", SECRET.slice(0, 10)],
  ])("rechaza %s", (_name, value) => {
    expect(hasValidInternalSecret(req(value))).toBe(false);
  });

  it("sin secreto configurado falla cerrado, aunque la cabecera venga vacía", () => {
    vi.stubEnv("HWE_REVALIDATION_SECRET", "");

    expect(hasValidInternalSecret(req(""))).toBe(false);
    expect(hasValidInternalSecret(req("cualquiera"))).toBe(false);
  });
});
