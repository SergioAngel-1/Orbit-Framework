import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Los contenedores que cargan WordPress usan imágenes propias con PhpRedis.
//
//  La imagen oficial no trae la extensión `redis`, así que Redis Object Cache caía a
//  Predis (PHP puro): medido en producción, ~42 llamadas a Redis por arranque de
//  WordPress costaban ~40 ms por petición. Con la extensión, el plugin la detecta
//  solo (`class_exists('Redis')`).
//
//  Lo que se rompe en silencio y fija este test:
//    - un servicio que vuelve a la imagen oficial sigue funcionando, pero con Predis
//      (y `wpcli`/`wp-cron` hablarían con Redis por otro cliente que la web);
//    - una etiqueta local sin `pull_policy: build` hace que Compose intente bajarla de
//      un registro;
//    - una versión de PhpRedis sin fijar (o sin suma) trae lo que haya ese día;
//    - `WP_REDIS_CLIENT` = phpredis: con la extensión ausente (una imagen sin
//      reconstruir, WordPress nativo en modo híbrido), redis-cache 2.8.0 hace
//      `new Redis()` y el `Error` de clase inexistente no lo atrapa su `catch
//      (Exception)`: fatal en cada petición. Sin la constante, cae a Predis.
// ============================================================================

const read = (relative: string) =>
  readFileSync(fileURLToPath(new URL(`../../../${relative}`, import.meta.url)), "utf8");

/** Texto sin comentarios de línea (los comentarios nombran las mismas cosas). */
const code = (source: string) =>
  source
    .split("\n")
    .filter((line) => !line.trim().startsWith("#") && !line.trim().startsWith("//"))
    .join("\n");

/** El bloque de un servicio (desde `  nombre:` hasta el siguiente de primer nivel). */
function service(compose: string, name: string): string {
  const start = compose.indexOf(`\n  ${name}:\n`);
  expect(start, `servicio ${name}`).toBeGreaterThanOrEqual(0);
  const rest = compose.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][a-z0-9_-]*:\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

/** Nombres de todos los servicios del compose. */
function serviceNames(compose: string): string[] {
  const start = compose.indexOf("\nservices:\n");
  const end = compose.search(/\n(?:volumes|networks):\n/);
  const block = compose.slice(start, end === -1 ? undefined : end);
  return [...block.matchAll(/\n {2}([a-z][a-z0-9_-]*):\n/g)].map((m) => m[1]!);
}

const IMAGES = {
  wordpress: {
    context: "./backend/docker/wordpress",
    base: "wordpress:7.1-php8.4-apache",
  },
  cli: { context: "./backend/docker/wp-cli", base: "wordpress:cli-php8.4" },
} as const;

const SERVICES = [
  ["wordpress", "wordpress"],
  ["wpcli", "cli"],
  ["wp-cron", "cli"],
] as const;

const COMPOSES = [
  ["docker-compose.yml", "dev", code(read("docker-compose.yml"))],
  ["docker-compose.prod.yml", "prod", code(read("docker-compose.prod.yml"))],
] as const;

const DOCKERFILES = {
  wordpress: read("backend/docker/wordpress/Dockerfile"),
  cli: read("backend/docker/wp-cli/Dockerfile"),
};

/** Valor por defecto de un `ARG` del Dockerfile. */
const arg = (dockerfile: string, name: string) =>
  dockerfile.match(new RegExp(`^ARG ${name}=(\\S+)$`, "m"))?.[1];

describe.each(COMPOSES)("%s: imágenes de WordPress", (_, env, compose) => {
  it.each(SERVICES)("%s usa la imagen propia, construida en local", (name, kind) => {
    const block = service(compose, name);
    const tag = kind === "wordpress" ? "hwe-wordpress" : "hwe-wp-cli";
    expect(block).toContain(`\n    image: ${tag}:${env}\n`);
    expect(block).toContain(`\n    build:\n      context: ${IMAGES[kind].context}\n`);
    expect(block).toContain("\n    pull_policy: build\n");
  });

  it("ningún servicio carga ya la imagen oficial de WordPress directamente", () => {
    expect(compose).not.toMatch(/^\s*image:\s*["']?wordpress:/m);
  });

  it("todo servicio con WP_REDIS_* (el ancla compartida) usa una imagen con PhpRedis", () => {
    const withRedisConfig = serviceNames(compose).filter((name) =>
      /WORDPRESS_CONFIG_EXTRA:\s*\*wordpress-config-extra/.test(service(compose, name)),
    );
    expect(withRedisConfig.sort()).toEqual(SERVICES.map(([name]) => name).sort());
  });

  it("no fuerza WP_REDIS_CLIENT: con la extensión ausente sería un fatal, no Predis", () => {
    expect(compose).not.toContain("WP_REDIS_CLIENT");
  });
});

describe.each(Object.entries(DOCKERFILES))("Dockerfile de %s", (kind, dockerfile) => {
  const body = code(dockerfile);

  it("parte de la misma etiqueta oficial de siempre", () => {
    expect(body).toMatch(
      new RegExp(
        `^FROM ${IMAGES[kind as keyof typeof IMAGES].base.replace(/\./g, "\\.")}$`,
        "m",
      ),
    );
  });

  it("fija PhpRedis a una versión exacta, con suma SHA-256 comprobada", () => {
    expect(arg(body, "PHPREDIS_VERSION")).toMatch(/^\d+\.\d+\.\d+$/);
    expect(arg(body, "PHPREDIS_SHA256")).toMatch(/^[0-9a-f]{64}$/);
    expect(body).toContain('https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz"');
    expect(body).toContain(
      'echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -',
    );
    // Se instala el archivo verificado, nunca un nombre que PECL resuelva al vuelo.
    expect(body).toContain("pecl install /tmp/redis.tgz");
    expect(body).not.toMatch(/pecl install redis\b/);
    expect(body).toContain("docker-php-ext-enable redis");
  });

  it("deja escrita la procedencia de la versión", () => {
    const version = arg(body, "PHPREDIS_VERSION")!;
    expect(dockerfile).toContain(`PhpRedis ${version}:`);
    expect(dockerfile).toContain("https://pecl.php.net/package/redis");
    expect(dockerfile).toContain(
      `https://github.com/phpredis/phpredis/releases/tag/${version}`,
    );
  });

  it("limpia la descarga y la caché de PECL en la misma capa", () => {
    const run = body.slice(body.indexOf("RUN "));
    expect(run).toContain("rm -rf /tmp/redis.tgz /tmp/pear");
  });
});

describe("PhpRedis: la misma extensión en la web, el CLI y el cron", () => {
  it("las dos imágenes fijan la misma versión y la misma suma", () => {
    for (const name of ["PHPREDIS_VERSION", "PHPREDIS_SHA256"]) {
      expect(arg(DOCKERFILES.cli, name), name).toBe(arg(DOCKERFILES.wordpress, name));
    }
  });

  it("la imagen de CLI quita las herramientas de compilación y vuelve a www-data", () => {
    const body = code(DOCKERFILES.cli);
    expect(body).toContain(
      "apk add --no-cache --virtual .phpredis-build-deps $PHPIZE_DEPS",
    );
    expect(body).toContain("apk del --no-network .phpredis-build-deps");
    expect(body.trim().split("\n").at(-1)).toBe("USER www-data");
  });
});
