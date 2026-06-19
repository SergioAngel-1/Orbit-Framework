// ============================================================================
//  Identificador de correlación de petición (request-id).
//
//  Edge-safe (sin `server-only` ni imports de Node): lo usa el middleware (edge)
//  y también los Route Handlers (node). Permite seguir UNA petición a través de
//  Caddy → Next → WordPress en los logs.
//
//  Caddy genera y reenvía `X-Request-Id` ({http.request.uuid}); si por algún
//  motivo no llega, lo generamos aquí. Siempre se propaga y se devuelve al
//  cliente en la respuesta.
// ============================================================================

export const REQUEST_ID_HEADER = "x-request-id";

/** Genera un id razonablemente único (UUID si está disponible). */
function generateId(): string {
  try {
    // `crypto.randomUUID` existe en el runtime edge y en Node moderno.
    return globalThis.crypto.randomUUID();
  } catch {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  }
}

/** Devuelve el request-id entrante si es válido, o uno nuevo. */
export function getOrCreateRequestId(headers: Headers): string {
  const incoming = headers.get(REQUEST_ID_HEADER);
  if (incoming && incoming.length > 0 && incoming.length <= 200) {
    return incoming;
  }
  return generateId();
}

/** Devuelve el request-id presente en las cabeceras, o null. */
export function getRequestId(headers: Headers): string | null {
  const v = headers.get(REQUEST_ID_HEADER);
  return v && v.length > 0 ? v : null;
}
