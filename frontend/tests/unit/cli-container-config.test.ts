import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Los contenedores de WP-CLI comparten las constantes de WordPress.
//
//  `wp-config-docker.php` evalúa `WORDPRESS_CONFIG_EXTRA` del ENTORNO del proceso
//  en cada carga: no la hornea en wp-config.php. `wpcli` y `wp-cron` leen el mismo
//  wp-config.php del volumen `wordpress_core`, pero no recibían la variable, así que
//  corrían sin `HWE_REVALIDATION_SECRET` (cada `wp hwe setup` avisaba de que no
//  revalidaba y los cambios del Control Center tardaban hasta 5 minutos) ni
//  `WP_REDIS_*`.
//
//  El bloque vive una sola vez en un campo `x-…` con ancla YAML; los tres servicios
//  apuntan a él. Un valor inline distinto en cualquiera de ellos vuelve a abrir el
//  hueco, y este test lo detecta.
// ============================================================================

const read = (relative: string) =>
  readFileSync(fileURLToPath(new URL(`../../../${relative}`, import.meta.url)), "utf8");

/** Bloque `services.<name>` del compose (hasta el siguiente servicio de primer nivel). */
function service(compose: string, name: string): string {
  const start = compose.indexOf(`\n  ${name}:`);
  expect(start, `servicio ${name}`).toBeGreaterThan(-1);
  const rest = compose.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][\w-]*:\s*\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

/** Bloque del ancla `x-wordpress-config-extra` (hasta `services:`). */
function wordpressConfigAnchor(compose: string): string {
  const start = compose.indexOf("x-wordpress-config-extra: &wordpress-config-extra |");
  expect(start, "ancla x-wordpress-config-extra").toBeGreaterThan(-1);
  const rest = compose.slice(start);
  const end = rest.indexOf("\nservices:");
  return end === -1 ? rest : rest.slice(0, end);
}

const CLI_SERVICES = ["wordpress", "wpcli", "wp-cron"] as const;
const COMPOSES = [
  ["docker-compose.yml", read("docker-compose.yml")],
  ["docker-compose.prod.yml", read("docker-compose.prod.yml")],
] as const;

describe.each(COMPOSES)(
  "%s: constantes de WordPress en los contenedores de CLI",
  (_, compose) => {
    it("define el bloque UNA vez, con el secreto de revalidación", () => {
      expect(compose).toMatch(
        /^x-wordpress-config-extra: &wordpress-config-extra \|$/m,
      );

      const anchor = wordpressConfigAnchor(compose);
      expect(anchor).toContain("HWE_REVALIDATION_SECRET");
      expect(anchor).toContain("WP_REDIS_HOST");
    });

    it("define la URL interna del frontend para llamadas servidor a servidor", () => {
      // `HEADLESS_FRONTEND_URL` es la URL pública (navegador, CORS, correos). Desde dentro
      // de un contenedor `localhost` es el propio contenedor, así que la revalidación ISR y la
      // medición de consentimiento necesitan el nombre del servicio en la red de Compose.
      expect(
        wordpressConfigAnchor(compose),
        "sin HEADLESS_FRONTEND_INTERNAL_URL, WordPress se llama a sí mismo",
      ).toMatch(
        /define\(\s*'HEADLESS_FRONTEND_INTERNAL_URL',\s*'\$\{HEADLESS_FRONTEND_INTERNAL_URL:-http:\/\/frontend:3000\}'/,
      );
    });

    it("wpcli recibe la URL interna en el entorno (la usan los webhooks de setup.sh)", () => {
      expect(service(compose, "wpcli")).toMatch(
        /HEADLESS_FRONTEND_INTERNAL_URL:\s*"\$\{HEADLESS_FRONTEND_INTERNAL_URL:-http:\/\/frontend:3000\}"/,
      );
    });

    it("wordpress, wpcli y wp-cron usan ese mismo ancla", () => {
      for (const name of CLI_SERVICES) {
        expect(
          service(compose, name),
          `${name} no comparte WORDPRESS_CONFIG_EXTRA`,
        ).toMatch(/WORDPRESS_CONFIG_EXTRA:\s*\*wordpress-config-extra/);
      }
    });

    it("ninguno duplica el bloque a mano", () => {
      for (const name of CLI_SERVICES) {
        expect(service(compose, name), `${name} tiene un bloque inline`).not.toMatch(
          /WORDPRESS_CONFIG_EXTRA:\s*\|/,
        );
      }
    });
  },
);
