import fs from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import es from "@/i18n/messages/es.json";
import { CLIENT_NAMESPACES, pickClientMessages } from "./client-messages";

const SRC = path.resolve(__dirname, "../..");

function sourceFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return sourceFiles(full);
    return /\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)
      ? [full]
      : [];
  });
}

describe("pickClientMessages", () => {
  it("solo deja los namespaces de cliente", () => {
    const picked = pickClientMessages({
      login: { a: "1" },
      legal: { b: "2" },
      llms: {},
    });
    expect(Object.keys(picked)).toEqual(["login"]);
  });

  it("un namespace de la lista que no existe en el catálogo no rompe", () => {
    expect(pickClientMessages({})).toEqual({});
  });

  it("todos los namespaces de la lista existen en el catálogo (sin erratas)", () => {
    for (const namespace of CLIENT_NAMESPACES) {
      expect(namespace in es, namespace).toBe(true);
    }
  });
});

// El síntoma de olvidar un namespace es un texto con la clave cruda, y solo en el
// navegador: ningún tipo ni build lo ve. Esta guarda recorre los Client
// Components y comprueba que cada namespace que leen está en la lista.
describe("namespaces que usan los Client Components", () => {
  const clientFiles = sourceFiles(SRC).filter((file) =>
    /^\s*["']use client["']/.test(fs.readFileSync(file, "utf8")),
  );

  it("hay Client Components que revisar", () => {
    expect(clientFiles.length).toBeGreaterThan(20);
  });

  it('cada `useTranslations("ns")` y `t.raw("ns")` raíz está en CLIENT_NAMESPACES', () => {
    const allowed = new Set<string>(CLIENT_NAMESPACES);
    const missing: string[] = [];
    for (const file of clientFiles) {
      const source = fs.readFileSync(file, "utf8");
      const rel = path.relative(SRC, file);
      const used = [
        ...source.matchAll(/useTranslations\(\s*["'`]([A-Za-z0-9_.]+)["'`]\s*\)/g),
      ].map((m) => m[1]!.split(".")[0]!);
      // `useTranslations()` sin argumento lee del catálogo entero con `t.raw("ns")`.
      if (/useTranslations\(\s*\)/.test(source)) {
        used.push(
          ...[...source.matchAll(/\bt\.raw\(\s*["']([A-Za-z0-9_]+)["']\s*\)/g)].map(
            (m) => m[1]!,
          ),
        );
      }
      for (const namespace of used) {
        if (!allowed.has(namespace)) missing.push(`${rel}: ${namespace}`);
      }
    }
    expect(missing).toEqual([]);
  });

  it("ningún Client Component pide una clave de otro namespace con una variable", () => {
    // `useTranslations(variable)` no se puede comprobar estáticamente.
    const dynamic = clientFiles.filter((file) =>
      /useTranslations\(\s*[A-Za-z_]/.test(fs.readFileSync(file, "utf8")),
    );
    expect(dynamic.map((f) => path.relative(SRC, f))).toEqual([]);
  });
});
