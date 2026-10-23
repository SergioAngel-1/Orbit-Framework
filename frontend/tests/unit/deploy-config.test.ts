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
const ci = root(".github/workflows/ci.yml");

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

describe("docker-compose.prod.yml: segmentación de red", () => {
  /** Redes de un servicio (`networks:` → lista de items). */
  function networksOf(block: string): string[] {
    const list = block.match(/\n {4}networks:\n((?: {6}- *\w+\n)+)/);
    return list ? [...list[1]!.matchAll(/- *(\w+)/g)].map((m) => m[1]!) : [];
  }

  it("la capa de datos es interna: ni Caddy ni el resto de la pila sale por ella", () => {
    expect(prod).toMatch(/\n {2}data:\n {4}driver: bridge\n {4}internal: true/);
  });

  it("Caddy NO está en `data`: no puede resolver ni alcanzar db ni redis", () => {
    const networks = networksOf(service(prod, "caddy"));

    expect(networks).toContain("edge");
    expect(networks).toContain("app");
    expect(networks).not.toContain("data");
  });

  it("db y redis viven SOLO en `data` (red interna, sin salida a Internet)", () => {
    for (const name of ["db", "redis"]) {
      expect(networksOf(service(prod, name)), name).toEqual(["data"]);
    }
  });

  it("WordPress, el frontend y wp-cli conservan salida a Internet", () => {
    // WordPress: SMTP, actualizaciones de plugins y webhooks salientes.
    // Frontend: pasarelas de pago y Sentry. wp-cli: `setup.sh` descarga WP y plugins.
    for (const name of ["wordpress", "frontend", "wpcli", "wp-cron"]) {
      const networks = networksOf(service(prod, name));
      expect(networks, name).toContain("data");
      expect(
        networks.some((n) => n !== "data"),
        name,
      ).toBe(true);
    }
  });

  it("WordPress y el frontend se hablan por `app` y Caddy llega a los dos", () => {
    for (const name of ["wordpress", "frontend"]) {
      expect(networksOf(service(prod, name)), name).toContain("app");
    }
    expect(networksOf(service(prod, "caddy"))).toContain("edge");
  });
});

describe("docker-compose.prod.yml: ciclo de vida y recursos", () => {
  it("los servicios con estado reciben SIGTERM y tienen tiempo para cerrar", () => {
    // Sin `init`, Node es PID 1 y no reapea zombis; sin `stop_grace_period`,
    // Docker espera 10 s y manda SIGKILL con peticiones en vuelo (y un bgsave de
    // Redis o la caché de Next a medio escribir).
    for (const name of ["frontend", "wordpress", "db", "redis", "caddy"]) {
      const block = service(prod, name);
      expect(block, name).toContain("init: true");
      expect(block, name).toMatch(/stop_grace_period: \d+s/);
    }
  });

  it("Caddy tiene margen de memoria para HTTP/3 y un límite de GC explícito", () => {
    expect(service(prod, "caddy")).toContain("memory: 256m");
    expect(service(prod, "caddy")).toContain('GOMEMLIMIT: "100MiB"');
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

describe("docker-compose.prod.yml: hardening", () => {
  // La cabecera del compose promete `no-new-privileges` en TODOS los servicios.
  it("todos los servicios llevan no-new-privileges", () => {
    const top = prod.split("\nservices:\n")[1]!.split(/\nvolumes:\n|\nnetworks:\n/)[0]!;
    const names = [...top.matchAll(/^  ([\w-]+):\s*$/gm)].map((m) => m[1]!);

    expect(names.length).toBeGreaterThanOrEqual(8);
    for (const name of names) {
      expect(service(prod, name), name).toContain("no-new-privileges:true");
    }
  });
});

describe("sondas de salud: miden lo que dicen medir", () => {
  // `/api/health` devuelve 200 aunque Redis y WordPress estén caídos (y hace dos
  // pings de red cada 30 s): como sonda de contenedor, reiniciaba o dejaba "sano"
  // por el motivo equivocado. La readiness estricta (`?ready=1`) la exige el
  // despliegue, no el healthcheck.
  it("el frontend de dev y de prod sondea liveness (`/api/health/live`)", () => {
    for (const compose of [prod, dev]) {
      const frontend = service(compose, "frontend");
      expect(frontend).toContain("/api/health/live");
      expect(frontend).not.toMatch(/api\/health(?!\/live)/);
    }
  });

  it("WordPress se sondea con el endpoint ligero, no con /wp-json/ (>1 MB con WooCommerce)", () => {
    const wordpress = service(prod, "wordpress");
    expect(wordpress).toContain("wp-json/wp/v2/categories?per_page=1");
    expect(wordpress).not.toMatch(/curl[^\n]*localhost\/wp-json\/["'\s]/);
  });

  it("Caddy tiene sonda propia (API de administración en loopback)", () => {
    const caddyService = service(prod, "caddy");
    expect(caddyService).toContain("healthcheck:");
    expect(caddyService).toContain("http://localhost:2019/config/");
  });
});

describe(".github/workflows/cd.yml", () => {
  it("el nombre de la imagen va en minúsculas (GHCR rechaza «Org/Repo»)", () => {
    expect(cd).toMatch(/IMAGE_LC="\$\{IMAGE,,\}"/);
    // Todas las etiquetas salen del nombre ya en minúsculas, no de `env.IMAGE`.
    expect(cd).not.toMatch(/\$\{\{ env\.IMAGE \}\}:/);
  });

  it("sin WORDPRESS_PUBLIC_URL el build se omite con un aviso, no falla", () => {
    expect(cd).toMatch(
      /build-push:[\s\S]*?if: \$\{\{ vars\.WORDPRESS_PUBLIC_URL != '' \}\}/,
    );
    expect(cd).toMatch(/sin-configurar:[\s\S]*?vars\.WORDPRESS_PUBLIC_URL == ''/);
  });
});

describe("Caddy: CI valida con la versión de producción", () => {
  it("la imagen del `caddy validate` de CI es la misma que la de docker-compose.prod.yml", () => {
    const image = (source: string) => /image:\s*(caddy:[\w.-]+)/.exec(source)?.[1];
    const inCi = /\b(caddy:[\w.-]+)/.exec(
      ci.slice(ci.indexOf("Validar Caddyfile")),
    )?.[1];

    expect(image(prod)).toBeDefined();
    expect(inCi).toBe(image(prod));
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
