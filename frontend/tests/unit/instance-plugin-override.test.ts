import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Los plugins de instancia llegan a los tres contenedores de WordPress.
//
//  En desarrollo, `docker-compose.yml` monta cada módulo del framework (`hwe-*`)
//  uno a uno y deja los plugins de terceros en el volumen `wordpress_content`.
//  Un plugin de instancia (`hwe-instance-<slug>/`) no está en ninguno de los dos,
//  así que sin un override WordPress no lo ve. La vía que la instancia puede usar
//  sin editar el núcleo es `docker-compose.override.yml` (Compose lo carga solo);
//  el ejemplo versionado es `docker-compose.override.example.yml`.
//
//  El test fija las dos condiciones que evitan romper la pila: el plugin se monta
//  en `wordpress`, `wpcli` y `wp-cron`, y el destino es el mismo directorio que usan
//  los módulos del núcleo (nunca la carpeta `plugins/` entera, que taparía
//  WooCommerce y los plugins de terceros del volumen).
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

const override = read("docker-compose.override.example.yml");
const dev = read("docker-compose.yml");

describe("docker-compose.override.example.yml: plugin de instancia", () => {
  it("monta el plugin de ejemplo en wordpress, wpcli y wp-cron", () => {
    expect(override).toContain(
      "./backend/wp-content/plugins/hwe-instance-example:/var/www/html/wp-content/plugins/hwe-instance-example",
    );

    for (const name of ["wordpress", "wpcli", "wp-cron"]) {
      expect(service(override, name), `${name} no hereda el plugin`).toMatch(
        /volumes:\s*\*instance-plugins/,
      );
    }
  });

  it("usa la misma ruta de destino que los módulos del núcleo", () => {
    const coreMount = dev.match(
      /\.\/backend\/wp-content\/plugins\/hwe-memberships:(\/var\/www\/html\/wp-content\/plugins\/hwe-memberships)/,
    );
    expect(coreMount, "mount de un módulo del núcleo").not.toBeNull();

    // Un plugin de instancia ocupa el mismo directorio de destino que `hwe-memberships`:
    // mismo padre, su propio slug.
    const coreDestination = coreMount![1]!;
    const expected = coreDestination.replace(
      /hwe-memberships$/,
      "hwe-instance-example",
    );
    expect(override).toContain(`hwe-instance-example:${expected}`);
  });

  it("NO monta la carpeta plugins entera (taparía WooCommerce y terceros)", () => {
    expect(override).not.toMatch(/\.\/backend\/wp-content\/plugins:\//);
    expect(override).not.toMatch(/\.\/backend\/wp-content:\//);
  });
});
