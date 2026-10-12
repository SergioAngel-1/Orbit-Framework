import { afterEach, describe, expect, it, vi } from "vitest";
import { assertSecrets, findSecretProblems } from "@/lib/security/secret-guard";

// ============================================================================
//  ALLOWED_ORIGIN en el guard de arranque.
//
//  Sin ella, `assertAllowedOrigin` devuelve true y la verificación de Origin de
//  `guardMutation` queda desactivada EN SILENCIO. Y con ella mal escrita la
//  protección falla al revés: la comparación es igualdad estricta de cadenas
//  contra la cabecera `Origin` del navegador, así que una barra final, una ruta o
//  un `:443` hacen que TODA escritura responda 403 sin ningún aviso.
//
//  `next start` fija NODE_ENV=production: un arranque local o de CI pasa por las
//  reglas de producción aunque sirva por http en localhost. Sin la excepción de
//  loopback, el job e2e de CI no podía arrancar el servidor nunca.
// ============================================================================

const SECRETS = {
  GRAPHQL_JWT_AUTH_SECRET_KEY: "valid-long-secret-key-minimum-24",
  CSRF_SECRET: "valid-long-secret-key-minimum-24",
  WC_WEBHOOK_SECRET: "valid-long-secret-key-minimum-24",
  HWE_REVALIDATION_SECRET: "valid-long-secret-key-minimum-24",
  WC_CONSUMER_KEY: "valid-consumer-key",
  WC_CONSUMER_SECRET: "valid-consumer-secret",
};

function problems(env: Record<string, string | undefined>): string[] {
  return findSecretProblems({ ...SECRETS, ...env });
}

const originProblems = (env: Record<string, string | undefined>) =>
  problems(env).filter((p) => p.includes("ALLOWED_ORIGIN"));

describe("ALLOWED_ORIGIN: obligatoria", () => {
  it("señala su ausencia", () => {
    expect(originProblems({ NODE_ENV: "production" })).toHaveLength(1);
    expect(originProblems({ NODE_ENV: "development" })).toHaveLength(1);
  });

  it("una cadena vacía cuenta como ausente", () => {
    expect(originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: "" })).toHaveLength(
      1,
    );
  });

  it.each(["localhost:3000", "tienda.example", "/ruta", "  "])(
    "rechaza %j: no es una URL absoluta",
    (value) => {
      expect(
        originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: value }),
      ).toHaveLength(1);
    },
  );

  it.each(["ftp://tienda.example", "javascript:alert(1)", "file:///etc/passwd"])(
    "rechaza %j: no es http(s)",
    (value) => {
      expect(
        originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: value }),
      ).not.toEqual([]);
    },
  );
});

describe("ALLOWED_ORIGIN: https en producción", () => {
  it("acepta https en producción", () => {
    expect(
      originProblems({
        NODE_ENV: "production",
        ALLOWED_ORIGIN: "https://tienda.example",
      }),
    ).toEqual([]);
  });

  it("rechaza un host real por http en producción", () => {
    expect(
      originProblems({
        NODE_ENV: "production",
        ALLOWED_ORIGIN: "http://tienda.example",
      }),
    ).toHaveLength(1);
  });

  it("acepta http en desarrollo", () => {
    expect(
      originProblems({
        NODE_ENV: "development",
        ALLOWED_ORIGIN: "http://tienda.local:3000",
      }),
    ).toEqual([]);
  });

  it.each([
    "http://localhost:3000",
    "http://127.0.0.1:3000",
    "http://[::1]:3000",
    "http://localhost",
  ])(
    "acepta %s en producción: un origen de loopback no es un despliegue real",
    (value) => {
      expect(originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: value })).toEqual(
        [],
      );
    },
  );

  it.each([
    "http://localhost.tienda.example",
    "http://tienda.example.localhost.evil.com",
    "http://127.0.0.1.tienda.example",
    "http://localhost@tienda.example",
    "http://tienda.example/localhost",
  ])("sigue rechazando %s: contiene 'localhost' pero NO es loopback", (value) => {
    expect(
      originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: value }),
    ).not.toEqual([]);
  });
});

