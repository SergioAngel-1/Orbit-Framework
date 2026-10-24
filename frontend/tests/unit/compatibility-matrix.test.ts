import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  docs/COMPATIBILITY.md no se desvía de lo que de verdad se instala.
//
//  La matriz copia a mano las versiones de `frontend/package.json` y de
//  `backend/scripts/setup.sh`. Tras los parches de octubre de 2026 decía zod 4.4.3
//  y vitest ^4.1.11 cuando el paquete fijaba 4.6.7 y ^4.1.12: quien revisa una
//  instancia contra la matriz concluye que le falta un parche que sí tiene (o al
//  revés). Cada fila con un paquete npm conocido o una variable de `setup.sh` tiene
//  que coincidir con su fuente.
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");
const read = (relative: string) => readFileSync(path.join(ROOT, relative), "utf8");

const pkg = JSON.parse(read("frontend/package.json")) as {
  dependencies: Record<string, string>;
  devDependencies: Record<string, string>;
};
const npmVersions: Record<string, string> = {
  ...pkg.dependencies,
  ...pkg.devDependencies,
};
const setup = read("backend/scripts/setup.sh");
const rows = read("docs/COMPATIBILITY.md")
  .split("\n")
  .filter((line) => line.startsWith("|"))
  .map((line) =>
    line
      .split("|")
      .slice(1, -1)
      .map((cell) => cell.trim()),
  );

/** Las versiones entre backticks de una celda (`a` / `b`). */
const versionsIn = (cell: string) => [...cell.matchAll(/`([^`]+)`/g)].map((m) => m[1]);

describe("docs/COMPATIBILITY.md", () => {
  it("las versiones npm coinciden con frontend/package.json", () => {
    const checked: string[] = [];
    for (const [nameCell, versionCell] of rows) {
      if (!nameCell || !versionCell) continue;
      const names = nameCell.split("/").map((n) => n.trim());
      // `@testing-library/react / jsdom`: un nombre con barra se recompone.
      const packages = names.reduce<string[]>((acc, part) => {
        const last = acc[acc.length - 1];
        if (last?.startsWith("@") && !last.includes("/"))
          acc[acc.length - 1] = `${last}/${part}`;
        else acc.push(part);
        return acc;
      }, []);
      const versions = versionsIn(versionCell);
      packages.forEach((name, i) => {
        if (!(name in npmVersions)) return;
        const documented = versions[Math.min(i, versions.length - 1)];
        expect(documented, name).toBe(npmVersions[name]);
        checked.push(name);
      });
    }
    // Si el parseo deja de reconocer la tabla, el test no puede pasar en vacío.
    expect(checked).toEqual(expect.arrayContaining(["next", "zod", "vitest", "jsdom"]));
  });

  it("los plugins fijados coinciden con backend/scripts/setup.sh", () => {
    const checked: string[] = [];
    for (const [, versionCell, overrideCell] of rows) {
      const variable = overrideCell?.match(/^`([A-Z_]+_VERSION)`$/)?.[1];
      if (!variable) continue;
      const fixed = setup.match(
        new RegExp(`^${variable}="\\$\\{${variable}:-([^}]+)\\}"`, "m"),
      );
      expect(fixed?.[1], variable).toBe(versionsIn(versionCell)[0]);
      checked.push(variable);
    }
    expect(checked).toEqual(
      expect.arrayContaining([
        "WOOCOMMERCE_VERSION",
        "WPGRAPHQL_VERSION",
        "REDISCACHE_VERSION",
      ]),
    );
  });
});
