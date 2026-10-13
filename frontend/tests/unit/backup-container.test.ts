import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  El contenedor `backup` de producción tiene que hacer copias de verdad.
//
//  Fallo silencioso reproducido con alpine:3.23: con `cap_drop: ALL`, `crond`
//  (busybox) no puede cambiar de usuario ni de grupos para lanzar cada tarea —aunque
//  ya sea root— y la descarta sin ninguna salida. El contenedor salía sano y con el
//  cron programado, y no se hacía ni una copia. Con SETUID y SETGID la tarea corre.
//
//  Y el panel habla con el contenedor por archivos fuera del docroot
//  (`./backups`, ver HWE_BACKUPS_DIR): la config, el disparador de "Backup ahora" y
//  el estado de cada corrida (hwe-backup-status.json, que lee el panel).
// ============================================================================

const read = (relative: string) =>
  readFileSync(fileURLToPath(new URL(`../../../${relative}`, import.meta.url)), "utf8");

const prod = read("docker-compose.prod.yml");
// Sin comentarios: las explicaciones nombran rutas que el código ya no usa.
const entrypoint = read("backend/scripts/backup-entrypoint.sh")
  .split("\n")
  .filter((line) => !line.trim().startsWith("#"))
  .join("\n");

/** Bloque del servicio `backup` (hasta el siguiente servicio o sección de primer nivel). */
function backupService(): string {
  const start = prod.indexOf("\n  backup:");
  expect(start, "servicio backup").toBeGreaterThan(-1);
  const rest = prod.slice(start + 1);
  const end = rest.slice(1).search(/\n(?:\S|\s{2}[a-z][\w-]*:\s*\n)/);
  return end === -1 ? rest : rest.slice(0, end + 1);
}

/** Lista YAML simple bajo una clave del servicio (`cap_add:` → ["SETUID", …]). */
function yamlList(block: string, key: string): string[] {
  const match = block.match(new RegExp(`\\n\\s{4}${key}:\\n((?:\\s{6}-\\s*\\S+\\n)+)`));
  if (!match) return [];
  return [...match[1]!.matchAll(/-\s*(\S+)/g)].map((m) => m[1]!);
}

describe("docker-compose.prod.yml: servicio backup", () => {
  const block = backupService();

  it("sigue sin privilegios: cap_drop ALL y no-new-privileges", () => {
    expect(yamlList(block, "cap_drop")).toContain("ALL");
    expect(block).toContain("no-new-privileges:true");
  });

  it("devuelve SOLO SETUID y SETGID, que crond necesita para lanzar cada tarea", () => {
    expect(yamlList(block, "cap_add").sort()).toEqual(["SETGID", "SETUID"]);
  });
});

describe("backup-entrypoint.sh: arranca con los scripts montados en solo lectura", () => {
  // El compose monta los scripts con `:ro`. Un `chmod +x` sobre un archivo de un montaje
  // de solo lectura falla SIEMPRE (EROFS); con `set -e` el entrypoint moría al arrancar y
  // el contenedor entraba en bucle de reinicios. Por eso no hay ninguna orden chmod (los
  // comentarios que la nombran no cuentan): los scripts ya llegan +x versionados en git.
  it("no ejecuta chmod sobre los scripts montados", () => {
    expect(entrypoint).not.toMatch(/^\s*chmod\s/m);
  });

  it("los scripts siguen montándose :ro (no se aflojan permisos)", () => {
    expect(prod).toMatch(/\.\/backend\/scripts:\/opt\/hwe\/scripts:ro/);
  });

  // Un bind mount de un ARCHIVO fija su inodo: `git reset --hard` (el CD) lo sustituye
  // por otro y el contenedor seguía ejecutando la versión vieja hasta recrearse (en
  // producción, scripts de hacía semanas, sin la configuración del panel). Se monta la
  // carpeta, que siempre ve los archivos actuales.
  it("monta la CARPETA de scripts, no cada archivo suelto, en producción y en desarrollo", () => {
    const dev = read("docker-compose.yml");
    for (const compose of [prod, dev]) {
      expect(compose).not.toMatch(/backend\/scripts\/backup-[\w-]+\.sh:/);
      expect(compose).toContain(
        'entrypoint: ["/bin/sh", "/opt/hwe/scripts/backup-entrypoint.sh"]',
      );
    }
  });
});

describe("backup-entrypoint.sh", () => {
  // El cron programado y el watcher de "Backup ahora" escriben al stdout del proceso 1, que
  // es lo que lee `docker logs hwe_backup` (un archivo interno sería invisible y efímero); el
  // resultado de cada corrida también queda en hwe-backup-status.json, que lee el panel.
  it("programa el cron y el watcher del disparador manual", () => {
    expect(entrypoint).toMatch(/backup-cron\.sh scheduled %s/);
    expect(entrypoint).toMatch(/backup-trigger-watch\.sh %s/);
    expect(entrypoint).toContain("CRON_LOG='>> /proc/1/fd/1 2>&1'");
    // Las rutas salen de la carpeta montada, no de un `/usr/local/bin` que ya no existe.
    expect(entrypoint).toContain("$SCRIPTS_DIR");
    expect(entrypoint).not.toContain("/usr/local/bin/backup");
  });

  it("la salida de las copias NO va a un archivo interno del contenedor", () => {
    expect(entrypoint).not.toMatch(/>>\s*\/var\/log\/backup\.log/);
  });

  it("un fallo de `apk add` no se esconde tras el contenedor: el job fallaría después a la vista", () => {
    const apk = entrypoint.split("\n").find((line) => line.includes("apk add"));

    expect(apk).toBeDefined();
    expect(apk).toContain(">/dev/null");
  });
});
