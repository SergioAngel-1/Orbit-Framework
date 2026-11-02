import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

// ============================================================================
//  `contract/config.schema.json` (generado desde Schema.php; lo fija
//  `backend/tests/Unit/ControlCenter/ContractTest.php`) frente al lado TS:
//  todo campo que `/config` publica tiene su valor por defecto en
//  `CONFIG_DEFAULTS`, que es lo que el frontend sirve con WordPress caído.
// ============================================================================

interface JsonSchemaNode {
  type?: string | string[];
  "x-hwe-translation-of"?: string;
  properties?: Record<string, JsonSchemaNode>;
}

const ROOT = path.resolve(__dirname, "../../..");
const contract = JSON.parse(
  readFileSync(path.join(ROOT, "contract/config.schema.json"), "utf8"),
) as JsonSchemaNode;

/** Rutas `a.b.c` de las hojas del contrato. */
/** Las variantes `<campo>_<idioma>` dependen de `i18n.locales`: no tienen default en TS. */
function leafPaths(node: JsonSchemaNode, prefix = ""): string[] {
  if (node["x-hwe-translation-of"]) return [];
  if (!node.properties) return [prefix];
  return Object.entries(node.properties).flatMap(([key, child]) =>
    leafPaths(child, prefix ? `${prefix}.${key}` : key),
  );
}

function has(value: unknown, dotted: string): boolean {
  let current: unknown = value;
  for (const key of dotted.split(".")) {
    if (typeof current !== "object" || current === null || !(key in current))
      return false;
    current = (current as Record<string, unknown>)[key];
  }
  return true;
}

describe("contract/config.schema.json", () => {
  const leaves = leafPaths(contract).filter((p) => p !== "modules");

  it("describe la config pública entera (no un fichero vacío)", () => {
    expect(leaves.length).toBeGreaterThan(100);
    expect(leaves).toContain("brand.name");
  });

  it("cada campo publicado tiene su valor en CONFIG_DEFAULTS", () => {
    expect(leaves.filter((leaf) => !has(CONFIG_DEFAULTS, leaf))).toEqual([]);
  });
});
