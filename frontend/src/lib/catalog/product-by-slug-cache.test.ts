import { beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  `getProductBySlug` va memoizado por petición (`cache` de React).
//
//  La ficha lo pide desde `generateMetadata`, la página y sus secciones; GraphQL
//  va por POST y la memoización de `fetch` de Next no lo cubre. Fuera de un render
//  `cache` no memoiza, así que aquí se sustituye por un memoizador por argumentos.
//  La clave es siempre `(slug, locale)`: omitir el idioma es pedir el principal.
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
const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import { routing } from "@/i18n/routing";
import { getProductBySlug } from "./products";

beforeEach(() => {
  fetchGraphQL.mockReset();
  fetchGraphQL.mockResolvedValue({
    product: {
      id: "p1",
      databaseId: 1,
      name: "Camiseta",
      slug: "camiseta",
      type: "SIMPLE",
    },
  });
});

describe("getProductBySlug memoizado por petición", () => {
  it("metadata y página piden la misma ficha a WordPress una sola vez", async () => {
    const [a, b] = await Promise.all([
      getProductBySlug("camiseta"),
      getProductBySlug("camiseta", routing.defaultLocale),
    ]);

    expect(a).toBe(b);
    expect(fetchGraphQL).toHaveBeenCalledOnce();
  });

  it("otro slug es otra lectura", async () => {
    // Slugs que el primer test no ha leído: la memoria dura lo que el módulo.
    await getProductBySlug("taza");
    await getProductBySlug("vaso");

    expect(fetchGraphQL).toHaveBeenCalledTimes(2);
  });
});
