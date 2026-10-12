import fs from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Guarda estructural: TODA llamada del BFF a WordPress pasa por un cliente que
//  añade el secreto interno.
//
//  Con `hwe-bff-only`, una llamada a GraphQL, la Store API o `hwe/v1/auth|consent`
//  sin el secreto recibe un 404 de WordPress. Un `fetch` suelto que se cuele en
//  una ruta nueva (típicamente al portar código) no fallaría en los tests
//  unitarios, que simulan `fetch`: solo en la pila real. Este test lo impide por
//  construcción: los únicos módulos que conocen la URL de WordPress son los
//  clientes de la lista.
// ============================================================================

const SRC = path.resolve(__dirname, "../..");

/** Módulos que construyen URLs de WordPress; todos añaden `internalSecretHeaders()`. */
const WORDPRESS_CLIENTS = new Set([
  "lib/wordpress/internal-fetch.ts", // REST de WordPress (hwe/v1, wp/v2…) y la URL base
  "lib/graphql-client.ts", // GraphQL
  "lib/woocommerce/client.ts", // wc/v3 (ck/cs)
  "lib/woocommerce/store-client.ts", // Store API (Cart-Token)
  "proxy.ts", // refresh del JWT (edge: no puede importar el cliente de servidor)
]);

function sourceFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return sourceFiles(full);
    return /\.(ts|tsx)$/.test(entry.name) && !/\.test\.(ts|tsx)$/.test(entry.name)
      ? [full]
      : [];
  });
}

/** El código sin comentarios de línea ni de bloque (los comentarios pueden nombrar las variables). */
function code(file: string): string {
  return fs
    .readFileSync(file, "utf8")
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/^\s*\/\/.*$/gm, "");
}

const files = sourceFiles(SRC).map((full) => ({
  rel: path.relative(SRC, full).split(path.sep).join("/"),
  code: code(full),
  raw: fs.readFileSync(full, "utf8"),
}));

describe("llamadas del BFF a WordPress", () => {
  it("hay archivos que revisar (la guarda no es vacía)", () => {
    expect(files.length).toBeGreaterThan(100);
    expect(files.some((f) => f.rel === "lib/wordpress/internal-fetch.ts")).toBe(true);
  });

  it("nadie usa NEXT_PUBLIC_WORDPRESS_API_URL: el navegador no habla con WordPress", () => {
    const offenders = files.filter((f) =>
      f.code.includes("NEXT_PUBLIC_WORDPRESS_API_URL"),
    );

    expect(offenders.map((f) => f.rel)).toEqual([]);
  });

  it("solo los clientes conocen las URLs de WordPress", () => {
    const urlVars = /WORDPRESS_INTERNAL_API_URL|WC_API_URL|WC_STORE_API_URL/;
    const offenders = files.filter(
      (f) => urlVars.test(f.code) && !WORDPRESS_CLIENTS.has(f.rel),
    );

    expect(offenders.map((f) => f.rel)).toEqual([]);
  });

  it("nadie construye rutas /wp-json/ por su cuenta salvo con wpFetch", () => {
    const offenders = files.filter(
      (f) =>
        !WORDPRESS_CLIENTS.has(f.rel) &&
        /wp-json/.test(f.code) &&
        // La única forma permitida: `wpFetch("/wp-json/…", …)`.
        /fetch\(\s*[`"'$]/.test(f.code.replace(/wpFetch\(/g, "")),
    );

    expect(offenders.map((f) => f.rel)).toEqual([]);
  });

  it("todo cliente añade internalSecretHeaders()", () => {
    for (const rel of WORDPRESS_CLIENTS) {
      const file = files.find((f) => f.rel === rel);
      expect(file, rel).toBeDefined();
      expect(file!.code, rel).toMatch(/internalSecretHeaders\(\)/);
    }
  });

  it("el secreto interno nunca llega a un componente de cliente", () => {
    const offenders = files.filter(
      (f) =>
        /^\s*["']use client["']/.test(f.raw) &&
        /security\/internal-secret|wordpress\/internal-fetch/.test(f.code),
    );

    expect(offenders.map((f) => f.rel)).toEqual([]);
  });
});
