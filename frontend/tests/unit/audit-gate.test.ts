import { describe, expect, it } from "vitest";
import {
  evaluateAudit,
  formatReport,
  parseAuditOutput,
} from "../../scripts/audit-gate.mjs";

// ============================================================================
//  Gate de `npm audit` de CI (scripts/audit-gate.mjs).
//
//  Falla ante cualquier aviso high/critical del árbol COMPLETO salvo los de una
//  allowlist explícita y justificada. Estos tests fijan el contrato: qué cuenta
//  como hallazgo, qué se tolera y qué hace que el gate falle cerrado.
// ============================================================================

const HIGH = {
  source: 1240992,
  name: "braces",
  title: "braces vulnerable to stack-exhaustion DoS",
  url: "https://github.com/advisories/GHSA-vfj7-8cjw-p6xm",
  severity: "high",
  range: "<=3.0.3",
};

const CRITICAL = {
  source: 1111111,
  name: "left-pad",
  title: "RCE en left-pad",
  url: "https://github.com/advisories/GHSA-aaaa-bbbb-cccc",
  severity: "critical",
  range: "*",
};

const MODERATE = {
  source: 2222222,
  name: "postcss-selector-parser",
  title: "Complejidad cuadrática",
  url: "https://github.com/advisories/GHSA-rj75-hqrm-r3gf",
  severity: "moderate",
  range: "<7.1.6",
};

/** Construye un informe con la forma de `npm audit --json` (v2). */
function report(vulns: Record<string, { severity: string; via: unknown[] }>) {
  return { auditReportVersion: 2, vulnerabilities: vulns };
}

const ALLOW_BRACES = [{ id: "GHSA-vfj7-8cjw-p6xm", reason: "sin parche; solo dev" }];

describe("evaluateAudit", () => {
  it("sin vulnerabilidades no hay nada que bloquear", () => {
    const r = evaluateAudit(report({}), []);
    expect(r.blocking).toEqual([]);
    expect(r.allowed).toEqual([]);
    expect(r.staleAllowlist).toEqual([]);
  });

  it("un aviso high fuera de la allowlist bloquea", () => {
    const r = evaluateAudit(report({ braces: { severity: "high", via: [HIGH] } }), []);
    expect(r.blocking.map((a) => a.id)).toEqual(["GHSA-vfj7-8cjw-p6xm"]);
  });

  it("un aviso critical fuera de la allowlist bloquea", () => {
    const r = evaluateAudit(
      report({ "left-pad": { severity: "critical", via: [CRITICAL] } }),
      [],
    );
    expect(r.blocking.map((a) => a.id)).toEqual(["GHSA-aaaa-bbbb-cccc"]);
  });

  it("moderate y low no bloquean", () => {
    const r = evaluateAudit(
      report({
        "postcss-selector-parser": { severity: "moderate", via: [MODERATE] },
        tiny: {
          severity: "low",
          via: [
            {
              ...MODERATE,
              source: 3,
              url: "https://github.com/advisories/GHSA-low",
              severity: "low",
            },
          ],
        },
      }),
      [],
    );
    expect(r.blocking).toEqual([]);
  });

  it("un aviso en la allowlist se tolera y se informa aparte", () => {
    const r = evaluateAudit(
      report({ braces: { severity: "high", via: [HIGH] } }),
      ALLOW_BRACES,
    );
    expect(r.blocking).toEqual([]);
    expect(r.allowed.map((a) => a.id)).toEqual(["GHSA-vfj7-8cjw-p6xm"]);
    expect(r.allowed[0].reason).toBe("sin parche; solo dev");
  });

  it("los paquetes que solo heredan un aviso (via = nombres) no cuentan como hallazgos propios", () => {
    // npm marca como `high` a toda la cadena de dependientes de braces, pero su
    // `via` son cadenas, no avisos. Con braces permitido no debe quedar nada.
    const r = evaluateAudit(
      report({
        braces: { severity: "high", via: [HIGH] },
        micromatch: { severity: "high", via: ["braces"] },
        "fast-glob": { severity: "high", via: ["micromatch"] },
        "eslint-config-next": { severity: "high", via: ["fast-glob"] },
      }),
      ALLOW_BRACES,
    );
    expect(r.blocking).toEqual([]);
    expect(r.allowed).toHaveLength(1);
  });

  it("un aviso nuevo junto a uno permitido bloquea solo el nuevo", () => {
    const r = evaluateAudit(
      report({
        braces: { severity: "high", via: [HIGH] },
        "left-pad": { severity: "critical", via: [CRITICAL] },
      }),
      ALLOW_BRACES,
    );
    expect(r.blocking.map((a) => a.id)).toEqual(["GHSA-aaaa-bbbb-cccc"]);
    expect(r.allowed.map((a) => a.id)).toEqual(["GHSA-vfj7-8cjw-p6xm"]);
  });

  it("el mismo aviso visto desde varios paquetes se cuenta una sola vez", () => {
    const r = evaluateAudit(
      report({
        a: { severity: "high", via: [HIGH] },
        b: { severity: "high", via: [HIGH] },
      }),
      [],
    );
    expect(r.blocking).toHaveLength(1);
  });

  it("una entrada de la allowlist que ya no aparece se señala como obsoleta", () => {
    const r = evaluateAudit(report({}), ALLOW_BRACES);
    expect(r.staleAllowlist).toEqual(["GHSA-vfj7-8cjw-p6xm"]);
    expect(r.blocking).toEqual([]);
  });

  it("un aviso sin url usa su `source` como identificador", () => {
    const sinUrl = { source: 42, name: "x", title: "t", severity: "high" };
    const r = evaluateAudit(report({ x: { severity: "high", via: [sinUrl] } }), []);
    expect(r.blocking.map((a) => a.id)).toEqual(["npm-advisory-42"]);
  });

  it("falla cerrado ante un informe sin `vulnerabilities`", () => {
    expect(() => evaluateAudit({}, [])).toThrow(/vulnerabilities/);
    expect(() => evaluateAudit(null, [])).toThrow();
  });
});

