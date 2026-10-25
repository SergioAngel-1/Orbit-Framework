import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  E2E del CI dentro del repositorio de una instancia.
//
//  Los specs de `frontend/tests/e2e/**` (salvo `instance/`) están escritos contra
//  las vistas del starter, que una instancia reescribe por contrato (AGENTS.md
//  §1.1). En su repositorio fallarían siempre y no puede editar `.github/**`, así
//  que las dos pasadas del job `e2e` apuntan SOLO a `tests/e2e/instance/` (su
//  carpeta reservada) cuando la variable de repositorio `HWE_INSTANCE` vale el
//  literal `true`; sin ella, el framework ejecuta su suite de siempre.
//
//  Se lee como texto: aquí no hay GitHub Actions que evalúe el `if`.
// ============================================================================

const ci = readFileSync(
  fileURLToPath(new URL("../../../.github/workflows/ci.yml", import.meta.url)),
  "utf8",
);
const playwrightConfig = readFileSync(
  fileURLToPath(new URL("../../playwright.config.ts", import.meta.url)),
  "utf8",
);

/** El bloque de un job (desde `  <job>:` hasta el siguiente job de primer nivel). */
function job(name: string): string {
  const start = ci.indexOf(`\n  ${name}:\n`);
  expect(start, `job ${name}`).toBeGreaterThanOrEqual(0);
  const rest = ci.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][a-z0-9_-]*:\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

/** El bloque de un paso (`      - name: <paso>`) dentro de un job. */
function step(jobName: string, stepName: string): string {
  const block = job(jobName);
  const marker = `      - name: ${stepName}\n`;
  const start = block.indexOf(marker);
  expect(start, `paso ${stepName} de ${jobName}`).toBeGreaterThanOrEqual(0);
  const rest = block.slice(start + marker.length);
  const next = rest.search(/\n {6}- name: /);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

/** Los argumentos de cada `npx playwright test …` del bloque, en orden. */
function playwrightCommands(block: string): string[] {
  return [...block.matchAll(/npx playwright test([^\n]*)/g)].map((match) =>
    match[1].trim(),
  );
}

const INSTANCE_SPECS = "tests/e2e/instance/ --pass-with-no-tests";

describe("CI: e2e en modo instancia (HWE_INSTANCE=true)", () => {
  it("el job `e2e` recibe el interruptor de repositorio", () => {
    expect(job("e2e")).toMatch(/^ {6}HWE_INSTANCE: \$\{\{ vars\.HWE_INSTANCE \}\}$/m);
  });

  it("la primera pasada solo ejecuta los specs de la instancia", () => {
    const block = step("e2e", "Tests e2e");
    // Rama de instancia primero; si no, la suite completa del framework (sin args).
    expect(block).toContain('if [ "${HWE_INSTANCE:-}" = "true" ]; then');
    expect(playwrightCommands(block)).toEqual([INSTANCE_SPECS, ""]);
    // Sin specs en la carpeta reservada el job no debe fallar.
    expect(block).toContain("--pass-with-no-tests");
  });

  it("la pasada de módulos apagados también ejecuta solo los specs de la instancia", () => {
    const block = step("e2e", "Tests e2e con los módulos apagados");
    expect(block).toContain('if [ "${HWE_INSTANCE:-}" = "true" ]; then');
    expect(playwrightCommands(block)).toEqual([
      INSTANCE_SPECS,
      "tests/e2e/modules-off.spec.ts tests/e2e/smoke.spec.ts",
    ]);
  });

  it("Playwright no ejecuta la carpeta de la instancia en el repositorio del framework", () => {
    // `testDir` es `./tests/e2e` y Playwright desciende a subcarpetas: sin
    // `testIgnore`, un spec de la instancia se colaría en `npm run e2e` y en el CI
    // del framework. Con `HWE_INSTANCE=true` (el mismo interruptor) se ejecutan.
    expect(playwrightConfig).toContain('"**/instance/**"');
    expect(playwrightConfig).toContain("process.env.HWE_INSTANCE");
  });
});
