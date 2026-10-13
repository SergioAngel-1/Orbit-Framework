import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { describe, expect, it } from "vitest";

// Todo `<form>` del sitio tiene que decidir si HubSpot puede capturarlo (ver
// `hubspot.ts`): los sensibles llevan `HUBSPOT_DO_NOT_COLLECT` y los que SÍ
// deben llegar al CRM están en esta lista. Un formulario nuevo sin decidir
// rompe el test en vez de mandar sus datos a HubSpot en silencio.
const COLLECTED = new Set(["components/forms/contact-form.tsx"]);

const SRC = join(__dirname, "..", "..");

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.tsx$/.test(name) && !/\.test\.tsx$/.test(name) ? [path] : [];
  });
}

/** Sin comentarios: un «`<form>`» dentro de uno no es un formulario. */
function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");
}

const forms = sourceFiles(SRC)
  .map((path) => ({
    file: relative(SRC, path),
    source: code(readFileSync(path, "utf8")),
  }))
  .filter(({ source }) => /<form\b/.test(source));

describe("formularios y la captura de HubSpot", () => {
  it("hay formularios que revisar", () => {
    expect(forms.length).toBeGreaterThan(5);
  });

  it.each(forms)("$file decide si HubSpot lo captura", ({ file, source }) => {
    const tags = source.match(/<form\b[^>]*>/g) ?? [];
    for (const tag of tags) {
      if (COLLECTED.has(file)) {
        expect(tag).not.toContain("HUBSPOT_DO_NOT_COLLECT");
      } else {
        expect(tag).toContain("{...HUBSPOT_DO_NOT_COLLECT}");
      }
    }
  });
});
