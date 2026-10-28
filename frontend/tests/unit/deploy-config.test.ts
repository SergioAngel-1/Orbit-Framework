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

/**
 * Constantes PHP que ve `wordpress`: el bloque `x-wordpress-config-extra` (ancla YAML
 * compartida con `wpcli` y `wp-cron`) o, si volviera a estar inline, el propio servicio.
 */
function wordpressConfig(compose: string): string {
  const anchor = compose.indexOf("x-wordpress-config-extra: &wordpress-config-extra |");
  if (anchor === -1) {
    return service(compose, "wordpress");
  }
  const rest = compose.slice(anchor);
  const end = rest.indexOf("\nservices:");
  return end === -1 ? rest : rest.slice(0, end);
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

  it("el stage prod sube parches de Alpine y deja `public/` con el dueño del proceso", () => {
    const prodStage = dockerfile.slice(dockerfile.indexOf("AS prod"));

    // Paridad con `base` (sharp es nativo) y parches del sistema del momento del
    // build: el `pull` del tag de Node no los trae.
    expect(prodStage).toContain("apk add --no-cache libc6-compat");
    expect(prodStage).toContain("apk upgrade --no-cache");
    expect(prodStage).toContain(
      "COPY --from=builder --chown=nextjs:nodejs /app/public ./public",
    );
    expect(prodStage).toContain("org.opencontainers.image.title");
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
    expect(wordpressConfig(prod)).toContain("define( 'DISABLE_WP_CRON', true );");
    expect(service(prod, "wp-cron")).toContain("wp cron event run --due-now");
  });

  it("Redis compartido: WordPress usa prefijo y flush selectivo (un flush no borra el frontend)", () => {
    const wordpress = wordpressConfig(prod);
    expect(wordpress).toContain("define( 'WP_REDIS_PREFIX', 'hwe-wp:' );");
    expect(wordpress).toContain("define( 'WP_REDIS_SELECTIVE_FLUSH', true );");
  });

  it("los medios se emiten siempre por el dominio público", () => {
    expect(wordpressConfig(prod)).toContain(
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

  it("persiste TODA la caché de Next (ISR + imágenes), no solo images/", () => {
    expect(service(prod, "frontend")).toContain("next_cache:/app/.next/cache");
    expect(prod).toMatch(/\n {2}next_cache:\n/);
    // El volumen de solo imágenes dejaba el ISR en frío en cada despliegue.
    expect(prod).not.toContain("next_image_cache");
  });

  it("purga la caché de imágenes, que `next/image` no recolecta nunca", () => {
    const prune = service(prod, "cache-prune");

    expect(prune).toContain("next_cache:/cache");
    expect(prune).toContain("find /cache/images");
    // `$$` es el escape de Compose: con un solo `$`, Compose sustituye la variable
    // al RENDERIZAR y el `find` recibe `-mtime +""` (no borra nada) aunque el
    // entorno del contenedor sí la defina.
    expect(prune).toContain('-mtime +"$${CACHE_IMAGES_MAX_AGE_DAYS}"');
  });

  it("MariaDB lleva el buffer pool dimensionado al límite de memoria", () => {
    const db = service(prod, "db");
    expect(db).toContain("--innodb-buffer-pool-size=");
    expect(db).toContain("--max-connections=");
  });

  it("MariaDB acota conexiones y paquete, y deja rastro de las consultas lentas", () => {
    const db = service(prod, "db");
    expect(db).toContain("--max-connections=${MYSQL_MAX_CONNECTIONS:-50}");
    // 16 MB (default) rompía un restore con filas grandes; 100 conexiones + 256 MB
    // de buffer pool rozaban el OOM del contenedor.
    expect(db).toContain("--max-allowed-packet=64M");
    expect(db).toContain("--slow-query-log=1");
    expect(db).toContain("--skip-name-resolve");
  });

  it("wp-cron no solapa corridas ni ignora SIGTERM", () => {
    for (const compose of [prod, dev]) {
      const cron = service(compose, "wp-cron");
      expect(cron).toContain("flock -n /tmp/wp-cron.lock");
      expect(cron).toContain("timeout 300");
      expect(cron).toContain("trap 'exit 0' TERM");
    }
  });

  it("Redis sigue escribiendo si falla el bgsave (aquí los datos son caché)", () => {
    expect(service(prod, "redis")).toContain('"--stop-writes-on-bgsave-error", "no"');
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

describe("docker-compose.yml: desarrollo expuesto solo en loopback", () => {
  /** Puertos publicados de un servicio (`ports:` → comillas). */
  function publishedPorts(block: string): string[] {
    const section = block.match(/\n {4}ports:\n((?: {6}- .*\n)+)/);
    return section ? [...section[1]!.matchAll(/-\s*"([^"]+)"/g)].map((m) => m[1]!) : [];
  }

  // Sin el prefijo, MariaDB (wordpress/wordpress), Redis (sin auth) y WP/Next
  // quedaban alcanzables desde la red local: cualquiera podía FLUSHALL del Redis
  // que guarda revocaciones, rate-limit e idempotencia.
  it.each(["db", "redis", "wordpress", "frontend"])(
    "%s publica solo en 127.0.0.1",
    (name) => {
      const ports = publishedPorts(service(dev, name));

      expect(ports.length, name).toBeGreaterThan(0);
      for (const port of ports) {
        expect(port, `${name}: ${port}`).toMatch(/^127\.0\.0\.1:/);
      }
    },
  );
});

describe("frontend/.dockerignore: ningún .env* entra en el contexto de build", () => {
  const ignore = root("frontend/.dockerignore");

  it("excluye el patrón .env* (no solo .env y .env.local)", () => {
    // Un `frontend/.env.production` —lo natural al preparar un despliegue— se
    // colaba en el contexto y se horneaba en la imagen: Next lo lee en `next
    // build` e inlinearía en el artefacto cualquier NEXT_PUBLIC_*.
    expect(ignore).toMatch(/^\.env\*$/m);
    expect(ignore).not.toMatch(/^\.env$/m);
    expect(ignore).not.toMatch(/^\.env\.local$/m);
  });

  it("deja pasar la plantilla .env.example (no son secretos)", () => {
    expect(ignore).toMatch(/^!\.env\.example$/m);
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
    const wordpress = wordpressConfig(dev);
    expect(wordpress).toContain("define( 'WP_REDIS_PREFIX', 'hwe-wp:' );");
    expect(wordpress).toContain("define( 'WP_REDIS_SELECTIVE_FLUSH', true );");
  });

  it("tiene wp-cron y desactiva el pseudo-cron por petición", () => {
    expect(wordpressConfig(dev)).toContain("define( 'DISABLE_WP_CRON', true );");
    expect(service(dev, "wp-cron")).toContain("wp cron event run --due-now");
  });

  it("deriva el origen público y los orígenes permitidos de FRONTEND_PORT", () => {
    const frontend = service(dev, "frontend");
    const devOrigin = "http://localhost:${FRONTEND_PORT:-3000}";

    // Sin esto, una pila en otro puerto publicaba enlaces de recuperación y
    // verificación al :3000 de otra pila (o a nada) y el navegador no podía
    // escribir en /api/* porque su Origin no coincidía con ALLOWED_ORIGIN.
    expect(frontend).toMatch(
      new RegExp(
        `NEXT_PUBLIC_SITE_URL:\\s+"\\$\\{NEXT_PUBLIC_SITE_URL:-${devOrigin.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\}"`,
      ),
    );
    expect(frontend).toMatch(
      new RegExp(
        `ALLOWED_ORIGIN:\\s+"\\$\\{ALLOWED_ORIGIN:-${devOrigin.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\}"`,
      ),
    );
    const wordpress = wordpressConfig(dev);
    expect(wordpress).toContain(
      `define( 'HEADLESS_FRONTEND_URL', '\${HEADLESS_FRONTEND_URL:-http://localhost:\${FRONTEND_PORT:-3000}}' );`,
    );
    expect(wordpress).toContain(
      `define( 'HEADLESS_ALLOWED_ORIGINS', '\${HEADLESS_ALLOWED_ORIGINS:-http://localhost:\${FRONTEND_PORT:-3000}}' );`,
    );
  });

  it("con el puerto por defecto, los valores efectivos son los de siempre", () => {
    expect(dev).toContain("${FRONTEND_PORT:-3000}");
    expect(root(".env.example")).toMatch(/^FRONTEND_PORT=3000$/m);
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

describe("ci.yml: permisos y coste", () => {
  /** Bloque de un job del workflow. */
  function job(name: string): string {
    const start = ci.indexOf(`\n  ${name}:\n`);
    expect(start, `job ${name}`).toBeGreaterThanOrEqual(0);
    const rest = ci.slice(start + 1);
    const next = rest.slice(1).search(/\n {2}[a-z][\w-]*:\s*\n/);
    return next === -1 ? rest : rest.slice(0, next + 1);
  }

  it("declara permisos mínimos: ningún job necesita escribir en el repositorio", () => {
    expect(ci).toMatch(/\npermissions:\n {2}contents: read\n/);
  });

  it.each(["frontend", "phpunit", "contract", "e2e", "config"])(
    "el job %s tiene timeout (nada de 6 h colgado)",
    (name) => {
      expect(job(name)).toMatch(/timeout-minutes: \d+/);
    },
  );

  it("la suite de tests corre UNA vez (test:coverage ya ejecuta los tests)", () => {
    const frontendJob = job("frontend");

    expect(frontendJob).toContain("npm run test:coverage");
    expect(frontendJob).not.toMatch(/run: npm run test$/m);
  });
});

describe("imágenes: tags reproducibles", () => {
  it("Node y Redis fijan al menos el minor (nada de major flotante)", () => {
    // `node:24-alpine` y `redis:8-alpine` resuelven a versiones distintas según el
    // día: dos builds del mismo commit podían no ser el mismo artefacto.
    expect(dockerfile).toMatch(/FROM node:\d+\.\d+-alpine/);
    for (const compose of [prod, dev]) {
      expect(compose).toMatch(/image: redis:\d+\.\d+-alpine/);
    }
  });

  it("producción no baja imágenes nuevas a mitad de un despliegue", () => {
    for (const name of ["caddy", "db", "wordpress", "redis"]) {
      expect(service(prod, name), name).toContain("pull_policy: if_not_present");
    }
    // `backup` se construye en el servidor (imagen local con mariadb-client y jq):
    // su política es `build`, no una descarga de un registro.
    expect(service(prod, "backup")).toContain("pull_policy: build");
  });
});

describe("core de WordPress: el volumen no se actualiza solo", () => {
  it("los scripts (con update-core.sh) llegan al contenedor wpcli", () => {
    for (const compose of [prod, dev]) {
      expect(service(compose, "wpcli")).toContain("./backend/scripts:/scripts:ro");
    }
    expect(() => root("backend/scripts/update-core.sh")).not.toThrow();
  });

  it("el RUNBOOK documenta el paso que `docker compose pull` NO hace", () => {
    expect(root("docs/RUNBOOK.md")).toContain("update-core.sh --apply");
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

describe("Caddy: HWE_LOGIN_SLUG vacío no secuestra la raíz", () => {
  it("Caddy recibe /wp-login.php cuando la variable está vacía", () => {
    // Caddy solo aplica `{$VAR:defecto}` si la variable NO existe: con el valor
    // vacío, `handle /{$HWE_LOGIN_SLUG:wp-login.php}` se convertía en `handle /`
    // y la portada respondía 302 a /wp-admin/ en vez de servir la tienda.
    const caddyService = service(prod, "caddy");

    expect(caddyService).toContain('HWE_LOGIN_SLUG: "${HWE_LOGIN_SLUG:-wp-login.php}"');
    expect(caddyService).not.toContain('HWE_LOGIN_SLUG: "${HWE_LOGIN_SLUG:-}"');
  });

  it("WordPress sigue tratando la variable vacía como «sin mover»", () => {
    expect(wordpressConfig(prod)).toContain(
      "define( 'HWE_LOGIN_SLUG', '${HWE_LOGIN_SLUG:-}' );",
    );
  });

  it("el Caddyfile mantiene wp-login.php como valor por defecto", () => {
    expect(caddy).toContain("handle /{$HWE_LOGIN_SLUG:wp-login.php} {");
    expect(caddy).toContain(
      "path /wp-admin /wp-admin/* /wp-login.php /{$HWE_LOGIN_SLUG:wp-login.php}",
    );
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

// Varias pilas de Orbite pueden convivir en el mismo daemon de Docker (una instancia junto a
// la pila de desarrollo del framework). Sin variables, TODO cambia de golpe: los nombres de
// contenedor son únicos en el daemon y los puertos 3307/6379 chocan. Los valores por defecto
// son los de siempre, así que una instalación sin configurar nada sigue igual.
describe("varias pilas en la misma máquina", () => {
  const composeDev = root("docker-compose.yml");
  const composeProd = root("docker-compose.prod.yml");
  const env = root(".env.example");

  it("los nombres de contenedor salen de HWE_CONTAINER_PREFIX, con `hwe` por defecto", () => {
    for (const name of [
      "db",
      "wordpress",
      "wpcli",
      "wp_cron",
      "backup",
      "redis",
      "frontend",
    ]) {
      expect(composeDev, name).toContain(
        `container_name: \${HWE_CONTAINER_PREFIX:-hwe}_${name}`,
      );
    }
    for (const name of [
      "caddy",
      "db",
      "wordpress",
      "wpcli",
      "redis",
      "frontend",
      "wp_cron",
      "backup",
      "cache_prune",
    ]) {
      expect(composeProd, name).toContain(
        `container_name: \${HWE_CONTAINER_PREFIX:-hwe}_${name}`,
      );
    }
    // Ningún nombre fijo: lo que impediría levantar dos pilas.
    expect(composeDev).not.toMatch(/container_name: hwe_/);
    expect(composeProd).not.toMatch(/container_name: hwe_/);
  });

  it("los puertos de base de datos y Redis son variables con el valor de siempre", () => {
    expect(dev).toContain('"127.0.0.1:${DB_PORT:-3307}:3306"');
    expect(dev).toContain('"127.0.0.1:${REDIS_PORT:-6379}:6379"');
  });

  it(".env.example documenta las variables y el proyecto de Compose por pila", () => {
    expect(env).toMatch(/^DB_PORT=3307$/m);
    expect(env).toMatch(/^REDIS_PORT=6379$/m);
    expect(env).toMatch(/^HWE_CONTAINER_PREFIX=hwe$/m);
    expect(env).toMatch(/COMPOSE_PROJECT_NAME/);
  });
});
