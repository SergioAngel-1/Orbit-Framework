import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { safeJsonLd } from "./safe-json-ld";

describe("safeJsonLd", () => {
  it("un valor con </script> no puede cerrar el bloque", () => {
    const out = safeJsonLd({ name: "</script><script>alert(1)</script>" });
    expect(out).not.toContain("</script>");
    expect(out).not.toContain("<");
    expect(JSON.parse(out)).toEqual({ name: "</script><script>alert(1)</script>" });
  });

  it("escapa & y los separadores de línea Unicode", () => {
    const value = { a: "A&B", b: "x\u2028y\u2029z" };
    const out = safeJsonLd(value);
    expect(out).not.toContain("&");
    expect(out).not.toMatch(/[\u2028\u2029]/);
    expect(JSON.parse(out)).toEqual(value);
  });
});

// Un texto del CMS (FAQ, nombre de producto, extracto) que lleve `</script>`
// cierra el bloque y abre otro: cada JSON-LD se serializa con `safeJsonLd`. Una
// vista nueva con `JSON.stringify` a pelo rompe aquí.
describe("JSON-LD en las vistas", () => {
  function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) return sources(path);
      return /\.tsx$/.test(name) && !/\.test\.tsx$/.test(name) ? [path] : [];
    });
  }

  it("ningún componente embebe JSON-LD con JSON.stringify", () => {
    const offenders = sources(join(__dirname, "..", "..")).filter((file) => {
      const source = readFileSync(file, "utf8");
      return (
        source.includes("application/ld+json") &&
        /__html:\s*JSON\.stringify\(/.test(source)
      );
    });
    expect(offenders).toEqual([]);
  });
});
