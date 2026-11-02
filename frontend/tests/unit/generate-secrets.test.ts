import { execFileSync } from "node:child_process";
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, describe, expect, it } from "vitest";

// ============================================================================
//  backend/scripts/generate-secrets.sh: los secretos compartidos quedan con el
//  MISMO valor en `.env` y `frontend/.env.local`. El interno del BFF
//  (`HWE_REVALIDATION_SECRET`) se escribía solo en la raíz y, en modo híbrido,
//  el frontend se quedaba con el placeholder: WordPress respondía 404 a todo.
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");
let dir: string | undefined;
afterEach(() => {
  if (dir) rmSync(dir, { recursive: true, force: true });
  dir = undefined;
});

const valueOf = (file: string, key: string) =>
  readFileSync(file, "utf8")
    .split("\n")
    .find((line) => line.startsWith(`${key}=`))
    ?.slice(key.length + 1);

function setup() {
  dir = mkdtempSync(path.join(tmpdir(), "secrets-"));
  mkdirSync(path.join(dir, "frontend"));
  copyFileSync(path.join(ROOT, ".env.example"), path.join(dir, ".env.example"));
  copyFileSync(
    path.join(ROOT, "frontend/.env.example"),
    path.join(dir, "frontend/.env.example"),
  );
  return dir;
}

const run = (cwd: string) =>
  execFileSync("sh", [path.join(ROOT, "backend/scripts/generate-secrets.sh")], {
    cwd,
    stdio: "pipe",
  });

describe("generate-secrets.sh", () => {
  it("escribe HWE_REVALIDATION_SECRET en los dos ficheros con el mismo valor", () => {
    const cwd = setup();
    run(cwd);
    const root = valueOf(path.join(cwd, ".env"), "HWE_REVALIDATION_SECRET");
    const front = valueOf(
      path.join(cwd, "frontend/.env.local"),
      "HWE_REVALIDATION_SECRET",
    );
    expect(root).toMatch(/^[0-9a-f]{64}$/);
    expect(front).toBe(root);
    expect(valueOf(path.join(cwd, "frontend/.env.local"), "CSRF_SECRET")).toBe(
      valueOf(path.join(cwd, ".env"), "CSRF_SECRET"),
    );
  });

  it("con un secreto propio en la raíz, lo copia al frontend sin rotarlo", () => {
    const cwd = setup();
    run(cwd);
    const root = valueOf(path.join(cwd, ".env"), "HWE_REVALIDATION_SECRET");
    const frontFile = path.join(cwd, "frontend/.env.local");
    writeFileSync(
      frontFile,
      readFileSync(frontFile, "utf8").replace(
        /^HWE_REVALIDATION_SECRET=.*$/m,
        "HWE_REVALIDATION_SECRET=changeme-hwe-revalidation-secret",
      ),
    );
    run(cwd);
    expect(valueOf(path.join(cwd, ".env"), "HWE_REVALIDATION_SECRET")).toBe(root);
    expect(valueOf(frontFile, "HWE_REVALIDATION_SECRET")).toBe(root);
  });
});
