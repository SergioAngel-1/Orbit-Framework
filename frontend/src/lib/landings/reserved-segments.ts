import { routing } from "@/i18n/routing";
import { instanceRegistry } from "@/instance/registry";

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
//  el fallo silencioso que esto evita.
// ============================================================================

/** Rutas de un solo tramo del starter (`app/[locale]/*`) y el área de las landings. */
export const CORE_ROOT_SEGMENTS: readonly string[] = [
  "about",
  "account",
  "blog",
  "cart",
  "categories",
  "checkout",
  "contact",
  "forgot-password",
  "learn",
  "legal",
  "llms.txt",
  "login",
  "products",
  "register",
  "reset-password",
  "verify-email",
];

/** El tramo del área educativa: `/learn/<tramo>`. */
export const AREA_SEGMENT = "learn";

/**
 * Tramos reservados: los del núcleo, los códigos de idioma, `api` y los de la
 * instancia.
 */
export function reservedRootSegments(
  extra: readonly string[] = instanceRegistry.landings?.reservedRootSegments ?? [],
): ReadonlySet<string> {
  return new Set<string>([...CORE_ROOT_SEGMENTS, ...routing.locales, "api", ...extra]);
}
