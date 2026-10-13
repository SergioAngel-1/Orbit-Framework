import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));

import {
  getFeaturedPostOverride,
  getPosts,
  getPostSlugs,
  getPostsIndex,
  getTopTags,
  wpDateIso,
} from "./posts";

beforeEach(() => {
  fetchGraphQL.mockReset();
});

const page = (
  nodes: object[],
  hasNextPage = false,
  endCursor: string | null = null,
) => ({
  posts: { pageInfo: { hasNextPage, endCursor }, nodes },
});

describe("getPosts", () => {
  it("pasa los filtros a la consulta, con la etiqueta como lista", async () => {
    fetchGraphQL.mockResolvedValue(page([]));

    await getPosts({
      tag: "guias",
      author: "ana",
      category: "novedades",
      search: "lino",
    });

    expect(fetchGraphQL.mock.calls[0]![1].variables).toMatchObject({
      tag: ["guias"],
      author: "ana",
      category: "novedades",
      search: "lino",
      first: 9,
    });
  });

  it("sin filtros no manda ninguno (una cadena vacía filtraría por «nada»)", async () => {
    fetchGraphQL.mockResolvedValue(page([]));

    await getPosts({ author: "", search: "" });

    const variables = fetchGraphQL.mock.calls[0]![1].variables;
    expect(variables.tag).toBeUndefined();
    expect(variables.author).toBeUndefined();
    expect(variables.search).toBeUndefined();
  });

  it("cada etiqueta lleva el valor de ?tag= de su idioma", async () => {
    fetchGraphQL.mockResolvedValue(
      page([
        {
          id: "1",
          title: "t",
          tags: {
            nodes: [
              {
                name: "Bienestar animal",
                slug: "bienestar-animal",
                name_en: "Animal welfare",
              },
            ],
          },
        },
      ]),
    );

    const es = await getPosts({ locale: "es" });
    const en = await getPosts({ locale: "en" });

    expect(es.posts[0]!.tags!.nodes[0]).toMatchObject({ urlSlug: "bienestar-animal" });
    expect(en.posts[0]!.tags!.nodes[0]).toMatchObject({
      name: "Animal welfare",
      urlSlug: "animal-welfare",
    });
  });
});

describe("getPosts — CMS vacío", () => {
  it("una respuesta sin `posts` es una lista vacía, no un error", async () => {
    fetchGraphQL.mockResolvedValue({});
    expect(await getPosts()).toEqual({
      posts: [],
      pageInfo: { hasNextPage: false, endCursor: null },
    });
  });
});

describe("getTopTags", () => {
  it("descarta las de una sola entrada y recorta al límite", async () => {
    fetchGraphQL.mockResolvedValue({
      tags: {
        nodes: [
          { name: "A", slug: "a", count: 9 },
          { name: "B", slug: "b", count: 4 },
          { name: "C", slug: "c", count: 1 },
          { name: "D", slug: "d", count: null },
        ],
      },
    });

    const top = await getTopTags(2);

    expect(top.map((t) => t.slug)).toEqual(["a", "b"]);
  });

  it("pide más de lo que va a pintar para absorber el filtro", async () => {
    fetchGraphQL.mockResolvedValue({ tags: { nodes: [] } });
    await getTopTags(10);
    expect(fetchGraphQL.mock.calls[0]![1].variables.first).toBeGreaterThan(10);
  });

  it("un CMS vacío no rompe", async () => {
    fetchGraphQL.mockResolvedValue({});
    expect(await getTopTags()).toEqual([]);
  });
});

describe("getFeaturedPostOverride", () => {
  it("devuelve null si no hay selección", async () => {
    fetchGraphQL.mockResolvedValue({ hweFeaturedPost: null });
    expect(await getFeaturedPostOverride()).toBeNull();
  });

  it("un fallo del CMS no tumba el listado: null", async () => {
    fetchGraphQL.mockRejectedValue(new Error("down"));
    expect(await getFeaturedPostOverride()).toBeNull();
  });
});

describe("getPostSlugs", () => {
  it("recorre todas las páginas por cursor", async () => {
    fetchGraphQL
      .mockResolvedValueOnce(
        page([{ slug: "a", modified: "2026-01-01T00:00:00" }], true, "c1"),
      )
      .mockResolvedValueOnce(page([{ slug: "b", modified: null }], false, null));

    const slugs = await getPostSlugs();

    expect(slugs.map((s) => s.slug)).toEqual(["a", "b"]);
    expect(fetchGraphQL.mock.calls[1]![1].variables.after).toBe("c1");
  });

  it("un cursor que no avanza no deja el recorrido en bucle", async () => {
    fetchGraphQL.mockResolvedValue(page([{ slug: "a", modified: null }], true, "same"));

    await getPostSlugs();

    expect(fetchGraphQL.mock.calls.length).toBeLessThanOrEqual(20);
  });

  it("un fallo da lista vacía (el sitemap sigue con el resto)", async () => {
    fetchGraphQL.mockRejectedValue(new Error("down"));
    expect(await getPostSlugs()).toEqual([]);
  });
});

describe("getPostsIndex", () => {
  it("un fallo del CMS se propaga: no se cachea un archivo vacío como bueno", async () => {
    fetchGraphQL.mockRejectedValue(new Error("down"));
    await expect(getPostsIndex()).rejects.toThrow("down");
  });
});

describe("wpDateIso", () => {
  it("declara UTC sobre la variante GMT", () => {
    expect(wpDateIso("2026-06-10T21:08:02", "2026-06-10T16:08:02")).toBe(
      "2026-06-10T21:08:02+00:00",
    );
  });

  it("sin GMT (o con un valor raro) cae a la fecha local tal cual", () => {
    expect(wpDateIso(null, "2026-06-10T16:08:02")).toBe("2026-06-10T16:08:02");
    expect(wpDateIso("0000-00-00 00:00:00", "2026-06-10T16:08:02")).toBe(
      "2026-06-10T16:08:02",
    );
    expect(wpDateIso(undefined, null)).toBeUndefined();
  });
});
