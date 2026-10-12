import { describe, expect, it } from "vitest";
import { Linter } from "eslint";
// La config real del repo: si la regla cambia, este test lo nota.
import eslintConfig from "../../eslint.config.mjs";

const linter = new Linter();
// La config usa valores de regla sin tipar (`"warn"`, mensajes), que el tipo
// `Linter.Config` no acepta tal cual: el cast es solo de tipos, el contenido es
// la config real que usa `npm run lint`.
const config = eslintConfig as unknown as Linter.Config[];

/** Errores de la regla de frontera al lintar `code` como si viviera en `filename`. */
function boundaryErrors(code: string, filename: string): string[] {
  const messages = linter.verify(code, config, filename);
  return messages
    .filter((m) => m.ruleId === "no-restricted-imports" && m.severity === 2)
    .map((m) => m.message);
}

// La frontera núcleo/instancia (AGENTS.md §1.1): el núcleo no puede depender de
// código de instancia. Se comprueba con la config real, no con una copia.
describe("ESLint: frontera núcleo/instancia", () => {
  it("rechaza importar @/instance/** desde el núcleo (salvo el registro)", () => {
    expect(
      boundaryErrors(
        "import { algo } from '@/instance/lib/algo';",
        "src/lib/ejemplo.ts",
      ),
    ).toHaveLength(1);
    expect(
      boundaryErrors(
        "import type { Algo } from '@/instance/lib/tipos';",
        "src/lib/ejemplo.ts",
      ),
    ).toHaveLength(1);
  });

  it("permite la única puerta: @/instance/registry", () => {
    expect(
      boundaryErrors(
        "import { instanceRegistry } from '@/instance/registry';",
        "src/lib/ejemplo.ts",
      ),
    ).toHaveLength(0);
    expect(
      boundaryErrors(
        "import { instanceRegistry } from '@/instance/registry';",
        "src/app/api/revalidate/route.ts",
      ),
    ).toHaveLength(0);
  });

  it("también aplica en app/api, proxy.ts y components/ui", () => {
    for (const filename of [
      "src/app/api/ejemplo/route.ts",
      "src/proxy.ts",
      "src/components/ui/boton.tsx",
    ]) {
      expect(
        boundaryErrors("import { algo } from '@/instance/lib/algo';", filename),
      ).toHaveLength(1);
    }
  });

  it("no aplica fuera del núcleo (una vista de instancia puede usar instance/lib)", () => {
    expect(
      boundaryErrors(
        "import { algo } from '@/instance/lib/algo';",
        "src/app/[locale]/ejemplo/page.tsx",
      ),
    ).toHaveLength(0);
  });

  it("sigue rechazando components/** (salvo ui/) desde el núcleo", () => {
    expect(
      boundaryErrors(
        "import { Card } from '@/components/products/card';",
        "src/lib/ejemplo.ts",
      ),
    ).toHaveLength(1);
    expect(
      boundaryErrors(
        "import { Button } from '@/components/ui/button';",
        "src/lib/ejemplo.ts",
      ),
    ).toHaveLength(0);
  });
});