describe("ALLOWED_ORIGIN: coincidencia exacta con el Origin del navegador", () => {
  // El navegador envía `Origin: https://tienda.example` (sin barra, sin ruta y sin
  // el puerto por defecto). La comparación del servidor es de cadenas.
  it.each([
    ["una barra final", "https://tienda.example/"],
    ["una ruta", "https://tienda.example/tienda"],
    ["el puerto https por defecto", "https://tienda.example:443"],
    ["una query", "https://tienda.example?x=1"],
    ["credenciales", "https://usuario@tienda.example"],
    ["mayúsculas en el host", "https://Tienda.Example"],
  ])(
    "rechaza un origen con %s: ninguna escritura coincidiría y todas darían 403",
    (_label, value) => {
      const found = originProblems({ NODE_ENV: "production", ALLOWED_ORIGIN: value });

      expect(found).toHaveLength(1);
      expect(found[0]).toMatch(/exact|sin ruta|barra/i);
    },
  );

  it("acepta un origen con puerto no estándar", () => {
    expect(
      originProblems({
        NODE_ENV: "production",
        ALLOWED_ORIGIN: "https://tienda.example:8443",
      }),
    ).toEqual([]);
  });

  it("el mismo requisito rige en desarrollo", () => {
    expect(
      originProblems({
        NODE_ENV: "development",
        ALLOWED_ORIGIN: "http://localhost:3000/",
      }),
    ).toHaveLength(1);
  });
});

describe("el entorno del job e2e de CI pasa las reglas de producción", () => {
  // Es exactamente el bloque `env` del job `e2e` de .github/workflows/ci.yml: si
  // el guard dejara de aceptarlo, `next start` abortaría y `wait-on` agotaría su
  // espera sin explicar nada.
  const CI_E2E_ENV = {
    NODE_ENV: "production",
    GRAPHQL_JWT_AUTH_SECRET_KEY: "dummy-ci-jwt-secret-key-at-least-24-chars",
    CSRF_SECRET: "dummy-ci-csrf-secret-at-least-24-characters-len",
    WC_WEBHOOK_SECRET: "dummy-ci-webhook-secret-at-least-24-characters",
    HWE_REVALIDATION_SECRET: "dummy-ci-revalidation-secret-24-chars",
    WC_CONSUMER_KEY: "ck_dummy_ci_key",
    WC_CONSUMER_SECRET: "cs_dummy_ci_secret",
    ALLOWED_ORIGIN: "http://localhost:3000",
  };

  it("no reporta ningún problema", () => {
    expect(findSecretProblems(CI_E2E_ENV)).toEqual([]);
  });

  it("el workflow de CI declara esos mismos valores", async () => {
    const { readFileSync } = await import("node:fs");
    const { fileURLToPath } = await import("node:url");
    const workflow = readFileSync(
      fileURLToPath(new URL("../../../.github/workflows/ci.yml", import.meta.url)),
      "utf8",
    );

    for (const [name, value] of Object.entries(CI_E2E_ENV)) {
      if (name === "NODE_ENV") continue; // lo fija `next start`
      expect(workflow, `${name} en el job e2e`).toContain(`${name}: ${value}`);
    }
  });
});

describe("assertSecrets", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  function stubEnv(env: Record<string, string>) {
    for (const [name, value] of Object.entries({ ...SECRETS, ...env })) {
      vi.stubEnv(name, value);
    }
  }

  it("en producción aborta el arranque si ALLOWED_ORIGIN es http en un host real", () => {
    stubEnv({ NODE_ENV: "production", ALLOWED_ORIGIN: "http://tienda.example" });

    expect(() => assertSecrets()).toThrow(/ALLOWED_ORIGIN/);
  });

  it("en producción arranca con un origen https válido", () => {
    stubEnv({ NODE_ENV: "production", ALLOWED_ORIGIN: "https://tienda.example" });

    expect(() => assertSecrets()).not.toThrow();
  });

  it("fuera de producción solo avisa, no aborta", () => {
    stubEnv({ NODE_ENV: "development", ALLOWED_ORIGIN: "no-es-una-url" });
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

    expect(() => assertSecrets()).not.toThrow();
    expect(warn).toHaveBeenCalled();
  });
});
