import { routing } from "@/i18n/routing";
import { instanceRegistry } from "@/instance/registry";
import { publicRootSegments } from "@/lib/instance/public-paths";
import { AREA_SEGMENT, CORE_ROOT_SEGMENTS } from "./core-segments";

export { AREA_SEGMENT, CORE_ROOT_SEGMENTS };

// ============================================================================
//  Tramos de la RAÍZ que una landing no puede ocupar.
//
//  Next da precedencia al segmento estático sobre `[landingPath]`: una landing
//  en `/products` no rompería la tienda, simplemente sería inalcanzable, en
//  silencio y sin ningún error. Por eso una landing de raíz sobre un tramo
//  reservado vuelve al área educativa (ver `withConfiguredUrl`).
//
//  La lista son las rutas del starter bajo `app/[locale]/` más los códigos de
//  idioma (`/en` es el prefijo) y `api` (`app/api/*`, fuera de `[locale]`). Una
//  instancia que añada rutas propias de un solo tramo las declara en
//  `instanceRegistry.landings.reservedRootSegments`.
//
//  `tests/unit/landings-reserved-segments.test.ts` recorre `app/[locale]/` y
//  falla si una carpeta de ruta no está aquí: añadir una ruta sin reservarla es
//  el fallo silencioso que esto evita. La lista vive en `core-segments.ts` (la comparte la
//  validación de rutas públicas, que no puede depender de este módulo: aquí se importa
//  `routing.ts`).
// ============================================================================

/**
 * Tramos reservados: los del núcleo, los códigos de idioma, `api`, los de la instancia y los
 * tramos PÚBLICOS de las rutas remapeadas (una landing en `/productos` también sería
 * inalcanzable detrás de la ruta pública de la tienda).
 */
export function reservedRootSegments(
  extra: readonly string[] = instanceRegistry.landings?.reservedRootSegments ?? [],
): ReadonlySet<string> {
  return new Set<string>([
    ...CORE_ROOT_SEGMENTS,
    ...routing.locales,
    "api",
    ...publicRootSegments(),
    ...extra,
  ]);
}
