import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logRedisFailure } from "@/lib/redis/log";
import type { MembershipPrice } from "./types";

// ============================================================================
//  Caché corta, POR ID, de los precios públicos de membresía.
//
//  `GET /api/memberships/prices` es anónima y la llama cualquiera que abra una
//  ficha o un listado con el módulo encendido. Sin caché, cada una de esas
//  peticiones hacía una lectura del catálogo en la Store API, con WordPress
//  como cuello de botella: un amplificador trivial.
//
//  Dos niveles, misma clave (el id del producto) y el mismo TTL corto:
//    - L1 en memoria, por isolate: corta la ráfaga inmediata (varias tarjetas
//      en la misma página, navegación seguida) aunque Redis no esté.
//    - L2 en Redis, compartida entre isolates y réplicas: es lo que evita que
//      N réplicas hagan N lecturas del catálogo. Fail-open: sin Redis se sigue
//      sirviendo (solo se pierde el ahorro compartido). El fallo se registra
//      con `logRedisFailure`, nunca en silencio.
//
//  Además, las peticiones en vuelo se comparten por id: N peticiones
//  simultáneas con los mismos ids esperan la MISMA lectura del catálogo.
//
//  TTL de decenas de segundos a propósito: es un precio de escaparate, y una
//  edición de precio en WooCommerce tiene que verse sin esperar a un deploy.
// ============================================================================

/** TTL de una entrada (memoria y Redis). */
export const PUBLIC_PRICE_TTL_SECONDS = 30;

/** Tope de entradas del nivel en memoria; al llegar, se barren las caducadas. */
const MEMORY_MAX_ENTRIES = 2_000;

/** Prefijo de la clave en Redis. No lleva datos de nadie: solo el id del producto. */
const REDIS_KEY_PREFIX = "memberships:public-price:";

/** `null` cacheado = el producto no estaba en el catálogo (no se reintenta). */
interface CacheEntry {
  expiresAt: number;
  price: MembershipPrice | null;
}

/** Mapa id → precio de una tanda; los ids sin producto no aparecen. */
export type PublicPriceMap = Map<number, MembershipPrice>;

const memory = new Map<number, CacheEntry>();
const inFlight = new Map<number, Promise<MembershipPrice | null>>();

function cacheKey(id: number): string {
  return `${REDIS_KEY_PREFIX}${id}`;
}

function readMemory(id: number): CacheEntry | undefined {
  const entry = memory.get(id);
  if (!entry) return undefined;
  if (entry.expiresAt <= Date.now()) {
    memory.delete(id);
    return undefined;
  }
  return entry;
}

function sweepMemory(): void {
  const now = Date.now();
  for (const [id, entry] of memory) {
    if (entry.expiresAt <= now) memory.delete(id);
  }
  // Si aun así sigue lleno (todo vigente), se descarta la mitad más antigua:
  // el orden de inserción de un Map hace que sean las entradas más viejas.
  if (memory.size < MEMORY_MAX_ENTRIES) return;
  let pending = Math.ceil(memory.size / 2);
  for (const id of memory.keys()) {
    memory.delete(id);
    if (--pending <= 0) break;
  }
}

function writeMemory(id: number, price: MembershipPrice | null): void {
  if (memory.size >= MEMORY_MAX_ENTRIES) sweepMemory();
  memory.set(id, { expiresAt: Date.now() + PUBLIC_PRICE_TTL_SECONDS * 1_000, price });
}

/** Valida lo que llega de Redis: una entrada corrupta es un fallo de caché. */
function parseCachedPrice(raw: string): MembershipPrice | null | undefined {
  let value: unknown;
  try {
    value = JSON.parse(raw);
  } catch {
    return undefined;
  }
  if (value === null) return null;
  if (typeof value !== "object" || Array.isArray(value)) return undefined;
  const record = value as Record<string, unknown>;
  if (
    typeof record.id !== "number" ||
    typeof record.regular !== "string" ||
    typeof record.price !== "string" ||
    typeof record.member_price !== "string" ||
    typeof record.percent !== "number"
  ) {
    return undefined;
  }
  return {
    id: record.id,
    regular: record.regular,
    price: record.price,
    member_price: record.member_price,
    percent: record.percent,
  };
}

