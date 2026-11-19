import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

// ============================================================================
//  `getMembershipLevels` va memoizado por petición (`cache` de React).
//
//  Fuera de un render de servidor `cache` no memoiza, así que aquí se sustituye
//  por un memoizador por argumentos: si el lector dejara de envolverse en
//  `cache`, la segunda llamada volvería a pedir a WordPress y el test fallaría.
// ============================================================================

vi.mock("react", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react")>();
  return {
    ...actual,
    cache: <A extends unknown[], R>(fn: (...args: A) => R) => {
      const memo = new Map<string, R>();
      return (...args: A): R => {
        const key = JSON.stringify(args);
        if (!memo.has(key)) memo.set(key, fn(...args));
        return memo.get(key)!;
      };
    },
  };
});
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

import { getMembershipLevels } from "./server";

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(wpFetch).mockImplementation(
    async () => new Response(JSON.stringify({ items: [] }), { status: 200 }),
  );
});

describe("getMembershipLevels memoizado por petición", () => {
  it("dos lecturas del mismo idioma en un render piden a WordPress una sola vez", async () => {
    const [a, b] = await Promise.all([
      getMembershipLevels("es"),
      getMembershipLevels("es"),
    ]);

    expect(a).toBe(b);
    expect(wpFetch).toHaveBeenCalledOnce();
  });

  it("otro idioma es otra lectura", async () => {
    // Idiomas que el primer test no ha leído: la memoria dura lo que el módulo.
    await getMembershipLevels("fr");
    await getMembershipLevels("de");

    expect(wpFetch).toHaveBeenCalledTimes(2);
  });
});
