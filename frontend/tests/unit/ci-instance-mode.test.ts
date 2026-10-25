import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  CI del núcleo dentro del repo de una instancia.
//
//  `.github/**` es núcleo y la instancia no lo edita (docs/UPGRADE.md §1). Pero dos
//  jobs del CI solo tienen sentido en el repositorio del framework:
//
//   · `anti-instance` persigue justo los tokens que el código de la instancia lleva.
//   · `contract` superpone `examples/instance-minimal/` al repo y PISA el
//     `registry.ts` y los `mu-plugins/instance/` de la instancia: probaría otra cosa.
//
//  El interruptor es la variable de repositorio `HWE_INSTANCE` (valor exacto `true`).
//  Se lee como texto: aquí no hay GitHub Actions que evalúe el `if`.
// ============================================================================

const ci = readFileSync(
  fileURLToPath(new URL("../../../.github/workflows/ci.yml", import.meta.url)),
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

describe("CI: modo instancia (HWE_INSTANCE=true)", () => {
  it.each(["anti-instance", "contract"])(
    "el job %s solo corre en el repositorio del framework",
    (name) => {
      expect(job(name)).toContain("if: ${{ vars.HWE_INSTANCE != 'true' }}");
    },
  );

  it("el job del frontend recibe la variable para que sus tests se adapten", () => {
    // `gitignore.test.ts` describe la política del FRAMEWORK (carpeta de instancia
    // ignorada, patrón de plugins comentado); en una instancia eso es al revés.
    expect(job("frontend")).toMatch(
      /^ {4}env:\n(?: {6}\S.*\n)*? {6}HWE_INSTANCE: \$\{\{ vars\.HWE_INSTANCE \}\}/m,
    );
  });

  it("el valor de la variable es el literal `true`, como lee el resto del núcleo", () => {
    // El `if` compara contra la cadena exacta: cualquier otro valor mantiene el CI
    // del framework (fail-closed).
    const instructions = readFileSync(
      fileURLToPath(new URL("../../../docs/CREATE_INSTANCE.md", import.meta.url)),
      "utf8",
    );
    expect(instructions).toContain("HWE_INSTANCE=true");
  });
});
