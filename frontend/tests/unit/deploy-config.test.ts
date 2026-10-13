import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Configuración de despliegue: lo que se rompe EN SILENCIO si alguien la toca
//  sin saber por qué está así. Ninguna de estas propiedades falla un build ni un
//  tipo: solo se nota en producción.
// ============================================================================

const root = (relative: string) =>
  readFileSync(fileURLToPath(new URL(`../../../${relative}`, import.meta.url)), "utf8");

/** Texto sin comentarios de línea (los comentarios nombran las mismas cosas). */
const code = (source: string) =>
  source
    .split("\n")
    .filter((line) => !line.trim().startsWith("#"))
    .join("\n");

const dockerfile = code(root("frontend/Dockerfile"));
const prod = code(root("docker-compose.prod.yml"));
const dev = code(root("docker-compose.yml"));
const caddy = code(root("Caddyfile"));
const cd = root(".github/workflows/cd.yml");

/** El bloque de un servicio (desde `  nombre:` hasta el siguiente de primer nivel). */
function service(compose: string, name: string): string {
  const start = compose.indexOf(`\n  ${name}:\n`);
  expect(start, `servicio ${name}`).toBeGreaterThanOrEqual(0);
  const rest = compose.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][a-z0-9_-]*:\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

describe("frontend/Dockerfile", () => {
  it("congela WORDPRESS_PUBLIC_URL ANTES de copiar el código y falla pronto sin ella", () => {
    const arg = dockerfile.indexOf("ARG WORDPRESS_PUBLIC_URL");
    const guard = dockerfile.indexOf('test -n "$WORDPRESS_PUBLIC_URL"');
    const copy = dockerfile.indexOf("COPY . .\n\nRUN npm run build");
    expect(arg).toBeGreaterThan(-1);
    expect(guard).toBeGreaterThan(arg);
    // Delante del COPY: con el builder clásico cada instrucción es una capa, y un
    // ARG detrás de `COPY . .` no se cachearía jamás.
    expect(copy).toBeGreaterThan(guard);
  });

  it("copia scripts/ antes de `npm ci` (el postinstall parchea Next)", () => {
    const scripts = dockerfile.indexOf("COPY scripts ./scripts");
    const install = dockerfile.indexOf("RUN npm ci");
    expect(scripts).toBeGreaterThan(-1);
    expect(install).toBeGreaterThan(scripts);
  });

  it("el stage dev va DESPUÉS de prod: el builder clásico recorre el archivo hasta el target", () => {
    expect(dockerfile.indexOf("FROM base AS dev")).toBeGreaterThan(
      dockerfile.indexOf("AS prod"),
    );
  });

  it("deja el directorio de la caché de imágenes con el dueño del proceso", () => {
    expect(dockerfile).toMatch(
      /mkdir -p \.next\/cache\/images && chown -R nextjs:nodejs \.next\/cache/,
    );
  });

  it("el `public/` que copia el stage prod existe en el repositorio", () => {
    expect(() =>
      readFileSync(fileURLToPath(new URL("../../public/.gitkeep", import.meta.url))),
    ).not.toThrow();
  });
});

describe("docker-compose.prod.yml", () => {
  it("el núcleo de WordPress se comparte entre wordpress, wpcli, wp-cron y caddy (solo lectura)", () => {
    for (const name of ["wordpress", "wpcli", "wp-cron"]) {
      expect(service(prod, name), name).toContain("wordpress_core:/var/www/html\n");
    }
    expect(service(prod, "caddy")).toContain("wordpress_core:/var/www/html:ro");
  });

  it("DISABLE_WP_CRON va emparejado con el servicio wp-cron que ejecuta los eventos", () => {
    expect(service(prod, "wordpress")).toContain("define( 'DISABLE_WP_CRON', true );");
    expect(service(prod, "wp-cron")).toContain("wp cron event run --due-now");
  });

  it("Redis compartido: WordPress usa prefijo y flush selectivo (un flush no borra el frontend)", () => {
    const wordpress = service(prod, "wordpress");
    expect(wordpress).toContain("define( 'WP_REDIS_PREFIX', 'hwe-wp:' );");
    expect(wordpress).toContain("define( 'WP_REDIS_SELECTIVE_FLUSH', true );");
  });

  it("los medios se emiten siempre por el dominio público", () => {
    expect(service(prod, "wordpress")).toContain(
      "define( 'WP_CONTENT_URL', 'https://${PUBLIC_DOMAIN}/wp-content' );",
    );
  });

  it("el frontend construye con los argumentos que el build congela", () => {
    const frontend = service(prod, "frontend");
    for (const arg of [
      "WORDPRESS_PUBLIC_URL:",
      "NEXT_PUBLIC_SITE_URL:",
      "CSP_ANALYTICS_PROVIDERS:",
    ]) {
      expect(frontend, arg).toContain(arg);
    }
    expect(frontend).toContain("target: prod");
  });

  it("el frontend sirve con plazos de runtime holgados, no con los de por defecto", () => {
    const frontend = service(prod, "frontend");
    expect(frontend).toMatch(
      /GRAPHQL_TIMEOUT_MS:\s+"\$\{RUNTIME_GRAPHQL_TIMEOUT_MS:-25000\}"/,
    );
    expect(frontend).toMatch(
      /CONFIG_TIMEOUT_MS:\s+"\$\{RUNTIME_CONFIG_TIMEOUT_MS:-15000\}"/,
    );
  });

  it("el frontend conoce el origen público de los medios en runtime (CmsImage)", () => {
    expect(service(prod, "frontend")).toMatch(/\n\s+WORDPRESS_PUBLIC_URL:/);
  });

  it("la caché de imágenes tiene su volumen", () => {
    expect(service(prod, "frontend")).toContain(
      "next_image_cache:/app/.next/cache/images",
    );
    expect(prod).toMatch(/\n {2}next_image_cache:\n/);
  });

  it("MariaDB lleva el buffer pool dimensionado al límite de memoria", () => {
    const db = service(prod, "db");
    expect(db).toContain("--innodb-buffer-pool-size=");
    expect(db).toContain("--max-connections=");
  });
});

