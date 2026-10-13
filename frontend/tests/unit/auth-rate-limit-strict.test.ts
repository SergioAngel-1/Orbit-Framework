import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Todo endpoint de escritura de `/api/auth/*` limita con `strict: true`: sin
//  Redis degrada a un contador en memoria en vez de quedarse sin límite
//  (fail-open). `AGENTS.md §9` lo promete para «los endpoints de auth»; esta
//  guarda rompe si una ruta nueva (o una existente) olvida la opción.
// ============================================================================

const AUTH_DIR = path.resolve(__dirname, "../../src/app/api/auth");

function routes(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) return routes(full);
    return name === "route.ts" ? [full] : [];
  });
}

describe("rate-limit de los endpoints de escritura de auth", () => {
  const guarded = routes(AUTH_DIR).filter((file) =>
    readFileSync(file, "utf8").includes("guardMutation("),
  );

  it("encuentra los endpoints de escritura (la guarda no está vacía)", () => {
    expect(guarded.length).toBeGreaterThanOrEqual(10);
  });

  it.each(guarded.map((file) => [path.relative(AUTH_DIR, file), file]))(
    "%s limita con strict: true",
    (_name, file) => {
      expect(readFileSync(file as string, "utf8")).toMatch(/strict:\s*true/);
    },
  );
});
