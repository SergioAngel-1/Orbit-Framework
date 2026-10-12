import { execFileSync } from "node:child_process";
import path from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  .gitignore: lo que contiene secretos o copias de seguridad no se versiona.
//
//  `.env` es una coincidencia EXACTA de ese nombre: no cubre `.env.prod`, justo el
//  archivo que docker-compose.prod.yml y `.env.prod.example` mandan crear con TODOS
//  los secretos de producción. Y las copias que generan backup.sh/backup-cron.sh
//  (`uploads-*.tar.gz` y el directorio `backups/`) no estaban cubiertas: los
//  volcados de la base de datos (`*.sql.gz`) sí, los medios no.
//
//  Se pregunta a git de verdad (`git check-ignore`), no se lee el archivo.
// ============================================================================

const ROOT = path.resolve(__dirname, "../../..");

/** ¿Ignora git esta ruta? (`check-ignore` sale con 0 si la ignora y con 1 si no.) */
function ignored(relativePath: string): boolean {
  try {
    execFileSync("git", ["check-ignore", "-q", "--no-index", relativePath], {
      cwd: ROOT,
      stdio: "ignore",
    });
    return true;
  } catch {
    return false;
  }
}

describe(".gitignore", () => {
  it.each([
    [".env.prod", "secretos de producción"],
    [".env", "entorno local"],
    ["frontend/.env.local", "entorno local del frontend"],
    ["backups/", "directorio destino de las copias"],
    ["backups/db-20260101-030000.sql.gz", "volcado de la base de datos"],
    ["backups/uploads-20260101-030000.tar.gz", "copia de los medios"],
    ["uploads-20260101-030000.tar.gz", "copia de los medios fuera del directorio"],
  ])("ignora %s (%s)", (file) => {
    expect(ignored(file)).toBe(true);
  });

  it.each([
    [".env.example", "plantilla de desarrollo"],
    [".env.prod.example", "plantilla de producción"],
    ["frontend/.env.example", "plantilla del frontend"],
    ["backend/scripts/backup.sh", "el propio script de copias"],
  ])("NO ignora %s (%s)", (file) => {
    expect(ignored(file)).toBe(false);
  });
});
