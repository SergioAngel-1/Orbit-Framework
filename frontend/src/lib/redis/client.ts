import "server-only";
import Redis from "ioredis";

// ============================================================================
//  Cliente Redis singleton (server-only).
//
//  Se reutiliza entre invocaciones y sobrevive al hot-reload de desarrollo
//  guardándose en `globalThis`. Si REDIS_URL no está definido o la conexión
//  falla, devolvemos `null` y los consumidores degradan (fail-open en
//  rate-limit; sin idempotencia) en vez de tumbar la petición.
// ============================================================================

const globalForRedis = globalThis as unknown as {
  __hweRedis?: Redis | null;
};

export function getRedis(): Redis | null {
  if (globalForRedis.__hweRedis !== undefined) {
    return globalForRedis.__hweRedis;
  }

  const url = process.env.REDIS_URL;
  if (!url) {
    globalForRedis.__hweRedis = null;
    return null;
  }

  try {
    const client = new Redis(url, {
      maxRetriesPerRequest: 1,
      enableOfflineQueue: false, // comandos fallan rápido si Redis no responde
      lazyConnect: false,
    });
    // Evita que un error de conexión emita excepciones no capturadas.
    client.on("error", () => {
      /* el degradado se gestiona en cada comando con try/catch */
    });
    globalForRedis.__hweRedis = client;
    return client;
  } catch {
    globalForRedis.__hweRedis = null;
    return null;
  }
}
