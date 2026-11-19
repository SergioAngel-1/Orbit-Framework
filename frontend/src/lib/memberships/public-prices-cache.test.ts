import { beforeEach, describe, expect, it, vi } from "vitest";
import { cachedPublicPrices, resetPublicPriceCache } from "./public-prices-cache";
import type { MembershipPrice } from "./types";

// ============================================================================
//  Caché corta por id de los precios públicos de membresía.
//
//  Sin Redis la caché en memoria del isolate ya corta la ráfaga (lo prueba
//  `server.test.ts`); aquí se prueba el nivel compartido: que se escribe por id
//  con su TTL, que otra réplica (memoria vacía) sirve desde Redis sin leer el
//  catálogo, que una entrada corrupta se ignora y que un Redis caído es
//  fail-open y deja rastro.
// ============================================================================

const { store, setCalls, fakeRedis, logRedisFailure } = vi.hoisted(() => {
  const store = new Map<string, string>();
  const setCalls: Array<{ key: string; value: string; mode: string; ttl: number }> = [];
  const fakeRedis = {
    get: vi.fn(async (key: string) => store.get(key) ?? null),
    pipeline: vi.fn(() => {
      const ops: Array<() => void> = [];
      return {
        set(key: string, value: string, mode: string, ttl: number) {
          setCalls.push({ key, value, mode, ttl });
          ops.push(() => store.set(key, value));
          return this;
        },
        exec: vi.fn(async () => {
          for (const op of ops) op();
          return [];
        }),
      };
    }),
  };
  return { store, setCalls, fakeRedis, logRedisFailure: vi.fn() };
});

vi.mock("@/lib/redis/client", () => ({ getRedis: () => fakeRedis }));
vi.mock("@/lib/redis/log", () => ({ logRedisFailure }));

const price = (id: number): MembershipPrice => ({
  id,
  regular: "25.00",
  price: "20.00",
  member_price: "20.00",
  percent: 0,
});

/** Deja correr los microtasks del pipeline (`writeRedis` no se espera). */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const loader = (map: Map<number, MembershipPrice>) => vi.fn(async () => new Map(map));

beforeEach(() => {
  store.clear();
  setCalls.length = 0;
  resetPublicPriceCache();
  fakeRedis.get.mockClear();
  fakeRedis.pipeline.mockClear();
  logRedisFailure.mockClear();
});

describe("caché Redis de precios públicos", () => {
  it("escribe una clave POR ID con su TTL, no una por tanda", async () => {
    const load = loader(new Map([[601, price(601)]]));

    await cachedPublicPrices([601], load);
    await flush();

    expect(load).toHaveBeenCalledTimes(1);
    expect(setCalls).toHaveLength(1);
    expect(setCalls[0]).toMatchObject({
      key: "memberships:public-price:601",
      mode: "EX",
      ttl: 30,
    });
    expect(JSON.parse(setCalls[0]!.value)).toEqual(price(601));
  });

  it("otra réplica (memoria vacía) sirve desde Redis sin leer el catálogo", async () => {
    const load = loader(new Map([[601, price(601)]]));
    await cachedPublicPrices([601], load);
    await flush();

    // El isolate se recicla: la memoria se pierde, Redis no.
    resetPublicPriceCache();
    const otherLoad = loader(new Map());

    const result = await cachedPublicPrices([601], otherLoad);

    expect(otherLoad).not.toHaveBeenCalled();
    expect(result.get(601)).toEqual(price(601));
  });

  it("un id sin producto se guarda como `null` y no se reintenta", async () => {
    const load = loader(new Map());
    await cachedPublicPrices([999], load);
    await flush();
    resetPublicPriceCache();

    const otherLoad = loader(new Map());
    const result = await cachedPublicPrices([999], otherLoad);

    expect(otherLoad).not.toHaveBeenCalled();
    expect(result.size).toBe(0);
    expect(store.get("memberships:public-price:999")).toBe("null");
  });

  it("una entrada corrupta se ignora y se vuelve a leer el catálogo", async () => {
    store.set("memberships:public-price:601", "{no es json");
    const load = loader(new Map([[601, price(601)]]));

    const result = await cachedPublicPrices([601], load);

    expect(load).toHaveBeenCalledTimes(1);
    expect(result.get(601)).toEqual(price(601));
  });

  it("un Redis caído es fail-open y deja un evento", async () => {
    fakeRedis.get.mockRejectedValueOnce(new Error("connection closed"));
    const load = loader(new Map([[601, price(601)]]));

    const result = await cachedPublicPrices([601], load);

    expect(result.get(601)).toEqual(price(601));
    expect(load).toHaveBeenCalledTimes(1);
    expect(logRedisFailure).toHaveBeenCalledWith(
      "memberships.public_prices",
      "read",
      expect.any(Error),
      expect.objectContaining({ level: "warn" }),
    );
  });
});
