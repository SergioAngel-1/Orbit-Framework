// ============================================================================
//  Aserciones sobre la REDACCIÓN del starter, que solo valen en el framework.
//
//  `frontend/src/i18n/messages/**` es de la instancia (`tools/boundaries.json`): puede
//  cambiar cada frase. Un test del núcleo que compara con la frase literal del starter
//  obligaba a la instancia a editarlo, y `upgrade-check` lo marcaba como deriva. Lo que
//  ese test comprueba (qué clave elige el núcleo, qué datos interpola, a qué cae) ya lo
//  prueba el CI del framework con sus catálogos.
//
//  Mismo interruptor que el resto del CI (`gitignore.test.ts`, playwright.config.ts):
//  con `HWE_INSTANCE=true` estas aserciones se omiten; sin él (el framework) se ejecutan.
// ============================================================================

import { expect, it } from "vitest";

/** ¿Los catálogos son los del starter (repositorio del framework)? */
export const STARTER_COPY = process.env.HWE_INSTANCE !== "true";

/** `it` para un test cuyo único objeto es la redacción del starter: se omite en una instancia. */
export const itStarterCopy = STARTER_COPY ? it : it.skip;

/**
 * Valor esperado de un texto compuesto dentro de un objeto más grande: la frase del starter en
 * el framework y cualquier cadena en una instancia (la forma del objeto se sigue comprobando).
 */
export function starterCopy(text: string): unknown {
  return STARTER_COPY ? text : expect.any(String);
}
