// ============================================================================
//  Tramos de la RAÍZ que ya ocupa el starter (`app/[locale]/*`).
//
//  Vive aparte de `reserved-segments.ts` para que lo pueda importar también la validación
//  de rutas públicas (`i18n/routing.ts`) sin crear un ciclo: `reserved-segments.ts` importa
//  `routing.ts` (para los idiomas), así que no puede ser su dependencia.
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
