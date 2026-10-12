import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Techo de procesos de Apache en PRODUCCIÓN.
//
//  La imagen oficial de WordPress trae `MaxRequestWorkers 150` y
//  `MaxConnectionsPerChild 0` (los hijos no se reciclan nunca), y cada hijo puede
//  crecer hasta el `memory_limit` de PHP. Con un contenedor de unos cientos de MB
//  basta una ráfaga de consultas WPGraphQL pesadas (o el prerender de `next build`)
//  para que Apache haga fork hasta que el cgroup lo mata: 500 en /graphql, luego
//  408 y Apache colgado.
//
//  `backend/config/apache-mpm.conf` se monta en `conf-enabled/`, que Apache lee
//  DESPUÉS de `mods-enabled/mpm_prefork.conf`, así que gana sin pisar el archivo de la
//  distribución. Este test fija la relación entre ese techo y el límite de memoria
//  del contenedor: subir uno sin el otro es justo el error que se quiere evitar.
// ============================================================================

const read = (relative: string) =>
  readFileSync(fileURLToPath(new URL(`../../../${relative}`, import.meta.url)), "utf8");

const conf = existsSync(
  fileURLToPath(new URL("../../../backend/config/apache-mpm.conf", import.meta.url)),
)
  ? read("backend/config/apache-mpm.conf")
  : "";
const confCode = conf
  .split("\n")
  .filter((line) => !line.trim().startsWith("#"))
  .join("\n");

function directive(name: string): number | undefined {
  const match = confCode.match(new RegExp(`^\\s*${name}\\s+(\\d+)\\s*$`, "m"));
  return match ? Number(match[1]) : undefined;
}

const prodCompose = read("docker-compose.prod.yml");
const devCompose = read("docker-compose.yml");

/** Bloque `services.<name>` del compose (hasta el siguiente servicio de primer nivel). */
function service(compose: string, name: string): string {
  const start = compose.indexOf(`\n  ${name}:`);
  expect(start, `servicio ${name}`).toBeGreaterThan(-1);
  const rest = compose.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][\w-]*:\s*\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

/** Límite de memoria del contenedor en MB (`memory: 512m` / `2g`). */
function memoryLimitMb(block: string): number {
  const match = block.match(/memory:\s*(\d+)([mg])/i);
  expect(match, "deploy.resources.limits.memory").not.toBeNull();
  const amount = Number(match![1]);
  return match![2]!.toLowerCase() === "g" ? amount * 1024 : amount;
}

describe("apache-mpm.conf", () => {
  it("existe y fija los cinco parámetros del MPM prefork", () => {
    expect(conf).not.toBe("");
    for (const name of [
      "StartServers",
      "MinSpareServers",
      "MaxSpareServers",
      "MaxRequestWorkers",
      "MaxConnectionsPerChild",
    ]) {
      expect(directive(name), name).toBeDefined();
    }
    expect(confCode).toContain("<IfModule mpm_prefork_module>");
  });

  it("recorta el techo de la imagen (150 workers) y recicla los hijos", () => {
    expect(directive("MaxRequestWorkers")).toBeLessThan(150);
    // 0 = no reciclar nunca: es lo que deja crecer a un hijo sin límite.
    expect(directive("MaxConnectionsPerChild")).toBeGreaterThan(0);
  });

  it("es coherente: arranque ≤ reserva ≤ techo", () => {
    expect(directive("StartServers")!).toBeLessThanOrEqual(
      directive("MaxRequestWorkers")!,
    );
    expect(directive("MinSpareServers")!).toBeLessThanOrEqual(
      directive("MaxSpareServers")!,
    );
    expect(directive("MaxSpareServers")!).toBeLessThanOrEqual(
      directive("MaxRequestWorkers")!,
    );
  });

  it("el techo cabe en el límite de memoria del contenedor de WordPress", () => {
    // Medido en un servidor real: ~60 MB de cargo efectivo por hijo con carga de
    // WPGraphQL y ~100 MB de base (proceso padre + segmento compartido de OPcache).
    const PER_CHILD_MB = 60;
    const BASE_MB = 100;
    const limit = memoryLimitMb(service(prodCompose, "wordpress"));

    expect(
      BASE_MB + directive("MaxRequestWorkers")! * PER_CHILD_MB,
    ).toBeLessThanOrEqual(limit);
  });
});

describe("docker-compose: el techo solo va en producción", () => {
  it("producción monta apache-mpm.conf en conf-enabled (se lee después del MPM de la distribución)", () => {
    const wordpress = service(prodCompose, "wordpress");

    expect(wordpress).toMatch(
      /\.\/backend\/config\/apache-mpm\.conf:\/etc\/apache2\/conf-enabled\/zz-[\w-]+\.conf:ro/,
    );
  });

  it("desarrollo no lo monta: allí no hay presión de memoria que justifique estrechar nada", () => {
    expect(devCompose).not.toContain("apache-mpm.conf");
  });
});