describe("parseAuditOutput", () => {
  it("devuelve el informe cuando la salida es JSON de auditoría", () => {
    const out = JSON.stringify(report({}));
    expect(parseAuditOutput(out).vulnerabilities).toEqual({});
  });

  it("falla cerrado si npm devuelve un error (p. ej. registro inaccesible)", () => {
    const out = JSON.stringify({
      error: { code: "ENOTFOUND", summary: "request failed" },
    });
    expect(() => parseAuditOutput(out)).toThrow(/ENOTFOUND|request failed/);
  });

  it("falla cerrado si la salida no es JSON", () => {
    expect(() => parseAuditOutput("<html>502 Bad Gateway</html>")).toThrow(/JSON/);
    expect(() => parseAuditOutput("")).toThrow();
  });
});

describe("formatReport", () => {
  it("lista los hallazgos con id, paquete, severidad y enlace", () => {
    const r = evaluateAudit(
      report({ "left-pad": { severity: "critical", via: [CRITICAL] } }),
      [],
    );
    const text = formatReport(r);
    expect(text).toContain("GHSA-aaaa-bbbb-cccc");
    expect(text).toContain("left-pad");
    expect(text).toContain("critical");
    expect(text).toContain("https://github.com/advisories/GHSA-aaaa-bbbb-cccc");
  });

  it("incluye el motivo de lo permitido y avisa de las entradas obsoletas", () => {
    const r = evaluateAudit(report({ braces: { severity: "high", via: [HIGH] } }), [
      ...ALLOW_BRACES,
      { id: "GHSA-0000-0000-0000", reason: "ya parcheado" },
    ]);
    const text = formatReport(r);
    expect(text).toContain("sin parche; solo dev");
    expect(text).toContain("GHSA-0000-0000-0000");
  });
});
