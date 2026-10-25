import es from "@/i18n/messages/es.json";
import en from "@/i18n/messages/en.json";

// ============================================================================
//  Catálogos de mensajes del frontend, en un solo sitio.
//
//  `messages/**` es de la INSTANCIA (el framework solo trae `es` y `en` de serie), así
//  que el núcleo no puede dar por hecho qué catálogos existen: este módulo —el ÚNICO
//  que importa los JSON— los registra y cualquier idioma sin catálogo cae a `es`, que
//  es el idioma principal de la plantilla.
//
//  Una instancia monolingüe borra `en.json` y se queda con `es`; no hace falta tocar
//  este archivo (el bundler y los tests resuelven el catálogo ausente a `es`, ver
//  `tsconfig.json`, `next.config.mjs` y `vitest.config.ts`). Si añade un idioma, añade
//  aquí su import junto al JSON y declara el idioma en `src/i18n/routing.ts`.
//
//  Sin `import "server-only"`: lo usan también componentes de cliente
//  (`lib/memberships/format.ts`). Los JSON se empaquetan, no se leen en tiempo de
//  ejecución.
// ============================================================================

/** Forma del catálogo (la de `es.json`, el idioma principal). */
export type Catalog = typeof es;

const CATALOGS: Record<string, Catalog> = { es, en };

/** Código base de un locale (`pt-BR` → `pt`), que es la clave del catálogo. */
function baseLocale(locale: string): string {
  return locale.toLowerCase().split("-")[0] || "es";
}

/**
 * Catálogo del idioma pedido. Un idioma sin catálogo (o un valor raro) devuelve el
 * español: la UI nunca se queda sin textos.
 */
export function catalogFor(locale: string): Catalog {
  return CATALOGS[baseLocale(locale)] ?? es;
}

/**
 * ¿El idioma tiene catálogo PROPIO (distinto del español)? En una instancia monolingüe,
 * `en` resuelve al mismo módulo que `es` y esta función devuelve `false`: los tests que
 * comprueban traducciones al inglés se saltan en vez de comparar un catálogo consigo mismo.
 */
export function hasOwnCatalog(locale: string): boolean {
  const catalog = CATALOGS[baseLocale(locale)];
  return catalog !== undefined && catalog !== es;
}
