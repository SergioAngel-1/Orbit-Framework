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
] as const;

describe("findSecretProblems", () => {
  const saved: Record<string, string | undefined> = {};

  beforeEach(() => {
    for (const k of KEYS) {
      saved[k] = process.env[k];
      process.env[k] = GOOD;
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
