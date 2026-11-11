import net from "node:net";

// ============================================================================
//  Servidor RESP mínimo en proceso, para probar el cliente ioredis REAL sin
//  Redis ni Docker.
//
//  Contesta lo justo para el handshake de ioredis (INFO, CLIENT, SELECT) y para
//  los comandos que usan los tests (INCR, EXPIRE, TTL, SET con NX, GET, DEL,
//  PING). Cualquier otro comando recibe +OK.
//
//  `mute: true` acepta conexiones pero no contesta nunca: ioredis se queda en
//  `connect`, esperando la respuesta del chequeo de disponibilidad (un Redis
//  que no responde).
// ============================================================================

export interface RespServer {
  url: string;
  /** Comandos recibidos, en mayúsculas y con sus argumentos. */
  commands: string[][];
  /** Cierra el servidor y corta las conexiones abiertas (una caída). */
  close(): Promise<void>;
}

/** Extrae los comandos completos (arrays de bulk strings) de un búfer. */
function drain(pending: Buffer): { commands: string[][]; rest: Buffer } {
  const commands: string[][] = [];
  let offset = 0;
  for (;;) {
    let cursor = offset;
    const lineEnd = (from: number) => pending.indexOf("\r\n", from);
    let end = lineEnd(cursor);
    if (end < 0 || pending[cursor] !== 0x2a /* * */) break;
    const count = Number(pending.toString("utf8", cursor + 1, end));
    cursor = end + 2;
    const args: string[] = [];
    let complete = true;
    for (let i = 0; i < count; i++) {
      end = lineEnd(cursor);
      if (end < 0) {
        complete = false;
        break;
      }
      const size = Number(pending.toString("utf8", cursor + 1, end));
      const start = end + 2;
      if (pending.length < start + size + 2) {
        complete = false;
        break;
      }
      args.push(pending.toString("utf8", start, start + size));
      cursor = start + size + 2;
    }
    if (!complete) break;
    commands.push(args);
    offset = cursor;
  }
  return { commands, rest: pending.subarray(offset) };
}

export async function startRespServer(
  options: { mute?: boolean } = {},
): Promise<RespServer> {
  const store = new Map<string, string>();
  const commands: string[][] = [];
  const sockets = new Set<net.Socket>();

  const reply = ([name, key, value, ...rest]: string[]): string => {
    switch (name.toUpperCase()) {
      case "INFO":
        return "$25\r\n# Server\r\nredis_version:7\r\n";
      case "PING":
        return "+PONG\r\n";
      case "INCR": {
        const next = Number(store.get(key) ?? 0) + 1;
        store.set(key, String(next));
        return `:${next}\r\n`;
      }
      case "EXPIRE":
        return store.has(key) ? ":1\r\n" : ":0\r\n";
      case "TTL":
        return store.has(key) ? ":60\r\n" : ":-2\r\n";
      case "SET": {
        const nx = rest.some((flag) => flag.toUpperCase() === "NX");
        if (nx && store.has(key)) return "$-1\r\n";
        store.set(key, value);
        return "+OK\r\n";
      }
      case "GET": {
        const found = store.get(key);
        return found === undefined ? "$-1\r\n" : `$${found.length}\r\n${found}\r\n`;
      }
      case "DEL":
        return store.delete(key) ? ":1\r\n" : ":0\r\n";
      default:
        return "+OK\r\n";
    }
  };

  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on("close", () => sockets.delete(socket));
    socket.on("error", () => {});
    let pending: Buffer = Buffer.alloc(0);
    socket.on("data", (chunk) => {
      pending = Buffer.concat([pending, chunk]);
      const drained = drain(pending);
      pending = Buffer.from(drained.rest);
      for (const args of drained.commands) {
        commands.push(args.map((arg, i) => (i === 0 ? arg.toUpperCase() : arg)));
        if (!options.mute) socket.write(reply(args));
      }
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const { port } = server.address() as net.AddressInfo;

  return {
    url: `redis://127.0.0.1:${port}`,
    commands,
    close: () =>
      new Promise<void>((resolve) => {
        for (const socket of sockets) socket.destroy();
        server.close(() => resolve());
      }),
  };
}

/** URL de un puerto en el que no escucha nadie (Redis caído: conexión rechazada). */
export async function closedRedisUrl(): Promise<string> {
  const server = await startRespServer();
  const { url } = server;
  await server.close();
  return url;
}
