// ============================================================================
//  Punto de carga del SDK de Sentry para el cliente.
//
//  Vive en su propio módulo a propósito: `instrumentation-client.ts` importa
//  `client-sentry.ts` (ligero) y este cargador se pide con `import()` cuando el
//  navegador está inactivo. El SDK entra por un import ESTÁTICO aquí, no por un
//  segundo import dinámico: Turbopack fundía el dinámico anidado con el chunk de
//  arranque y volvía a descargar Sentry en todas las páginas.
// ============================================================================

import * as Sentry from "@sentry/nextjs";
import type { SentrySdk } from "./client-sentry";

/** Devuelve el SDK ya empaquetado en este chunk, que solo se pide con DSN. */
export function loadSentrySdk(): Promise<SentrySdk> {
  return Promise.resolve(Sentry);
}