/** Lee de Redis lo que no estaba en memoria. Un fallo devuelve un mapa vacío. */
async function readRedis(ids: readonly number[]): Promise<Map<number, CacheEntry>> {
  const hits = new Map<number, CacheEntry>();
  const redis = getRedis();
  if (!redis) return hits;

  try {
    const raws = await Promise.all(ids.map((id) => redis.get(cacheKey(id))));
    ids.forEach((id, index) => {
      const raw = raws[index];
      if (typeof raw !== "string") return;
      const price = parseCachedPrice(raw);
      // `undefined` = entrada ilegible: se ignora y se vuelve a consultar.
      if (price === undefined) return;
      hits.set(id, {
        expiresAt: Date.now() + PUBLIC_PRICE_TTL_SECONDS * 1_000,
        price,
      });
    });
  } catch (error) {
    logRedisFailure("memberships.public_prices", "read", error, {
      level: "warn",
      effect: "se lee el catálogo en cada petición",
    });
  }
  return hits;
}

/**
 * Escribe en Redis la tanda. No se espera: la respuesta no puede pagar la ida y
 * vuelta (ni la espera de arranque del cliente). `writeRedis` no rechaza nunca.
 */
function writeRedis(
  entries: ReadonlyArray<readonly [number, MembershipPrice | null]>,
): void {
  const redis = getRedis();
  if (!redis || entries.length === 0) return;

  try {
    const pipeline = redis.pipeline();
    for (const [id, price] of entries) {
      pipeline.set(cacheKey(id), JSON.stringify(price), "EX", PUBLIC_PRICE_TTL_SECONDS);
    }
    // El pipeline devuelve sus resultados por `exec()`; un rechazo se registra.
    pipeline.exec().catch((error: unknown) => {
      logRedisFailure("memberships.public_prices", "write", error, {
        level: "warn",
        effect: "la caché compartida no se actualiza",
      });
    });
  } catch (error) {
    logRedisFailure("memberships.public_prices", "write", error, {
      level: "warn",
      effect: "la caché compartida no se actualiza",
    });
  }
}

/**
 * Precios públicos de `ids`, servidos desde la caché cuando se puede.
 *
 * @param ids        Ids ya normalizados (únicos y positivos).
 * @param loadMisses Lee del catálogo los ids que no estén cacheados. Recibe solo
 *                   los que faltan, así que un id cacheado nunca se vuelve a pedir.
 */
export async function cachedPublicPrices(
  ids: readonly number[],
  loadMisses: (ids: number[]) => Promise<PublicPriceMap>,
): Promise<PublicPriceMap> {
  const prices: PublicPriceMap = new Map();
  const notInMemory: number[] = [];

  for (const id of ids) {
    const entry = readMemory(id);
    if (!entry) {
      notInMemory.push(id);
      continue;
    }
    if (entry.price) prices.set(id, entry.price);
  }
  if (notInMemory.length === 0) return prices;

  const fromRedis = await readRedis(notInMemory);
  const missing: number[] = [];
  for (const id of notInMemory) {
    const entry = fromRedis.get(id);
    if (!entry) {
      missing.push(id);
      continue;
    }
    writeMemory(id, entry.price);
    if (entry.price) prices.set(id, entry.price);
  }
  if (missing.length === 0) return prices;

  // Tras el `await` de Redis, otra petición del mismo isolate puede haber
  // registrado ya una lectura en vuelo para alguno de estos ids: se comparte.
  const waiting = new Map<number, Promise<MembershipPrice | null>>();
  const toFetch: number[] = [];
  for (const id of missing) {
    const pending = inFlight.get(id);
    if (pending) waiting.set(id, pending);
    else toFetch.push(id);
  }

  if (toFetch.length > 0) {
    const batch = loadMisses(toFetch).then((loaded) => {
      const entries: Array<[number, MembershipPrice | null]> = toFetch.map((id) => [
        id,
        loaded.get(id) ?? null,
      ]);
      for (const [id, price] of entries) writeMemory(id, price);
      writeRedis(entries);
      return loaded;
    });

    for (const id of toFetch) {
      const tracked = batch
        .then((loaded) => loaded.get(id) ?? null)
        // La entrada se borra al resolverse, también si la lectura falla: un
        // fallo no se cachea y el siguiente intento vuelve a preguntar.
        .finally(() => {
          if (inFlight.get(id) === tracked) inFlight.delete(id);
        });
      inFlight.set(id, tracked);
      waiting.set(id, tracked);
    }
  }

  const settled = await Promise.all(
    [...waiting].map(async ([id, pending]) => [id, await pending] as const),
  );
  for (const [id, price] of settled) {
    if (price) prices.set(id, price);
  }
  return prices;
}

/**
 * Solo para tests: olvida el nivel en memoria y las lecturas en vuelo. Redis no
 * se toca (en los tests no hay cliente).
 */
export function resetPublicPriceCache(): void {
  memory.clear();
  inFlight.clear();
}
