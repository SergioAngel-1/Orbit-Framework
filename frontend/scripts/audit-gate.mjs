#!/usr/bin/env node
// ============================================================================
//  Gate de `npm audit` para CI (`npm run audit:gate`).
//
//  Falla ante cualquier aviso `high` o `critical` del árbol COMPLETO de
//  dependencias (también las de desarrollo: en Siu varias altas transitivas de
//  herramientas de build se cazaron así), salvo los de la allowlist de abajo.
//
//  Por qué no basta `npm audit --audit-level=high`: un aviso sin versión
//  corregida publicada dejaría CI en rojo de forma permanente y bloquearía
//  todo lo demás. La allowlist lo tolera de forma EXPLÍCITA y justificada, y el
//  resto de hallazgos —presentes o futuros— siguen rompiendo la build.
//
//  Falla CERRADO: si `npm audit` no se puede ejecutar (registro caído, salida
//  que no es JSON) el gate termina con código 2 en vez de dar el visto bueno.
//
//  Códigos de salida: 0 = limpio · 1 = hallazgos sin permitir · 2 = no evaluable.
// ============================================================================

import { spawnSync } from "node:child_process";
import { pathToFileURL } from "node:url";

/** Severidades que hacen fallar el gate. */
const BLOCKING_SEVERITIES = new Set(["high", "critical"]);

/**
 * @typedef {{ id: string, reason: string }} AllowEntry
 * @typedef {{
 *   id: string,
 *   package: string,
 *   title: string,
 *   severity: string,
 *   url: string | null,
 * }} Advisory
 * @typedef {Advisory & { reason: string }} AllowedAdvisory
 */

/**
 * Avisos tolerados. Cada entrada DEBE llevar el motivo y la condición para
 * retirarla. Una entrada que deja de aparecer en el audit se señala como
 * obsoleta (no rompe CI, pero el informe pide retirarla).
 *
 * @type {AllowEntry[]}
 */
export const ALLOWLIST = [
  {
    id: "GHSA-vfj7-8cjw-p6xm",
    reason:
      "braces <=3.0.3 (DoS por patrones anidados). No existe versión corregida " +
      "publicada: 3.0.3 es la última. Solo llega por eslint-config-next → " +
      "fast-glob → micromatch, es decir, herramientas de desarrollo que no se " +
      "empaquetan ni se ejecutan en producción (`npm audit --omit=dev` está " +
      "limpio). Retirar cuando se publique un braces corregido.",
  },
];

