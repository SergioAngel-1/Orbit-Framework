import "server-only";
import { AsyncLocalStorage } from "node:async_hooks";

// ============================================================================
//  Contexto de petición (AsyncLocalStorage) para PROPAGAR el request-id a las
//  llamadas server-to-server (WordPress/WooCommerce) sin pasar el id a mano por
//  cada función.
//
//  Uso en un Route Handler (Node):
//    return runWithRequestId(reqId, async () => { ...lógica... });
//  Los clientes (`graphql-client`, `client`, `store-client`) leen
//  `currentRequestId()` y añaden la cabecera `X-Request-Id` automáticamente, de
//  modo que el log de WordPress puede correlacionarse con el del frontend.
// ============================================================================

interface RequestStore {
  requestId: string;
}

const storage = new AsyncLocalStorage<RequestStore>();

/** Ejecuta `fn` con el request-id disponible en el contexto asíncrono. */
export function runWithRequestId<T>(requestId: string, fn: () => T): T {
  return storage.run({ requestId }, fn);
}

/** Devuelve el request-id del contexto actual, o null si no se estableció. */
export function currentRequestId(): string | null {
  return storage.getStore()?.requestId ?? null;
}
