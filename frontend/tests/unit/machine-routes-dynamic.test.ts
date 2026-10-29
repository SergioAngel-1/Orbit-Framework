import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

// ============================================================================
//  Rutas de máquina SEO: dinámicas, nunca ISR.
//
//  La imagen de producción se construye SIN WordPress: una ruta ISR se
//  prerenderiza ahí con la config degradada y sirve ese resultado (sitemap
//  vacío, `llms.txt` en 404, robots sin guarda de staging) hasta su primera
//  revalidación tras el despliegue. Sus datos ya van por la Data Cache y el CDN
//  las cachea con `s-maxage`.
// ============================================================================

const APP = join(__dirname, "..", "..", "src", "app");
const ROUTES = [
  "robots.txt/route.ts",
  "sitemap.xml/route.ts",
  "sitemap/[segmento]/route.ts",
  "llms.txt/route.ts",
  "[locale]/llms.txt/route.ts",
  "indexnow.txt/route.ts",
];

describe("rutas de máquina SEO", () => {
  it.each(ROUTES)("%s es force-dynamic y sin prerender", (route) => {
    const source = readFileSync(join(APP, route), "utf8");
    expect(source).toMatch(/export const dynamic = "force-dynamic"/);
    expect(source).not.toMatch(/export const revalidate\b/);
    expect(source).not.toMatch(/generateStaticParams/);
  });
});
