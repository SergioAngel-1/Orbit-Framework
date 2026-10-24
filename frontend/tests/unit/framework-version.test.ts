import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  FRAMEWORK_VERSION: de qué release del framework parte una instancia.
//
//  `frontend/package.json` no sirve para eso: está en la zona gris de UPGRADE.md y
//  la instancia puede versionar ahí su propio producto. Sin una fuente fiable, un
//  agente que leía «2.0.0» en un clon de `main` concluía que le faltaban parches
//  que sí tenía (o al revés). El archivo tiene que coincidir con la última release
//  del CHANGELOG, o la versión que lee la instancia vuelve a mentir.
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");
const read = (relative: string) => readFileSync(path.join(ROOT, relative), "utf8");

describe("FRAMEWORK_VERSION", () => {
  const version = read("FRAMEWORK_VERSION").trim();

  it("es un SemVer sin nada más", () => {
    expect(version).toMatch(/^\d+\.\d+\.\d+$/);
  });

  it("coincide con la última release del CHANGELOG", () => {
    const latest = read("CHANGELOG.md").match(/^## \[(\d+\.\d+\.\d+)\]/m);
    expect(latest?.[1]).toBe(version);
  });
});
