// ============================================================================
//  Endpoint GraphQL de pega para el CI.
//
//  Responde `{"data":{}}` a cualquier petición. No imita el esquema: el objetivo
//  NO es construir con datos realistas, sino que el build se complete con el CMS
//  VACÍO y así ejercitar las ramas de fallback y null-handling de cada página.
//
//  Por qué hace falta: `next build` prerenderiza, y varias páginas ESTÁTICAS
//  bajo `[locale]` se renderizan SIEMPRE —no dependen de `generateStaticParams`,
//  que sí puede devolver lista vacía—, así que consultan GraphQL durante el
//  build. Un runner de GitHub no tiene WordPress, y `lib/catalog/products.ts`
//  propaga los errores de red A PROPÓSITO (convertirlos en 404 escondería una
//  caída del CMS detrás de "producto no encontrado"). Sin este stub el build
//  muere con ECONNREFUSED.
//
//  Esto es EXCLUSIVO del CI. El build de producción corre contra el WordPress
//  real; allí un fallo de red debe seguir rompiendo el build, porque ese build
//  sí hornea las páginas que se van a servir.
//
//  ── La ÚNICA excepción al "CMS vacío": la config pública ───────────────────
//  `/wp-json/hwe/v1/config` devuelve la paleta de `site-config.ci.json`. No es
//  un capricho: `fetchRemoteConfig` mezcla esa respuesta sobre `CONFIG_DEFAULTS`,
//  y con `{"data":{}}` el CI nunca ejercitaría la rama de ÉXITO de la config
//  remota (la que corre en producción), solo la de respaldo. El fixture
//  reproduce la paleta por defecto del framework; `tests/unit/ci-stub.test.ts`
//  comprueba que no diverge de `CONFIG_DEFAULTS`.
//
//  Solo `design.colors`: `typography` traería los enlaces a Google Fonts y con
//  ellos una dependencia de red dentro de cada spec.
//
//  Uso:  node .github/scripts/stub-graphql.mjs   (puerto: STUB_GRAPHQL_PORT, 8080)
//        STUB_SITE_CONFIG_EXTRA='{"payments":{"cash_enabled":true}}' añade secciones
// ============================================================================
import { createServer } from "node:http";
import { readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const CONFIG_PATH = "/wp-json/hwe/v1/config";

/**
 * Config pública que sirve el stub: los colores del fixture y, opcionalmente,
 * las secciones de `extra` (`STUB_SITE_CONFIG_EXTRA`, JSON). Sirve para que un
 * e2e ejercite algo desactivado por defecto —varios medios de pago, un
 * módulo— sin tocar el fixture. `extra` nunca sustituye la paleta.
 */
export function buildSiteConfig(fixture, extra = {}) {
  return { ...extra, design: { colors: fixture.design.colors } };
}

/** Servidor HTTP del stub (sin escuchar: quien lo usa elige el puerto). */
export function createStubServer(siteConfig) {
  return createServer((req, res) => {
    // Se drena el cuerpo aunque no se use: sin consumirlo, el cliente puede
    // quedarse esperando a que se libere el socket.
    req.resume();
    req.on("end", () => {
      const path = new URL(req.url ?? "/", "http://127.0.0.1").pathname;
      const body = path === CONFIG_PATH ? siteConfig : { data: {} };
      res.writeHead(200, { "content-type": "application/json" });
      res.end(JSON.stringify(body));
    });
  });
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const port = Number(process.env.STUB_GRAPHQL_PORT ?? 8080);
  const fixture = JSON.parse(
    readFileSync(new URL("./site-config.ci.json", import.meta.url), "utf8"),
  );
  const extra = process.env.STUB_SITE_CONFIG_EXTRA
    ? JSON.parse(process.env.STUB_SITE_CONFIG_EXTRA)
    : {};
  createStubServer(buildSiteConfig(fixture, extra)).listen(port, "127.0.0.1", () => {
    console.log(`stub GraphQL escuchando en http://127.0.0.1:${port}`);
  });
}
