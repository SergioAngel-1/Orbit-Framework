import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { findSecretProblems } from "@/lib/security/secret-guard";

// Secretos válidos (largos y no por defecto) para partir de un estado correcto.
const GOOD = "x9f2a7b4c1d8e6f3a2b9c0d7e4f1a8b5"; // 32 chars

const KEYS = [
  "GRAPHQL_JWT_AUTH_SECRET_KEY",
  "CSRF_SECRET",
  "WC_WEBHOOK_SECRET",
  "HWE_REVALIDATION_SECRET",
  "WC_CONSUMER_KEY",
  "WC_CONSUMER_SECRET",
  "ALLOWED_ORIGIN", // obligatoria; su validación detallada está en secret-guard-origin.test.ts
] as const;

describe("findSecretProblems", () => {
  const saved: Record<string, string | undefined> = {};

  beforeEach(() => {
    for (const k of KEYS) {
      saved[k] = process.env[k];
      process.env[k] = k === "ALLOWED_ORIGIN" ? "https://tienda.example" : GOOD;
    }
  });

  afterEach(() => {
    for (const k of KEYS) {
      if (saved[k] === undefined) delete process.env[k];
      else process.env[k] = saved[k];
    }
  });

  it("no reporta problemas con secretos válidos", () => {
    expect(findSecretProblems()).toEqual([]);
  });

  it("detecta un valor por defecto inseguro", () => {
    process.env.CSRF_SECRET = "changeme-super-secret-csrf-key";
    const problems = findSecretProblems();
    expect(problems.some((p) => p.includes("CSRF_SECRET"))).toBe(true);
  });

  it("detecta un secreto demasiado corto", () => {
    process.env.GRAPHQL_JWT_AUTH_SECRET_KEY = "corto";
    const problems = findSecretProblems();
    expect(problems.some((p) => p.includes("GRAPHQL_JWT_AUTH_SECRET_KEY"))).toBe(true);
  });

  it("detecta un secreto ausente requerido", () => {
    delete process.env.WC_CONSUMER_KEY;
    const problems = findSecretProblems();
    expect(problems.some((p) => p.includes("WC_CONSUMER_KEY"))).toBe(true);
  });
});

describe("findSecretProblems — pasarela de pruebas en producción", () => {
  const GOOD = "x9f2a7b4c1d8e6f3a2b9c0d7e4f1a8b5";

  /** Entorno de producción completo y correcto, con la pasarela real indicada. */
  function productionEnv(extra: Record<string, string> = {}): Record<string, string> {
    return {
      NODE_ENV: "production",
      GRAPHQL_JWT_AUTH_SECRET_KEY: GOOD,
      CSRF_SECRET: GOOD,
      WC_WEBHOOK_SECRET: GOOD,
      HWE_REVALIDATION_SECRET: GOOD,
      WC_CONSUMER_KEY: "ck_live",
      WC_CONSUMER_SECRET: "cs_live",
      ALLOWED_ORIGIN: "https://tienda.example",
      PAYMENT_PROVIDER: "wompi",
      ...extra,
    };
  }

  it("una pasarela real y sin secreto de noop no da problemas", () => {
    expect(findSecretProblems(productionEnv())).toEqual([]);
  });

  it("PAYMENT_PROVIDER=noop en producción es un problema de arranque", () => {
    const problems = findSecretProblems(productionEnv({ PAYMENT_PROVIDER: "noop" }));
    expect(problems.some((p) => p.includes("PAYMENT_PROVIDER"))).toBe(true);
  });

  it("el literal público de noop no vale como NOOP_INTEGRITY_SECRET", () => {
    const problems = findSecretProblems(
      productionEnv({ NOOP_INTEGRITY_SECRET: "noop-sandbox-secret" }),
    );
    expect(problems.some((p) => p.includes("NOOP_INTEGRITY_SECRET"))).toBe(true);
  });

  it("un NOOP_INTEGRITY_SECRET propio y largo no da problemas", () => {
    const problems = findSecretProblems(
      productionEnv({ NOOP_INTEGRITY_SECRET: "secreto-de-sandbox-propio-0123456789" }),
    );
    expect(problems).toEqual([]);
  });
});
