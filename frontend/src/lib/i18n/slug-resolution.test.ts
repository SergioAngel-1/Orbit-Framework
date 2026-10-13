import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", async () => {
  const actual =
    await vi.importActual<typeof import("@/lib/graphql-client")>(
      "@/lib/graphql-client",
    );
  return { ...actual, fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args) };
});
vi.mock("react", async () => {
  const actual = await vi.importActual<typeof import("react")>("react");
  // `cache` memoiza por petición: en los tests cada llamada es una petición.
  return { ...actual, cache: <T>(fn: T) => fn };
});
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

import { GraphQLClientError } from "@/lib/graphql-client";
import { nativeSlugFrom, resolveBySlugParam } from "./slug-resolution";

/** WordPress de prueba: slug traducido → slug nativo, por idioma. */
function wordpress(table: Record<string, Record<string, string>>) {
  fetchGraphQL.mockImplementation(
    async (_query: string, options: { variables: Record<string, string> }) => {
      const { locale, slug } = options.variables;
      return { hweSlugFrom: table[locale!]?.[slug!] ?? null };
    },
  );
}

const ITEMS: Record<string, { title: string }> = {
  "mi-entrada": { title: "Mi entrada" },
};
const fetchByNative = vi.fn(async (slug: string) => ITEMS[slug] ?? null);

describe("resolveBySlugParam", () => {
  beforeEach(() => {
    fetchGraphQL.mockReset();
    fetchByNative.mockClear();
    wordpress({
      en: { "my-post": "mi-entrada" },
      "pt-BR": { "meu-post": "mi-entrada" },
    });
  });

  it("en el idioma por defecto resuelve el slug nativo sin consultar traducciones", async () => {
    const found = await resolveBySlugParam("post", "mi-entrada", "es", fetchByNative);

    expect(found).toEqual({ item: ITEMS["mi-entrada"], nativeSlug: "mi-entrada" });
    expect(fetchGraphQL).not.toHaveBeenCalled();
  });

  it("en un secundario resuelve el slug traducido de ese idioma", async () => {
    const found = await resolveBySlugParam("post", "my-post", "en", fetchByNative);

    expect(found?.nativeSlug).toBe("mi-entrada");
    expect(fetchByNative).toHaveBeenCalledWith("mi-entrada");
  });

  it("en un secundario acepta también el slug nativo", async () => {
    const found = await resolveBySlugParam("post", "mi-entrada", "en", fetchByNative);

    expect(found?.nativeSlug).toBe("mi-entrada");
  });

  it("acepta el slug de CUALQUIER otro idioma (selector de idioma, enlaces viejos)", async () => {
    // /en/blog/meu-post: el slug es el de pt-BR.
    const fromEn = await resolveBySlugParam("post", "meu-post", "en", fetchByNative);
    expect(fromEn?.nativeSlug).toBe("mi-entrada");

    // /blog/my-post: el slug es el de en, la página es el idioma por defecto.
    const fromEs = await resolveBySlugParam("post", "my-post", "es", fetchByNative);
    expect(fromEs?.nativeSlug).toBe("mi-entrada");
  });

  it("un slug que no existe en ningún idioma es null", async () => {
    expect(await resolveBySlugParam("post", "nada", "en", fetchByNative)).toBeNull();
    expect(await resolveBySlugParam("post", "nada", "es", fetchByNative)).toBeNull();
  });

  it("pregunta a WordPress con el tipo, el idioma y el slug", async () => {
    await resolveBySlugParam("product", "my-post", "en", fetchByNative);

    expect(fetchGraphQL.mock.calls[0]![1].variables).toEqual({
      postType: "product",
      locale: "en",
      slug: "my-post",
    });
  });

  it("una categoría de producto se busca por su taxonomía y revalida con el tag del catálogo", async () => {
    await resolveBySlugParam("product_cat", "self-love", "en", fetchByNative);

    expect(fetchGraphQL.mock.calls[0]![1]).toMatchObject({
      variables: { postType: "product_cat", locale: "en", slug: "self-love" },
      tags: ["products"],
    });
  });

  it("no oculta un fallo de red como «no existe» (se hornearía un 404)", async () => {
    fetchGraphQL.mockRejectedValue(new GraphQLClientError("network", "caído"));

    await expect(
      resolveBySlugParam("post", "my-post", "en", fetchByNative),
    ).rejects.toThrow("caído");
  });
});

describe("nativeSlugFrom", () => {
  beforeEach(() => {
    fetchGraphQL.mockReset();
  });

  it("si WordPress no tiene la búsqueda (idiomas sin configurar) no hay slug traducido", async () => {
    fetchGraphQL.mockRejectedValue(
      new GraphQLClientError(
        "graphql",
        'Cannot query field "hweSlugFrom" on type "RootQuery".',
      ),
    );

    expect(await nativeSlugFrom("post", "en", "x")).toBeNull();
  });
});