describe("docker-compose.prod.yml: la base de datos no se pierde por descuido", () => {
  const envProd = root(".env.prod.example");

  it("db_data es un volumen EXTERNO con nombre fijo: `down -v` no lo borra y un proyecto distinto no crea otro vacío", () => {
    const block = prod.slice(prod.indexOf("\nvolumes:"));
    expect(block).toMatch(
      /\n {2}db_data:\n {4}external: true\n {4}name: \$\{HWE_DB_VOLUME:-hwe_db_data\}/,
    );
  });

  it(".env.prod.example fija COMPOSE_FILE a producción y documenta el volumen", () => {
    expect(envProd).toMatch(/^COMPOSE_FILE=docker-compose\.prod\.yml$/m);
    expect(envProd).toMatch(/^HWE_DB_VOLUME=hwe_db_data$/m);
  });

  it("el compose de desarrollo conserva su volumen normal (en dev `down -v` debe limpiar)", () => {
    const dev = root("docker-compose.yml");
    const block = dev.slice(dev.indexOf("\nvolumes:"));
    expect(block).toMatch(/\n {2}db_data:\n/);
    expect(block).not.toMatch(/db_data:\n {4}external/);
  });
});

describe("docker-compose.yml (desarrollo)", () => {
  it("Redis compartido: WordPress usa prefijo y flush selectivo", () => {
    const wordpress = service(dev, "wordpress");
    expect(wordpress).toContain("define( 'WP_REDIS_PREFIX', 'hwe-wp:' );");
    expect(wordpress).toContain("define( 'WP_REDIS_SELECTIVE_FLUSH', true );");
  });

  it("tiene wp-cron y desactiva el pseudo-cron por petición", () => {
    expect(service(dev, "wordpress")).toContain("define( 'DISABLE_WP_CRON', true );");
    expect(service(dev, "wp-cron")).toContain("wp cron event run --due-now");
  });

  it("monta los plugins propios (módulos) que setup.sh activa", () => {
    for (const plugin of [
      "hwe-banners",
      "hwe-site-reviews",
      "hwe-coupon-rules",
      "hwe-consent-log",
    ]) {
      expect(service(dev, "wordpress"), plugin).toContain(
        `/plugins/${plugin}:/var/www/html/wp-content/plugins/${plugin}`,
      );
    }
  });
});

describe("Caddyfile", () => {
  it("los .php de wp-content siguen yendo a Apache; el resto lo sirve Caddy del disco", () => {
    const start = caddy.indexOf("handle /wp-content/* {");
    expect(start).toBeGreaterThan(-1);
    const block = caddy.slice(start, caddy.indexOf("\n    }\n", start));
    const php = block.indexOf("handle @php");
    const files = block.indexOf("file_server");
    expect(php).toBeGreaterThan(-1);
    expect(block.slice(php, files)).toContain("reverse_proxy wordpress:80");
    expect(files).toBeGreaterThan(php);
    expect(block).toContain("root * /var/www/html");
  });

  it("el frontend reintenta la conexión durante el despliegue en vez de dar 502", () => {
    expect(caddy).toMatch(/reverse_proxy frontend:3000 \{[^}]*lb_try_duration 30s/);
  });

  it("el redirect del panel solo existe con ADMIN_DOMAIN y va ANTES del handle de /wp-admin", () => {
    const moved = caddy.indexOf("handle @panel_moved {");
    const admin = caddy.indexOf("handle /wp-admin/* {");
    expect(caddy).toContain('expression `"{$ADMIN_DOMAIN:}" != ""`');
    expect(moved).toBeGreaterThan(-1);
    expect(moved).toBeLessThan(admin);
  });

  it("importa los sitios opcionales de caddy/conf.d", () => {
    expect(caddy).toContain("import /etc/caddy/conf.d/*.caddy");
  });
});

describe("CD", () => {
  it("construye la imagen con WORDPRESS_PUBLIC_URL (sin ella el Dockerfile falla)", () => {
    expect(cd).toContain("WORDPRESS_PUBLIC_URL=${{ vars.WORDPRESS_PUBLIC_URL }}");
  });

  it("exige la CI en verde para el commit antes de construir", () => {
    expect(cd).toContain("actions/workflows/ci.yml/runs?head_sha=");
  });

  it("calienta la caché tras desplegar a producción y comprueba rutas reales", () => {
    expect(cd).toContain("backend/scripts/warm-cache.sh");
    expect(cd).toContain("/api/health?ready=1");
    expect(cd).toContain("/sitemap.xml");
  });

  it("hace una copia de la base de datos ANTES de desplegar a producción y aborta si no hay", () => {
    const backup = cd.indexOf("Copia de seguridad previa");
    const deploy = cd.indexOf("Desplegar (SSH → pull + up -d)\n");
    expect(backup, "paso de copia previa").toBeGreaterThan(-1);
    expect(backup).toBeLessThan(deploy);
    expect(cd).toContain("/opt/hwe/scripts/backup-cron.sh manual");
    // Una corrida omitida por lock sale con éxito: se exige un volcado reciente y con contenido.
    expect(cd).toMatch(
      /find backups[^\n]*db-\*\.sql\.gz[^\n]*-size \+0[^\n]*-mmin -15/,
    );
    expect(cd).toContain("se aborta el despliegue");
  });

  it("permite volver atrás sin construir", () => {
    expect(cd).toContain("image_tag:");
  });
});
