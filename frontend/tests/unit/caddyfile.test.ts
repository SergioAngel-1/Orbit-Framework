import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Caddyfile: las rutas de comercio de WordPress no se exponen al navegador.
//
//  Caddy proxyaba `/wp-json/*` entero, así que `/wp-json/wc/store/v1/cart`
//  respondía 200 con el carrito y los métodos de pago, y `/wp-json/wc/v3/*`
//  quedaba a un paso de las claves de consumidor: se podía operar carrito y
//  checkout saltándose el Origin, el CSRF, el rate-limit y la sesión del BFF. El
//  navegador debe pasar SIEMPRE por el BFF (que habla con WordPress por la red
//  interna de Docker, sin pasar por Caddy).
//
//  En Caddy gana el PRIMER `handle` que casa, así que los dos bloques de bloqueo
//  deben ir ANTES del genérico `/wp-json/*`. Un reordenamiento inocente los
//  dejaría inertes sin que nada falle: de ahí este test.
// ============================================================================

const caddyfile = readFileSync(
  fileURLToPath(new URL("../../../Caddyfile", import.meta.url)),
  "utf8",
);

/** Texto sin comentarios (las líneas `# …` mencionan estas rutas y no cuentan). */
const code = caddyfile
  .split("\n")
  .filter((line) => !line.trim().startsWith("#"))
  .join("\n");

/** Cuerpo del bloque `handle <matcher> { … }`, o undefined si no existe. */
function handleBlock(matcher: string): string | undefined {
  const start = code.indexOf(`handle ${matcher} {`);
  if (start === -1) return undefined;
  return code.slice(start, code.indexOf("}", start) + 1);
}

describe("Caddyfile: wc/store y wc/v3 cerrados", () => {
  it.each(["/wp-json/wc/store/*", "/wp-json/wc/v3/*"])(
    "%s responde 404 y no se proxya",
    (matcher) => {
      const block = handleBlock(matcher);

      expect(block, `falta el bloque handle ${matcher}`).toBeDefined();
      expect(block).toContain('respond "Not Found" 404');
      expect(block).not.toContain("reverse_proxy");
    },
  );

  it.each(["/wp-json/wc/store/*", "/wp-json/wc/v3/*"])(
    "%s va ANTES del genérico /wp-json/* (en Caddy gana el primero que casa)",
    (matcher) => {
      const blocked = code.indexOf(`handle ${matcher} {`);
      const generic = code.indexOf("handle /wp-json/* {");

      expect(blocked).toBeGreaterThanOrEqual(0);
      expect(generic).toBeGreaterThanOrEqual(0);
      expect(blocked).toBeLessThan(generic);
    },
  );

  it("el resto de /wp-json/* sigue abierto: /wp-admin lo necesita (editor de bloques)", () => {
    const generic = handleBlock("/wp-json/*");

    expect(generic).toBeDefined();
    expect(generic).toContain("reverse_proxy wordpress:80");
  });

  it("no hay ningún otro camino hacia las rutas de comercio", () => {
    // Ningún handle proxya `/wp-json/wc*` ni `/?rest_route=` (los parámetros de
    // consulta los cierra hwe-bff-only en WordPress; ver tarea 18).
    const proxiedWc = code.match(
      /handle\s+\/wp-json\/wc[^\s]*\s*\{[^}]*reverse_proxy/g,
    );

    expect(proxiedWc).toBeNull();
  });
});

// ============================================================================
//  Cabeceras de seguridad en las rutas que Caddy sirve DIRECTO desde WordPress.
//
//  /wp-admin/*, /wp-login.php, /wp-json/* y /wp-content/* no pasan por Next.js, así
//  que nunca recibían las cabeceras que `next.config.mjs` manda para el frontend.
//  HSTS, X-Content-Type-Options y Referrer-Policy van a nivel de sitio con el MISMO
//  valor que Next (duplicarlas es inocuo). X-Frame-Options va por ruta y como
//  SAMEORIGIN: el frontend usa DENY para sí mismo y WordPress no debe heredar ese
//  valor más estricto (rompería los iframes propios de wp-admin, p. ej. la vista
//  previa del Customizer).
// ============================================================================

const nextConfig = readFileSync(
  fileURLToPath(new URL("../../next.config.mjs", import.meta.url)),
  "utf8",
);

/** Parte del sitio anterior al primer `handle`: las directivas globales a toda respuesta. */
const siteLevel = code.slice(0, code.indexOf("handle "));

/** Valor que `next.config.mjs` da a una cabecera. */
function nextHeaderValue(key: string): string {
  const match = nextConfig.match(
    new RegExp(`key:\\s*"${key}",\\s*value:\\s*"([^"]+)"`),
  );
  expect(match, `${key} en next.config.mjs`).not.toBeNull();
  return match![1]!;
}

describe("Caddyfile: cabeceras de seguridad base", () => {
  it.each(["Strict-Transport-Security", "X-Content-Type-Options", "Referrer-Policy"])(
    "%s va a nivel de sitio y con el mismo valor que next.config.mjs",
    (name) => {
      const value = nextHeaderValue(name);

      expect(siteLevel).toContain(`header >${name} "${value}"`);
    },
  );

  it("las cabeceras con `>` se aplican después del proxy, así que pisan las del upstream", () => {
    for (const name of [
      "Strict-Transport-Security",
      "X-Content-Type-Options",
      "Referrer-Policy",
    ]) {
      expect(siteLevel).toMatch(new RegExp(`header\\s+>${name}\\s`));
    }
  });

  it("X-Frame-Options NO es global: el frontend usa DENY y no debe pisarse", () => {
    expect(siteLevel).not.toContain("X-Frame-Options");
  });
});

describe("Caddyfile: X-Frame-Options SAMEORIGIN en lo que sirve WordPress", () => {
  it.each(["/wp-admin/*", "/wp-login.php", "/wp-json/*", "/wp-content/*"])(
    "%s manda X-Frame-Options SAMEORIGIN y sigue proxyando",
    (matcher) => {
      const block = handleBlock(matcher);

      expect(block, `falta el bloque handle ${matcher}`).toBeDefined();
      expect(block).toContain('header >X-Frame-Options "SAMEORIGIN"');
      expect(block).toContain("reverse_proxy wordpress:80");
    },
  );

  it("el frontend (catch-all) no define X-Frame-Options: lo manda Next con DENY", () => {
    const frontend = code.slice(
      code.indexOf("reverse_proxy frontend:3000") - 40,
      code.indexOf("reverse_proxy frontend:3000") + 40,
    );

    expect(frontend).not.toContain("X-Frame-Options");
    expect(nextHeaderValue("X-Frame-Options")).toBe("DENY");
  });
});
