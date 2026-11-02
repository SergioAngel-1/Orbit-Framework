import { execFileSync, spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import {
  classify,
  compileRules,
  eslintBoundary,
  globToRegExp,
  loadBoundaries,
} from "../../../tools/boundaries.mjs";

// ============================================================================
//  Frontera núcleo/instancia: `tools/boundaries.json` es la fuente única que
//  leen ESLint y `tools/upgrade-check.mjs`. Aquí se fija qué dueño tiene cada
//  ruta, que la doc (UPGRADE.md §1) no diverja del manifiesto y que la
//  comprobación de actualización clasifique bien un merge real.
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");
const rules = loadBoundaries(ROOT);

describe("tools/boundaries.json: dueño de cada ruta", () => {
  it.each([
    ["frontend/src/lib/cart/index.ts", "core"],
    ["frontend/src/app/api/store/cart/route.ts", "core"],
    ["frontend/src/app/api/instance/returns/route.ts", "instance"],
    ["frontend/src/components/ui/button.tsx", "core"],
    ["frontend/src/components/layout/site-header.tsx", "instance"],
    ["frontend/src/app/[locale]/page.tsx", "instance"],
    ["frontend/src/app/not-found.tsx", "core"],
    ["frontend/src/instance/registry.ts", "instance"],
    ["frontend/src/i18n/messages/es.json", "instance"],
    ["frontend/src/i18n/routing.ts", "gray"],
    ["frontend/package.json", "gray"],
    ["backend/wp-content/plugins/hwe-loyalty/hwe-loyalty.php", "core"],
    ["backend/wp-content/plugins/hwe-instance-tienda/plugin.php", "instance"],
    ["backend/wp-content/mu-plugins/instance/10-campos.php", "instance"],
    ["tools/boundaries.local.json", "instance"],
    ["tools/upgrade-check.mjs", "core"],
    ["contract/openapi.json", "core"],
    ["docker-compose.yml", "core"],
    ["docker-compose.override.yml", "instance"],
  ])("%s → %s", (file, owner) => {
    expect(classify(file, rules)).toBe(owner);
  });

  it("cada fichero versionado tiene dueño (nada queda sin listar)", () => {
    const files = execFileSync("git", ["ls-files"], { cwd: ROOT, encoding: "utf8" })
      .split("\n")
      .filter(Boolean);
    const unlisted = files.filter((file) => classify(file, rules) === "unlisted");
    expect(unlisted).toEqual([]);
  });

  it("las rutas de instancia de UPGRADE.md §1 están en el manifiesto", () => {
    const doc = readFileSync(path.join(ROOT, "docs/UPGRADE.md"), "utf8");
    const section = doc.split("**Instancia**")[1].split("**Zona gris")[0];
    const documented = [...section.matchAll(/`((?:frontend|backend)\/[^`]+)`/g)]
      .map((match) => match[1])
      .filter((p) => !p.includes("instance.config.json"));
    expect(documented.length).toBeGreaterThan(5);
    for (const documentedPath of documented) {
      const sample = documentedPath
        .replace(/\(salvo[^)]*\)/, "")
        .trim()
        .replace(/\/$/, "/x.ts")
        .replace(/\*\*$/, "x.ts")
        .replace(/\*/g, "tienda");
      expect([documentedPath, classify(sample, rules)]).toEqual([
        documentedPath,
        "instance",
      ]);
    }
  });
});

describe("tools/boundaries.mjs", () => {
  it("globToRegExp: ** cruza carpetas, * no y los corchetes son literales", () => {
    expect(globToRegExp("a/**").test("a/b/c.ts")).toBe(true);
    expect(globToRegExp("a/*").test("a/b/c.ts")).toBe(false);
    expect(globToRegExp("a/[locale]/**").test("a/[locale]/p.tsx")).toBe(true);
    expect(globToRegExp("a/[locale]/**").test("a/l/p.tsx")).toBe(false);
  });

  it("compileRules falla en voz alta con un dueño desconocido", () => {
    expect(() => compileRules([{ path: "x", owner: "mio" }], "prueba.json")).toThrow(
      /prueba.json/,
    );
  });

  it("el manifiesto local de la instancia se evalúa antes que el del framework", () => {
    const dir = mkdtempSync(path.join(tmpdir(), "boundaries-"));
    try {
      mkdirSync(path.join(dir, "tools"));
      writeFileSync(
        path.join(dir, "tools/boundaries.json"),
        JSON.stringify({ rules: [{ path: "**", owner: "core" }] }),
      );
      writeFileSync(
        path.join(dir, "tools/boundaries.local.json"),
        JSON.stringify({ rules: [{ path: "ops/**", owner: "instance" }] }),
      );
      const local = loadBoundaries(dir);
      expect(classify("ops/seed.sh", local)).toBe("instance");
      expect(classify("backend/x.php", local)).toBe("core");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it("eslintBoundary: núcleo con lint y fuera las rutas de instancia que contiene", () => {
    const boundary = eslintBoundary(rules);
    expect(boundary.files).toEqual(
      expect.arrayContaining([
        "src/lib/**/*.{ts,tsx}",
        "src/app/api/**/*.{ts,tsx}",
        "src/proxy.ts",
        "src/components/ui/**/*.{ts,tsx}",
      ]),
    );
    expect(boundary.ignores).toEqual(["src/app/api/instance/**/*.{ts,tsx}"]);
  });
});

// ── Merge de prueba real en repos temporales ─────────────────────────────────

const SCRIPT = path.join(ROOT, "tools/upgrade-check.mjs");
let workdir: string | undefined;

afterEach(() => {
  if (workdir) rmSync(workdir, { recursive: true, force: true });
  workdir = undefined;
});

function run(cwd: string, cmd: string, args: string[]) {
  execFileSync(cmd, args, { cwd, stdio: "ignore" });
}

function write(dir: string, file: string, content: string) {
  mkdirSync(path.dirname(path.join(dir, file)), { recursive: true });
  writeFileSync(path.join(dir, file), content);
}

/**
 * Repo con el manifiesto real; la «instancia» (rama main) y la «release» (tag)
 * cambian las rutas indicadas de forma incompatible.
 */
function scenario(paths: string[]) {
  workdir = mkdtempSync(path.join(tmpdir(), "upgrade-check-"));
  const dir = workdir;
  run(dir, "git", ["init", "-q", "-b", "main"]);
  run(dir, "git", ["config", "user.email", "t@example.invalid"]);
  run(dir, "git", ["config", "user.name", "t"]);
  run(dir, "git", ["config", "commit.gpgsign", "false"]);
  write(
    dir,
    "tools/boundaries.json",
    readFileSync(path.join(ROOT, "tools/boundaries.json"), "utf8"),
  );
  write(
    dir,
    "tools/boundaries.mjs",
    readFileSync(path.join(ROOT, "tools/boundaries.mjs"), "utf8"),
  );
  for (const file of paths) write(dir, file, "base\n");
  run(dir, "git", ["add", "-A"]);
  run(dir, "git", ["commit", "-q", "-m", "base"]);
  run(dir, "git", ["switch", "-q", "-c", "release"]);
  for (const file of paths) write(dir, file, "framework\n");
  run(dir, "git", ["commit", "-q", "-am", "release"]);
  run(dir, "git", ["tag", "v9.9.9"]);
  run(dir, "git", ["switch", "-q", "main"]);
  for (const file of paths) write(dir, file, "instancia\n");
  run(dir, "git", ["commit", "-q", "-am", "instancia"]);
  const result = spawnSync("node", [SCRIPT, "v9.9.9"], { cwd: dir, encoding: "utf8" });
  const branch = execFileSync("git", ["branch", "--show-current"], {
    cwd: dir,
    encoding: "utf8",
  });
  const branches = execFileSync("git", ["branch"], { cwd: dir, encoding: "utf8" });
  return { ...result, branch: branch.trim(), branches };
}

describe("tools/upgrade-check.mjs", () => {
  it("conflictos solo en instancia y zona gris: sale con 0 y deja el árbol intacto", () => {
    const r = scenario([
      "frontend/src/components/layout/site-header.tsx",
      "frontend/package.json",
    ]);
    expect(r.status).toBe(0);
    expect(r.stdout).toContain(
      "instancia (gana lo local): frontend/src/components/layout/site-header.tsx",
    );
    expect(r.stdout).toContain("zona gris (a mano):        frontend/package.json");
    expect(r.branch).toBe("main");
    expect(r.branches).not.toContain("upgrade-check-");
  });

  it("un conflicto en el núcleo falla y avisa de la deriva", () => {
    const r = scenario(["frontend/src/lib/cart/index.ts"]);
    expect(r.status).toBe(1);
    expect(r.stdout).toContain(
      "núcleo editado:            frontend/src/lib/cart/index.ts",
    );
    expect(r.stdout).toContain(
      "NÚCLEO EDITADO:            frontend/src/lib/cart/index.ts",
    );
  });

  it("una ref inexistente sale con 2", () => {
    const r = scenario(["frontend/src/instance/registry.ts"]);
    expect(r.status).toBe(0);
    const missing = spawnSync("node", [SCRIPT, "v0.0.0-no"], {
      cwd: workdir,
      encoding: "utf8",
    });
    expect(missing.status).toBe(2);
  });
});
