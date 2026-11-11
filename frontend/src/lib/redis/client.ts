import "server-only";
import Redis, { Command } from "ioredis";
import { logReadFailure } from "@/lib/observability/read-failure";

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

/**
 * Plazo máximo que el ARRANQUE de la conexión puede retener los comandos.
 *
 * `getRedis()` crea el cliente en el primer uso y, con `enableOfflineQueue:
 * false`, ioredis rechaza cualquier comando mientras el socket no está listo
 * («Stream isn't writeable and enableOfflineQueue options is false»). Sin esta
 * espera, la primera petición tras arrancar tomaba un Redis sano por caído y
 * degradaba (rate-limit sin límite, anti-replay sin deduplicar, tokens sin
 * comprobar…).
 *
 * El plazo es único por cliente, no por comando: se cierra al llegar `ready`,
 * al primer fallo de conexión (rechazada, cortada) o al vencer, y a partir de
 * ahí los comandos fallan rápido como siempre. Con Redis caído desde el
 * arranque solo paga la espera la ráfaga inicial, y solo si el servidor ni
 * responde ni rechaza.
 */
export const REDIS_STARTUP_GRACE_MS = 1_500;

/**
 * Retiene los comandos de usuario mientras la primera conexión se establece.
 *
 * Se engancha en `sendCommand`, el punto por el que pasan todos los comandos
 * (también los de un pipeline), así que cubre a todos los consumidores de
 * `getRedis()` sin tocarlos. El handshake de ioredis (`info`, `select`,
 * `auth`…) pasa sin esperar: de él depende que llegue `ready`.
 */
function holdCommandsUntilFirstConnection(client: Redis): void {
  const send = client.sendCommand.bind(client);
  let settled = false;

  const startup = new Promise<void>((resolve) => {
    const finish = () => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      for (const event of ["ready", "close", "end"]) client.off(event, finish);
      resolve();
    };
    const timer = setTimeout(finish, REDIS_STARTUP_GRACE_MS);
    timer.unref?.(); // no mantiene vivo el proceso si se está apagando
    for (const event of ["ready", "close", "end"]) client.once(event, finish);
  });

  client.sendCommand = (
    command: Command,
    stream?: Parameters<Redis["sendCommand"]>[1],
  ) => {
    const connecting = client.status === "connecting" || client.status === "connect";
    if (
      settled ||
      !connecting ||
      Command.checkFlag("HANDSHAKE_COMMANDS", command.name)
    ) {
      return send(command, stream);
    }
    // Si al terminar la espera no hay conexión, `send` lo rechaza como siempre.
    // (sin devolver `send`: su rechazo ya llega al llamador por `command.promise`)
    void startup.then(() => {
      send(command, stream);
    });
    return command.promise;
  };
}

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
    holdCommandsUntilFirstConnection(client);
    globalForRedis.__hweRedis = client;
    return client;
  } catch (error) {
    // Sin cliente, rate-limit, idempotencia y anti-replay degradan: que se sepa.
    logReadFailure("redis.client_init_failed", error, {
      message: "No se pudo crear el cliente de Redis: la degradación queda activa",
    });
    globalForRedis.__hweRedis = null;
    return null;
  }
}
