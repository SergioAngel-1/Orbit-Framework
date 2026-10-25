import { defineRouting } from "next-intl/routing";

// ============================================================================
//  Configuración de idiomas que ven los TESTS del núcleo.
//
//  La suite del framework no puede depender de los idiomas de la instancia: una tienda
//  monolingüe (`locales: ["es"]`, sin `messages/en.json`) debe poder correr los mismos
//  tests que el framework. `vitest.config.ts` aliasa `@/i18n/routing` a este módulo, así
//  que los tests que ejercitan el comportamiento multi-idioma encuentran `es` y `en`
//  SIEMPRE, sin tocarlos.
//
//  Solo afecta a Vitest (TypeScript y `next build` usan el `routing.ts` real). Un test
//  que quiera verificar la configuración REAL de la instancia importa el archivo por
//  ruta relativa (`import { routing } from "../../i18n/routing"`), que no pasa por el
//  alias.
// ============================================================================

export const routing = defineRouting({
  locales: ["es", "en"],
  defaultLocale: "es",
  localePrefix: "as-needed",
});