/** Identificador estable de un aviso: el GHSA de su URL o, si falta, su `source`. */
function advisoryId(via) {
  const match = typeof via.url === "string" && via.url.match(/\/advisories\/([^/?#]+)/);
  return match ? match[1] : `npm-advisory-${via.source}`;
}

/**
 * Convierte la salida de `npm audit --json` en un informe, o falla cerrado.
 *
 * @param {string} stdout
 * @returns {{ vulnerabilities: Record<string, any>, [key: string]: any }}
 */
export function parseAuditOutput(stdout) {
  if (typeof stdout !== "string" || stdout.trim() === "") {
    throw new Error("npm audit no devolvió salida (¿registro inaccesible?).");
  }
  let parsed;
  try {
    parsed = JSON.parse(stdout);
  } catch (err) {
    const detail = err instanceof Error ? err.message : String(err);
    throw new Error(`La salida de npm audit no es JSON válido: ${detail}`);
  }
  if (parsed && typeof parsed === "object" && parsed.error) {
    const { code, summary } = parsed.error;
    throw new Error(`npm audit falló: ${[code, summary].filter(Boolean).join(" · ")}`);
  }
  return parsed;
}

/**
 * Separa los avisos high/critical entre bloqueantes y permitidos.
 *
 * Solo cuentan los avisos propiamente dichos (los objetos de `via`). Los
 * paquetes que únicamente heredan un aviso (`via` = nombres de otros paquetes)
 * no son hallazgos propios: npm los marca con la misma severidad, pero al
 * permitir el aviso de origen toda su cadena queda cubierta.
 *
 * @param {unknown} report Salida ya parseada de `npm audit --json`.
 * @param {AllowEntry[]} allowlist
 * @returns {{
 *   blocking: Advisory[],
 *   allowed: AllowedAdvisory[],
 *   staleAllowlist: string[],
 * }}
 */
export function evaluateAudit(report, allowlist) {
  const vulns = report && typeof report === "object" ? report.vulnerabilities : undefined;
  if (!vulns || typeof vulns !== "object") {
    throw new Error("Informe de npm audit inválido: falta `vulnerabilities`.");
  }

  /** @type {Map<string, Advisory>} */
  const seen = new Map();
  for (const [name, vuln] of Object.entries(vulns)) {
    for (const via of vuln?.via ?? []) {
      if (!via || typeof via !== "object") continue;
      const id = advisoryId(via);
      if (seen.has(id)) continue;
      seen.set(id, {
        id,
        package: via.name ?? name,
        title: via.title ?? "(sin título)",
        severity: via.severity ?? vuln.severity,
        url: via.url ?? null,
      });
    }
  }

  const reasonById = new Map(allowlist.map((entry) => [entry.id, entry.reason]));
  const blocking = [];
  const allowed = [];
  for (const advisory of seen.values()) {
    if (!BLOCKING_SEVERITIES.has(advisory.severity)) continue;
    const reason = reasonById.get(advisory.id);
    if (reason === undefined) blocking.push(advisory);
    else allowed.push({ ...advisory, reason });
  }

  const staleAllowlist = allowlist.map((entry) => entry.id).filter((id) => !seen.has(id));
  return { blocking, allowed, staleAllowlist };
}

/**
 * Texto del informe para la salida de CI.
 *
 * @param {ReturnType<typeof evaluateAudit>} result
 * @returns {string}
 */
export function formatReport(result) {
  const lines = [];

  if (result.blocking.length > 0) {
    lines.push(`✖ Avisos high/critical sin permitir (${result.blocking.length}):`);
    for (const a of result.blocking) {
      lines.push(`  - ${a.id} [${a.severity}] ${a.package}: ${a.title}`);
      if (a.url) lines.push(`    ${a.url}`);
    }
    lines.push(
      "  Corrígelos (npm audit fix / subir la dependencia) o, solo si no hay arreglo " +
        "posible y no afectan a producción, añádelos con motivo a ALLOWLIST.",
    );
  } else {
    lines.push("✔ npm audit: sin avisos high/critical fuera de la allowlist.");
  }

  if (result.allowed.length > 0) {
    lines.push(`ℹ Avisos tolerados por la allowlist (${result.allowed.length}):`);
    for (const a of result.allowed) {
      lines.push(`  - ${a.id} [${a.severity}] ${a.package}`);
      lines.push(`    Motivo: ${a.reason}`);
    }
  }

  if (result.staleAllowlist.length > 0) {
    lines.push(
      `⚠ Entradas obsoletas en la allowlist (ya no aparecen en el audit; retíralas ` +
        `de scripts/audit-gate.mjs): ${result.staleAllowlist.join(", ")}`,
    );
  }

  return lines.join("\n");
}

/** Ejecuta `npm audit --json` y devuelve su salida estándar. */
function runAudit() {
  const run = spawnSync("npm", ["audit", "--json"], {
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
    shell: process.platform === "win32",
  });
  if (run.error) throw run.error;
  // `npm audit` sale con 1 cuando hay avisos: lo que importa es el JSON.
  return run.stdout;
}

function main() {
  try {
    const result = evaluateAudit(parseAuditOutput(runAudit()), ALLOWLIST);
    console.log(formatReport(result));
    process.exitCode = result.blocking.length > 0 ? 1 : 0;
  } catch (err) {
    const detail = err instanceof Error ? err.message : String(err);
    console.error(`✖ audit-gate: no se pudo evaluar npm audit (falla cerrado). ${detail}`);
    process.exitCode = 2;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
